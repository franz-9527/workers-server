<?php
declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use RuntimeException;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Http\Admin\AdminModule;

final class ChatDirectReportAdminModule implements AdminModule
{
    public function __construct(private Database $db,private Auth $auth) {}

    public function supports(string $route): bool
    {
        return $route==='chat.direct-reports';
    }

    public function dispatch(string $route): void
    {
        if ($route!=='chat.direct-reports') throw new RuntimeException('后台路由不存在');
        $this->reports();
    }

    private function reports(): void
    {
        $this->auth->requirePermission('chat.evidence.view');
        if (($_SERVER['REQUEST_METHOD']??'')!=='GET') throw new RuntimeException('此入口只接受 GET 请求');
        $admin=(string)$this->auth->user()['id'];
        $report=(string)($_GET['report_id']??'');
        [$rows,$evidence]=$this->db->transaction(function()use($admin,$report){
            if ($this->db->fetchValue('SELECT CAST(report_reviewer_admin_id AS CHAR) FROM chat_direct_release WHERE id=1 LOCK IN SHARE MODE')!==$admin) {
                http_response_code(403);
                throw new RuntimeException('当前账号没有普通私聊举报权限');
            }
            $rows=$this->db->fetchAll('SELECT CAST(id AS CHAR) id,conversation_id,message_id,reason,message_type,created_at
                FROM chat_direct_reports WHERE created_at>=UTC_TIMESTAMP(6)-INTERVAL 30 DAY ORDER BY id DESC LIMIT 50');
            $evidence=null;
            if (preg_match('/^[1-9][0-9]{0,19}$/D',$report)) {
                $evidence=$this->db->fetch("SELECT CAST(d.id AS CHAR) id,CAST(d.message_id AS CHAR) message_id,d.message_type,CASE WHEN SHA2(COALESCE(m.text_body,''),256)=d.proof_hash
                    THEN LEFT(m.text_body,320) ELSE NULL END evidence FROM chat_direct_reports d
                    JOIN chat_messages m ON m.id=d.message_id AND m.conversation_id=d.conversation_id
                    JOIN chat_conversations c ON c.id=d.conversation_id AND c.conversation_type='direct'
                    WHERE d.id=:id AND d.created_at>=UTC_TIMESTAMP(6)-INTERVAL 30 DAY",['id'=>$report]);
                if($evidence){
                    \audit('chat.direct_report.read','chat_direct_report',(int)$report,'查看单条已举报私聊消息');
                    $evidence['images']=in_array($evidence['message_type'],['image','image_album'],true)?
                        $this->db->fetchAll('SELECT CAST(item_id AS CHAR) item_id FROM chat_image_items WHERE message_id=:id ORDER BY ordinal LIMIT 9',
                            ['id'=>$evidence['message_id']]):[];
                }
            }
            return [$rows,$evidence];
        });
        header('Cache-Control: private, no-store');
        echo \view('admin/chat-direct-reports',['activeMenu'=>'chat.governance','pageTitle'=>'普通私聊举报','rows'=>$rows,'evidence'=>$evidence]);
    }
}
