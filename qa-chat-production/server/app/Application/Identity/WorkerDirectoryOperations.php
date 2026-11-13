<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Identity;

use Throwable;
use WorkerAdmin\Contracts\IdentityRepository;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Contracts\WorkerCloudGateway;
use WorkerAdmin\Domain\Identity\IdentityOperationException;

/** 师傅公开资料、本人资料、资料保存与手机号受控查看。 */
final class WorkerDirectoryOperations
{
    private IdentityRepository $repository;
    private WorkerCloudGateway $cloud;
    private SettingsProvider $settings;
    private PrivateValueCipher $cipher;

    public function __construct(IdentityRepository $repository,WorkerCloudGateway $cloud,SettingsProvider $settings,PrivateValueCipher $cipher)
    {
        $this->repository=$repository;$this->cloud=$cloud;$this->settings=$settings;$this->cipher=$cipher;
    }

    public function map(): array
    {
        return ['items'=>[],'linked_cloud_ids'=>[],'authority'=>'cloud','deprecated'=>true];
    }

    public function search(array $query): array
    {
        $page=max(1,(int)($query['page']??1));$pageSize=max(1,min(50,(int)($query['page_size']??20)));
        $keyword=mb_substr(trim((string)($query['keyword']??'')),0,80);$skill=mb_substr(trim((string)($query['skill']??'')),0,20);
        $data=$this->cloud->invoke('server.publicWorkerSearch',['page'=>$page,'page_size'=>$pageSize,'keyword'=>$keyword,'skill'=>$skill]);
        $items=[];
        foreach((array)($data['items']??[]) as $row){
            if(!is_array($row))continue;$location=is_array($row['location']??null)?$row['location']:[];$skills=is_array($row['skills']??null)?$row['skills']:null;
            $items[]=[
                '_id'=>mb_substr((string)($row['_id']??''),0,128),'workerName'=>mb_substr((string)($row['workerName']??''),0,80),
                'location'=>['province'=>mb_substr((string)($location['province']??''),0,64),'city'=>mb_substr((string)($location['city']??''),0,64),'district'=>mb_substr((string)($location['district']??''),0,64),'name'=>mb_substr((string)($location['name']??''),0,200),'address'=>mb_substr((string)($location['address']??''),0,500)],
                'pay_fixed'=>!empty($row['pay_fixed']),'skills'=>$skills,'doEVA'=>!empty($row['doEVA']),'doCurtain'=>!empty($row['doCurtain']),'doFilm'=>!empty($row['doFilm']),'doPaint'=>!empty($row['doPaint']),'dotArtpaint'=>!empty($row['dotArtpaint']),'doWallPaneling'=>!empty($row['doWallPaneling']),'createTime'=>(int)($row['createTime']??0),
            ];
        }
        return ['items'=>$items,'page'=>$page,'page_size'=>$pageSize,'has_more'=>!empty($data['has_more'])];
    }

