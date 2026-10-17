<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

use RuntimeException;

final class WeChatPayService
{
    private const API_HOST = 'https://api.mch.weixin.qq.com';
    private $version;

    public function __construct(?string $version = null)
    {
        $selected = strtolower(trim((string) ($version ?: \setting('wechat.pay.api_version', 'v3'))));
        $this->version = in_array($selected, ['v2', 'v3'], true) ? $selected : 'v3';
    }

    public function apiVersion(): string { return $this->version; }
    public function enabled(): bool { return \WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy::paymentEnabled((bool)\setting('wechat.pay.enabled',false)); }
    public function appId(): string { return trim((string) \setting('wechat.miniapp_appid', '')); }
    public function mchId(): string { return trim((string) \setting('wechat.pay.mchid', '')); }

    public function createJsapiPayment(string $outTradeNo, string $description, int $amountCents, string $openid): array
    {
        $this->assertConfigured();
        if ($amountCents < 1 || $openid === '') throw new RuntimeException('支付金额或付款人 OpenID 不正确。');
        if ($this->version === 'v2') {
            $response = $this->requestV2('/pay/unifiedorder', [
                'appid'=>$this->appId(), 'mch_id'=>$this->mchId(), 'nonce_str'=>$this->nonce(),
                'body'=>mb_substr($description, 0, 120), 'out_trade_no'=>$outTradeNo,
                'total_fee'=>$amountCents, 'spbill_create_ip'=>\request_ip(),
                'notify_url'=>(string) \setting('wechat.pay.notify_url', \app_url('api/wechatpay/notify')),
                'trade_type'=>'JSAPI', 'openid'=>$openid, 'sign_type'=>'HMAC-SHA256',
            ]);
            $prepayId = trim((string) ($response['prepay_id'] ?? ''));
            if ($prepayId === '') throw new RuntimeException('微信支付 API v2 未返回 prepay_id。');
            return ['prepay_id'=>$prepayId, 'payment'=>$this->signClientPaymentV2($prepayId)];
        }
        $response = $this->requestV3('POST', '/v3/pay/transactions/jsapi', [
            'appid'=>$this->appId(), 'mchid'=>$this->mchId(), 'description'=>mb_substr($description, 0, 120),
            'out_trade_no'=>$outTradeNo,
            'notify_url'=>(string) \setting('wechat.pay.notify_url', \app_url('api/wechatpay/notify')),
            'amount'=>['total'=>$amountCents, 'currency'=>'CNY'], 'payer'=>['openid'=>$openid],
        ]);
        $prepayId = trim((string) ($response['prepay_id'] ?? ''));
        if ($prepayId === '') throw new RuntimeException('微信支付 API v3 未返回 prepay_id。');
        return ['prepay_id'=>$prepayId, 'payment'=>$this->signClientPaymentV3($prepayId)];
    }

    public function queryPayment(string $outTradeNo): array
    {
        $this->assertConfigured();
        if ($this->version === 'v2') {
            return $this->normalizeV2Payment($this->requestV2('/pay/orderquery', [
                'appid'=>$this->appId(), 'mch_id'=>$this->mchId(), 'out_trade_no'=>$outTradeNo,
                'nonce_str'=>$this->nonce(), 'sign_type'=>'HMAC-SHA256',
            ]));
        }
        return $this->requestV3('GET', '/v3/pay/transactions/out-trade-no/' . rawurlencode($outTradeNo) . '?mchid=' . rawurlencode($this->mchId()), null);
    }

    public function clientPaymentParameters(string $prepayId): array
    {
        $this->assertConfigured();
        return $this->version === 'v2' ? $this->signClientPaymentV2($prepayId) : $this->signClientPaymentV3($prepayId);
    }

