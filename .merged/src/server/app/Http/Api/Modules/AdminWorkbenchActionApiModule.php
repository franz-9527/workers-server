<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\AdminWorkbenchActionComposition;
use WorkerAdmin\Foundation\AdminWorkbenchOperationsComposition;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

/** V2.5.0 管理员工作台写动作 API；HTTP 只适配参数，事务落 Application/Infrastructure。 */
final class AdminWorkbenchActionApiModule implements ApiModule
{
    use OrderApiModuleSupport;
    use AdminWorkbenchModuleSupport;

    private OrderApplicationContainer $container;
    private AdminWorkbenchActionComposition $actions;
    private AdminWorkbenchOperationsComposition $operations;

    public function __construct(Database $db)
    {
        $this->container = new OrderApplicationContainer($db);
        $this->actions = new AdminWorkbenchActionComposition($db);
        $this->operations = new AdminWorkbenchOperationsComposition($db);
    }

    public function supports(string $route): bool
    {
        return in_array($route, [
            'admin.order.contact-reveal.review',
            'admin.after_sales.repair.resolve',
            'admin.platform-income.confirm',
            'admin.order.worker.reassign',
            'admin.after_sales.repair.intervene',
            'admin.task.retry-platform-publish',
            'admin.order.followup.add',
            'admin.order.followup.complete',
        ], true);
    }

    public function requiresContentSecurity(string $route): bool
    {
        return true;
    }

    public function dispatch(string $route): void
    {
        $this->orderRequireMethod('POST');
        $actor = $this->orderActor($this->container);
        $this->requireWorkbenchAdmin($this->container, $actor);
        $body = $this->orderJsonBody();

        try {
            $ops = $this->operations->operations();
            if ($route === 'admin.order.worker.reassign') {
                $data = $ops->reassignWorker($actor, $body);
                \json_response(['ok'=>true,'message'=>'已改派，新师傅仍需本人确认接单。','data'=>$data]);
                return;
            }
            if ($route === 'admin.after_sales.repair.intervene') {
                $data = $ops->interveneRepair($actor, $body);
                \json_response(['ok'=>true,'message'=>!empty($data['already_intervened'])?'平台已在介入处理中':'平台已主动介入本次维修售后。','data'=>$data]);
                return;
            }
            if ($route === 'admin.task.retry-platform-publish') {
                $data = $ops->retryPlatformAutoPublish($actor, $body);
                \json_response(['ok'=>true,'message'=>(string)($data['message'] ?? '重新检查完成'),'data'=>$data]);
                return;
            }
            if ($route === 'admin.order.followup.add') {
                $data = $ops->addInternalFollowup($actor, $body);
                \json_response(['ok'=>true,'message'=>'内部跟进已保存，仅管理员可见。','data'=>$data]);
                return;
            }
            if ($route === 'admin.order.followup.complete') {
                $data = $ops->completeInternalFollowup($actor, $body);
                \json_response(['ok'=>true,'message'=>'跟进事项已完成。','data'=>$data]);
                return;
            }

            if ($route === 'admin.after_sales.repair.resolve') {
                $result = $this->actions->repairResolution()->execute(
                    ['channel'=>'mini_admin','user_id'=>(int)($actor['user_id'] ?? 0)],
                    $body
                );
                \json_response(['ok'=>true,'message'=>(string)($result['message'] ?? '处理成功'),'data'=>is_array($result['data'] ?? null) ? $result['data'] : []]);
                return;
            }

            if ($route === 'admin.platform-income.confirm') {
                $miniUserId = (int)($actor['user_id'] ?? 0);
                $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 420);
                $body['note'] = '[小程序管理员 users.id='.$miniUserId.']'.($note !== '' ? ' '.$note : ' 已核对真实到账流水');
                $this->container->platformOrderFinance()->markIncomePaid($body, 0);
                \json_response(['ok'=>true,'message'=>'平台收入款已确认到账，本单平台结算完成。','data'=>[]]);
                return;
            }

            $requestId = trim((string)($body['request_id'] ?? ''));
            $decision = trim((string)($body['decision'] ?? ''));
            $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 500);
            $result = $this->actions->contactRevealReview()->reviewByPublicNo(
                $requestId,$decision,$note,['channel'=>'mini_admin','user_id'=>(int)($actor['user_id'] ?? 0)]
            );
            \json_response(['ok'=>true,'message'=>(string)($result['message'] ?? '处理成功'),'data'=>[
                'status'=>(string)($result['status'] ?? ''),'order_id'=>(int)($result['order_id'] ?? 0),
            ]]);
        } catch (Throwable $exception) {
            $this->orderHandleException($exception);
        }
    }
}
