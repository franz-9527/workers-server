<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Messaging;

use RuntimeException;
use Throwable;
use WorkerAdmin\Contracts\SubscriptionMetadata;
use WorkerAdmin\Contracts\SubscribeMessageGateway;

final class SubscriptionLibrarySearch
{
    use ConfigurationInput;
    private SubscriptionMetadata $catalog;
    private SubscribeMessageGateway $subscribe;

    public function __construct(SubscriptionMetadata $catalog, SubscribeMessageGateway $subscribe)
    {
        $this->catalog = $catalog;
        $this->subscribe = $subscribe;
    }

    public function notificationSettingsLibrary(array $input,int $adminId=0):array
    {

        $sceneKey=isset($input['scene'])&&!is_array($input['scene'])?trim((string)$input['scene']):'';
        $tid=isset($input['tid'])&&!is_array($input['tid'])?trim((string)$input['tid']):'';
        $query=isset($input['q'])&&!is_array($input['q'])?trim((string)$input['q']):'';
        $requestedCategory=isset($input['category'])&&!is_array($input['category'])?(int)$input['category']:0;
        $scanStart=isset($input['scan_start'])&&!is_array($input['scan_start'])?max(0,(int)$input['scan_start']):0;
        $mode=isset($input['mode'])&&!is_array($input['mode'])?trim((string)$input['mode']):'search';
        $definitions=$this->catalog->definitions();
        if(!isset($definitions[$sceneKey]))return AdminConfigurationResult::json(['ok'=>false,'message'=>'订阅场景不存在。'],400);
        try{
            $wechat=$this->subscribe;
            if($tid!==''){
                $keywords=$wechat->publicTemplateKeywords($tid);
                $recommended=$this->catalog->recommend($definitions[$sceneKey],$keywords);
                return AdminConfigurationResult::json(['ok'=>true,'data'=>['keywords'=>$keywords,'recommended_kids'=>$recommended]]);
            }

            $categories=$wechat->categoryList();
            $categoryNames=[];foreach($categories as $category)$categoryNames[(int)($category['id']??0)]=(string)($category['name']??'');
            if($mode==='categories'){
                return AdminConfigurationResult::json(['ok'=>true,'data'=>['categories'=>$categories]]);
            }

            if($requestedCategory<1)return AdminConfigurationResult::json(['ok'=>false,'message'=>'请先选择微信类目，再输入模板名称关键词搜索。'],422);
            if(!isset($categoryNames[$requestedCategory]))return AdminConfigurationResult::json(['ok'=>false,'message'=>'所选微信订阅类目已不在当前小程序类目列表，请刷新后重试。'],400);
            if($query==='')return AdminConfigurationResult::json(['ok'=>false,'message'=>'请输入模板名称关键词后再搜索。'],422);

            $categoryIds=[$requestedCategory];
            // 微信官方接口不支持标题关键词参数，因此后台只能按类目分页读取后本地匹配。
            // 为保护每日 API 配额，每次“搜索/继续搜索”最多读 90 条（3 页），绝不在一次点击后自动扫完整个类目。
            $scanLimit=3000;$chunkSize=90;$resultLimit=20;
            $scanStart=min($scanStart,$scanLimit);$chunkEnd=min($scanLimit,$scanStart+$chunkSize);
            $templatesByTid=[];$start=$scanStart;$total=0;$scanned=0;$progressed=false;
            $cachedPages=0;$liveRequests=0;$exactMatch=false;$interrupted='';
            $normalize=static function(string $text):string{
                $text=mb_strtolower(trim($text));
                return (string)(preg_replace('/[\s\p{P}\p{S}]+/u','',$text)??$text);
            };
            $normalizedQuery=$normalize($query);
            while($start<$chunkEnd){
                try{
                    $batch=$wechat->publicTemplateTitles($categoryIds,$start,30);
                }catch(RuntimeException $exception){
                    $interrupted=mb_substr($exception->getMessage(),0,240);
                    break;
                }
                if(!empty($batch['cache_hit']))$cachedPages++;else $liveRequests++;
                $total=max($total,(int)($batch['count']??0));
                $rows=is_array($batch['data']??null)?$batch['data']:[];
                if(!$rows)break;
                $progressed=true;
                foreach($rows as $row){
                    if(!is_array($row))continue;
                    $scanned++;
                    $title=(string)($row['title']??'');$normalizedTitle=$normalize($title);
                    $matches=$normalizedQuery!==''&&mb_strpos($normalizedTitle,$normalizedQuery)!==false;
                    if(!$matches)continue;
                    $templateTid=(string)($row['tid']??'');if($templateTid==='')continue;
                    $row['category_name']=(string)($categoryNames[$requestedCategory]??'');
                    $templatesByTid[$templateTid]=$row;
                    if($normalizedTitle===$normalizedQuery)$exactMatch=true;
                }
                $start+=count($rows);
                if($exactMatch||count($templatesByTid)>=$resultLimit||count($rows)<30||$start>=$total)break;
            }

            $templates=array_values($templatesByTid);
            usort($templates,static function(array $a,array $b)use($normalizedQuery,$normalize):int{
                $at=$normalize((string)($a['title']??''));$bt=$normalize((string)($b['title']??''));
                $aExact=$at===$normalizedQuery?0:(mb_strpos($at,$normalizedQuery)===0?1:2);
                $bExact=$bt===$normalizedQuery?0:(mb_strpos($bt,$normalizedQuery)===0?1:2);
                if($aExact!==$bExact)return $aExact<=>$bExact;
                return strcmp((string)($a['title']??''),(string)($b['title']??''));
            });

            $hasMore=$interrupted===''&&!$exactMatch&&count($templates)<$resultLimit&&$progressed&&$start<$total&&$start<$scanLimit;
            $truncated=$total>$start&&!$hasMore&&$interrupted===''&&!$exactMatch;
            return AdminConfigurationResult::json(['ok'=>true,'data'=>[
                'categories'=>$categories,'templates'=>$templates,'query'=>$query,'total'=>$total,'scanned'=>$scanned,
                'category_id'=>$requestedCategory,'truncated'=>$truncated,'scan_limit'=>$scanLimit,
                'scan_start'=>$scanStart,'next_start'=>$start,'has_more'=>$hasMore,'chunk_size'=>$chunkSize,
                'cached_pages'=>$cachedPages,'live_requests'=>$liveRequests,'exact_match'=>$exactMatch,'interrupted'=>$interrupted,
            ]]);
        }catch(Throwable $exception){
            return AdminConfigurationResult::json(['ok'=>false,'message'=>mb_substr($exception->getMessage(),0,240)],400);
        }

    }
}
