<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat {
    final class ChatCallCredentials {
        public function runtime():array{return ['app_id'=>str_repeat('a',32),'customer_id'=>'test','customer_secret'=>'fixture'];}
    }
}
namespace {
    require __DIR__.'/server/app/Application/Chat/ChatCallUsageLookup.php';
    $fields=['durationAudioAll','durationVideoHd','durationVideoHdp','durationVideo1080P','durationVideo2K','durationVideo4K'];
    $meta=[];$values=[];
    foreach($fields as $key){$meta[$key]=['unit'=>'second'];$values[$key]=0;}
    $values['durationAudioAll']=120;
    $usage=['meta'=>$meta,'usages'=>[['date'=>gmdate('Y-m-d',strtotime('yesterday',time())).'T00:00:00.000Z','usage'=>$values]]];
    $transport=function($path,$id,$secret)use($usage){
        if($path==='/dev/v1/projects')return ['status'=>201,'body'=>json_encode(['projects'=>[['id'=>'project-1','vendor_key'=>str_repeat('a',32)]]])];
        if(str_contains($path,'business=default')||str_contains($path,'business=miniapp'))
            return ['status'=>200,'body'=>json_encode($usage)];
        throw new \RuntimeException('unrecognized fixture query');
    };
    $service=new \WorkerAdmin\Application\Chat\ChatCallUsageLookup(new \WorkerAdmin\Application\Chat\ChatCallCredentials(),$transport);
    $result=$service->read();
    if(($result['business']['default']['seconds']['durationAudioAll']??-1)!==120)throw new \RuntimeException('duration wrong');
    if(($result['business']['miniapp']['days']??0)!==1)throw new \RuntimeException('miniapp wrong');
    $bad=$usage;$bad['usages'][0]['usage']['durationAudioAll']=-1;
    try{$service->summarize($bad,$result['from'],$result['to']);throw new \RuntimeException('negative value accepted');}
    catch(\RuntimeException $e){if($e->getMessage()==='negative value accepted')throw $e;}
    echo "synthetic usage lookup checks passed\n";
}
