<?php

namespace WorkerAdmin\Core;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

class Database
{
    private $config;
    private $pdo;
    private $transactionDepth = 0;
    private $transactionRollbackOnly = false;
    private array $beforeCommitCallbacks = [];
    private bool $runningBeforeCommit = false;

    // 2026-08-14：统一 PDO 连接并强制真实预处理，兼容 MySQL 5.7.44。
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function pdo()
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('服务器未安装 pdo_mysql 扩展。');
        }

        $host = isset($this->config['host']) ? $this->config['host'] : '127.0.0.1';
        $port = isset($this->config['port']) ? (int) $this->config['port'] : 3306;
        $database = isset($this->config['database']) ? $this->config['database'] : '';
        $charset = isset($this->config['charset']) ? $this->config['charset'] : 'utf8mb4';
        if ($database === '') {
            throw new RuntimeException('数据库名称尚未配置。');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);
        try {
            $this->pdo = new PDO($dsn, (string) $this->config['username'], (string) $this->config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $this->pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $this->pdo->exec("SET time_zone='+00:00'");
        } catch (PDOException $exception) {
            throw new RuntimeException('数据库连接失败，请检查安装配置。', 0, $exception);
        }

        return $this->pdo;
    }

    /**
     * MySQL 原生预处理不允许在同一条 SQL 中重复使用同名占位符。
     * 这里仅展开重复命名参数，继续保持 ATTR_EMULATE_PREPARES=false，避免各业务控制器重复踩坑。
     */
    private function prepareAndExecute($sql, array $params = [])
    {
        list($sql, $params) = $this->expandDuplicateNamedParameters((string) $sql, $params);
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    private function expandDuplicateNamedParameters($sql, array $params)
    {
        if (!$params || strpos($sql, ':') === false) {
            return [$sql, $params];
        }

        $normalized = [];
        foreach ($params as $key => $value) {
            $normalized[ltrim((string) $key, ':')] = $value;
        }
        $seen = [];
        $expanded = [];
        $rewritten = preg_replace_callback('/(?<!:):([A-Za-z_][A-Za-z0-9_]*)\b/', function ($match) use (&$seen, &$expanded, $normalized) {
            $name = $match[1];
            if (!array_key_exists($name, $normalized)) {
                return $match[0];
            }
            $index = isset($seen[$name]) ? ++$seen[$name] : ($seen[$name] = 1);
            $target = $index === 1 ? $name : $name . '__dup' . $index;
            $expanded[$target] = $normalized[$name];
            return ':' . $target;
        }, $sql);

        // 保留未出现在 SQL 中的参数会触发 HY093；因此只传递实际匹配到的命名参数。
        // 本项目数据库层统一使用命名参数，若 SQL 没有匹配到占位符则沿用原参数以便显式暴露调用错误。
        return [$rewritten, $expanded ?: $params];
    }

    public function fetch($sql, array $params = [])
    {
        $statement = $this->prepareAndExecute($sql, $params);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    public function fetchAll($sql, array $params = [])
    {
        return $this->prepareAndExecute($sql, $params)->fetchAll();
    }

    public function fetchValue($sql, array $params = [])
    {
        return $this->prepareAndExecute($sql, $params)->fetchColumn();
    }

    public function execute($sql, array $params = [])
    {
        return $this->prepareAndExecute($sql, $params)->rowCount();
    }

    public function insert($sql, array $params = [])
    {
        $this->execute($sql, $params);
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * 共享连接的事务加入语义：只有最外层真正 begin/commit；内层 transaction 加入当前事务。
     * 任一内层回调抛错都会把整笔用例标记 rollback-only，即使上层业务误捕获异常，也禁止提交部分结果。
     */
    public function transaction(callable $callback)
    {
        $pdo = $this->pdo();
        $outermost = $this->transactionDepth === 0;

        if ($outermost) {
            $pdo->beginTransaction();
            $this->transactionRollbackOnly = false;
            $this->beforeCommitCallbacks = [];
        }
        $this->transactionDepth++;

        try {
            $result = $callback($this);

            if (!$outermost) {
                $this->transactionDepth--;
                return $result;
            }

            if ($this->transactionRollbackOnly) {
                throw new RuntimeException('事务已因内部失败标记为回滚，禁止提交部分业务结果。');
            }

            $this->runningBeforeCommit = true;
            ksort($this->beforeCommitCallbacks,SORT_STRING);
            foreach ($this->beforeCommitCallbacks as $beforeCommit) $beforeCommit($this);
            $this->runningBeforeCommit = false;
            if ($this->transactionRollbackOnly) throw new RuntimeException('事务已因内部失败标记为回滚，禁止提交部分业务结果。');
            $this->transactionDepth--;
            $pdo->commit();
            $this->resetTransactionState();
            return $result;
        } catch (Throwable $exception) {
            if ($this->transactionDepth > 0) {
                $this->transactionDepth--;
            }
            $this->transactionRollbackOnly = true;

            if ($outermost) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $this->resetTransactionState();
            }
            throw $exception;
        }
    }

    /** Register bounded DML work after all outer/nested writes, before the same commit. */
    public function beforeCommit(string $key, callable $callback): void
    {
        if ($this->transactionDepth<1 || !$this->pdo()->inTransaction() || $this->runningBeforeCommit
            || $key==='' || strlen($key)>96 || (!isset($this->beforeCommitCallbacks[$key]) && count($this->beforeCommitCallbacks)>=128)) {
            throw new RuntimeException('预提交动作必须在原事务内登记且不得递归或超过上限');
        }
        $this->beforeCommitCallbacks[$key]=$callback;
    }

    private function resetTransactionState()
    {
        $this->transactionDepth = 0;
        $this->transactionRollbackOnly = false;
        $this->beforeCommitCallbacks = [];
        $this->runningBeforeCommit = false;
    }

    public function tableExists($table)
    {
        $count = $this->fetchValue(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table',
            ['table' => $table]
        );
        return (int) $count > 0;
    }

    public function columnExists($table, $column)
    {
        $count = $this->fetchValue(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column',
            ['table' => $table, 'column' => $column]
        );
        return (int) $count > 0;
    }
}
