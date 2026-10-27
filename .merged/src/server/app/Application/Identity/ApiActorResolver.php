<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Identity;

use RuntimeException;
use WorkerAdmin\Contracts\ApiActorRepository;
use WorkerAdmin\Contracts\SettingsProvider;

/**
 * 小程序 API 统一可信身份解析。
 *
 * 客户端只提供登录 token；user/worker/管理员与演示身份全部由服务端会话重新解析。
 */
final class ApiActorResolver
{
    private ApiActorRepository $repository;
    private SettingsProvider $settings;
    private bool $testingEnvironment;

    public function __construct(ApiActorRepository $repository, SettingsProvider $settings, bool $testingEnvironment = false)
    {
        $this->repository = $repository;
        $this->settings = $settings;
        $this->testingEnvironment = $testingEnvironment;
    }

    /** @return array<string,mixed> */
    public function resolve(string $token): array
    {
        if (preg_match('/^[A-Fa-f0-9]{64}$/', $token) !== 1) {
            throw new RuntimeException('请重新登录', 401);
        }
        return $this->resolveTokenHash(hash('sha256', $token));
    }

    /** Token repositories share actor resolution; their submit use case touches the session once. */
    public function resolveTokenHash(string $tokenHash, bool $touchSession = true): array
    {
        if (preg_match('/^[A-Fa-f0-9]{64}$/', $tokenHash) !== 1) {
            throw new RuntimeException('请重新登录', 401);
        }
        $appId = trim((string)$this->settings->get('wechat.miniapp_appid', ''));
        if ($appId === '') {
            throw new RuntimeException('小程序登录配置不完整，请联系管理员', 503);
        }

        $actor = $this->repository->findByTokenHash($tokenHash, $appId);
        if (!$actor) {
            throw new RuntimeException('登录已过期，请重新进入小程序', 401);
        }
        if ($touchSession) $this->repository->touchSession((int)$actor['session_id']);

        $actor['worker_id'] = isset($actor['worker_id']) && $actor['worker_id'] !== null ? (int)$actor['worker_id'] : null;
        $actor['real_user_id'] = (int)$actor['user_id'];
        $actor['is_demo_actor'] = false;
        $actor['is_test_actor'] = false;
        $actor['demo_role'] = '';
        $actor['test_instance_id'] = isset($actor['test_instance_id']) && $actor['test_instance_id'] !== null ? (int)$actor['test_instance_id'] : null;

        $testUserId=(int)($actor['test_actor_user_id']??0);
        $testInstanceId=(int)($actor['test_instance_id']??0);
        $testExpires=(string)($actor['test_actor_expires_at']??'');
        $testExpiryTs=$testExpires!==''?strtotime($testExpires.' UTC'):false;
        $testActor=null;
        if($testUserId>0||$testInstanceId>0||$testExpires!==''){
            $validWindow=$testExpiryTs!==false&&$testExpiryTs>time();
            if($this->testingEnvironment&&$validWindow&&$this->isRealMiniAdmin($actor)&&$testUserId>0&&$testInstanceId>0){
                $testActor=$this->repository->findTestActor($testUserId,$testInstanceId);
            }
            if(!$testActor){
                $this->repository->clearTestActor((int)$actor['session_id']);
                $actor['test_actor_user_id']=null;$actor['test_instance_id']=null;$actor['test_actor_expires_at']=null;
            }else{
                $preserve=[
                    'session_id'=>$actor['session_id'],
                    'payer_openid'=>$actor['payer_openid']??'',
                    'real_user_id'=>$actor['real_user_id'],
                    'test_actor_user_id'=>$testUserId,
                    'test_instance_id'=>$testInstanceId,
                    'test_actor_expires_at'=>$testExpires,
                ];
                $actor=array_merge($actor,$testActor,$preserve);
                $actor['worker_id']=isset($actor['worker_id'])&&$actor['worker_id']!==null?(int)$actor['worker_id']:null;
                $actor['is_demo_actor']=true;
                $actor['is_test_actor']=true;
                $actor['demo_role']=(string)($testActor['test_role']??'');
            }
        }

        $demoUserId = (int)($actor['demo_actor_user_id'] ?? 0);
        if (!$testActor && $demoUserId > 0) {
            $canProxy = $this->isRealMiniAdmin($actor) && $this->settings->bool('demo.data_enabled', false);
            $demo = $canProxy ? $this->repository->findDemoActor($demoUserId) : null;
            if (!$demo) {
                $this->repository->clearDemoActor((int)$actor['session_id']);
                $actor['demo_actor_user_id'] = null;
            } else {
                $preserve = [
                    'session_id' => $actor['session_id'],
                    'payer_openid' => $actor['payer_openid'] ?? '',
                    'real_user_id' => $actor['real_user_id'],
                    'demo_actor_user_id' => $demoUserId,
                ];
                $actor = array_merge($actor, $demo, $preserve);
                $actor['worker_id'] = isset($actor['worker_id']) && $actor['worker_id'] !== null ? (int)$actor['worker_id'] : null;
                $actor['is_demo_actor'] = true;
                $actor['demo_role'] = (string)($demo['demo_role'] ?? '');
            }
        }

        // 订单模块统一使用服务端可信管理员标记；演示代理永远不继承真实管理员业务权限。
        $actor['is_admin'] = $this->isMiniAdmin($actor);
        $actor['business_admin'] = $actor['is_admin'];
        return $actor;
    }

    /** @param array<string,mixed> $actor */
    public function isRealMiniAdmin(array $actor): bool
    {
        $openids = $this->settings->get('wechat.admin_openids', []);
        if (is_string($openids)) {
            $decoded = json_decode($openids, true);
            $openids = is_array($decoded) ? $decoded : preg_split('/[\s,，]+/u', $openids, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($openids)) return false;
        $normalized = [];
        foreach ($openids as $openid) {
            $openid = trim((string)$openid);
            if ($openid !== '') $normalized[] = $openid;
        }
        return in_array(trim((string)($actor['payer_openid'] ?? '')), $normalized, true);
    }

    /** @param array<string,mixed> $actor */
    public function isMiniAdmin(array $actor): bool
    {
        return empty($actor['is_demo_actor']) && $this->isRealMiniAdmin($actor);
    }
}
