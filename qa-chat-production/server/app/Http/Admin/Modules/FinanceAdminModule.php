<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use RuntimeException;
use Throwable;
use WorkerAdmin\Application\Money\WebAdminFinance;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\WebAdminFinanceComposition;
use WorkerAdmin\Http\Admin\AdminModule;

final class FinanceAdminModule implements AdminModule
{
    private const ROUTES=[
        'payments','wallets','wallets.recharge.refund','refunds','refunds.approve','commissions',
        'tail-settlement','tail-settlement.bad-debt','tail-settlement.review','withdrawals','withdrawals.transition',
    ];

    private Auth $auth;
    private WebAdminFinance $finance;

    public function __construct(Database $db,Auth $auth,Csrf $csrf)
    {
        $this->auth=$auth;
        $this->finance=WebAdminFinanceComposition::build($db);
    }

    public function supports(string $route): bool { return in_array($route,self::ROUTES,true); }

    public function dispatch(string $route): void
    {
        $this->auth->requireLogin();
        $this->auth->requirePermission($this->permission($route));
        $post=in_array($route,['wallets.recharge.refund','refunds.approve','tail-settlement.bad-debt','tail-settlement.review','withdrawals.transition'],true);
        $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
        if(($post&&$method!=='POST')||(!$post&&$method!=='GET')){http_response_code(405);throw new RuntimeException('请求方式不正确。');}
        try{
            if($route==='wallets.recharge.refund'){$this->walletRechargeRefund();return;}
            if($route==='refunds.approve'){$this->refundApprove();return;}
            if($route==='tail-settlement.bad-debt'){$this->tailBadDebt();return;}
            if($route==='tail-settlement.review'){$this->tailReview();return;}
            if($route==='withdrawals.transition'){$this->withdrawalTransition();return;}
            if($route==='wallets'){$this->wallets();return;}
            if($route==='tail-settlement'){$this->tailSettlement();return;}
            $this->resource($route);
        }catch(RuntimeException $exception){
            if($post){\flash('danger',$exception->getMessage());\redirect($this->backUrl($route));}
            http_response_code(500);
            echo \view('errors/500',['pageTitle'=>'财务数据读取失败','message'=>'财务数据读取失败，请稍后重试。']);
        }catch(Throwable $exception){
            $requestId=\request_id();\write_log('admin-finance-error','['.date('c').'] request_id='.$requestId.' route='.$route.' '.$exception."\n");
            if($post){\flash('danger','财务操作失败，请稍后重试。请求编号：'.$requestId);\redirect($this->backUrl($route));}
            http_response_code(500);
            echo \view('errors/500',['pageTitle'=>'财务数据读取失败','message'=>'财务数据读取失败，请稍后重试。请求编号：'.$requestId]);
        }
    }

    private function resource(string $resource): void
    {
        $meta=$this->meta($resource);
        $data=$this->finance->resourcePage($resource,$this->page(),$this->query('status'),$this->query('q'),$this->query('business_type'));
        $stats=$resource==='payments'?$this->formatPaymentStats((array)($data['finance_stats']??[])):[];
        echo \view('admin/resource-list',[
            'pageTitle'=>$meta['title'],'activeMenu'=>$resource,'result'=>$data,'resource'=>$resource,'columns'=>$meta['columns'],
            'status'=>$this->query('status'),'search'=>$this->query('q'),'businessType'=>$this->query('business_type'),'financeStats'=>$stats,
        ]);
    }

    private function wallets(): void
    {
        $data=$this->finance->wallets($this->page(),$this->id(false),$this->query('q'));
        echo \view('admin/wallets',[
            'pageTitle'=>'用户余额','activeMenu'=>'wallets','result'=>$data['result'],'search'=>$data['search'],'selected'=>$data['selected'],
            'ledger'=>$data['ledger'],'recharges'=>$data['recharges'],'smsBatches'=>$data['smsBatches'],'reservations'=>$data['reservations'],
            'stats'=>$data['stats'],'schemaWarning'=>$data['schemaWarning'],
        ]);
    }

    private function tailSettlement(): void
    {
        $data=$this->finance->tailSettlement($this->query('q'));
        echo \view('admin/tail-settlement',['pageTitle'=>'尾款处理','activeMenu'=>'tail-settlement','search'=>$data['search'],'delayRows'=>$data['delayRows'],'adjustmentRows'=>$data['adjustmentRows']]);
    }

