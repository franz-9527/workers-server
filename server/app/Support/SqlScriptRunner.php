<?php

declare(strict_types=1);

namespace WorkerAdmin\Support;

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * 2026-08-14：无 Composer 环境下的 MySQL 脚本解析与执行器。
 */
final class SqlScriptRunner
{
    /**
     * 2026-08-14：读取并执行 SQL 文件，返回可记录到升级历史中的统计数据。
     *
     * @return array{file:string,checksum:string,statements:int,execution_ms:int}
     */
    public function runFile(PDO $pdo, string $file): array
    {
        $realFile = realpath($file);
        if ($realFile === false || !is_file($realFile) || !is_readable($realFile)) {
            throw new SqlScriptException('SQL 文件不存在或不可读。');
        }

        $sql = file_get_contents($realFile);
        if ($sql === false) {
            throw new SqlScriptException('SQL 文件读取失败。');
        }

        $rawChecksum = hash('sha256', $sql);
        $result = $this->run($pdo, $sql, $realFile);
        $result['checksum'] = $rawChecksum;
        return $result;
    }

    /**
     * 2026-08-14：执行 SQL 文本；单条失败时给出序号和安全摘要，避免泄露连接信息。
     *
     * @return array{file:string,checksum:string,statements:int,execution_ms:int}
     */
    public function run(PDO $pdo, string $sql, string $sourceName = 'inline.sql', int $startStatement = 1): array
    {
        $sql = $this->removeUtf8Bom($sql);
        if (trim($sql) === '') {
            throw new SqlScriptException('SQL 脚本内容为空。');
        }

        $startedAt = microtime(true);
        $statements = $this->splitStatements($sql);
        $executed = 0;
        if ($startStatement < 1 || $startStatement > count($statements)) {
            throw new SqlScriptException('SQL 续跑起点超出脚本范围。');
        }

        foreach ($statements as $index => $statement) {
            if ($index + 1 < $startStatement) continue;
            if (trim($statement) === '' || !$this->hasExecutableSql($statement)) {
                continue;
            }

            try {
                $this->executeStatement($pdo, $statement);
                ++$executed;
            } catch (Throwable $exception) {
                $number = $index + 1;
                $summary = $this->statementSummary($statement);
                throw new SqlScriptException(
                    sprintf('SQL 第 %d 条执行失败（%s）：%s', $number, $summary, $exception->getMessage()),
                    0,
                    $exception
                );
            }
        }

        return [
            'file' => $sourceName,
            'checksum' => hash('sha256', $sql),
            'statements' => $executed,
            'execution_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /**
     * 2026-08-14：按 MySQL 语法状态拆分语句，兼容字符串、注释及 DELIMITER 指令。
     *
     * @return string[]
     */
    public function splitStatements(string $sql): array
    {
        $length = strlen($sql);
        $delimiter = ';';
        $buffer = '';
        $statements = [];
        $quote = null;
        $lineComment = false;
        $blockComment = false;
        $lineStart = true;

        for ($position = 0; $position < $length; ++$position) {
            $character = $sql[$position];
            $next = $position + 1 < $length ? $sql[$position + 1] : '';

            if ($lineComment) {
                $buffer .= $character;
                if ($character === "\n") {
                    $lineComment = false;
                    $lineStart = true;
                }
                continue;
            }

            if ($blockComment) {
                $buffer .= $character;
                if ($character === '*' && $next === '/') {
                    $buffer .= '/';
                    ++$position;
                    $blockComment = false;
                }
                continue;
            }

            if ($quote !== null) {
                $buffer .= $character;
                if ($character === '\\' && $quote !== '`' && $next !== '') {
                    $buffer .= $next;
                    ++$position;
                    continue;
                }
                if ($character === $quote) {
                    if ($next === $quote) {
                        $buffer .= $next;
                        ++$position;
                    } else {
                        $quote = null;
                    }
                }
                $lineStart = $character === "\n";
                continue;
            }

            if ($lineStart) {
                $remaining = substr($sql, $position);
                if (preg_match('/^[ \t]*DELIMITER[ \t]+([^\r\n]+)[\r\n]*/i', $remaining, $match) === 1) {
                    $newDelimiter = trim($match[1]);
                    if ($newDelimiter === '') {
                        throw new SqlScriptException('DELIMITER 指令缺少分隔符。');
                    }
                    $delimiter = $newDelimiter;
                    $position += strlen($match[0]) - 1;
                    $lineStart = true;
                    continue;
                }
            }

            if ($character === '-' && $next === '-' && $this->isDashComment($sql, $position)) {
                $buffer .= '--';
                ++$position;
                $lineComment = true;
                $lineStart = false;
                continue;
            }

            if ($character === '#') {
                $buffer .= $character;
                $lineComment = true;
                $lineStart = false;
                continue;
            }

            if ($character === '/' && $next === '*') {
                $buffer .= '/*';
                ++$position;
                $blockComment = true;
                $lineStart = false;
                continue;
            }

            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
                $buffer .= $character;
                $lineStart = false;
                continue;
            }

            if ($delimiter !== '' && substr($sql, $position, strlen($delimiter)) === $delimiter) {
                if (trim($buffer) !== '') {
                    $statements[] = trim($buffer);
                }
                $buffer = '';
                $position += strlen($delimiter) - 1;
                $lineStart = false;
                continue;
            }

            $buffer .= $character;
            if ($character === "\n" || $character === "\r") {
                $lineStart = true;
            } elseif ($character !== ' ' && $character !== "\t") {
                $lineStart = false;
            }
        }

        if ($quote !== null) {
            throw new SqlScriptException('SQL 脚本包含未闭合的字符串或标识符。');
        }
        if ($blockComment) {
            throw new SqlScriptException('SQL 脚本包含未闭合的块注释。');
        }
        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }

        return $statements;
    }