    public function detail(array $actor,array $query): array
    {
        $requested=trim((string)($query['id']??''));if($requested==='')throw new IdentityOperationException('缺少师傅编号','WORKER_ID_REQUIRED',422);
        $local=$this->repository->findLocalWorker($requested);
        if($local&&$this->repository->isDemoWorkerUser((string)($local['user_public_id']??''))){
            if(empty($actor['is_demo_actor']))throw new IdentityOperationException('演示师傅资料仅在演示身份中可见','DEMO_DATA_BOUNDARY',403);
            return $this->demoDetail($local);
        }
        if(!empty($actor['is_demo_actor']))throw new IdentityOperationException('演示身份不能访问真实云端师傅资料','DEMO_DATA_BOUNDARY',403);
        $allowOrderContext=!empty($actor['is_admin']);$orderPublicNo=trim((string)($query['order_id']??''));
        if(!$allowOrderContext&&$orderPublicNo!==''&&$local)$allowOrderContext=$this->repository->canUseWorkerOrderContext($actor,(int)$local['id'],$orderPublicNo,trim((string)($query['share_token']??'')));
        $cloudId=$local&&!empty($local['cloud_worker_id'])?(string)$local['cloud_worker_id']:$requested;
        if($local&&empty($local['cloud_worker_id'])){
            $openid=$this->repository->activeOpenidForUser((int)$local['user_id']);
            if($openid!==''){
                $owner=$this->cloud->invoke('server.owner.get',['openid'=>$openid]);$ownerWorker=is_array($owner['worker']??null)?$owner['worker']:[];
                if($ownerWorker){$this->repository->projectCloudWorker((int)$local['user_id'],$openid,$ownerWorker);$cloudId=(string)($ownerWorker['cloud_id']??$ownerWorker['_id']??$cloudId);}
            }
        }
        $data=$this->cloud->invoke('admin.detail',['id'=>$cloudId]);$profile=is_array($data['worker']??null)?$data['worker']:[];
        if(!$profile||((string)($profile['status']??'pending')!=='approved'&&!$allowOrderContext))throw new IdentityOperationException('师傅资料不存在、未审核或已暂停','WORKER_NOT_AVAILABLE',404);
        if($local)$this->repository->projectCloudWorker((int)$local['user_id'],(string)($profile['openid']??''),$profile);
        $realName=$local?$this->repository->decryptWorkerRealName($local):'';$verified=$realName!=='';
        $displayName=$verified?$realName:(string)($profile['workerName']??'师傅');
        $media=[];foreach(array_slice((array)($profile['image_urls']??$profile['imgList']??[]),0,6) as $index=>$url)if(is_string($url)&&preg_match('#^https?://#i',$url))$media[]=['id'=>'cloud-'.$index,'url'=>$url];
        $skillLabels=[];$skills=is_array($profile['skills']??null)?$profile['skills']:[];
        foreach($skills as $key=>$enabled){$label=is_int($key)?trim((string)$enabled):($enabled?trim((string)$key):'');if($label!==''&&!in_array($label,$skillLabels,true))$skillLabels[]=$label;}
        foreach(['doEVA'=>'热胶墙布','doCurtain'=>'窗帘','doFilm'=>'玻璃膜','doPaint'=>'油漆','dotArtpaint'=>'艺术漆','doWallPaneling'=>'软硬包'] as $key=>$label)if(!empty($profile[$key])&&!in_array($label,$skillLabels,true))$skillLabels[]=$label;
        $location=is_array($profile['location']??null)?$profile['location']:[];
        return [
            'id'=>$cloudId,'display_name'=>$displayName,'real_name_verified'=>$verified,'worker_type'=>(string)($profile['workerType']??''),'worker_time'=>(string)($profile['workerTime']??''),
            'city'=>(string)($location['city']??''),'district'=>(string)($location['district']??''),'service_area'=>trim((string)($location['city']??'').' '.(string)($location['district']??'')),
            'rating'=>(float)($local['rating_average']??5),'review_count'=>(int)($local['review_count']??0),'completed_orders'=>(int)($local['completed_orders']??0),
            'base_fee'=>(string)($profile['baseFeeAmount']??''),'installation_fee'=>(string)($profile['workerPaid']??''),'old_wall_service'=>!empty($profile['pay_oldwall']),'old_wall_fee'=>(string)($profile['pay_oldwall_money']??''),
            'sells_materials'=>!empty($profile['sell']),'materials'=>(string)($profile['sell_goods']??''),'description'=>(string)($profile['content']??''),'skills'=>$skillLabels,'media'=>$media,'avatar_url'=>(string)($local['avatar_url']??''),
        ];
    }

    public function profile(array $actor): array
    {
        if(!empty($actor['is_demo_actor']))return $this->demoProfile($actor);
        $openid=(string)($actor['payer_openid']??'');if($openid==='')throw new IdentityOperationException('当前微信身份缺少 OpenID，请重新登录','WORKER_OPENID_REQUIRED',401);
        try{$data=$this->cloud->invoke('server.owner.get',['openid'=>$openid]);}catch(Throwable $e){throw new IdentityOperationException('师傅发布记录暂时无法读取，请稍后重试','WORKER_PROFILE_CLOUD_UNAVAILABLE',503);}
        $profile=is_array($data['worker']??null)?$data['worker']:[];
        if($profile)$this->repository->projectCloudWorker((int)$actor['user_id'],$openid,$profile);
        return ['profile'=>$profile,'media'=>[],'status'=>$profile?(string)($profile['status']??'pending'):'none','reason'=>$profile?(string)($profile['reviewReason']??''):'','is_server_profile'=>false,'authority'=>'cloud','history_count'=>(int)($data['history_count']??0),'edit_policy'=>$this->repository->workerProfileEditPolicy($actor)];
    }

