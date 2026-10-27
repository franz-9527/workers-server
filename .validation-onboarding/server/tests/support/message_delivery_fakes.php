<?php
declare(strict_types=1);

final class DeliverySms implements \WorkerAdmin\Contracts\SmsGateway {
 public array $sent=[];public array $receipts=[];public string $failure='';public array $outcome=['outcome'=>'accepted','biz_id'=>'biz','request_id'=>'request','provider_code'=>'OK'];public array $details=[];
 public function enabled():bool{return true;}
 public function send(string $m,string $c,array $p,string $o='',bool $test=false):array{$this->sent[]=[$m,$c,$p,$o];if($this->failure!=='')throw new RuntimeException($this->failure);return [];}
 public function sendWithOutcome(string $m,string $c,array $p,string $o='',bool $test=false):array{$this->sent[]=[$m,$c,$p,$o];return $this->outcome;}
 public function querySendDetails(string $m,string $d,?string $b=null):array{$this->receipts[]=[$m,$d,$b];return ['outcome'=>'ok','details'=>$this->details];}
}

final class DeliverySubscribe implements \WorkerAdmin\Contracts\SubscribeMessageGateway {
 public array $sent=[];public string $failure='';
 public function categoryList():array{return [];}
 public function publicTemplateTitles(array $a,int $s=0,int $l=30):array{return [];}
 public function publicTemplateKeywords(string $tid):array{return [];}
 public function addTemplate(string $tid,array $kids,string $desc=''):string{return '';}
 public function templateList():array{return [];}
 public function send(array $p):array{$this->sent[]=$p;if($this->failure!=='')throw new RuntimeException($this->failure);return [];}
}

final class DeliveryAdminAlert implements \WorkerAdmin\Contracts\AdminAlertGateway {public array $sent=[];public function send(string $text,?string $path=null):array{$this->sent[]=[$text,$path];return ['errcode'=>0,'errmsg'=>'ok'];}}
