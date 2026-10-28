<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\AdminOrderProxyComposition;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

/**
 * 管理员旧代操作 API 兼容路由。
 *
 * V2.5.0 不再允许代确认接单、代报价、代客户确认或代师傅完成售后；
 * 保留路由 owner 仅用于稳定返回明确业务错误，避免回退到旧 Controller 或未知处理器。
 * 协助预约与联系补录由独立 Application 用例执行，不经过旧代理动作分发。
 */
final class AdminOrderProxyApiModule implements ApiModule
{
    use OrderApiModuleSupport;
    use AdminWorkbenchModuleSupport;

    private OrderApplicationContainer $container;
    private AdminOrderProxyComposition $composition;

    public function __construct(Database $db)
    {
        $this->container = new OrderApplicationContainer($db);
        $this->composition = new AdminOrderProxyComposition($db);
    }

    public function supports(string $route): bool
    {
        return $route === 'admin.proxy.action' || in_array($route, ['admin.order.assistance.context','admin.order.assistance.save','admin.order.assistance.media.upload'], true);
    }

    public function requiresContentSecurity(string $route): bool
    {
        return $route !== 'admin.order.assistance.context';
    }

    public function dispatch(string $route): void
    {
        $this->orderRequireMethod($route === 'admin.order.assistance.context' ? 'GET' : 'POST');
        $actor = $this->orderActor($this->container);
        $this->requireWorkbenchAdmin($this->container, $actor);
        $body = $route === 'admin.order.assistance.context' ? $_GET : ($route === 'admin.order.assistance.media.upload' ? $_POST : $this->orderJsonBody());

        try {
            $operator = ['channel'=>'mini_admin','user_id'=>(int)($actor['user_id'] ?? 0)];
            if ($route === 'admin.order.assistance.media.upload') {
                $file=$_FILES['file'] ?? [];
                if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
                    $this->orderFail('图片上传失败，请重试','UPLOAD_FAILED',422);
                }
                $data=$this->composition->assistanceMedia($this->container)->upload($operator,$body,(string)$file['tmp_name']);
                $data['url']=$this->composition->signMedia($data,$actor,(int)$data['order_id']);
                \json_response(['ok'=>true,'message'=>'资料已保存','data'=>$data]);
                return;
            }
            if ($route !== 'admin.proxy.action') {
                $data = $this->composition->assistance($this->container)->execute($route, $operator, $body);
                if ($route === 'admin.order.assistance.context') {
                    foreach ($data['media'] as &$media) {
                        $media['url']=$this->composition->signMedia($media,$actor,(int)$data['order_db_id']);
                        $metadata=json_decode((string)($media['metadata_json'] ?? ''),true);
                        $editable=strpos((string)$media['purpose'],'measurement_')===0 ? !empty($data['can_edit_measurement'])
                            : ($media['purpose']==='repair_result' ? in_array($metadata['after_sale_id'] ?? '',$data['repair_results'],true) : !empty($data['can_upload_fulfillment']));
                        $media['can_delete']=($media['created_by_type'] ?? '')==='mini_admin'
                            && ($metadata['assistance_assignment_id'] ?? '')===($data['assignment_id'] ?? '') && $editable;
                        $media['after_sale_id']=$metadata['after_sale_id'] ?? '';
                        unset($media['metadata_json']);
                    }
                    unset($media);
                }
                \json_response(['ok'=>true,'message'=>$route === 'admin.order.assistance.context' ? '' : '补录已保存','data'=>$data]);
                return;
            }
            $result = $this->composition->action()->execute($operator, $body);
            \json_response([
                'ok'=>true,
                'message'=>(string)($result['message'] ?? '处理成功'),
                'data'=>is_array($result['data'] ?? null) ? $result['data'] : [],
            ]);
        } catch (Throwable $exception) {
            $this->orderHandleException($exception);
        }
    }
}