    private function walletRechargeRefund(): void
    {
        $adminId=(int)($this->auth->user()['id']??0);$result=$this->finance->refundWalletRecharge($this->id(),$this->post('reason'),$adminId);
        $refundId=(int)($result['refund']['id']??0);
        if($result['state']==='processing'){\flash('info','这笔充值的微信退款已经在处理中，无需重复提交。');}
        elseif($result['state']==='submitted'){
            \audit('wallet.recharge.refund.submit','refund',$refundId,'充值纠错退款已提交微信；冻结余额保持到最终结果');
            \flash('success','微信已受理充值纠错退款；对应余额继续冻结，最终成功后才正式冲销。');
        }else{
            \audit('wallet.recharge.refund.submit_uncertain','refund',$refundId,'充值纠错退款提交结果不确定，冻结未释放');
            \flash('warning','微信退款提交结果暂无法确认，系统已保留冻结且不会重复扣余额；请稍后在本页使用同一退款单重试。');
        }
        \redirect(\admin_url('wallets',['id'=>(int)($result['wallet_id']??0)]));
    }

    private function refundApprove(): void
    {
        $id=$this->id();$result=$this->finance->approveRefund($id,$this->post('amount'),(int)($this->auth->user()['id']??0));
        if($result['state']==='submitted'){
            \audit('refund.approve','refund',$id,'审核通过并提交微信退款接口');
            \flash('success','微信已受理退款，当前为处理中；最终以微信退款通知为准。');
        }else{
            \audit('refund.submit_failed','refund',$id,'退款审核通过但微信接口提交失败');
            \flash('error','退款已审核，但微信接口提交失败：'.(string)($result['error']??'未知错误').'。修正支付配置后可再次执行。');
        }
        \redirect(\admin_url('refunds'));
    }

    private function withdrawalTransition(): void
    {
        $id=$this->id();$target=$this->post('target');
        $this->finance->transitionWithdrawal($id,$target,$this->post('external_trade_no'),$this->post('review_note'),(int)($this->auth->user()['id']??0));
        \audit('withdrawal.transition','withdrawal',$id,$target);
        \flash('success',$target==='update_trade_no'?'支付宝流水号已更新。':($target==='paid'?'提现已登记为人工转账完成。':'提现状态已更新。'));
        \redirect(\admin_url('withdrawals'));
    }

    private function tailBadDebt(): void
    {
        $id=$this->id();$note=mb_substr($this->post('review_note'),0,500);
        $this->finance->markTailBadDebt($id,$note,(int)($this->auth->user()['id']??0));
        \audit('tail_settlement.bad_debt','offline_payment_delay',$id,'标记坏账：'.$note);\flash('success','该笔延期尾款已标记为坏账。');\redirect(\admin_url('tail-settlement'));
    }

    private function tailReview(): void
    {
        $id=$this->id();$target=$this->post('target');$note=mb_substr($this->post('review_note'),0,500);
        $this->finance->reviewTailSettlement($id,$target,$note,(int)($this->auth->user()['id']??0));
        \audit('tail_settlement.review','offline_collection_adjustment',$id,$target.'：'.$note);
        \flash('success',$target==='approved'?'实际尾款已完成复核。':'异常尾款已驳回，订单和服务费已恢复原金额。');\redirect(\admin_url('tail-settlement'));
    }

    private function permission(string $route): string
    {
        if($route==='payments'||$route==='wallets')return 'payments.manage';
        if(strpos($route,'refunds')===0||$route==='wallets.recharge.refund')return 'refunds.manage';
        if($route==='commissions'||strpos($route,'tail-settlement')===0)return 'commissions.manage';
        return 'withdrawals.manage';
    }

