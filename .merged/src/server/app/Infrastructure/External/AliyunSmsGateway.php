<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

use RuntimeException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * 阿里云短信 RPC + ACS3 签名。
 * 普通通知继续使用 send()；付费短信使用结构化 sendWithOutcome()/querySendDetails()。
 */
final class AliyunSmsGateway implements \WorkerAdmin\Contracts\SmsGateway
{
    public function enabled(): bool
    {
        return \filter_var(\setting('sms.enabled', false), FILTER_VALIDATE_BOOLEAN)
            && strtolower((string) \setting('sms.provider', 'aliyun')) === 'aliyun';
    }

    /** @return array<string,mixed> */
    public function send(string $mobile, string $templateCode, array $parameters, string $outId = '', bool $allowDisabledForTest = false): array
    {
        $outcome = $this->sendWithOutcome($mobile, $templateCode, $parameters, $outId, $allowDisabledForTest);
        if (($outcome['outcome'] ?? '') === 'accepted') return (array)($outcome['response'] ?? []);
        throw new RuntimeException((string)($outcome['message'] ?? '阿里云短信发送失败'));
    }

    /**
     * @return array{outcome:string,biz_id:?string,request_id:?string,provider_code:?string,message:string,response:array}
     */
    public function sendWithOutcome(string $mobile, string $templateCode, array $parameters, string $outId = '', bool $allowDisabledForTest = false): array
    {
        try {
            $config = $this->configuration($allowDisabledForTest);
            $mobile = \WorkerAdmin\Domain\Identity\MobileNumber::normalize($mobile);
            if ($mobile === '') return $this->configurationOutcome('短信接收手机号不是有效的 11 位号码。');
            TestingEnvironmentPolicy::assertSmsRecipientAllowed($mobile);
            if (trim($templateCode) === '') return $this->configurationOutcome('短信模板编码为空。');

            $payload = [
                'PhoneNumbers'=>$mobile,
                'SignName'=>$config['sign_name'],
                'TemplateCode'=>trim($templateCode),
                'TemplateParam'=>json_encode($parameters, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ];
            if ($outId !== '') $payload['OutId'] = substr($outId, 0, 64);
            $transport = $this->signedRequest('SendSms', $payload, $config);
            if (($transport['transport'] ?? '') !== 'response') {
                return [
                    'outcome'=>'uncertain','biz_id'=>null,'request_id'=>null,'provider_code'=>null,
                    'message'=>(string)($transport['message'] ?? '短信请求结果无法确认'),
                    'response'=>[],
                ];
            }

            $decoded = (array)($transport['response'] ?? []);
            $code = strtoupper((string)($decoded['Code'] ?? ''));
            if ($code === 'OK') {
                return [
                    'outcome'=>'accepted',
                    'biz_id'=>(string)($decoded['BizId'] ?? '') ?: null,
                    'request_id'=>(string)($decoded['RequestId'] ?? '') ?: null,
                    'provider_code'=>'OK',
                    'message'=>'阿里云已受理',
                    'response'=>$decoded,
                ];
            }
            return [
                'outcome'=>'rejected','biz_id'=>null,
                'request_id'=>(string)($decoded['RequestId'] ?? '') ?: null,
                'provider_code'=>$code !== '' ? $code : null,
                'message'=>mb_substr((string)($decoded['Message'] ?? $code ?: '阿里云明确拒绝发送'),0,300),
                'response'=>$decoded,
            ];
        } catch (RuntimeException $exception) {
            return $this->configurationOutcome($exception->getMessage());
        }
    }

    /**
     * QuerySendDetails，不返回 Content，避免短信正文进入业务层。
     * @return array{outcome:string,provider_code:?string,message:string,details:array}
     */
    public function querySendDetails(string $mobile, string $sendDate, ?string $bizId = null): array
    {
        try {
            $config = $this->configuration(false);
            $mobile = \WorkerAdmin\Domain\Identity\MobileNumber::normalize($mobile);
            if ($mobile === '' || preg_match('/^\d{8}$/', $sendDate) !== 1) {
                return ['outcome'=>'configuration_error','provider_code'=>null,'message'=>'短信回执查询参数无效','details'=>[]];
            }
            TestingEnvironmentPolicy::assertSmsRecipientAllowed($mobile);
            $payload = ['PhoneNumber'=>$mobile,'SendDate'=>$sendDate,'PageSize'=>'50','CurrentPage'=>'1'];
            if ($bizId !== null && trim($bizId) !== '') $payload['BizId'] = trim($bizId);
            $transport = $this->signedRequest('QuerySendDetails', $payload, $config);
            if (($transport['transport'] ?? '') !== 'response') {
                return ['outcome'=>'uncertain','provider_code'=>null,'message'=>(string)($transport['message'] ?? '回执查询结果不确定'),'details'=>[]];
            }
            $decoded=(array)($transport['response']??[]);
            $code=strtoupper((string)($decoded['Code']??''));
            if($code!=='OK')return [
                'outcome'=>'rejected','provider_code'=>$code?:null,
                'message'=>mb_substr((string)($decoded['Message']??$code?:'回执查询失败'),0,300),'details'=>[]
            ];
            $list=$decoded['SmsSendDetailDTOs']['SmsSendDetailDTO']??[];
            if(!is_array($list))$list=[];
            if(isset($list['SendStatus']))$list=[$list];
            $safe=[];
            foreach($list as $row){
                if(!is_array($row))continue;
                $safe[]=[
                    'send_status'=>(int)($row['SendStatus']??0),
                    'err_code'=>mb_substr((string)($row['ErrCode']??''),0,100),
                    'out_id'=>mb_substr((string)($row['OutId']??''),0,128),
                    'phone_num'=>\WorkerAdmin\Domain\Identity\MobileNumber::normalize((string)($row['PhoneNum']??'')),
                    'send_date'=>(string)($row['SendDate']??''),
                    'receive_date'=>(string)($row['ReceiveDate']??''),
                ];
            }
            return ['outcome'=>'ok','provider_code'=>'OK','message'=>'查询成功','details'=>$safe];
        } catch (RuntimeException $exception) {
            return ['outcome'=>'configuration_error','provider_code'=>null,'message'=>$exception->getMessage(),'details'=>[]];
        }
    }

    private function configuration(bool $allowDisabledForTest): array
    {
        if (!$allowDisabledForTest && !$this->enabled()) throw new RuntimeException('阿里云短信尚未启用。');
        if ($allowDisabledForTest && strtolower((string)\setting('sms.provider','aliyun')) !== 'aliyun') throw new RuntimeException('当前短信服务商不是阿里云。');
        $accessKey=trim((string)\setting('sms.access_key_id',''));
        $secret=trim((string)\setting('sms.access_key_secret',''));
        $signName=trim((string)\setting('sms.sign_name',''));
        if($accessKey===''||$secret===''||$signName==='')throw new RuntimeException('阿里云短信配置不完整，请检查密钥和签名。');
        if(!function_exists('curl_init'))throw new RuntimeException('阿里云短信需要 PHP cURL 扩展。');
        return ['access_key'=>$accessKey,'secret'=>$secret,'sign_name'=>$signName];
    }

    private function configurationOutcome(string $message): array
    {
        return [
            'outcome'=>'configuration_error','biz_id'=>null,'request_id'=>null,'provider_code'=>null,
            'message'=>mb_substr($message,0,300),'response'=>[],
        ];
    }

    /** @return array{transport:string,response?:array,message?:string,http_status:int} */
    private function signedRequest(string $action,array $payload,array $config): array
    {
        ksort($payload);
        $queryParts=[];foreach($payload as $name=>$value)$queryParts[]=rawurlencode((string)$name).'='.rawurlencode((string)$value);
        $canonicalQuery=implode('&',$queryParts);
        $body='';$host='dysmsapi.aliyuncs.com';$date=gmdate('Y-m-d\TH:i:s\Z');$nonce=$this->uuid();$contentHash=hash('sha256',$body);
        $headers=['host'=>$host,'x-acs-action'=>$action,'x-acs-content-sha256'=>$contentHash,'x-acs-date'=>$date,'x-acs-signature-nonce'=>$nonce,'x-acs-version'=>'2017-05-25'];
        $canonicalHeaders='';foreach($headers as $name=>$value)$canonicalHeaders.=$name.':'.trim($value)."\n";
        $signedHeaders=implode(';',array_keys($headers));
        $canonicalRequest="POST\n/\n".$canonicalQuery."\n".$canonicalHeaders."\n".$signedHeaders."\n".$contentHash;
        $stringToSign="ACS3-HMAC-SHA256\n".hash('sha256',$canonicalRequest);
        $signature=hash_hmac('sha256',$stringToSign,(string)$config['secret']);
        $authorization='ACS3-HMAC-SHA256 Credential='.$config['access_key'].',SignedHeaders='.$signedHeaders.',Signature='.$signature;
        $httpHeaders=[];foreach($headers as $name=>$value)$httpHeaders[]=$name.': '.$value;$httpHeaders[]='Authorization: '.$authorization;
        $curl=curl_init('https://'.$host.'/?'.$canonicalQuery);
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$httpHeaders,CURLOPT_POSTFIELDS=>$body]);
        $response=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);$error=curl_error($curl);$errno=curl_errno($curl);curl_close($curl);
        if(!is_string($response)||$response===''){
            return ['transport'=>'uncertain','message'=>'阿里云短信请求未取得确认响应'.($errno?('（网络错误 '.$errno.'）'):''),'http_status'=>$status];
        }
        $decoded=json_decode($response,true);
        if(!is_array($decoded)){
            return ['transport'=>'uncertain','message'=>'阿里云短信响应无法解析','http_status'=>$status];
        }
        // 即使 HTTP 非 2xx，只要拿到阿里云结构化 Code，也属于明确 provider response。
        return ['transport'=>'response','response'=>$decoded,'message'=>$error,'http_status'=>$status];
    }

    private function uuid(): string
    {
        $data=random_bytes(16);$data[6]=chr((ord($data[6])&0x0f)|0x40);$data[8]=chr((ord($data[8])&0x3f)|0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($data),4));
    }
}