    /**
     * 2026-08-14：MySQL 的双横线注释要求其后为空白或文件结束。
     */
    private function isDashComment(string $sql, int $position): bool
    {
        $after = $position + 2;
        return !isset($sql[$after]) || ctype_space($sql[$after]);
    }

    /**
     * 2026-09-24：可能返回结果集的语句统一使用 query，并完整消费全部 rowset。
     *
     * MySQL PREPARE/EXECUTE 的 EXECUTE 可能动态执行 SELECT；若错误地交给 PDO::exec()，
     * mysqlnd 会保留未消费结果，下一条语句即触发 SQLSTATE HY000/2014。
     */
    private function executeStatement(PDO $pdo, string $statement): void
    {
        $command = preg_replace('/\A(?:\s+|--[^\r\n]*(?:\r?\n|\z)|#[^\r\n]*(?:\r?\n|\z)|\/\*(?!\!)[\s\S]*?\*\/)*\s*/', '', $statement);
        $command = is_string($command) ? $command : $statement;
        if (preg_match('/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|CALL|EXECUTE)\b/i', $command) === 1) {
            $result = $pdo->query($statement);
            if ($result === false) {
                throw new SqlScriptException('SQL 查询执行失败且未返回 PDOStatement。');
            }
            $this->drainResultSets($result);
            return;
        }
        $pdo->exec($statement);
    }

    /**
     * 2026-09-24：消费当前结果集及后续全部 rowset，确保连接可安全执行下一条 SQL。
     */
    private function drainResultSets(PDOStatement $statement): void
    {
        try {
            do {
                if ($statement->columnCount() > 0) {
                    while ($statement->fetch(PDO::FETCH_NUM) !== false) {
                        // 升级脚本只关心执行成功与否；结果内容无需保留。
                    }
                }
            } while ($statement->nextRowset());
        } finally {
            $statement->closeCursor();
        }
    }

    /**
     * 2026-08-14：跳过文件末尾的纯说明注释，同时保留 MySQL 可执行版本注释。
     */
    private function hasExecutableSql(string $statement): bool
    {
        $withoutComments = preg_replace(
            '/--[^\r\n]*(?:\r?\n|\z)|#[^\r\n]*(?:\r?\n|\z)|\/\*(?!\!)[\s\S]*?\*\//',
            '',
            $statement
        );
        return is_string($withoutComments) && trim($withoutComments) !== '';
    }

    /**
     * 2026-08-14：去除 UTF-8 BOM，避免第一条语句无法识别。
     */
    private function removeUtf8Bom(string $sql): string
    {
        return substr($sql, 0, 3) === "\xEF\xBB\xBF" ? substr($sql, 3) : $sql;
    }

    /**
     * 2026-08-14：错误页面只显示一行有限长度摘要。
     */
    private function statementSummary(string $statement): string
    {
        $redacted = preg_replace('/\'(?:\\\\.|\'\'|[^\'])*\'/s', "'…'", $statement);
        $redacted = is_string($redacted) ? preg_replace('/"(?:\\\\.|""|[^"])*"/s', '"…"', $redacted) : $statement;
        $summary = preg_replace('/\s+/u', ' ', trim(is_string($redacted) ? $redacted : $statement));
        if (!is_string($summary)) {
            return '无法生成摘要';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($summary, 0, 120, 'UTF-8');
        }
        return substr($summary, 0, 120);
    }
}

/**
 * 2026-08-14：为安装器和升级页提供可区分的脚本异常类型。
 */
final class SqlScriptException extends RuntimeException
{
}
