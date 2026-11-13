<?php
declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Upgrade;

use PDO;
use Throwable;
use WorkerAdmin\Support\SqlScriptRunner;

/** 触发器迁移权限预检，以及已确认早期 1419 失败的窄范围恢复。 */
final class TriggerMigrationGuard
{
    private const CHAT_CHECKSUM = '1dbe99fac9d2f54f14675688fd968f1702900792f91cdcc27b0650ffa0aeae9c';
    private PDO $pdo;
    private string $root;
    private SqlScriptRunner $runner;

    public function __construct(PDO $pdo,string $root,SqlScriptRunner $runner)
    {
        $this->pdo=$pdo;$this->root=$root;$this->runner=$runner;
    }

    /** 只读检查；绝不自动 SET GLOBAL、授予 SUPER 或跳过撤权触发器。 */
    public function preflight(array $migrations): array
    {
        $tables=[];$needed=false;
        foreach($migrations as $migration){
            $sql=file_get_contents($migration['path']);
            if(!is_string($sql))return $this->check(false,'无法读取迁移进行触发器权限预检。');
            foreach($this->runner->splitStatements($sql) as $statement){
                $command=$this->command($statement);
                if(preg_match('/^(?:CREATE|DROP)\s+TRIGGER\b/i',$command)!==1)continue;
                $needed=true;
                if(preg_match('/^CREATE\s+TRIGGER\s+`?\w+`?\s+(?:BEFORE|AFTER)\s+\w+\s+ON\s+`?(\w+)`?/i',$command,$match)===1)$tables[$match[1]]=true;
            }
        }
        if(!$needed)return $this->check(true,'当前待执行脚本不涉及触发器。');
        try{
            $query=$this->pdo->query('SELECT @@GLOBAL.log_bin AS binary_logging, @@GLOBAL.log_bin_trust_function_creators AS trusted_creators, DATABASE() AS database_name');
            $variables=$query->fetch(PDO::FETCH_ASSOC);$query->closeCursor();
            if(!is_array($variables))return $this->check(false,'无法读取 MySQL 触发器运行条件。');
            $query=$this->pdo->query('SHOW GRANTS');
            $grants=$query->fetchAll(PDO::FETCH_COLUMN);$query->closeCursor();
            $super=false;$allowed=[];
            foreach($grants as $grant){
                if(preg_match('/^GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s/i',(string)$grant,$match)!==1)continue;
                $privileges=array_map('trim',explode(',',strtoupper($match[1])));
                $scope=str_replace('`','',$match[2]);
                $all=in_array('ALL PRIVILEGES',$privileges,true);
                if($scope==='*.*'&&($all||in_array('SUPER',$privileges,true)))$super=true;
                if(!$all&&!in_array('TRIGGER',$privileges,true))continue;
                foreach(array_keys($tables) as $table){
                    if(in_array($scope,['*.*',$variables['database_name'].'.*',$variables['database_name'].'.'.$table],true))$allowed[$table]=true;
                }
            }
            if(!empty($variables['binary_logging'])&&empty($variables['trusted_creators'])&&!$super){
                return $this->check(false,'MySQL 二进制日志已开启，当前账号不能管理触发器（1419）。请数据库管理员记录原值后在升级期间设置 SET GLOBAL log_bin_trust_function_creators = 1；升级完成可恢复原值。网站账号无需授予 SUPER。');
            }
            $missing=array_diff(array_keys($tables),array_keys($allowed));
            if($missing)return $this->check(false,'当前数据库账号缺少这些表的 TRIGGER 权限：'.implode('、',$missing).'；请数据库管理员按本库范围补齐后重新预检。');
            return $this->check(true,'触发器权限与二进制日志条件已满足。');
        }catch(Throwable $error){
            return $this->check(false,'无法确认触发器管理权限，请数据库管理员核对当前账号的 TRIGGER 权限与 log_bin_trust_function_creators。');
        }
    }

