<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Contracts\AdminOrderEventRepository;
use WorkerAdmin\Contracts\AdminOrderRepository;
use WorkerAdmin\Core\Database;

final class DatabaseAdminOrderRepository implements AdminOrderRepository
{
    private Database $db;
    private AdminOrderEventRepository $events;
    private DatabaseAdminOrderReadStore $read;
    private DatabaseAdminOrderWriteStore $write;
    private DatabaseAdminOrderWorkflowStore $workflow;
    private DatabaseAdminOrderMediaStore $media;

    public function __construct(Database $db,AdminOrderEventRepository $events)
    {
        $this->db=$db;
        $this->events=$events;
        $this->read=new DatabaseAdminOrderReadStore($db);
        $this->write=new DatabaseAdminOrderWriteStore($db,$events);
        $this->workflow=new DatabaseAdminOrderWorkflowStore($db,$events);
        $this->media=new DatabaseAdminOrderMediaStore($db);
    }

    public function index(array $filters): array{return$this->read->index($filters);}
    public function detail(int $orderId): array{return$this->read->detail($orderId);}

    public function proxyCustomerActor(int $userId): array
    {
        if($userId<1)throw new RuntimeException('请选择有效的下单用户。');
        $row=$this->db->fetch(
            "SELECT u.id user_id,u.public_id user_public_id,u.nickname,u.mobile_masked,u.status,
                    CASE WHEN EXISTS(
                        SELECT 1 FROM wechat_identities wi
                        WHERE wi.user_id=u.id AND wi.status='active'
                    ) THEN 1 ELSE 0 END has_wechat_identity,
                    CASE WHEN EXISTS(
                        SELECT 1 FROM login_sessions s
                        WHERE s.user_id=u.id AND s.status='active' AND s.expires_at>UTC_TIMESTAMP()
                    ) THEN 1 ELSE 0 END has_active_session
             FROM users u WHERE u.id=:id AND u.status='active' LIMIT 1",
            ['id'=>$userId]
        );
        if(!$row)throw new RuntimeException('选择的客户不存在或不可用。');
        if(empty($row['mobile_masked'])||empty($row['has_wechat_identity'])){
            throw new RuntimeException('代下单必须绑定已授权手机号且具有有效微信身份的真实客户；未注册客户请使用平台承接单。');
        }
        $row['is_demo_actor']=false;
        $row['admin_proxy_consumer']=true;
        return $row;
    }

    public function internalIdByPublicNo(string $publicNo,int $userId): int
    {
        $publicNo=trim($publicNo);
        if($publicNo===''||$userId<1)return 0;
        return (int)$this->db->fetchValue(
            'SELECT id FROM orders WHERE BINARY public_no=BINARY :public_no AND user_id=:user_id LIMIT 1',
            ['public_no'=>$publicNo,'user_id'=>$userId]
        );
    }

    public function recordProxyCreated(int $orderId,int $adminId,string $adminName): void
    {
        if($orderId<1)return;
        $this->events->record($orderId,'admin_created',$adminId,'管理员代客户创建普通订单：'.$adminName);
    }

    public function create(array $input,int $adminId,string $adminName): int{return$this->write->create($input,$adminId,$adminName);}
    public function update(int $orderId,array $input,int $adminId): array{return$this->write->update($orderId,$input,$adminId);}
    public function audit(int $orderId,string $decision,string $reason,int $adminId): array{return$this->workflow->audit($orderId,$decision,$reason,$adminId);}
    public function publish(int $orderId,bool $publish): void{$this->write->publish($orderId,$publish);}
    public function cancel(int $orderId,string $reason,int $adminId): array{return$this->workflow->cancel($orderId,$reason,$adminId);}
    public function quote(int $orderId,array $input,int $adminId): int{return$this->write->quote($orderId,$input,$adminId);}
    public function assign(int $orderId,int $workerId,int $rateBp,string $reason,int $adminId): void{$this->write->assign($orderId,$workerId,$rateBp,$reason,$adminId);}
    public function invite(int $orderId,array $input,int $adminId): array{return$this->workflow->invite($orderId,$input,$adminId);}
    public function revokeInvite(int $orderId,int $inviteId): void{$this->write->revokeInvite($orderId,$inviteId);}
    public function releaseContact(int $orderId,int $adminId): void{$this->write->releaseContact($orderId,$adminId);}
    public function uploadMedia(int $orderId,string $purpose,array $file,int $adminId): int{return$this->media->upload($orderId,$purpose,$file,$adminId);}
    public function deleteMedia(int $orderId,int $mediaId): void{$this->media->delete($orderId,$mediaId);}
    public function media(int $mediaId): array{return$this->media->descriptor($this->read->media($mediaId));}
}
