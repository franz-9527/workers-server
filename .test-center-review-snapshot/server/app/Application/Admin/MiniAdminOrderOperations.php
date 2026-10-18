<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Admin;

use DateTimeImmutable;
use DateTimeZone;
use WorkerAdmin\Contracts\MiniAdminOrderRepository;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Domain\Admin\MiniAdminOperationException;

final class MiniAdminOrderOperations
{
    private MiniAdminOrderRepository $repository;
    private SettingsProvider $settings;
    private string $timezone;

    public function __construct(MiniAdminOrderRepository $repository, SettingsProvider $settings, string $timezone='Asia/Shanghai')
    {
        $this->repository=$repository;$this->settings=$settings;$this->timezone=$timezone;
    }

    /** @return array<string,mixed> */
    public function execute(string $route,array $actor,array $input): array
    {
        if(empty($actor['business_admin']))throw new MiniAdminOperationException('仅管理员可使用工作台','ADMIN_REQUIRED',403);
        if($route==='admin.order.action')return $this->orderAction($actor,$input);
        if($route==='admin.order.quote')return $this->platformQuote($actor,$input);
        if($route==='admin.order.share')return $this->share($actor,$input);
        throw new MiniAdminOperationException('接口不存在','NOT_FOUND',404);
    }

    /** @return array<string,mixed> */
    private function orderAction(array $actor,array $input): array
    {
        $action=strtolower(trim((string)($input['action']??'')));
        if(!in_array($action,['approve','supplement','reject','publish','hide','edit'],true))throw new MiniAdminOperationException('不支持该管理操作','INVALID_ADMIN_ACTION',422);
        $reason=mb_substr(trim((string)($input['reason']??'')),0,500);
        if(in_array($action,['supplement','reject'],true)&&mb_strlen($reason)<2)throw new MiniAdminOperationException('请填写处理原因','REASON_REQUIRED',422);
        $order=$this->repository->findOrder(trim((string)($input['order_id']??'')));
        $title=mb_substr(trim((string)($input['title']??'')),0,160);
        $description=mb_substr(trim((string)($input['description']??'')),0,2000);
        if($action==='edit'&&$title==='')throw new MiniAdminOperationException('订单标题不能为空','ORDER_TITLE_REQUIRED',422);
        $editValues=[];
        if($action==='edit'){
            $mobile=mb_substr(trim((string)($input['contact_mobile']??'')),0,32);
            if($mobile!==''&&preg_match('/^1\d{10}(?:转\d{1,8})?$/u',$mobile)!==1)throw new MiniAdminOperationException('对接人电话应为 11 位手机号，可在后面填写“转+分机号”','INVALID_CONTACT_MOBILE',422);
            $editValues=[
                'quantity_text'=>mb_substr(trim((string)($input['quantity_text']??'')),0,120),
                'wall_height_text'=>mb_substr(trim((string)($input['wall_height_text']??'')),0,120),
                'is_renovation'=>in_array($input['is_renovation']??null,[0,1,'0','1'],true)?(int)$input['is_renovation']:null,
                'expected_visit_at'=>$this->expectedVisitUtc(trim((string)($input['expected_visit_at']??''))),
                'city'=>mb_substr(trim((string)($input['city']??'')),0,64),
                'district'=>mb_substr(trim((string)($input['district']??'')),0,64),
                'address'=>mb_substr(trim((string)($input['address']??'')),0,255),
                'contact_name'=>mb_substr(trim((string)($input['contact_name']??'')),0,80),
                'contact_mobile'=>$mobile,
            ];
        }
        return $this->repository->performOrderAction($actor,$order,[
            'action'=>$action,'reason'=>$reason,'title'=>$title,'description'=>$description,'edit_values'=>$editValues,
            'auto_cancel_days'=>max(1,min(30,(int)$this->settings->get('order.auto_cancel_days',7))),
            'supplement_hours'=>max(1,min(168,(int)$this->settings->get('order.supplement_timeout_hours',72))),
        ]);
    }

    /** @return array<string,mixed> */
    private function platformQuote(array $actor,array $input): array
    {
        if($this->boolSetting('development.pause_quotes'))throw new MiniAdminOperationException('系统维护中，报价已暂停','QUOTES_PAUSED',503);
        $order=$this->repository->findOrder(trim((string)($input['order_id']??'')));
        if((string)($order['workflow_profile']??'construction')!=='construction')throw new MiniAdminOperationException('测量单不支持平台报价','PLATFORM_QUOTE_CONSTRUCTION_ONLY',409);
        $amount=(int)($input['amount_cents']??0);
        if($amount<max(1,(int)($order['booking_fee_cents']??0))||$amount>100000000){
            throw new MiniAdminOperationException('平台报价不能低于本单预约费 '.\money((int)($order['booking_fee_cents']??0)),'INVALID_AMOUNT',422);
        }
        $description=mb_substr(trim((string)($input['description']??'')),0,1000);
        $quote=$this->repository->savePlatformQuote($actor,$order,$amount,$description);
        return ['ok'=>true,'message'=>'平台报价已保存','data'=>['quote'=>$quote]];
    }

    /** @return array<string,mixed> */
    private function share(array $actor,array $input): array
    {
        $order=$this->repository->findOrder(trim((string)($input['order_id']??'')));
        $type=(($input['share_type']??'')==='ordinary')?'ordinary':'worker_invite';
        $validDays=max(1,min(30,(int)($input['valid_days']??7)));
        return $this->repository->createShare($actor,$order,[
            'share_type'=>$type,'valid_days'=>$validDays,
            'commission_rate'=>$input['commission_rate']??null,
            'reason'=>mb_substr(trim((string)($input['reason']??'小程序管理员邀请师傅')),0,500)?:'小程序管理员邀请师傅',
        ]);
    }

    private function expectedVisitUtc(string $value): ?string
    {
        if($value==='')return null;
        if(preg_match('/^(\d{4}-\d{2}-\d{2})\s*(上午|下午|晚上)$/u',$value,$matches)!==1)throw new MiniAdminOperationException('预约时间格式不正确，请选择日期和上午/下午/晚上','INVALID_DATETIME',422);
        $times=['上午'=>'09:00','下午'=>'14:00','晚上'=>'19:00'];
        try{
            return (new DateTimeImmutable($matches[1].' '.$times[$matches[2]],new DateTimeZone($this->timezone)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }catch(\Throwable $exception){
            throw new MiniAdminOperationException('预约时间格式不正确','INVALID_DATETIME',422);
        }
    }

    private function boolSetting(string $key): bool
    {
        $value=$this->settings->get($key,false);
        if(is_bool($value))return $value;
        if(is_numeric($value))return (int)$value>0;
        return in_array(strtolower(trim((string)$value)),['1','true','yes','on','enabled'],true);
    }
}
