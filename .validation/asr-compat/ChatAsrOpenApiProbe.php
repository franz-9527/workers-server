<?php
declare(strict_types=1);

namespace WorkerAdmin\Application\Chat;

use Closure;
use RuntimeException;

final class ChatAsrOpenApiProbe
{
    public const SERVICE='wxa8386175898e12c9';
    public const API='SentenceASR';
    public const REVISION='sentence-asr-compat-v6';
    private ?Closure $transport;

    public function __construct(?Closure $transport=null){$this->transport=$transport;}

    public function run(string $token,string $sourceUrl,string $audioBytes=''):array
    {
        $variants=[];$recommended='none';$stage='validate_token';
        try{
            if(preg_match('/^[A-Za-z0-9_-]{10,2048}$/D',$token)!==1)throw new RuntimeException('probe token unavailable');
            $stage='validate_source';$url=parse_url($sourceUrl);
            if(!$url||!in_array(($url['scheme']??''),['http','https'],true)||empty($url['host'])||strlen($sourceUrl)>=2048||isset($url['user'])||isset($url['pass'])||isset($url['fragment']))throw new RuntimeException('probe source unavailable');
            $stage='validate_audio';
            if($audioBytes!==''&&(strlen($audioBytes)>3*1024*1024))throw new RuntimeException('probe audio too large');

            $definitions=[
                ['name'=>'api_sentence_asr','api'=>'SentenceASR'],
                ['name'=>'api_sentence_recognition','api'=>'SentenceRecognition'],
                ['name'=>'api_sentence_recognition_wx','api'=>'SentenceRecognitionWX'],
                ['name'=>'api_sentence_recognition_lower','api'=>'sentenceRecognition'],
            ];

            foreach($definitions as $definition){
                $name=$definition['name'];$stage='prepare_'.$name;
                $clientMsgId=bin2hex(random_bytes(16));
                $audioKey=bin2hex(random_bytes(4));
                $data=$this->serviceUrlBody($sourceUrl,$audioKey);
                $body=['service'=>self::SERVICE,'api'=>$definition['api'],'client_msg_id'=>$clientMsgId,'data'=>$data];
                $stage='request_'.$name;$raw=$this->transport?($this->transport)($token,$body):$this->request($token,$body);
                $stage='parse_'.$name;$result=$this->summarize($name,$raw);
                $variants[]=$result;
                if($recommended==='none'&&!empty($result['success']))$recommended=$name;
            }
            return ['status'=>'completed','revision'=>self::REVISION,'tested_at'=>gmdate('c'),'recommended'=>$recommended,'variants'=>$variants];
        }catch(\Throwable $error){
            $code='CHAT_ASR_PROBE_INTERNAL_'.strtoupper(preg_replace('/[^a-z0-9_]+/i','_',$stage)??'UNKNOWN');
            if(preg_match('/^CHAT_ASR_PROBE_INTERNAL_[A-Z0-9_]{1,80}$/D',$code)!==1)$code='CHAT_ASR_PROBE_INTERNAL_UNKNOWN';
            return ['status'=>'failed','revision'=>self::REVISION,'tested_at'=>gmdate('c'),'recommended'=>$recommended,'variants'=>$variants,'code'=>$code];
        }
    }

    private function common(string $key):array
    {
        return [
            'Action'=>'SentenceRecognitionWX',
            'EngSerViceType'=>'16k_zh',
            'VoiceFormat'=>'mp3',
            'UsrAudioKey'=>$key,
        ];
    }

    private function serviceUrlBody(string $sourceUrl,string $key):array
    {
        return $this->common($key)+[
            'SourceType'=>0,
            'Url'=>$sourceUrl,
        ];
    }

    private function request(string $token,array $body):array
    {
        if(!function_exists('curl_init'))throw new RuntimeException('probe curl unavailable');
        $payload=json_encode($body,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $url='https://api.weixin.qq.com/wxa/servicemarket?access_token='.rawurlencode($token);
        $response='';$curl=curl_init($url);
        if($curl===false)throw new RuntimeException('probe curl unavailable');
        curl_setopt_array($curl,[
            CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json; charset=utf-8','Accept: application/json'],
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response):int{
                if(strlen($response)+strlen($chunk)>65536)return 0;
                $response.=$chunk;return strlen($chunk);
            },
        ]);
        $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        if($ok===false||$status<1)return ['status'=>$status,'body'=>''];
        return ['status'=>$status,'body'=>$response];
    }

    private function summarize(string $name,array $raw):array
    {
        $status=(int)($raw['status']??0);$body=(string)($raw['body']??'');
        $decoded=json_decode($body,true,32);
        $errcode=is_array($decoded)&&isset($decoded['errcode'])&&(is_int($decoded['errcode'])||is_string($decoded['errcode']))?(string)$decoded['errcode']:'';
        $errmsg=$this->safeText(is_array($decoded)?(string)($decoded['errmsg']??''):'');
        $providerData=is_array($decoded)?($decoded['data']??null):null;
        $dataType=is_string($providerData)?'string':(is_array($providerData)?'object':($providerData===null?'null':gettype($providerData)));
        if(is_string($providerData)){
            $parsed=json_decode($providerData,true,32);
            if(is_array($parsed))$providerData=$parsed;
        }
        $response=is_array($providerData)&&is_array($providerData['Response']??null)?$providerData['Response']:[];
        $error=is_array($response['Error']??null)?$response['Error']:[];
        $innerCode=preg_match('/^[A-Za-z0-9_.:-]{1,96}$/D',(string)($error['Code']??''))===1?(string)$error['Code']:'';
        $requestId=(string)($response['RequestId']??'');
        $result=$response['Result']??null;
        $success=$status===200&&$errcode==='0'&&$innerCode===''&&$requestId!==''&&is_string($result);
        return [
            'name'=>$name,'http_status'=>$status,'errcode'=>$errcode,'errmsg'=>$errmsg,
            'data_type'=>$dataType,'inner_code'=>$innerCode,'request_id_present'=>$requestId!=='',
            'result_length'=>is_string($result)?mb_strlen($result,'UTF-8'):0,'success'=>$success,
        ];
    }

    private function safeText(string $value):string
    {
        $value=trim($value);
        if($value==='')return '';
        $value=preg_replace("#https?://[^\\s\"'<>]+#i",'[url]',$value)??'';
        $value=preg_replace('/(?i)(access_token|token|secret|signature)\\s*[:=]\\s*[^\\s,;]+/','$1=[redacted]',$value)??'';
        return mb_substr($value,0,240,'UTF-8');
    }
}
