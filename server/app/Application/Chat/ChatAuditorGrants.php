<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;
use RuntimeException;
use WorkerAdmin\Contracts\ChatAuditorGrantRepository;
/** Small-program audit authority has an independent generation on every change. */
final class ChatAuditorGrants
{
    public function __construct(private ChatAuditorGrantRepository $repository) {}
    public function recent():array {return $this->repository->recent();}
    public function change(array $input,string $adminId):void
    {
        $id=$input['user_id']??'';$reason=$input['reason']??'';
        if(!is_string($id)||!is_string($reason))throw new RuntimeException('请填写真实用户编号和授权理由');
        $id=trim($id);$reason=trim($reason);
        if(preg_match('/^[1-9][0-9]{0,19}$/D',$id)!==1||strlen($id)===20&&strcmp($id,'18446744073709551615')>0||mb_strlen($reason)<2||mb_strlen($reason)>200)throw new RuntimeException('请填写真实用户编号和授权理由');
        $this->repository->save($id,($input['enabled']??'')==='1',$reason,$adminId,bin2hex(random_bytes(32)));
    }
}
