<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\AdminWorkbenchActionComposition;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

final class AdminWorkbenchActionApiModule implements ApiModule
{
    use OrderApiModuleSupport;
    use AdminWorkbenchModuleSupport;

    private OrderApplicationContainer $container;
    private AdminWorkbenchActionComposition $actions;

    public function __construct(Database $db)
    {
        $this->container = new OrderApplicationContainer($db);
        $this->actions = new AdminWorkbenchActionComposition($db);
    }

    public function supports(string $route): bool
    {
        return in_array($route, [
            'admin.order.contact-reveal.review',
            'admin.after_sales.repair.resolve',
            'admin.platform-income.confirm',
        ], true);
    }

    public function requiresContentSecurity(string $route): bool { return true; }

    public function dispatch(string $route): void
    {
        $this->orderRequireMethod('POST');
        $actor = $this->orderActor($this->container);
        $this->requireWorkbenchAdmin($this->container, $actor);
        $body = $this->orderJsonBody();

        try {
            if ($route === 'admin.after_sales.repair.resolve') {
                $result = $this->actions->repairResolution()->execute(
                    ['channel'=>'mini_admin','user_id'=>(int)($actor['user_id'] ?? 0)],
                    $body
                );
                \json_response([
                    'ok'=>true,
                    'message'=>(string)($result['message'] ?? '处理成功'),
                    'data'=>is_array($result['data'] ?? null) ? $result['data'] : [],
                ]);
                return;
            }

            if ($route === 'admin.platform-income.confirm') {
                $miniUserId = (int)($actor['user_id'] ?? 0);
                $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 420);
                $body['note'] = '[小程序管理员 users.id='.$miniUserId.']'.($note !== '' ? ' '.$note : ' 已核对真实到账流水');
                $this->container->platformOrderFinance()->markIncomePaid($body, 0);
                \json_response([
                    'ok'=>true,
                    'message'=>'平台收入款已确认到账，本单平台结算完成。',
                    'data'=>[],
                ]);
                return;
            }

            $requestId = trim((string)($body['request_id'] ?? ''));
            $decision = trim((string)($body['decision'] ?? ''));
            $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 500);
            $result = $this->actions->contactRevealReview()->reviewByPublicNo(
                $requestId,
                $decision,
                $note,
                ['channel'=>'mini_admin','user_id'=>(int)($actor['user_id'] ?? 0)]
            );
            \json_response([
                'ok'=>true,
                'message'=>(string)($result['message'] ?? '处理成功'),
                'data'=>[
                    'status'=>(string)($result['status'] ?? ''),
                    'order_id'=>(int)($result['order_id'] ?? 0),
                ],
            ]);
        } catch (Throwable $exception) {
            $this->orderHandleException($exception);
        }
    }
}