    public function saveProfile(array $actor,array $input): array
    {
        $openid=(string)($actor['payer_openid']??'');if($openid==='')throw new IdentityOperationException('当前微信身份缺少 OpenID，请重新登录','WORKER_OPENID_REQUIRED',401);
        $policy=$this->repository->workerProfileEditPolicy($actor);
        if(empty($policy['can_edit']))throw new IdentityOperationException((string)$policy['message'],'WORKER_PROFILE_EDIT_LIMIT',429,['edit_policy'=>$policy]);
        $profile=is_array($input['profile']??null)?$input['profile']:$input;
        $name=mb_substr(trim((string)($profile['workerName']??'')),0,80);$type=mb_substr(trim((string)($profile['workerType']??'')),0,160);
        $location=is_array($profile['location']??null)?$profile['location']:[];$lat=is_numeric($location['latitude']??null)?(float)$location['latitude']:null;$lng=is_numeric($location['longitude']??null)?(float)$location['longitude']:null;
        if($name===''||$type==='')throw new IdentityOperationException('请填写姓名和安装类型','WORKER_PROFILE_REQUIRED',422);
        if($lat===null||$lng===null)throw new IdentityOperationException('请选择居住地址并确认地图位置','WORKER_LOCATION_REQUIRED',422);
        $legacyImages=array_values(array_filter((array)($profile['imgList']??[]),static function($value):bool{return is_string($value)&&preg_match('#^(?:cloud|https?)://#i',$value)===1;}));
        $images=array_slice(array_values(array_unique($legacyImages)),0,3);if(!$images)throw new IdentityOperationException('请至少上传一张师傅照片','WORKER_IMAGE_REQUIRED',422);
        $allowed=['workerName','workerType','mobile','phone','startDate','endDate','workerTime','baseFeeAmount','paid','money','pay_fixed','workerPaid','pay_oldwall','pay_oldwall_money','sell','sell_goods','skills','doEVA','doCurtain','doWallPaneling','dotArtpaint','doPaint','doFilm','content','location'];
        $clean=[];foreach($allowed as $key)if(array_key_exists($key,$profile))$clean[$key]=$profile[$key];
        $userMobile=$this->repository->currentUserMobile((int)$actor['user_id']);if($userMobile!==''&&!isset($clean['mobile']))$clean['mobile']=$userMobile;
        $clean['workerName']=$name;$clean['workerType']=$type;$clean['location']=$location;$clean['imgList']=$images;
        $reservation=$this->repository->reserveWorkerProfileEdit($actor,$policy);
        if($reservation===0){$latest=$this->repository->workerProfileEditPolicy($actor);throw new IdentityOperationException((string)($latest['message']??'本周期师傅资料修改次数已用完'),'WORKER_PROFILE_EDIT_LIMIT',429,['edit_policy'=>$latest]);}
        try{$data=$this->cloud->invoke('server.owner.save',['openid'=>$openid,'profile'=>$clean]);}catch(Throwable $e){if($reservation>0)$this->repository->releaseWorkerProfileEditReservation($reservation);throw $e;}
        $saved=is_array($data['worker']??null)?$data['worker']:[];
        if(!$saved){if($reservation>0)$this->repository->releaseWorkerProfileEditReservation($reservation);throw new IdentityOperationException('云端未返回已保存的师傅资料','WORKER_CLOUD_SAVE_EMPTY',502);}
        $this->repository->projectCloudWorker((int)$actor['user_id'],$openid,$saved);$this->repository->queueWorkerProfileSubmitted($actor,$saved,$name,$type,$location);
        return ['message'=>'师傅资料已保存到云开发并重新提交审核','data'=>['worker'=>$saved,'edit_policy'=>$this->repository->workerProfileEditPolicy($actor)]];
    }