    /** @return array<string,mixed> */
    private function meta(string $resource): array
    {
        $all=[
            'payments'=>['title'=>'微信支付','columns'=>[['key'=>'payment_no','label'=>'商户单号'],['key'=>'public_no','label'=>'订单号'],['key'=>'business_type','label'=>'业务类型','type'=>'payment_business'],['key'=>'api_version','label'=>'支付接口'],['key'=>'amount_cents','label'=>'金额','type'=>'money'],['key'=>'status','label'=>'状态','type'=>'status'],['key'=>'wechat_transaction_id','label'=>'微信单号'],['key'=>'paid_at','label'=>'支付时间','type'=>'datetime']]],
            'refunds'=>['title'=>'退款管理','columns'=>[['key'=>'refund_no','label'=>'退款单号'],['key'=>'public_no','label'=>'订单号'],['key'=>'amount_cents','label'=>'金额','type'=>'money'],['key'=>'reason','label'=>'原因'],['key'=>'status','label'=>'状态','type'=>'status'],['key'=>'wechat_refund_id','label'=>'微信退款号'],['key'=>'requested_at','label'=>'申请时间','type'=>'datetime']]],
            'commissions'=>['title'=>'平台服务费','columns'=>[['key'=>'public_no','label'=>'订单号'],['key'=>'worker_name','label'=>'师傅'],['key'=>'gross_commission_cents','label'=>'应收服务费','type'=>'money'],['key'=>'booking_fee_offset_cents','label'=>'预约费抵扣','type'=>'money'],['key'=>'amount_due_cents','label'=>'需补缴','type'=>'money'],['key'=>'amount_paid_cents','label'=>'已补缴','type'=>'money'],['key'=>'rate_bp_snapshot','label'=>'比例','type'=>'rate'],['key'=>'status','label'=>'状态','type'=>'status'],['key'=>'due_at','label'=>'到期时间','type'=>'datetime']]],
            'withdrawals'=>['title'=>'提现审核','columns'=>[['key'=>'request_no','label'=>'申请单号'],['key'=>'worker_name','label'=>'师傅'],['key'=>'amount_cents','label'=>'提现金额','type'=>'money'],['key'=>'holder_name_masked_snapshot','label'=>'姓名'],['key'=>'account_masked_snapshot','label'=>'支付宝账号'],['key'=>'status','label'=>'状态','type'=>'status'],['key'=>'external_transaction_no','label'=>'支付宝流水号'],['key'=>'created_at','label'=>'申请时间','type'=>'datetime']]],
        ];
        return $all[$resource];
    }

    /** @param array<string,int> $raw @return array<int,array<string,string>> */
    private function formatPaymentStats(array $raw): array
    {
        $received=(int)($raw['received_cents']??0);$refunded=(int)($raw['refunded_cents']??0);
        return [
            ['label'=>'成功收款','value'=>\money($received),'hint'=>number_format((int)($raw['paid_count']??0)).' 笔','tone'=>'income'],
            ['label'=>'订单预约费','value'=>\money((int)($raw['booking_cents']??0)),'hint'=>'用户线上支付','tone'=>'booking'],
            ['label'=>'测量在线款','value'=>\money((int)($raw['measurement_cents']??0)),'hint'=>'选师傅补款 + 测量现场加费，均在线支付','tone'=>'measurement'],
            ['label'=>'平台服务费','value'=>\money((int)($raw['commission_cents']??0)),'hint'=>'师傅线上缴纳','tone'=>'commission'],
            ['label'=>'用户余额充值','value'=>\money((int)($raw['wallet_recharge_cents']??0)),'hint'=>'受限钱包入金，不计业务收入','tone'=>'wallet'],
            ['label'=>'已成功退款','value'=>\money($refunded),'hint'=>'支持部分退款','tone'=>'refund'],
            ['label'=>'净收款','value'=>\money(max(0,$received-$refunded)),'hint'=>'成功收款 - 成功退款','tone'=>'net'],
        ];
    }

    private function backUrl(string $route): string
    {
        if($route==='wallets.recharge.refund')return \admin_url('wallets',['id'=>$this->id(false)]);
        if($route==='refunds.approve')return \admin_url('refunds');
        if(strpos($route,'tail-settlement')===0)return \admin_url('tail-settlement');
        if(strpos($route,'withdrawals')===0)return \admin_url('withdrawals');
        return \admin_url($route);
    }
    private function page(): int{return max(1,(int)$this->query('page','1'));}
    private function id(bool $required=true): int{$id=isset($_GET['id'])&&!is_array($_GET['id'])?(int)$_GET['id']:0;if($required&&$id<1)throw new RuntimeException('缺少有效记录编号。');return $id;}
    private function query(string $key,string $default=''): string{return isset($_GET[$key])&&!is_array($_GET[$key])?trim((string)$_GET[$key]):$default;}
    private function post(string $key,string $default=''): string{return isset($_POST[$key])&&!is_array($_POST[$key])?trim((string)$_POST[$key]):$default;}
}