    public function createRefund(string $outTradeNo, string $outRefundNo, int $refundCents, int $totalCents, string $reason): array
    {
        $this->assertConfigured(true);
        if ($refundCents < 1 || $refundCents > $totalCents) throw new RuntimeException('退款金额不正确。');
        if ($this->version === 'v2') {
            $row = $this->requestV2('/secapi/pay/refund', [
                'appid'=>$this->appId(), 'mch_id'=>$this->mchId(), 'nonce_str'=>$this->nonce(),
                'out_trade_no'=>$outTradeNo, 'out_refund_no'=>$outRefundNo, 'total_fee'=>$totalCents,
                'refund_fee'=>$refundCents, 'refund_desc'=>mb_substr($reason, 0, 80),
                'notify_url'=>(string) \setting('wechat.pay.refund_notify_url', \app_url('api/wechatpay/refund-notify')),
                'sign_type'=>'HMAC-SHA256',
            ], true);
            return ['refund_id'=>(string) ($row['refund_id'] ?? ''), 'out_refund_no'=>$outRefundNo,
                'out_trade_no'=>$outTradeNo, 'status'=>'PROCESSING', 'mchid'=>$this->mchId(),
                'amount'=>['refund'=>$refundCents, 'total'=>$totalCents, 'currency'=>'CNY']];
        }
        return $this->requestV3('POST', '/v3/refund/domestic/refunds', [
            'out_trade_no'=>$outTradeNo, 'out_refund_no'=>$outRefundNo, 'reason'=>mb_substr($reason, 0, 70),
            'notify_url'=>(string) \setting('wechat.pay.refund_notify_url', \app_url('api/wechatpay/refund-notify')),
            'amount'=>['refund'=>$refundCents, 'total'=>$totalCents, 'currency'=>'CNY'],
        ]);
    }

    public function queryRefund(string $outRefundNo): array
    {
        $this->assertConfigured();
        if ($this->version === 'v2') {
            $row = $this->requestV2('/pay/refundquery', [
                'appid'=>$this->appId(), 'mch_id'=>$this->mchId(), 'out_refund_no'=>$outRefundNo,
                'nonce_str'=>$this->nonce(), 'sign_type'=>'HMAC-SHA256',
            ]);
            $index=0; for($i=0;$i<(int)($row['refund_count']??1);$i++) if(($row['out_refund_no_'.$i]??'')===$outRefundNo){$index=$i;break;}
            $map=['SUCCESS'=>'SUCCESS','PROCESSING'=>'PROCESSING','REFUNDCLOSE'=>'CLOSED','CHANGE'=>'ABNORMAL'];
            $status=(string)($row['refund_status_'.$index]??'PROCESSING');
            return ['refund_id'=>(string)($row['refund_id_'.$index]??''),'out_refund_no'=>$outRefundNo,
                'out_trade_no'=>(string)($row['out_trade_no']??''),'mchid'=>(string)($row['mch_id']??''),
                'status'=>$map[$status]??$status,'amount'=>['refund'=>(int)($row['refund_fee_'.$index]??0),'total'=>(int)($row['total_fee']??0),'currency'=>'CNY']];
        }
        return $this->requestV3('GET', '/v3/refund/domestic/refunds/' . rawurlencode($outRefundNo), null);
    }

    public function decodeNotification(string $rawBody, array $headers, bool $refund = false): array
    {
        if (strncmp(ltrim($rawBody), '<xml', 4) === 0) return $this->decodeNotificationV2($rawBody, $refund);
        return $this->decodeNotificationV3($rawBody, $headers);
    }

    private function decodeNotificationV2(string $rawBody, bool $refund): array
    {
        $notice=$this->xmlDecode($rawBody);
        if (!$refund || !empty($notice['sign'])) $this->verifyV2($notice);
        if(($notice['return_code']??'')!=='SUCCESS')throw new RuntimeException('微信支付 API v2 通知返回失败。');
        if(!$refund){
            if(($notice['result_code']??'')!=='SUCCESS')throw new RuntimeException('微信支付 API v2 支付结果不是成功。');
            $resource=$this->normalizeV2Payment($notice); $id='v2-pay-'.((string)($notice['transaction_id']??'')?:hash('sha256',$rawBody));
            return ['notice'=>['id'=>$id,'event_type'=>'TRANSACTION.SUCCESS','resource_type'=>'xml','api_version'=>'v2'],'resource'=>$resource,'headers'=>[]];
        }
        $encrypted=base64_decode((string)($notice['req_info']??''),true); $key=strtolower(md5((string)\setting('wechat.pay.api_v2_key','')));
        $plain=is_string($encrypted)?openssl_decrypt($encrypted,'AES-256-ECB',$key,OPENSSL_RAW_DATA):false;
        if(!is_string($plain)||$plain==='')throw new RuntimeException('微信支付 API v2 退款通知解密失败。');
        $row=$this->xmlDecode($plain); $map=['SUCCESS'=>'SUCCESS','CHANGE'=>'ABNORMAL','REFUNDCLOSE'=>'CLOSED'];
        $status=$map[(string)($row['refund_status']??'')]??(string)($row['refund_status']??'');
        $resource=['refund_id'=>(string)($row['refund_id']??''),'out_refund_no'=>(string)($row['out_refund_no']??''),
            'out_trade_no'=>(string)($row['out_trade_no']??''),'mchid'=>(string)($row['mch_id']??$notice['mch_id']??''),
            'refund_status'=>$status,'amount'=>['refund'=>(int)($row['refund_fee']??0),'total'=>(int)($row['total_fee']??0),'currency'=>'CNY']];
        $id='v2-refund-'.($resource['refund_id']?:$resource['out_refund_no']).'-'.$status;
        return ['notice'=>['id'=>$id,'event_type'=>'REFUND.'.$status,'resource_type'=>'xml','api_version'=>'v2'],'resource'=>$resource,'headers'=>[]];
    }

