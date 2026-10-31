<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Messaging;



final class AdminConfigurationResult
{

    private \WorkerAdmin\Contracts\AdminConfigurationAudit $audit;
    public function __construct(\WorkerAdmin\Contracts\AdminConfigurationAudit $audit) { $this->audit=$audit; }
    public array $audits=[];public array $notices=[];
    public function audit(...$args):void { $this->audit->record($args);$this->audits[]=$args; }
    public function notice(string $type,string $message):void { $this->notices[]=[$type,$message]; }
    public function navigate(string $route,array $params=[],string $fragment=''):array
    { return ['kind'=>'command','audits'=>$this->audits,'notices'=>$this->notices,'route'=>$route,'params'=>$params,'fragment'=>$fragment]; }
    public static function json(array $payload,int $status=200):array { return ['kind'=>'json','payload'=>$payload,'status'=>$status]; }
}