    public function phonePolicy(array $actor,string $cloudWorkerId): array
    {
        $cloudWorkerId=trim($cloudWorkerId);if($cloudWorkerId==='')throw new IdentityOperationException('缺少师傅资料编号','WORKER_PHONE_REVEAL_TARGET_REQUIRED',422);
        if(!$this->settings->bool('worker.phone_reveal_enabled',true))return ['can_reveal'=>false,'role'=>'none','remaining_24h'=>0,'reason_code'=>'DISABLED','reason'=>'后台已关闭师傅手机号查看'];
        $openid=trim((string)($actor['payer_openid']??''));if($openid==='')return ['can_reveal'=>false,'role'=>'none','remaining_24h'=>0,'reason_code'=>'OPENID_REQUIRED','reason'=>'当前微信登录状态不完整，请重新登录'];
        if(!empty($actor['is_admin'])){
            if(!$this->settings->bool('worker.phone_reveal_admin_enabled',true))return ['can_reveal'=>false,'role'=>'admin','remaining_24h'=>0,'reason_code'=>'ADMIN_DISABLED','reason'=>'后台已关闭管理员手机号查看'];
            return ['can_reveal'=>true,'role'=>'admin','remaining_24h'=>null,'limit_24h'=>null,'unlimited'=>true,'_membership_card_id'=>'','reason_code'=>'','reason'=>''];
        }
        if(!$this->settings->bool('worker.phone_reveal_member_enabled',true))return ['can_reveal'=>false,'role'=>'none','remaining_24h'=>0,'reason_code'=>'MEMBER_DISABLED','reason'=>'后台已关闭会员手机号查看'];
        $userId=(int)$actor['user_id'];$this->repository->expireMembershipGrants($userId);$grant=$this->repository->activeMembershipGrant($userId);
        if(!$grant)return ['can_reveal'=>false,'role'=>'none','remaining_24h'=>0,'reason_code'=>'MEMBER_REQUIRED','reason'=>'仅后台已开通的有效会员和管理员可以查看师傅手机号'];
        $limit=max(0,min(500,$this->settings->int('worker.phone_reveal_member_24h_limit',8)));$seen=$this->repository->phoneRevealSeenCurrent($userId,'member',$cloudWorkerId);$used=$this->repository->phoneRevealUsedCount($userId,'member');$remaining=max(0,$limit-$used);
        if($limit<1||(!$seen&&$remaining<1))return ['can_reveal'=>false,'role'=>'member','remaining_24h'=>0,'reason_code'=>'LIMIT_REACHED','reason'=>'过去24小时可查看的不同师傅数量已达到后台上限'];
        return ['can_reveal'=>true,'role'=>'member','remaining_24h'=>$remaining,'limit_24h'=>$limit,'already_viewed_24h'=>$seen,'_membership_card_id'=>(string)$grant['cloud_card_id'],'reason_code'=>'','reason'=>''];
    }

    public function authorizePhoneReveal(array $actor,string $cloudWorkerId): array
    {
        $policy=$this->phonePolicy($actor,$cloudWorkerId);if(empty($policy['can_reveal']))throw new IdentityOperationException((string)($policy['reason']??'当前账号无权查看师傅手机号'),'WORKER_PHONE_REVEAL_FORBIDDEN',403);
        $role=(string)$policy['role'];$plain=bin2hex(random_bytes(32));$digest=hash('sha256',$role.'|'.$cloudWorkerId.'|'.(string)($actor['session_id']??''));
        $this->repository->issuePhoneRevealToken((int)$actor['user_id'],hash('sha256',$plain),$digest);
        return ['reveal_token'=>$plain,'expires_in_seconds'=>90,'role'=>$role];
    }

    public function revealPhone(array $actor,array $input,string $clientIp): array
    {
        $cloudId=trim((string)($input['cloud_worker_id']??''));$token=strtolower(trim((string)($input['reveal_token']??'')));
        $policy=$this->phonePolicy($actor,$cloudId);if(empty($policy['can_reveal']))throw new IdentityOperationException((string)($policy['reason']??'当前账号无权查看师傅手机号'),'WORKER_PHONE_REVEAL_FORBIDDEN',403);
        $role=(string)$policy['role'];$digest=hash('sha256',$role.'|'.$cloudId.'|'.(string)($actor['session_id']??''));
        if($role!=='admin'){
            if(preg_match('/^[a-f0-9]{64}$/',$token)!==1)throw new IdentityOperationException('查看授权已失效，请重新点击查看','WORKER_PHONE_REVEAL_TOKEN_REQUIRED',403);
            if(!$this->repository->consumePhoneRevealToken((int)$actor['user_id'],hash('sha256',$token),$digest))throw new IdentityOperationException('查看授权已过期或已经使用，请重新点击查看','WORKER_PHONE_REVEAL_TOKEN_INVALID',403);
        }elseif(preg_match('/^[a-f0-9]{64}$/',$token)===1){$this->repository->consumePhoneRevealToken((int)$actor['user_id'],hash('sha256',$token),$digest);}
        try{$data=$this->cloud->invoke('server.phoneReveal',['viewer_openid'=>(string)($actor['payer_openid']??''),'viewer_role'=>$role,'cloud_worker_id'=>$cloudId,'membership_card_id'=>$role==='member'?(string)($policy['_membership_card_id']??''):'']);}
        catch(Throwable $e){if($role==='admin')throw new IdentityOperationException('手机号查看失败'.($e->getMessage()!==''?'：'.mb_substr($e->getMessage(),0,180):''),'WORKER_PHONE_REVEAL_REJECTED',502);throw new IdentityOperationException('会员授权状态校验失败，请联系管理员','WORKER_PHONE_REVEAL_REJECTED',403);}
        $mobile=preg_replace('/\D+/','',(string)($data['mobile']??''));if(!is_string($mobile)||preg_match('/^1\d{10}$/',$mobile)!==1)throw new IdentityOperationException('该师傅暂未登记有效手机号','WORKER_PHONE_UNAVAILABLE',404);
        $limit=$role==='member'?max(0,(int)($policy['limit_24h']??0)):0;
        if(!$this->repository->recordPhoneRevealSuccess((int)$actor['user_id'],$cloudId,$role,$limit,$this->cipher->hmac($mobile,'worker-phone-reveal-mobile'),$this->cipher->hmac($clientIp,'worker-phone-reveal-ip')))throw new IdentityOperationException('过去24小时可查看的不同师傅数量已达到后台上限','WORKER_PHONE_REVEAL_LIMIT_REACHED',429);
        return ['mobile'=>$mobile,'mobile_masked'=>(string)($data['mobile_masked']??$this->maskMobile($mobile)),'worker_name'=>mb_substr((string)($data['worker_name']??''),0,80),'notice'=>'手机号仅用于当前联系，请勿批量采集、转存或营销。'];
    }

