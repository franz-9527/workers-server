<?php
declare(strict_types=1);

namespace WorkerAdmin\Support;

/** Global exception evidence without raw exception messages, SQL, arguments or request payloads. */
final class RuntimeExceptionReporter
{
    private static function symbol(string $value):string
    {
        return preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\:]{0,199}$/D',$value)===1?$value:'unknown';
    }

    private static function source(string $file):string
    {
        $file=str_replace('\\','/',$file);
        $root=rtrim(str_replace('\\','/',function_exists('base_path')?\base_path():dirname(__DIR__,2)),'/').'/';
        if(str_starts_with($file,$root)){
            $relative=substr($file,strlen($root));
            if(preg_match('#^[A-Za-z0-9_./-]{1,240}$#D',$relative)===1)return $relative;
        }
        return '[external]';
    }

    public static function classify(\Throwable $error):array
    {
        $message=$error->getMessage();
        if($error instanceof \WorkerAdmin\Domain\Order\OrderOperationException||$error instanceof \WorkerAdmin\Domain\Security\ContentSecurityException){
            $code=$error->apiCode();
            return ['category'=>'business','code'=>preg_match('/^[A-Z][A-Z0-9_]{0,79}$/D',$code)?$code:'BUSINESS_REJECTED'];
        }
        if($error instanceof \PDOException){
            $info=is_array($error->errorInfo??null)?$error->errorInfo:[];
            $state=(string)($info[0]??$error->getCode());
            if(!preg_match('/^[A-Z0-9]{5}$/D',$state))$state='';
            $driver=(string)($info[1]??'');
            if(preg_match('/^[0-9]{1,5}$/D',$driver)!==1)$driver='';
            if($state===''&&preg_match('/SQLSTATE\[([A-Z0-9]{5})\]/',$message,$m))$state=$m[1];
            if($driver===''&&preg_match('/SQLSTATE\[[A-Z0-9]{5}\]:[^:\r\n]{0,100}:\s*([0-9]{1,5})\b/',$message,$m))$driver=$m[1];
            $category=in_array($driver,['1267','1270','1271'],true)?'sql_collation':(in_array($driver,['1054','1146'],true)||in_array($state,['42S02','42S22'],true)?'sql_schema':(str_starts_with($state,'08')||in_array($driver,['1044','1045','2002','2003','2006','2013'],true)?'database_unavailable':'database_error'));
            return ['category'=>$category,'code'=>'SQLSTATE'.($state!==''?'_'.$state:'').($driver!==''?'_'.$driver:'')];
        }
        if($error instanceof \ParseError)return ['category'=>'php_parse','code'=>'PHP_PARSE_ERROR'];
        if(preg_match('/Call to undefined function ([A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,150})\(\)/',$message,$m))return ['category'=>'php_function','code'=>'PHP_FUNCTION_UNAVAILABLE','symbol'=>self::symbol($m[1])];
        if($error instanceof \TypeError||$error instanceof \ValueError)return ['category'=>'php_type','code'=>'PHP_TYPE_ERROR'];
        if(preg_match('/permission denied|open_basedir restriction|read-only file system/i',$message))return ['category'=>'filesystem','code'=>'FILESYSTEM_UNAVAILABLE'];
        if(preg_match('/timed out|timeout|could not resolve host|connection refused|curl error/i',$message))return ['category'=>'dependency','code'=>'DEPENDENCY_UNAVAILABLE'];
        return ['category'=>'unexpected','code'=>'UNEXPECTED_EXCEPTION'];
    }

    /** The fingerprint ignores variable request data; each independent request remains visible. */
    public static function record(\Throwable $error,string $requestId,string $route=''):array
    {
        $classification=self::classify($error);
        $trace=[];
        foreach(array_slice($error->getTrace(),0,12) as $frame){
            $trace[]=[
                'file'=>self::source((string)($frame['file']??'')),
                'line'=>(int)($frame['line']??0),
                'call'=>self::symbol((string)($frame['class']??'').(isset($frame['class'])?'::':'').(string)($frame['function']??'')),
            ];
        }
        $location=['exception'=>self::symbol(get_class($error)),'file'=>self::source($error->getFile()),'line'=>$error->getLine()];
        $fingerprint=substr(hash('sha256',json_encode([$classification,$location,array_slice($trace,0,3)],JSON_INVALID_UTF8_SUBSTITUTE)),0,24);
        return ['time'=>date('c'),'request_id'=>$requestId,'fingerprint'=>$fingerprint,'route'=>preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D',$route)?$route:'']+$classification+$location+['trace'=>$trace];
    }

    public static function encode(array $record):string
    {
        return (json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)?:'{"code":"EXCEPTION_REPORT_ENCODING_FAILED"}')."\n";
    }

    public static function report(\Throwable $error,string $channel,string $route=''):array
    {
        // Error reporting must not replace a payment failure or an existing response.
        try{$record=self::record($error,\request_id(),$route);}
        catch(\Throwable $reportError){
            $record=['category'=>'unexpected','code'=>'EXCEPTION_REPORT_FAILED','fingerprint'=>''];
            try{@error_log('workers exception classification failed request_id='.\request_id());}catch(\Throwable $ignored){}
            return $record;
        }
        // Only coalesce the same exception location within one HTTP request. Do not
        // suppress occurrences across requests or long-lived CLI worker iterations.
        static $seen=[];
        $key=$channel.':'.$record['fingerprint'];
        if(PHP_SAPI!=='cli'&&isset($seen[$key]))return $record;
        if(PHP_SAPI!=='cli'&&count($seen)<64)$seen[$key]=true;
        try{\write_log($channel,self::encode($record));}
        catch(\Throwable $ignored){try{@error_log('workers exception logging failed request_id='.$record['request_id']);}catch(\Throwable $ignoredAgain){}}
        return $record;
    }

    public static function publicMessage(array $record):string
    {
        return match($record['category']??''){
            'sql_schema'=>'服务器数据库结构可能尚未完成升级，请管理员核对数据库版本。',
            'sql_collation'=>'服务器数据库查询发生字符集冲突，请管理员根据请求编号排查。',
            'database_unavailable'=>'服务器数据库暂不可用，请稍后重试。',
            'php_function','php_parse'=>'服务器 PHP 运行环境或代码异常，请管理员根据请求编号排查。',
            'filesystem'=>'服务器文件访问异常，请管理员检查目录权限。',
            'dependency'=>'服务器依赖服务暂不可用，请稍后重试。',
            default=>'服务器未能完成请求，请联系管理员并提供请求编号。',
        };
    }
}