    /** 只接受原始包、failed、原始第 3/4 条 1419、相符维护标记和已建表结构。 */
    public function resumeStatement(array $migration,array $record,bool $maintenanceOwned=false): int
    {
        try{
            if(trim((string)file_get_contents($this->root.'/VERSION'))!=='2.7.0'
                ||($migration['version']??'')!=='2.7.0'||($migration['file']??'')!=='2.7.0.sql'
                ||($record['status']??'')!=='failed'
                ||!hash_equals(self::CHAT_CHECKSUM,strtolower((string)($migration['checksum']??'')))
                ||!hash_equals(self::CHAT_CHECKSUM,strtolower((string)($record['checksum_sha256']??'')))
                ||!hash_equals(self::CHAT_CHECKSUM,(string)hash_file('sha256',$migration['path'])))return 0;
            $number=$this->permissionFailureStatement((string)($record['error_message']??''));
            if($number===0)return 0;
            $file=$this->root.'/storage/maintenance.lock';
            if(!is_file($file))return 0;
            $maintenance=json_decode((string)file_get_contents($file),true);
            if(!is_array($maintenance))return 0;
            $failed=($maintenance['status']??'')==='failed'
                &&$this->permissionFailureStatement((string)($maintenance['error']??''))===$number;
            $owned=$maintenanceOwned&&($maintenance['status']??'')==='upgrading'
                &&($maintenance['recovery']??'')==='v2.7.0-trigger-permission';
            if(!$failed&&!$owned)return 0;
            $statements=$this->runner->splitStatements((string)file_get_contents($migration['path']));
            if($this->command($statements[0]??'')!=='SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci'
                ||strpos($this->command($statements[1]??''),'CREATE TABLE IF NOT EXISTS `chat_admin_sessions`')!==0
                ||$this->command($statements[2]??'')!=='DROP TRIGGER IF EXISTS `chat_admin_identity_revoke`'
                ||strpos($this->command($statements[3]??''),'CREATE TRIGGER `chat_admin_identity_revoke` AFTER UPDATE ON `admin_users`')!==0)return 0;
            $query=$this->pdo->query('SHOW CREATE TABLE `chat_admin_sessions`');
            $table=$query->fetch(PDO::FETCH_NUM);$query->closeCursor();
            if(!is_array($table)||$this->normalizeDdl((string)$table[1])!==$this->normalizeDdl($this->command($statements[1])))return 0;
            if($number===4){
                $query=$this->pdo->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='chat_admin_identity_revoke'");
                $exists=(int)$query->fetchColumn();$query->closeCursor();
                if($exists!==0)return 0;
            }
            return $number;
        }catch(Throwable $error){
            return 0;
        }
    }

    private function permissionFailureStatement(string $message): int
    {
        if(preg_match('/^SQL 第 ([34]) 条执行失败/u',$message,$match)!==1
            ||strpos($message,'1419')===false||stripos($message,'SUPER privilege')===false
            ||stripos($message,'binary logging')===false)return 0;
        return (int)$match[1];
    }

    private function normalizeDdl(string $ddl): string
    {
        $ddl=str_replace('`','',strtolower($ddl));
        $ddl=(string)preg_replace('/\bif\s+not\s+exists\s+/','',$ddl);
        $ddl=(string)preg_replace('/\s+auto_increment=\d+/','',$ddl);
        $ddl=(string)preg_replace('/\s+/u',' ',$ddl);
        return trim((string)preg_replace('/\s*([(),=])\s*/u','$1',$ddl));
    }

    private function command(string $statement): string
    {
        return trim((string)preg_replace('/\A(?:\s+|--[^\r\n]*(?:\r?\n|\z)|#[^\r\n]*(?:\r?\n|\z)|\/\*(?!\!)[\s\S]*?\*\/)*\s*/','',$statement));
    }

    private function check(bool $passed,string $message): array
    {
        return ['name'=>'触发器管理权限','passed'=>$passed,'message'=>$message];
    }
}