    private function demoDetail(array $local): array
    {
        return ['id'=>(string)$local['public_id'],'display_name'=>(string)$local['display_name'],'real_name_verified'=>false,'worker_type'=>'综合铺贴师傅','worker_time'=>'8年','city'=>(string)($local['city']?:'杭州市'),'district'=>(string)($local['district']?:'西湖区'),'service_area'=>trim((string)($local['city']?:'杭州市').' '.(string)($local['district']?:'西湖区')),'rating'=>(float)($local['rating_average']??4.98),'review_count'=>(int)($local['review_count']??18),'completed_orders'=>(int)($local['completed_orders']??18),'base_fee'=>'50','installation_fee'=>'80','old_wall_service'=>true,'old_wall_fee'=>'20','sells_materials'=>false,'materials'=>'','description'=>'独立演示师傅资料，不关联任何真实微信或云开发记录。','skills'=>['墙纸','墙布','玻璃膜','家具膜','窗帘','美缝'],'media'=>[],'avatar_url'=>(string)($local['avatar_url']??'')];
    }

    private function demoProfile(array $actor): array
    {
        $empty=['profile'=>[],'media'=>[],'status'=>'none','reason'=>'','is_server_profile'=>true,'authority'=>'demo_local','history_count'=>0,'edit_policy'=>['limited'=>false,'ever_approved'=>false,'can_edit'=>true,'limit_count'=>0,'period_days'=>30,'used_count'=>0,'remaining_count'=>null,'next_edit_at'=>null,'message'=>'首次入驻审核通过前可随时修改资料']];
        if((string)($actor['demo_role']??'')!=='worker'||empty($actor['worker_id']))return $empty;
        $profile=['_id'=>'DEMO-WORKER','status'=>'approved','workerName'=>(string)($actor['worker_name']?:'演示师傅'),'workerType'=>'综合铺贴师傅','workerTime'=>'8年','location'=>['city'=>(string)($actor['worker_city']?:'杭州市'),'district'=>(string)($actor['worker_district']?:'西湖区'),'address'=>'演示服务区域（虚构）'],'skills'=>['墙纸'=>true,'墙布'=>true,'玻璃膜'=>true,'家具膜'=>true,'窗帘'=>true,'美缝'=>true],'baseFeeAmount'=>'50','workerPaid'=>'80','pay_oldwall'=>true,'pay_oldwall_money'=>'20','sell'=>false,'sell_goods'=>'','content'=>'独立演示师傅，仅用于展示报价、自动成交接单、平台派单、预约、施工、完工、维修、结算和提现等流程。','imgList'=>[]];
        return ['profile'=>$profile,'media'=>[],'status'=>'approved','reason'=>'','is_server_profile'=>true,'authority'=>'demo_local','history_count'=>3,'edit_policy'=>['limited'=>false,'ever_approved'=>true,'can_edit'=>true,'limit_count'=>0,'period_days'=>30,'used_count'=>0,'remaining_count'=>null,'next_edit_at'=>null,'message'=>'演示身份不限制资料修改']];
    }

    private function maskMobile(string $mobile): string { return substr($mobile,0,3).'****'.substr($mobile,-4); }
}