    private function decodeNotificationV3(string $rawBody, array $headers): array
    {
        $timestamp=$this->header($headers,'wechatpay-timestamp');$nonce=$this->header($headers,'wechatpay-nonce');
        $signature=$this->header($headers,'wechatpay-signature');$serial=$this->header($headers,'wechatpay-serial');
        if($timestamp===''||$nonce===''||$signature===''||$serial==='')throw new RuntimeException('微信支付通知缺少验签头。');
        if(!ctype_digit($timestamp)||abs(time()-(int)$timestamp)>300)throw new RuntimeException('微信支付通知时间戳已过期。');
        $this->assertV3Serial($serial);$this->verifyV3Signature($timestamp."\n".$nonce."\n".$rawBody."\n",$signature);
        $notice=json_decode($rawBody,true);if(!is_array($notice)||!is_array($notice['resource']??null))throw new RuntimeException('微信支付通知正文格式不正确。');
        $resource=$notice['resource'];$cipher=base64_decode((string)($resource['ciphertext']??''),true);$apiKey=(string)\setting('wechat.pay.api_v3_key','');
        if(($resource['algorithm']??'')!=='AEAD_AES_256_GCM'||!is_string($cipher)||strlen($cipher)<17||strlen($apiKey)!==32)throw new RuntimeException('微信支付通知密文或 APIv3 密钥不正确。');
        $plain=openssl_decrypt(substr($cipher,0,-16),'aes-256-gcm',$apiKey,OPENSSL_RAW_DATA,(string)($resource['nonce']??''),substr($cipher,-16),(string)($resource['associated_data']??''));
        $decoded=is_string($plain)?json_decode($plain,true):null;if(!is_array($decoded))throw new RuntimeException('微信支付通知解密失败。');
        return ['notice'=>$notice,'resource'=>$decoded,'headers'=>$this->safeHeaders($headers)];
    }

    private function signClientPaymentV2(string $prepayId): array
    {
        $params=['appId'=>$this->appId(),'timeStamp'=>(string)time(),'nonceStr'=>$this->nonce(),'package'=>'prepay_id='.$prepayId,'signType'=>'HMAC-SHA256'];$params['paySign']=$this->signV2($params);return $params;
    }
    private function signClientPaymentV3(string $prepayId): array
    {
        $timestamp=(string)time();$nonce=$this->nonce();$package='prepay_id='.$prepayId;return ['timeStamp'=>$timestamp,'nonceStr'=>$nonce,'package'=>$package,'signType'=>'RSA','paySign'=>$this->signV3($this->appId()."\n".$timestamp."\n".$nonce."\n".$package."\n")];
    }

