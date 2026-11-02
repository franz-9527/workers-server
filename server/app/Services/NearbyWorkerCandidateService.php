<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

use RuntimeException;
use Throwable;
use WorkerAdmin\Core\Database;

final class NearbyWorkerCandidateService
{
    private Database $db;
    private WorkerQuoteEligibilityService $eligibility;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->eligibility = new WorkerQuoteEligibilityService($db);
    }

    public function capability(array $order, int $userId): array
    {
        $reason = '';
        if ((int)($order['user_id'] ?? 0) !== $userId) $reason = '只有订单本人可以通知附近师傅';
        elseif (!empty($order['is_demo'])) $reason = '演示订单不会发送真实短信';
        elseif (!$this->eligibility->orderCanCollectQuotes($order, true)) $reason = '订单当前不在公开报价阶段';
        elseif ($this->eligibility->isRestrictedMeasurementConstruction((int)$order['id'])) $reason = '关联测量施工单仅允许原测量师傅报价';
        elseif ($order['lat'] === null || $order['lng'] === null) $reason = '订单缺少可用于附近查询的位置';
        elseif (!in_array((string)($order['coordinate_system'] ?? 'GCJ-02'), ['GCJ-02'], true)) $reason = '订单位置坐标系暂不支持附近短信';
        elseif (\filter_var(\setting('development.pause_hall', false), FILTER_VALIDATE_BOOLEAN)
            || \filter_var(\setting('development.pause_quotes', false), FILTER_VALIDATE_BOOLEAN)) $reason = '平台当前暂停新报价';
        else {
            $sms = new AliyunSmsService();
            if (!$sms->enabled()) $reason = '短信服务暂未启用';
            elseif ($this->templateCode() === '') $reason = '附近师傅短信模板尚未配置';
            elseif ($this->eligibility->quoteSlotsRemaining((int)$order['id']) < 1) $reason = '本单当前报价名额已满';
        }

        return [
            'can_notify_nearby_workers'=>$reason === '',
            'unavailable_reason'=>$reason,
            'quote_slots_remaining'=>$this->eligibility->quoteSlotsRemaining((int)$order['id']),
        ];
    }

    public function listForOwner(array $order, int $userId): array
    {
        $capability = $this->capability($order, $userId);
        $wallet = (new UserWalletService($this->db))->summary($userId);
        $pricing = $this->pricing($userId, (int)$order['id']);

        if (!$capability['can_notify_nearby_workers']) {
            return array_merge($capability, $pricing, [
                'wallet_balance_cents'=>$wallet['balance_cents'],
                'wallet_reserved_cents'=>$wallet['reserved_cents'],
                'radius_km'=>30,'fallback_mode'=>false,'candidates'=>[],
            ]);
        }

        $pools=$this->candidatePools($order);
        $verified=$pools['verified30'];
        $marked=$pools['marked30'];
        $fallback=!empty($pools['fallback_mode']);

        if($fallback){
            $selected=$pools['fallback'];
        }else{
            usort($verified,fn(array $a,array $b):int=>((int)$a['distance_m'])<=>((int)$b['distance_m']));
            usort($marked,fn(array $a,array $b):int=>((int)$a['distance_m'])<=>((int)$b['distance_m']));
            $selected=array_merge(array_slice($verified,0,3),array_slice($marked,0,3));
        }

        $public = [];
        foreach ($selected as $candidate) $public[] = $this->publicCandidate($candidate, $userId, (int)$order['id'], $fallback);

        return array_merge($capability, $pricing, [
            'wallet_balance_cents'=>$wallet['balance_cents'],
            'wallet_reserved_cents'=>$wallet['reserved_cents'],
            'radius_km'=>30,
            'fallback_mode'=>$fallback,
            'fallback_notice'=>$fallback ? '30公里内暂无可通知师傅，以下为距离最近的3位' : '',
            'candidates'=>$public,
        ]);
    }

    /**
     * Resolve candidate tokens against a fresh candidate query.
     * Returned candidates include private mobile only for server-side dispatch preparation.
     */
    public function candidateRequestSummary(int $orderId,int $userId,array $tokens,bool $allowExpired=true): array
    {
        $tokens=array_values(array_unique(array_filter(array_map('strval',$tokens))));
        if(count($tokens)<1||count($tokens)>6)throw new RuntimeException('请选择 1 至 6 位师傅');
        $keys=[];
        foreach($tokens as $token){
            $claim=$this->decodeSignedToken($token,'candidate',$allowExpired);
            if((int)($claim['uid']??0)!==$userId||(int)($claim['oid']??0)!==$orderId){
                throw new RuntimeException('候选令牌不属于当前订单');
            }
            $type=(string)($claim['type']??'');
            $identity=(string)($claim['identity']??'');
            $scope=(string)($claim['scope']??'radius30');
            if($identity===''||!in_array($type,['verified','marked'],true)||!in_array($scope,['radius30','fallback'],true))throw new RuntimeException('候选令牌内容无效');
            $keys[]=$type.':'.$identity;
        }
        $keys=array_values(array_unique($keys));
        if(count($keys)<1||count($keys)>6)throw new RuntimeException('请选择 1 至 6 位师傅');
        sort($keys,SORT_STRING);
        return ['fingerprint'=>hash('sha256',implode('|',$keys)),'requested_count'=>count($keys),'identity_keys'=>$keys];
    }

    public function fingerprintCandidateTokens(int $orderId,int $userId,array $tokens,bool $allowExpired=true): string
    {
        $summary=$this->candidateRequestSummary($orderId,$userId,$tokens,$allowExpired);
        return (string)$summary['fingerprint'];
    }

    public function resolveCandidateTokens(array $order, int $userId, array $tokens): array
    {
        $tokens = array_values(array_unique(array_filter(array_map('strval',$tokens))));
        if (count($tokens) < 1 || count($tokens) > 6) throw new RuntimeException('请选择 1 至 6 位师傅');

        $claims = [];
        foreach ($tokens as $token) {
            $claim = $this->decodeSignedToken($token, 'candidate');
            if ((int)($claim['uid'] ?? 0) !== $userId || (int)($claim['oid'] ?? 0) !== (int)$order['id']) {
                throw new RuntimeException('候选令牌不属于当前订单');
            }
            $claims[(string)$claim['identity']] = $claim;
        }

        $pools=$this->candidatePools($order);
        $radiusByIdentity=[];
        foreach(array_merge($pools['verified30'],$pools['marked30']) as $candidate){
            $radiusByIdentity[(string)$candidate['worker_identity_key']]=$candidate;
        }
        $fallbackByIdentity=[];
        foreach($pools['fallback'] as $candidate){
            $fallbackByIdentity[(string)$candidate['worker_identity_key']]=$candidate;
        }

        $resolved = [];
        foreach ($claims as $identity=>$claim) {
            $scope=(string)($claim['scope']??'radius30');
            $source=$scope==='fallback'?$fallbackByIdentity:$radiusByIdentity;
            if (!isset($source[$identity])) continue;
            $candidate = $source[$identity];
            if ((string)$candidate['candidate_type'] !== (string)($claim['type'] ?? '')) continue;
            if (!empty($candidate['already_notified']) || empty($candidate['selectable'])) continue;
            $resolved[] = $candidate;
        }
        return $resolved;
    }

    public function validatePriceToken(string $token, int $userId, int $orderId, int $confirmedMaxTotal, int $validCount): array
    {
        $claim = $this->decodeSignedToken($token, 'price');
        if ((int)($claim['uid'] ?? 0) !== $userId || (int)($claim['oid'] ?? 0) !== $orderId) {
            throw new RuntimeException('价格确认已失效，请刷新候选列表');
        }
        $confirmedUnit = (int)($claim['fee'] ?? -1);
        $latest = $this->pricing($userId, $orderId);
        $latestUnit = (int)$latest['fee_cents'];
        if ($latestUnit > $confirmedUnit) {
            $error = new RuntimeException('短信价格已调整，请刷新后重新确认');
            $error->price_changed = true;
            throw $error;
        }
        $total = $latestUnit * $validCount;
        if ($confirmedMaxTotal < $total) throw new RuntimeException('本次费用超过你刚才确认的最高金额，请重新确认');
        return ['fee_cents'=>$latestUnit,'total_cents'=>$total,'pricing_version'=>$latest['pricing_version']];
    }

    public function findFreshCandidateForInvite(array $order, array $invite): ?array
    {
        $pools=$this->candidatePools($order);
        $scope=(int)($invite['distance_m_snapshot']??0)>30000?'fallback':'radius30';
        $fresh=$scope==='fallback'
            ? $pools['fallback']
            : array_merge($pools['verified30'],$pools['marked30']);
        foreach ($fresh as $candidate) {
            if ((string)$candidate['worker_identity_key'] !== (string)$invite['worker_identity_key']) continue;
            if ((string)$candidate['candidate_type'] !== (string)$invite['candidate_type']) continue;
            if (!hash_equals((string)$invite['mobile_hmac_snapshot'], (string)$candidate['mobile_hmac'])) return null;
            return $candidate;
        }
        return null;
    }

    public function templateCode(): string
    {
        $templates = \setting('sms.template_codes', []);
        if (!is_array($templates)) return '';
        $scene = $templates['nearby_worker_invite'] ?? '';
        if (is_string($scene)) return trim($scene);
        if (!is_array($scene)) return '';
        return trim((string)(($scene['template_code'] ?? '') ?: ($scene['template_id'] ?? '') ?: ($scene['code'] ?? '')));
    }

    public function priceTokenFor(int $userId, int $orderId): array
    {
        return $this->pricing($userId,$orderId);
    }

    private function pricing(int $userId, int $orderId): array
    {
        $row = $this->db->fetch("SELECT setting_value,updated_at FROM system_settings WHERE setting_key='sms.user_worker_invite_fee_cents' LIMIT 1");
        $fee = max(1, min(10000, (int)($row['setting_value'] ?? 10)));
        $updated = (string)($row['updated_at'] ?? '');
        $expires = time() + 600;
        $token = $this->encodeSignedToken([
            'uid'=>$userId,'oid'=>$orderId,'fee'=>$fee,'exp'=>$expires
        ], 'price');
        return [
            'fee_cents'=>$fee,
            'pricing_version'=>hash('sha256',$fee.'|'.$updated),
            'price_quote_token'=>$token,
            'price_quote_expires_at'=>gmdate('Y-m-d H:i:s',$expires),
        ];
    }

    private function candidatePools(array $order): array
    {
        // 正常模式严格限制 30km；只有两类候选在 30km 内都为 0 时才启用最近 3 位 fallback。
        // POST 与真正发送前都复用此方法，避免 GET/POST/scheduler 三套范围规则漂移。
        $verified30=$this->verifiedCandidates($order,30000,120);
        $marked30=$this->dedupeMarked($this->markedCandidates($order,30000,60),$verified30);
        if($verified30||$marked30){
            return ['verified30'=>$verified30,'marked30'=>$marked30,'fallback'=>[],'fallback_mode'=>false];
        }

        $verifiedAll=$this->verifiedCandidates($order,null,160);
        $markedAll=$this->dedupeMarked($this->markedCandidates($order,null,60),$verifiedAll);
        $combined=array_merge($verifiedAll,$markedAll);
        usort($combined,static function(array $a,array $b):int{
            $distance=((int)$a['distance_m'])<=>((int)$b['distance_m']);
            if($distance!==0)return $distance;
            return ($a['candidate_type']==='verified'?0:1)<=>($b['candidate_type']==='verified'?0:1);
        });
        return ['verified30'=>[],'marked30'=>[],'fallback'=>array_slice($combined,0,3),'fallback_mode'=>true];
    }

    private function verifiedCandidates(array $order, ?int $radiusMeters, int $limit): array
    {
        $lat = (float)$order['lat']; $lng = (float)$order['lng'];
        $params = ['lat'=>$lat,'lng'=>$lng,'coordinate_system'=>(string)($order['coordinate_system'] ?? 'GCJ-02')];
        $where = [
            "w.verification_status='approved'","w.invited_only=0","u.status='active'",
            "w.lat IS NOT NULL","w.lng IS NOT NULL","w.coordinate_system=:coordinate_system",
            "COALESCE(w.cloud_record_mode,'legacy')<>'demo_local'",
        ];
        if ($radiusMeters !== null) {
            $latDelta = ($radiusMeters / 1000) / 111.0;
            $cos = max(0.1, cos(deg2rad($lat)));
            $lngDelta = ($radiusMeters / 1000) / (111.0 * $cos);
            $where[] = 'w.lat BETWEEN :min_lat AND :max_lat';
            $where[] = 'w.lng BETWEEN :min_lng AND :max_lng';
            $params['min_lat']=$lat-$latDelta;$params['max_lat']=$lat+$latDelta;
            $params['min_lng']=$lng-$lngDelta;$params['max_lng']=$lng+$lngDelta;
        }
        $sql = "SELECT w.*,u.status user_status,COALESCE(rs.rating_average,w.rating,5.00) rating_average,
                       COALESCE(rs.review_count,0) review_count
                FROM worker_profiles w
                JOIN users u ON u.id=w.user_id
                LEFT JOIN rating_summaries rs ON rs.worker_id=w.id
                WHERE ".implode(' AND ',$where)."
                ORDER BY ABS(w.lat-:lat)+ABS(w.lng-:lng),w.id
                LIMIT ".max(1,min(200,$limit));
        $rows = $this->db->fetchAll($sql,$params);
        $result=[];$seenMobile=[];
        $ownerMobileHmac=$this->orderOwnerSmsMobileHmac($order);
        foreach($rows as $worker){
            if (!$this->eligibility->workerCanQuote($worker,$order,true)) continue;
            $mobile=MobileNumberService::workerProfileMobile($worker);
            if($mobile==='')continue;
            $hmac=MobileNumberService::hmac($mobile);
            if($ownerMobileHmac!==''&&hash_equals($ownerMobileHmac,$hmac))continue;
            if(isset($seenMobile[$hmac]))continue;
            if($this->suppressed($hmac))continue;
            $seenMobile[$hmac]=true;
            $distance=$this->distanceMeters($lat,$lng,(float)$worker['lat'],(float)$worker['lng']);
            if($radiusMeters!==null&&$distance>$radiusMeters)continue;
            $identity=hash('sha256','verified:'.(int)$worker['id']);
            $notified=$this->alreadyNotified((int)$order['id'],$identity,$hmac);
            $result[]=[
                'candidate_type'=>'verified','worker_id'=>(int)$worker['id'],'cloud_worker_id'=>(string)($worker['cloud_worker_id']??''),
                'worker_identity_key'=>$identity,'name'=>(string)$worker['display_name'],'mobile'=>$mobile,'mobile_hmac'=>$hmac,
                'distance_m'=>$distance,'rating'=>(float)$worker['rating_average'],'completed_orders'=>(int)($worker['completed_orders']??0),
                'detail_target'=>'worker-resolve:'.(string)$worker['public_id'],
                'verified'=>true,'already_notified'=>$notified,'selectable'=>!$notified,
            ];
        }
        return $result;
    }

    private function markedCandidates(array $order, ?int $radiusMeters, int $limit): array
    {
        if (!empty($order['is_demo'])) return [];
        try {
            $response=(new WorkerCloudGatewayService())->invoke('server.nearbyMarkedWorkers',[
                'latitude'=>(float)$order['lat'],'longitude'=>(float)$order['lng'],
                'radius_m'=>$radiusMeters===null?20000000:$radiusMeters,
                'limit'=>max(3,min(60,$limit)),
                'category_names'=>$this->orderCategoryNames($order),
            ]);
        } catch(Throwable $exception) {
            \write_log('nearby-marked','['.date('c').'] '.$exception->getMessage()."\n");
            return [];
        }
        $items=is_array($response['items']??null)?$response['items']:[];
        $result=[];
        $ownerMobileHmac=$this->orderOwnerSmsMobileHmac($order);
        foreach($items as $row){
            if(!is_array($row))continue;
            $name=(string)($row['name']??'');
            if(strpos($name,'❔')!==false)continue;
            if(strpos($name,'✅')===false&&strpos($name,'🈴')===false)continue;
            $mobile=MobileNumberService::normalize((string)($row['mobile']??''));
            if($mobile==='')continue;
            $hmac=MobileNumberService::hmac($mobile);
            if($ownerMobileHmac!==''&&hash_equals($ownerMobileHmac,$hmac))continue;
            // 历史标记资料只补充“尚未进入正式 worker_profiles”的号码。
            // worker_profiles 的历史 mobile_hmac 可能来自不同用途域，不能直接与短信域 HMAC 比较。
            if($this->hasCanonicalWorkerMobile($mobile,$hmac))continue;
            if($this->shouldHideUnregisteredMarkedMobile($hmac))continue;
            if($this->suppressed($hmac))continue;
            $lat=isset($row['lat'])?(float)$row['lat']:null;$lng=isset($row['lng'])?(float)$row['lng']:null;
            if($lat===null||$lng===null)continue;
            $distance=$this->distanceMeters((float)$order['lat'],(float)$order['lng'],$lat,$lng);
            if($radiusMeters!==null&&$distance>$radiusMeters)continue;
            $cloudId=(string)($row['cloud_id']??'');
            if($cloudId==='')continue;
            $identity=hash('sha256','marked:'.$cloudId);
            $notified=$this->alreadyNotified((int)$order['id'],$identity,$hmac);
            $result[]=[
                'candidate_type'=>'marked','worker_id'=>null,'cloud_worker_id'=>$cloudId,
                'worker_identity_key'=>$identity,'name'=>$name,'mobile'=>$mobile,'mobile_hmac'=>$hmac,
                'distance_m'=>$distance,'rating'=>null,'completed_orders'=>0,
                'detail_target'=>(string)(($row['detail_target']??'')?:('/pages/record/detail?id='.rawurlencode($cloudId))),
                'verified'=>false,'already_notified'=>$notified,'selectable'=>!$notified,
            ];
        }
        usort($result,fn(array $a,array $b):int=>((int)$a['distance_m'])<=>((int)$b['distance_m']));
        return array_slice($result,0,$limit);
    }

    private function orderCategoryNames(array $order): array
    {
        $names=[];
        foreach ([(string)($order['category_name_snapshot'] ?? ''),(string)($order['category_names_snapshot'] ?? '')] as $raw) {
            foreach (preg_split('/[、,，\/|;；]+/u',$raw) ?: [] as $name) {
                $name=trim((string)$name);
                if($name!==''&&!in_array($name,$names,true))$names[]=$name;
            }
        }
        if(!empty($order['id'])){
            foreach($this->db->fetchAll('SELECT category_name_snapshot FROM order_categories WHERE order_id=:order_id ORDER BY sort_order,category_id',['order_id'=>$order['id']]) as $row){
                $name=trim((string)($row['category_name_snapshot']??''));
                if($name!==''&&!in_array($name,$names,true))$names[]=$name;
            }
        }
        return array_slice($names,0,12);
    }

    private function hasCanonicalWorkerMobile(string $mobile,string $smsHmac): bool
    {
        $masked=\mask_private_mobile($mobile);
        if($masked==='')return false;
        $rows=$this->db->fetchAll(
            'SELECT mobile_ciphertext FROM worker_profiles WHERE mobile_masked=:masked AND mobile_ciphertext IS NOT NULL LIMIT 30',
            ['masked'=>$masked]
        );
        foreach($rows as $row){
            $canonical=MobileNumberService::workerProfileMobile($row);
            if($canonical===''||$canonical!==$mobile)continue;
            if(hash_equals($smsHmac,MobileNumberService::hmac($canonical)))return true;
        }
        return false;
    }

    public function unregisteredMarkedMobileHideThreshold(): int
    {
        return max(0,min(1000,(int)\setting('sms.user_worker_invite_unregistered_hide_after_count',10)));
    }

    public function unregisteredMarkedMobileAttemptCount(string $mobileHmac): int
    {
        if($mobileHmac==='')return 0;
        // 只统计真正进入外部 SendSms 调用窗口的事实。发送前取消/配置错误/发送前异常不算；
        // provider 拒绝、运营商失败或结果不确定仍属于真实触达尝试，不以最终扣费/送达作为计数口径。
        return (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM order_worker_sms_invites
             WHERE mobile_hmac_snapshot=:hmac
               AND attempted_at IS NOT NULL
               AND delivery_status<>'cancelled_before_send'
               AND COALESCE(error_code,'') NOT IN ('CONFIGURATION_ERROR','DISPATCH_EXCEPTION','CANCELLED_BEFORE_SEND')",
            ['hmac'=>$mobileHmac]
        );
    }

    public function shouldHideUnregisteredMarkedMobile(string $mobileHmac): bool
    {
        $threshold=$this->unregisteredMarkedMobileHideThreshold();
        return $threshold>0
            && $mobileHmac!==''
            && $this->unregisteredMarkedMobileAttemptCount($mobileHmac)>=$threshold;
    }

    private function orderOwnerSmsMobileHmac(array $order): string
    {
        $userId=(int)($order['user_id']??0);
        if($userId<1)return '';
        $cipher=(string)$this->db->fetchValue('SELECT mobile_ciphertext FROM users WHERE id=:id LIMIT 1',['id'=>$userId]);
        if($cipher==='')return '';
        $mobile=MobileNumberService::normalize(\decrypt_private_value($cipher,'user-mobile'));
        return $mobile!==''?MobileNumberService::hmac($mobile):'';
    }

    private function dedupeMarked(array $marked,array $verified): array
    {
        $seen=[];
        foreach($verified as $item)$seen[(string)$item['mobile_hmac']]=true;
        $out=[];
        foreach($marked as $item){
            $key=(string)$item['mobile_hmac'];if(isset($seen[$key]))continue;$seen[$key]=true;$out[]=$item;
        }
        return $out;
    }

    private function publicCandidate(array $candidate,int $userId,int $orderId,bool $fallback): array
    {
        $expires=time()+600;
        $token=$this->encodeSignedToken([
            'uid'=>$userId,'oid'=>$orderId,'type'=>$candidate['candidate_type'],
            'identity'=>$candidate['worker_identity_key'],'scope'=>$fallback?'fallback':'radius30','exp'=>$expires
        ],'candidate');
        return [
            'candidate_key'=>$token,
            'name'=>$candidate['name'],
            'candidate_type'=>$candidate['candidate_type'],
            'verified'=>(bool)$candidate['verified'],
            'identity_label'=>$candidate['verified']?'真实已审核师傅':'其他可联系师傅',
            'distance_m'=>(int)$candidate['distance_m'],
            'distance_text'=>$this->distanceText((int)$candidate['distance_m']),
            'rating'=>$candidate['rating'],
            'completed_orders'=>(int)$candidate['completed_orders'],
            'detail_target'=>$candidate['detail_target'],
            'already_notified'=>(bool)$candidate['already_notified'],
            'selectable'=>(bool)$candidate['selectable'],
            'notice'=>$candidate['verified']?'':'短信邀请后，师傅仍需登录并满足平台接单资格才能报价',
            'expires_at'=>gmdate('Y-m-d H:i:s',$expires),
        ];
    }

    private function alreadyNotified(int $orderId,string $identity,string $mobileHmac): bool
    {
        return (bool)$this->db->fetchValue(
            "SELECT id FROM order_worker_sms_invites
             WHERE order_id=:order_id
               AND (worker_identity_key=:identity OR mobile_hmac_snapshot=:mobile_hmac)
               AND (
                    delivery_status IN ('queued','sending','provider_accepted','uncertain','delivered','unknown')
                    OR billing_status IN ('held','charged','waived')
               )
             LIMIT 1",
            ['order_id'=>$orderId,'identity'=>$identity,'mobile_hmac'=>$mobileHmac]
        );
    }

    private function suppressed(string $mobileHmac): bool
    {
        return $mobileHmac!==''&&(bool)$this->db->fetchValue(
            "SELECT id FROM sms_recipient_suppressions WHERE mobile_hmac=:hmac AND status='active' LIMIT 1",
            ['hmac'=>$mobileHmac]
        );
    }

    private function distanceMeters(float $lat1,float $lng1,float $lat2,float $lng2): int
    {
        $earth=6371000.0;
        $aLat=deg2rad($lat1);$bLat=deg2rad($lat2);
        $dLat=deg2rad($lat2-$lat1);$dLng=deg2rad($lng2-$lng1);
        $a=sin($dLat/2)**2+cos($aLat)*cos($bLat)*sin($dLng/2)**2;
        return (int)round($earth*2*atan2(sqrt($a),sqrt(max(0,1-$a))));
    }

    private function distanceText(int $meters): string
    {
        if($meters<1000)return '约 '.$meters.'m';
        return '约 '.number_format($meters/1000,1,'.','').'km';
    }

    private function encodeSignedToken(array $payload,string $purpose): string
    {
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($json))throw new RuntimeException('令牌生成失败');
        $body=rtrim(strtr(base64_encode($json),'+/','-_'),'=');
        $secret=hash('sha256',(string)\app_config('app.key','').'|nearby-worker|'.$purpose,true);
        $sig=hash_hmac('sha256',$body,$secret);
        return $body.'.'.$sig;
    }

    private function decodeSignedToken(string $token,string $purpose,bool $allowExpired=false): array
    {
        $parts=explode('.',$token,2);
        if(count($parts)!==2||preg_match('/^[a-f0-9]{64}$/',$parts[1])!==1)throw new RuntimeException('候选或价格令牌格式不正确');
        $secret=hash('sha256',(string)\app_config('app.key','').'|nearby-worker|'.$purpose,true);
        $expected=hash_hmac('sha256',$parts[0],$secret);
        if(!hash_equals($expected,$parts[1]))throw new RuntimeException('候选或价格令牌校验失败');
        $padding=(4-strlen($parts[0])%4)%4;
        $json=base64_decode(strtr($parts[0],'-_','+/').str_repeat('=',$padding),true);
        $payload=is_string($json)?json_decode($json,true):null;
        if(!is_array($payload))throw new RuntimeException('候选或价格令牌内容无效');
        if(!$allowExpired&&(int)($payload['exp']??0)<time())throw new RuntimeException('候选或价格确认已过期，请刷新');
        return $payload;
    }
}
