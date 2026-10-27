<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use RuntimeException;
use Throwable;
use WorkerAdmin\Application\Order\WebAdminOrder;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\WebAdminOrderComposition;
use WorkerAdmin\Http\Admin\AdminModule;

final class OrderAdminModule implements AdminModule
{
    private const ROUTES=['orders','orders.proxy-create','orders.create','orders.view','orders.update','orders.audit','orders.publish','orders.cancel','orders.quote','orders.assign','orders.invite','orders.invite.revoke','orders.release','orders.media.upload','orders.media.delete','media.view'];
    private Auth $auth;private WebAdminOrder $orders;
    public function __construct(Database $db,Auth $auth,Csrf $csrf){$this->auth=$auth;$this->orders=WebAdminOrderComposition::build($db);}
    public function supports(string $route): bool{return in_array($route,self::ROUTES,true);}

    public function dispatch(string $route): void
    {
        $this->auth->requireLogin();
        if($route!=='media.view'){
            if(in_array($route,['orders','orders.view'],true))$this->requireEither('orders.view','orders.manage');else $this->auth->requirePermission('orders.manage');
        }
        $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
        try{
            if($route==='orders.proxy-create'){
                if($method==='GET'){$this->proxyCreate();return;}
                if($method!=='POST')throw new RuntimeException('请求方式不正确。');
                $admin=$this->auth->user();$adminId=(int)($admin['id']??0);
                $id=$this->orders->create($_POST,$adminId,(string)($admin['real_name']??''));
                \audit('order.create','order',$id,'管理员通过统一普通下单表单代客户创建订单');
                \flash('success','代下单草稿已创建。可在订单详情补充图片；客户本人核对、确认协议并支付真实微信预约费后才进入普通审核流程。');
                $this->redirectOrder($id);return;
            }
            $get=in_array($route,['orders','orders.view','media.view'],true);if(($get&&$method!=='GET')||(!$get&&$method!=='POST'))throw new RuntimeException('请求方式不正确。');
            if($route==='orders'){$this->index();return;}
            if($route==='orders.view'){$this->view();return;}
            if($route==='media.view'){$this->mediaView();return;}
            $id=$this->id();$admin=$this->auth->user();$adminId=(int)($admin['id']??0);
            if($route==='orders.create'){
                unset($_POST['approve_now'],$_POST['publish_now']);
                $id=$this->orders->create($_POST,$adminId,(string)($admin['real_name']??''));
                \audit('order.create','order',$id,'管理员代客户创建普通订单');
                \flash('success','代下单草稿已创建，请客户本人核对并支付真实微信预约费。');
                $this->redirectOrder($id);return;
            }
            if($route==='orders.update'){$change=$this->orders->update($id,$_POST,$adminId);\audit('order.update','order',$id,'管理员直接修改订单资料',$change['before']??null,$change['after']??null);\flash('success','订单施工资料已更新。');$this->redirectOrder($id);return;}
            if($route==='orders.audit'){$decision=$this->post('decision');$reason=$this->post('reason');$result=$this->orders->audit($id,$decision,$reason,$adminId);\audit('order.audit.'.$decision,'order',$id,$reason,$result['before']??null,$result['after']??null);\flash('success',$decision==='reject'?(string)($result['message']??'订单已取消并进入退款流程。'):($decision==='supplement'?'已要求用户补资料，系统会在期限临近时提醒，超时自动取消并退款。':'订单审核状态已更新。'));$this->redirectOrder($id);return;}
            if($route==='orders.publish'){$publish=$this->post('publish')==='1';$this->orders->publish($id,$publish);\audit('order.publish','order',$id,$publish?'公开订单':'下架订单');\flash('success',$publish?'订单已公开。':'订单已下架。');$this->redirectOrder($id);return;}
            if($route==='orders.cancel'){$reason=$this->post('reason');$result=$this->orders->cancel($id,$reason,$adminId);\audit('order.cancel','order',$id,$reason);\flash('success',(int)($result['refund_cents']??0)>0?'订单已停止履约；全部可退线上实收已按原支付拆分进入退款审核队列。':'订单已停止履约，无线上款项需退。');$this->redirectOrder($id);return;}
            if($route==='orders.quote'){$this->orders->quote($id,$_POST,$adminId);\audit('order.platform_quote','order',$id,'保存平台报价');\flash('success','平台报价已保存。');$this->redirectOrder($id);return;}
            if($route==='orders.assign'){$workerId=(int)$this->post('worker_id');$rate=max(0,min(10000,(int)round(((float)$this->post('commission_rate','10'))*100)));$reason=$this->post('reason');$this->orders->assign($id,$workerId,$rate,$reason,$adminId);\audit('order.assign','order',$id,'管理员指派师傅，佣金比例 '.number_format($rate/100,2).'%');\flash('success','已创建指派，等待师傅接受。');$this->redirectOrder($id);return;}
            if($route==='orders.invite'){$result=$this->orders->invite($id,$_POST,$adminId);$_SESSION['_generated_invite']=$result;if(($result['share_type']??'')==='ordinary'){\audit('order.share.ordinary','share_context',(int)$result['invite_id'],'管理员生成普通订单分享',null,['order_id'=>$id]);\flash('success','普通订单分享路径已生成；打开者不会自动成为受邀师傅。');}else{\audit('order.invite.create','invite_token',(int)$result['invite_id'],$this->post('reason')?:'管理员分享订单给受邀师傅',null,['order_id'=>$id]);\flash('success','受邀师傅口令已生成，请立即复制；离开详情页后将不再显示明文。');}$this->redirectOrder($id);return;}
            if($route==='orders.invite.revoke'){$inviteId=(int)$this->post('invite_id');$this->orders->revokeInvite($id,$inviteId);\audit('order.invite.revoke','invite_token',$inviteId,'管理员撤销受邀师傅口令');\flash('success','邀请口令已撤销。');$this->redirectOrder($id);return;}
            if($route==='orders.release'){$this->orders->releaseContact($id,$adminId);\audit('order.release_contact','order',$id,'管理员开放完整联系信息');\flash('success','已向当前师傅开放完整信息。');$this->redirectOrder($id);return;}
            if($route==='orders.media.upload'){$mediaId=$this->orders->uploadMedia($id,$this->post('purpose'),(array)($_FILES['file']??[]),$adminId);\audit('order.media.upload','order',$id,'管理员上传订单图片',null,['media_id'=>$mediaId,'purpose'=>$this->post('purpose')]);\flash('success','订单图片已压缩保存。');$this->redirectOrder($id);return;}
            if($route==='orders.media.delete'){$mediaId=(int)$this->post('media_id');$this->orders->deleteMedia($id,$mediaId);\audit('order.media.delete','order',$id,'管理员移除订单图片',null,['media_id'=>$mediaId]);\flash('success','图片已从订单移除，可在一天内通过服务器备份恢复。');$this->redirectOrder($id);return;}
        }catch(RuntimeException $e){\flash('danger',$e->getMessage());if($method==='POST')$this->redirectBack();http_response_code($e->getMessage()==='请求方式不正确。'?405:404);echo \view('errors/404',['pageTitle'=>'页面不存在']);}catch(Throwable $e){$requestId=\request_id();\write_log('admin-order-error','['.date('c').'] request_id='.$requestId.' route='.$route.' '.$e."\n");if($method==='POST'){\flash('danger','订单处理失败，请稍后重试。请求编号：'.$requestId);$this->redirectBack();}http_response_code(500);echo \view('errors/500',['pageTitle'=>'订单处理失败','message'=>'订单处理失败，请稍后重试。请求编号：'.$requestId]);}
    }