    private function requestV2(string $path, array $params, bool $withCertificate = false): array
    {
        $params['sign']=$this->signV2($params);$payload=$this->xmlEncode($params);$handle=curl_init(self::API_HOST.$path);if($handle===false)throw new RuntimeException('无法初始化微信支付 API v2 请求。');
        $temporary=[];$raw=false;$status=0;$error='';
        try{
            $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>['Content-Type: text/xml; charset=utf-8','User-Agent: BXWorker/2.2.2']];
            if($withCertificate){$cert=$this->writeTemporaryPem((string)\setting('wechat.pay.v2_client_certificate',''),'cert');$key=$this->writeTemporaryPem((string)\setting('wechat.pay.v2_client_key',''),'key');$temporary=[$cert,$key];$options[CURLOPT_SSLCERT]=$cert;$options[CURLOPT_SSLKEY]=$key;}
            curl_setopt_array($handle,$options);$raw=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_HTTP_CODE);$error=curl_error($handle);
        }finally{curl_close($handle);foreach($temporary as $file)if(is_file($file))@unlink($file);}
        if(!is_string($raw)||$status<200||$status>=300)throw new RuntimeException('微信支付 API v2 连接失败：'.mb_substr($error?:'HTTP '.$status,0,120));
        $row=$this->xmlDecode($raw);if(($row['return_code']??'')!=='SUCCESS')throw new RuntimeException('微信支付 API v2 请求失败：'.mb_substr((string)($row['return_msg']??'未知错误'),0,160));
        $this->verifyV2($row);if(($row['result_code']??'')!=='SUCCESS')throw new RuntimeException('微信支付 API v2 业务失败：'.mb_substr((string)($row['err_code_des']??$row['err_code']??'未知错误'),0,160));return $row;
    }

    private function requestV3(string $method,string $pathWithQuery,?array $body):array
    {
        $payload=$body===null?'':json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(!is_string($payload))throw new RuntimeException('微信支付请求编码失败。');$timestamp=(string)time();$nonce=$this->nonce();
        $message=strtoupper($method)."\n".$pathWithQuery."\n".$timestamp."\n".$nonce."\n".$payload."\n";$authorization='WECHATPAY2-SHA256-RSA2048 mchid="'.$this->mchId().'",nonce_str="'.$nonce.'",timestamp="'.$timestamp.'",serial_no="'.trim((string)\setting('wechat.pay.merchant_serial_no','')).'",signature="'.$this->signV3($message).'"';
        $handle=curl_init(self::API_HOST.$pathWithQuery);if($handle===false)throw new RuntimeException('服务器无法初始化微信支付 HTTPS 请求。');$responseHeaders=[];
        curl_setopt_array($handle,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CUSTOMREQUEST=>strtoupper($method),CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json','User-Agent: BXWorker/2.2.2','Authorization: '.$authorization],CURLOPT_HEADERFUNCTION=>static function($curl,string $line)use(&$responseHeaders):int{$position=strpos($line,':');if($position!==false)$responseHeaders[strtolower(trim(substr($line,0,$position)))]=trim(substr($line,$position+1));return strlen($line);}]);if($body!==null)curl_setopt($handle,CURLOPT_POSTFIELDS,$payload);
        $raw=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_HTTP_CODE);$error=curl_error($handle);curl_close($handle);if(!is_string($raw))throw new RuntimeException('微信支付接口连接失败：'.mb_substr($error,0,120));
        if(!isset($responseHeaders['wechatpay-signature'],$responseHeaders['wechatpay-timestamp'],$responseHeaders['wechatpay-nonce'],$responseHeaders['wechatpay-serial']))throw new RuntimeException('微信支付应答缺少平台验签头。');$this->assertV3Serial($responseHeaders['wechatpay-serial']);$this->verifyV3Signature($responseHeaders['wechatpay-timestamp']."\n".$responseHeaders['wechatpay-nonce']."\n".$raw."\n",$responseHeaders['wechatpay-signature']);
        $decoded=json_decode($raw,true);if($status<200||$status>=300){$message=is_array($decoded)?(string)($decoded['message']??$decoded['code']??'未知错误'):'HTTP '.$status;throw new RuntimeException('微信支付请求失败：'.mb_substr($message,0,180));}if(!is_array($decoded))throw new RuntimeException('微信支付返回数据无法识别。');return $decoded;
    }

    private function normalizeV2Payment(array $row):array{return ['trade_state'=>(string)($row['trade_state']??(($row['result_code']??'')==='SUCCESS'?'SUCCESS':'')),'out_trade_no'=>(string)($row['out_trade_no']??''),'transaction_id'=>(string)($row['transaction_id']??''),'appid'=>(string)($row['appid']??''),'mchid'=>(string)($row['mch_id']??''),'payer'=>['openid'=>(string)($row['openid']??'')],'amount'=>['total'=>(int)($row['total_fee']??0),'currency'=>(string)($row['fee_type']??'CNY')]];}
    private function signV2(array $params):string{unset($params['sign']);ksort($params);$pairs=[];foreach($params as $key=>$value)if($value!==''&&$value!==null&&!is_array($value))$pairs[]=$key.'='.$value;$apiKey=(string)\setting('wechat.pay.api_v2_key','');return strtoupper(hash_hmac('sha256',implode('&',$pairs).'&key='.$apiKey,$apiKey));}
    private function verifyV2(array $row):void{$signature=strtoupper((string)($row['sign']??''));if($signature===''||!hash_equals($this->signV2($row),$signature))throw new RuntimeException('微信支付 API v2 签名验证失败。');}
    private function xmlEncode(array $params):string{$xml='<xml>';foreach($params as $key=>$value){if(!preg_match('/^[A-Za-z0-9_]+$/',(string)$key))continue;$xml.='<'.$key.'><![CDATA['.str_replace(']]>',']]]]><![CDATA[>',(string)$value).']]></'.$key.'>'; }return $xml.'</xml>';}
    private function xmlDecode(string $xml):array{$previous=libxml_use_internal_errors(true);$object=simplexml_load_string($xml,'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);libxml_clear_errors();libxml_use_internal_errors($previous);if($object===false)throw new RuntimeException('微信支付 XML 正文无法识别。');$row=[];foreach($object->children() as $key=>$value)$row[(string)$key]=(string)$value;return $row;}
    private function signV3(string $message):string{$key=openssl_pkey_get_private($this->normalizePem((string)\setting('wechat.pay.private_key','')));if($key===false||!openssl_sign($message,$signature,$key,OPENSSL_ALGO_SHA256))throw new RuntimeException('微信支付商户私钥无法签名，请检查 PEM 内容。');return base64_encode($signature);}
    private function verifyV3Signature(string $message,string $signature):void{$key=openssl_pkey_get_public($this->normalizePem((string)\setting('wechat.pay.platform_certificate','')));$decoded=base64_decode($signature,true);if($key===false||!is_string($decoded)||openssl_verify($message,$decoded,$key,OPENSSL_ALGO_SHA256)!==1)throw new RuntimeException('微信支付平台签名验证失败。');}
    private function assertConfigured(bool $refund=false):void
    {
        if(!$this->enabled())throw new RuntimeException('管理员尚未启用微信支付。');if($this->appId()===''||$this->mchId()==='')throw new RuntimeException('微信支付 AppID 或商户号未配置。');
        if($this->version==='v2'){if(strlen((string)\setting('wechat.pay.api_v2_key',''))!==32)throw new RuntimeException('微信支付 API v2 密钥必须为 32 个字符。');if($refund&&(trim((string)\setting('wechat.pay.v2_client_certificate',''))===''||trim((string)\setting('wechat.pay.v2_client_key',''))===''))throw new RuntimeException('API v2 退款需配置商户证书与证书私钥 PEM。');return;}
        $required=[(string)\setting('wechat.pay.api_v3_key',''),(string)\setting('wechat.pay.merchant_serial_no',''),(string)\setting('wechat.pay.private_key',''),(string)\setting('wechat.pay.platform_certificate','')];foreach($required as $value)if(trim($value)==='')throw new RuntimeException('微信支付 API v3 配置不完整。');if(strlen((string)\setting('wechat.pay.api_v3_key',''))!==32)throw new RuntimeException('微信支付 API v3 密钥必须为 32 个字符。');
    }
    private function writeTemporaryPem(string $value,string $type):string{$file=tempnam(sys_get_temp_dir(),'bx-wxpay-'.$type.'-');if($file===false||file_put_contents($file,$this->normalizePem($value),LOCK_EX)===false)throw new RuntimeException('无法创建微信支付临时证书文件。');@chmod($file,0600);return $file;}
    private function assertV3Serial(string $serial):void{$expected=strtoupper(trim((string)\setting('wechat.pay.platform_cert_serial','')));if($expected!==''&&strtoupper($serial)!==$expected)throw new RuntimeException('微信支付平台证书序列号不匹配。');}
    private function normalizePem(string $value):string{return str_replace(["\\r\\n","\\n","\r\n"],["\n","\n","\n"],trim($value))."\n";}
    private function nonce():string{return bin2hex(random_bytes(16));}
    private function header(array $headers,string $name):string{foreach($headers as $key=>$value)if(strtolower((string)$key)===$name)return trim(is_array($value)?implode(',',$value):(string)$value);return '';}
    private function safeHeaders(array $headers):array{$safe=[];foreach(['wechatpay-timestamp','wechatpay-nonce','wechatpay-serial','wechatpay-signature']as$name)$safe[$name]=$this->header($headers,$name);return $safe;}
}
