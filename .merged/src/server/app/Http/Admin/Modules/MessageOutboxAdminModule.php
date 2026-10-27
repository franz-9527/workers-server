<?php
declare(strict_types=1);
namespace WorkerAdmin\Http\Admin\Modules;
use RuntimeException;
use Throwable;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Http\Admin\AdminModule;
use WorkerAdmin\Foundation\MessagingApplicationContainer;
use WorkerAdmin\Application\Messaging\MessageOutboxConsole;
final class MessageOutboxAdminModule implements AdminModule
{
    private Auth $auth;
    private Csrf $csrf;
    private MessageOutboxConsole $console;
    public function __construct(Database $db, Auth $auth, Csrf $csrf, ?MessageOutboxConsole $console=null)
    {
        $this->auth=$auth; $this->csrf=$csrf;
        $this->console=$console ?: (new MessagingApplicationContainer($db))->outbox();
    }
    public function supports(string $route): bool
    { return in_array($route, ['message-outbox','message-outbox.retry','message-outbox.close','message-outbox.suppression.add','message-outbox.suppression.remove'], true); }
    public function dispatch(string $route): void
    {
        $this->auth->requireLogin();
        $this->auth->requirePermission('settings.manage');
        if (!$this->supports($route)) throw new RuntimeException('后台路由不存在');
        try {
            if ($route === 'message-outbox') {
                echo \view('admin/message-outbox', $this->console->dashboard(
                    $this->query('status'), $this->query('q'), (int)$this->query('page','1'), $this->query('tab','records')
                ) + ['pageTitle'=>'消息发送记录','activeMenu'=>'message-outbox']);
                return;
            }
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
                http_response_code(405); throw new RuntimeException('该操作只接受 POST 请求。');
            }
            $this->csrf->verifyRequest();
            $id=(int)$this->query('id','0');
            if ($route === 'message-outbox.retry') {
                $message=$this->console->retry($id);
                \audit('message.outbox.retry','message_outbox',$id,'管理员人工重试消息：'.(string)$message['topic'].'；外部发送结果可能导致重复通知');
                \flash('success','消息已重新加入发送队列；请到计划任务查看后续发送结果。若上一次外部请求超时但实际已送达，用户可能收到重复通知。');
                \redirect(\admin_url('message-outbox',['tab'=>'records','status'=>'failed'])); return;
            }
            if ($route === 'message-outbox.close') {
                $reason=$this->post('close_reason');
                $message=$this->console->close($id,$reason);
                \audit('message.outbox.close','message_outbox',$id,'管理员结案消息：'.(string)$message['topic'].'；原因：'.$reason);
                \flash('success','消息已结案并停止自动重试；结案不代表通知已送达。');
                \redirect(\admin_url('message-outbox',['tab'=>'records','status'=>'closed'])); return;
            }
            if ($route === 'message-outbox.suppression.add') {
                $admin=$this->auth->user();
                $result=$this->console->addSuppression($this->post('mobile'),$this->post('reason'),$this->post('note'),(int)$admin['id']);
                \audit('sms.suppression.add','sms_recipient_suppression',null,'加入短信抑制名单：'.$result['masked'].'；原因：'.$result['reason']);
                \flash('success',$result['masked'].' 已加入附近师傅短信抑制名单。');
            } else {
                $row=$this->console->removeSuppression($id);
                \audit('sms.suppression.remove','sms_recipient_suppression',$id,'解除短信抑制：'.(string)($row['mobile_masked'] ?? ''));
                \flash('success','已解除该号码的附近师傅短信抑制。');
            }
            \redirect(\admin_url('message-outbox',['tab'=>'suppressions']));
        } catch (RuntimeException $exception) {
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') throw $exception;
            // Only the fields rendered by this form enter old input; no tokens or secrets.
            \old_input(['mobile'=>$this->post('mobile'),'reason'=>$this->post('reason'),'note'=>$this->post('note'),'close_reason'=>$this->post('close_reason')]);
            \flash('danger',$exception->getMessage());
            \redirect(\admin_url('message-outbox',['tab'=>$this->returnTab($route)]));
        } catch (Throwable $exception) {
            $id=\request_id();
            \write_log('admin-error','['.date('c').'] request_id='.$id.' route='.$route.' '.$exception->getMessage()."\n");
            $message='系统未能完成本次操作，请根据请求编号查看后台错误日志。 请求编号：'.$id;
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') { \flash('danger',$message); \redirect(\admin_url('message-outbox',['tab'=>$this->returnTab($route)])); return; }
            http_response_code(500); echo \view('errors/500',['pageTitle'=>'系统处理失败','message'=>$message]);
        }
    }
    private function returnTab(string $route): string
    { return strpos($route,'suppression.') !== false ? 'suppressions' : 'records'; }
    private function query(string $key, string $default=''): string
    { return isset($_GET[$key]) && !is_array($_GET[$key]) ? trim((string)$_GET[$key]) : $default; }
    private function post(string $key): string
    { return isset($_POST[$key]) && !is_array($_POST[$key]) ? trim((string)$_POST[$key]) : ''; }
}