    private function index(): void
    {
        $data=$this->orders->index(['q'=>$this->query('q'),'audit_status'=>$this->query('audit_status'),'lifecycle_status'=>$this->query('lifecycle_status'),'page'=>$this->query('page','1')]);
        $generated=$_SESSION['_generated_invite']??null;unset($_SESSION['_generated_invite']);
        echo \view('admin/orders/index',array_merge($data,['pageTitle'=>'订单管理','activeMenu'=>'orders','generatedInvite'=>$generated,'autoOpenOrderId'=>(int)$this->query('manage','0')]));
        echo '<script>(function(){var b=document.querySelector("[data-modal-open=\\"order-create\\"]");if(b){b.removeAttribute("data-modal-open");b.innerHTML="<i class=\\"ri-add-circle-line\\"></i> 代下单";b.addEventListener("click",function(){location.href="'.e(\admin_url('orders.proxy-create')).'";});}var m=document.querySelector("[data-modal=\\"order-create\\"]");if(m)m.remove();})();</script>';
    }

    private function proxyCreate(): void
    {
        $form=$this->orders->proxyForm();
        $data=$this->orders->index(['q'=>'','audit_status'=>'','lifecycle_status'=>'','page'=>'1']);
        echo \view('admin/orders/proxy-create',[
            'pageTitle'=>'代下单','activeMenu'=>'orders','form'=>$form,'users'=>$data['users']??[],
            'mapKey'=>(string)\setting('map.tencent_key','32CBZ-FEPK6-POMSA-MCDE2-M2MIK-ZOBK2'),
        ]);
    }

    private function view(): void {$id=$this->id();$data=$this->orders->detail($id);$generated=null;if(isset($_SESSION['_generated_invite'])&&is_array($_SESSION['_generated_invite'])&&(int)($_SESSION['_generated_invite']['order_id']??0)===$id){$generated=$_SESSION['_generated_invite'];unset($_SESSION['_generated_invite']);}echo \view('admin/orders/view',$data+['generatedInvite'=>$generated,'pageTitle'=>'订单详情','activeMenu'=>'orders']);}
    private function mediaView(): void {$media=$this->orders->media($this->id());if(($media['mode']??'')==='redirect'){header('Location: '.(string)$media['url'],true,302);exit;}if(($media['mode']??'')==='raw'){header('Content-Type: '.(string)$media['mime']);header('Content-Length: '.strlen((string)$media['body']));header('Cache-Control: private, max-age=300');header('X-Content-Type-Options: nosniff');echo (string)$media['body'];exit;}header('Content-Type: '.(string)$media['mime']);header('Content-Length: '.(string)$media['size']);header('Content-Disposition: inline; filename="'.(string)$media['filename'].'"');header('Cache-Control: private, max-age=300');header('X-Content-Type-Options: nosniff');readfile((string)$media['path']);exit;}
    private function requireEither(string $a,string $b): void {if($this->auth->can($a)||$this->auth->can($b))return;http_response_code(403);echo \view('errors/403',['pageTitle'=>'无权访问']);exit;}
    private function id(): int {$id=isset($_GET['id'])&&!is_array($_GET['id'])?(int)$_GET['id']:0;if($id<1)throw new RuntimeException('缺少有效订单编号。');return$id;}
    private function query(string $key,string $default=''): string{return isset($_GET[$key])&&!is_array($_GET[$key])?trim((string)$_GET[$key]):$default;}
    private function post(string $key,string $default=''): string{return isset($_POST[$key])&&!is_array($_POST[$key])?trim((string)$_POST[$key]):$default;}
    private function redirectOrder(int $id): void {\redirect(\admin_url('orders',['manage'=>$id]));}
    private function redirectBack(): void {$id=isset($_GET['id'])?(int)$_GET['id']:0;\redirect($id>0?\admin_url('orders',['manage'=>$id]):\admin_url('orders'));}
}
