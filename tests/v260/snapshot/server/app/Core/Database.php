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

    public function __construct(array $config){$this->config=$config;}
    public function pdo(){if($this->pdo instanceof PDO)return$this->pdo;if(!extension_loaded('pdo_mysql'))throw new RuntimeException('服务器未安装 pdo_mysql 扩展。');$host=$this->config['host']??'127.0.0.1';$port=(int)($this->config['port']??3306);$database=$this->config['database']??'';$charset=$this->config['charset']??'utf8mb4';if($database==='')throw new RuntimeException('数据库名称尚未配置。');$dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',$host,$port,$database,$charset);try{$this->pdo=new PDO($dsn,(string)$this->config['username'],(string)$this->config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>false,PDO::ATTR_TIMEOUT=>5]);$this->pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");$this->pdo->exec("SET time_zone='+00:00'");}catch(PDOException $exception){throw new RuntimeException('数据库连接失败，请检查安装配置。',0,$exception);}return$this->pdo;}
    private function prepareAndExecute($sql,array $params=[]){[$sql,$params]=$this->expandDuplicateNamedParameters((string)$sql,$params);$statement=$this->pdo()->prepare($sql);$statement->execute($params);return$statement;}
    private function expandDuplicateNamedParameters($sql,array $params){if(!$params||strpos($sql,':')===false)return[$sql,$params];$normalized=[];foreach($params as$key=>$value)$normalized[ltrim((string)$key,':')]=$value;$seen=[];$expanded=[];$rewritten=preg_replace_callback('/(?<!:):([A-Za-z_][A-Za-z0-9_]*)\b/',function($match)use(&$seen,&$expanded,$normalized){$name=$match[1];if(!array_key_exists($name,$normalized))return$match[0];$index=isset($seen[$name])?++$seen[$name]:($seen[$name]=1);$target=$index===1?$name:$name.'__dup'.$index;$expanded[$target]=$normalized[$name];return':'.$target;},$sql);return[$rewritten,$expanded?:$params];}
    public function fetch($sql,array $params=[]){$s=$this->prepareAndExecute($sql,$params);$row=$s->fetch();return$row===false?null:$row;}
    public function fetchAll($sql,array $params=[]){return$this->prepareAndExecute($sql,$params)->fetchAll();}
    public function fetchValue($sql,array $params=[]){return$this->prepareAndExecute($sql,$params)->fetchColumn();}
    public function execute($sql,array $params=[]){return$this->prepareAndExecute($sql,$params)->rowCount();}
    public function insert($sql,array $params=[]){$this->execute($sql,$params);return(int)$this->pdo()->lastInsertId();}
    public function transaction(callable $callback){$pdo=$this->pdo();$outermost=$this->transactionDepth===0;if($outermost){$pdo->beginTransaction();$this->transactionRollbackOnly=false;}$this->transactionDepth++;try{$result=$callback($this);$this->transactionDepth--;if(!$outermost)return$result;if($this->transactionRollbackOnly){if($pdo->inTransaction())$pdo->rollBack();$this->resetTransactionState();throw new RuntimeException('事务已因内部失败标记为回滚，禁止提交部分业务结果。');}$pdo->commit();$this->resetTransactionState();return$result;}catch(Throwable $exception){if($this->transactionDepth>0)$this->transactionDepth--;$this->transactionRollbackOnly=true;if($outermost){if($pdo->inTransaction())$pdo->rollBack();$this->resetTransactionState();}throw$exception;}}
    private function resetTransactionState(){$this->transactionDepth=0;$this->transactionRollbackOnly=false;}
    public function tableExists($table){$count=$this->fetchValue('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table',['table'=>$table]);return(int)$count>0;}
    public function columnExists($table,$column){$count=$this->fetchValue('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column',['table'=>$table,'column'=>$column]);return(int)$count>0;}
}
