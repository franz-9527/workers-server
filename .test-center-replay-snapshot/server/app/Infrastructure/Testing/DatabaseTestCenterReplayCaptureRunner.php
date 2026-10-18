<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use Throwable;
use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Core\Database;

/**
 * 在真实测试动作失败后补记可安全重放的请求参数。
 *
 * 只补写已经由业务 runner 持久化为 failed 的 test_runs，不创建新的运行记录；
 * CSRF/实例/请求键等传输字段不保存，疑似凭证/联系方式字段会阻断一键重放。
 */
final class DatabaseTestCenterReplayCaptureRunner implements TestCenterActionRunner
{
    private Database $db;
    private TestCenterActionRunner $inner;

    public function __construct(Database $db, TestCenterActionRunner $inner)
    {
        $this->db=$db;
        $this->inner=$inner;
    }

    public function available(string $instancePublicId): array
    {
        return $this->inner->available($instancePublicId);
    }

    public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey): array
    {
        try{
            return $this->inner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);
        }catch(Throwable $exception){
            $this->capture($instancePublicId,$actionKey,$input,$requestKey);
            throw $exception;
        }
    }

    private function capture(string $instancePublicId,string $actionKey,array $input,string $requestKey): void
    {
        if($instancePublicId===''||$actionKey===''||$requestKey==='')return;
        try{
            $instance=$this->db->fetch(
                'SELECT id FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',
                ['id'=>trim($instancePublicId)]
            );
            if(!$instance)return;
            $run=$this->db->fetch(
                "SELECT id,action_key,result,before_summary_json
                 FROM test_runs
                 WHERE instance_id=:instance AND BINARY idempotency_key=BINARY :idem
                 ORDER BY id DESC LIMIT 1",
                ['instance'=>(int)$instance['id'],'idem'=>$requestKey]
            );
            if(!$run||(string)$run['result']!=='failed'||(string)$run['action_key']!==$actionKey)return;

            [$safe,$blocked]=$this->sanitizeTopLevel($input);
            $summary=$this->decode((string)($run['before_summary_json']??''));
            $summary['replay_request']=$safe;
            $summary['replay_blocked_fields']=$blocked;
            $summary['replay_capture']=[
                'format'=>1,
                'action_key'=>$actionKey,
                'captured_at'=>gmdate('c'),
            ];
            $this->db->execute(
                'UPDATE test_runs SET before_summary_json=:summary WHERE id=:id AND result=\'failed\'',
                ['summary'=>$this->json($summary),'id'=>(int)$run['id']]
            );
        }catch(Throwable $ignored){
            // 复现元数据属于诊断辅助，绝不能覆盖原业务异常。
        }
    }

    /** @return array{0:array<string,mixed>,1:array<int,string>} */
    private function sanitizeTopLevel(array $input): array
    {
        $safe=[];$blocked=[];
        foreach($input as $key=>$value){
            $name=(string)$key;
            if(in_array($name,['id','action_key','request_key','_token','_csrf','csrf','csrf_token'],true))continue;
            if($this->sensitiveKey($name)){
                $blocked[]=$name;
                continue;
            }
            $safe[$name]=$this->sanitizeValue($value,$name,0,$blocked);
        }
        return[$safe,array_values(array_unique($blocked))];
    }

    /**
     * @param mixed $value
     * @param array<int,string> $blocked
     * @return mixed
     */
    private function sanitizeValue($value,string $path,int $depth,array &$blocked)
    {
        if($depth>3){
            $blocked[]=$path;
            return null;
        }
        if(is_array($value)){
            $out=[];$count=0;
            foreach($value as $key=>$item){
                if(++$count>100){
                    $blocked[]=$path.'.*';
                    break;
                }
                $name=(string)$key;$child=$path.'.'.$name;
                if(!is_int($key)&&$this->sensitiveKey($name)){
                    $blocked[]=$child;
                    continue;
                }
                $out[$key]=$this->sanitizeValue($item,$child,$depth+1,$blocked);
            }
            return$out;
        }
        if(is_string($value))return mb_substr($value,0,2000);
        if(is_int($value)||is_float($value)||is_bool($value)||$value===null)return$value;
        $blocked[]=$path;
        return null;
    }

    private function sensitiveKey(string $key): bool
    {
        return preg_match(
            '/(?:password|passwd|secret|token|openid|mobile|phone|webhook|credential|authorization|signature|private[_-]?key|api[_-]?key|access[_-]?key|csrf)/i',
            $key
        )===1;
    }

    /** @param mixed $value */
    private function json($value): string
    {
        $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return is_string($json)?$json:'{}';
    }

    private function decode(string $json): array
    {
        $value=json_decode($json,true);
        return is_array($value)?$value:[];
    }
}
