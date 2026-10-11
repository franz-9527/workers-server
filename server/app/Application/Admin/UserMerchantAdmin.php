<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use RuntimeException;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Contracts\UserMerchantAdminRepository;
use WorkerAdmin\Contracts\WorkerCloudGateway;

final class UserMerchantAdmin
{
    private UserMerchantAdminRepository $repository;
    private WorkerCloudGateway $cloud;
    private SettingsProvider $settings;

    public function __construct(UserMerchantAdminRepository $repository, WorkerCloudGateway $cloud, SettingsProvider $settings)
    {
        $this->repository = $repository;
        $this->cloud = $cloud;
        $this->settings = $settings;
    }

    /** @return array<string,mixed> */
    public function users(string $search, string $riskFilter, int $page): array
    {
        $riskFilter = trim($riskFilter);
        if (!in_array($riskFilter, ['', 'attention', 'restricted'], true)) $riskFilter = '';
        return $this->repository->usersPage(mb_substr(trim($search), 0, 100), $riskFilter, max(1, $page), 20, $this->settings->bool('demo.data_enabled', true));
    }

    /** @return array{grant_id:int,end:string,user_public_id:string} */
    public function grantMembership(int $userId, string $activationCode, int $adminId): array
    {
        $activationCode = trim($activationCode);
        if ($userId < 1) throw new RuntimeException('用户不存在。');
        if ($activationCode === '' || mb_strlen($activationCode) < 4 || mb_strlen($activationCode) > 128) {
            throw new RuntimeException('请输入有效会员激活码。');
        }

        $appId = trim((string)$this->settings->get('wechat.miniapp_appid', ''));
        if ($appId === '') throw new RuntimeException('当前小程序 AppID 未配置，不能开通会员。');
        $target = $this->repository->membershipTarget($userId, $appId);
        if (!$target) throw new RuntimeException('用户不存在。');
        if ((string)($target['status'] ?? '') !== 'active') throw new RuntimeException('当前用户状态不可开通会员。');
        $openid = trim((string)($target['openid'] ?? ''));
        if ($openid === '') throw new RuntimeException('该用户尚未绑定当前小程序微信身份，请让用户先登录一次当前小程序。');

        $cloud = $this->cloud->invoke('server.membershipGrant', [
            'target_openid'=>$openid,
            'activation_code'=>$activationCode,
        ]);
        $cardId = trim((string)($cloud['card_id'] ?? ''));
        $proof = strtolower(trim((string)($cloud['proof_hmac'] ?? '')));
        $end = trim((string)($cloud['vip_end_time'] ?? ''));
        if ($cardId === '' || preg_match('/^[a-f0-9]{64}$/', $proof) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) !== 1) {
            throw new RuntimeException('云端会员校验返回不完整，请检查云函数部署版本。');
        }

        $signingSecret = trim((string)$this->settings->get('wechat.worker_admin_secret', ''));
        if (strlen($signingSecret) < 32) throw new RuntimeException('云端管理签名密钥未正确配置。');
        $expectedProof = hash_hmac('sha256', 'membership|'.$cardId.'|'.$openid.'|'.$end, $signingSecret);
        if (!hash_equals($expectedProof, $proof)) throw new RuntimeException('云端会员证明校验失败，请检查服务器与云函数签名密钥。');

        $cloudStatus = $this->cloud->invoke('server.membershipStatus', [
            'target_openid'=>$openid,
            'membership_card_id'=>$cardId,
        ]);
        if (empty($cloudStatus['member_valid']) || empty($cloudStatus['member_bound']) || (string)($cloudStatus['vip_end_time'] ?? '') !== $end) {
            throw new RuntimeException('云端会员状态二次复核未通过，本地未写入授权。');
        }

        try {
            $expires = (new DateTimeImmutable($end.' 23:59:59', new DateTimeZone('Asia/Shanghai')))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (Exception $exception) {
            throw new RuntimeException('云端返回的会员到期时间无效。');
        }

        $grantId = $this->repository->saveMembershipGrant($userId, $adminId, mb_substr($cardId, 0, 128), $proof, $expires, $activationCode);
        return ['grant_id'=>$grantId,'end'=>$end,'user_public_id'=>(string)($target['public_id'] ?? '')];
    }

    /** @return array{changed:int,user_public_id:string} */
    public function revokeMembership(int $userId, int $adminId): array
    {
        if ($userId < 1) throw new RuntimeException('用户不存在。');
        $appId = trim((string)$this->settings->get('wechat.miniapp_appid', ''));
        if ($appId === '') throw new RuntimeException('当前小程序 AppID 未配置，不能同步撤销会员。');
        $target = $this->repository->membershipTarget($userId, $appId);
        if (!$target) throw new RuntimeException('用户不存在。');
        $openid = trim((string)($target['openid'] ?? ''));
        if ($openid === '') throw new RuntimeException('该用户没有当前小程序的有效微信身份，无法同步撤销。');

        $this->cloud->invoke('server.membershipRevoke', ['target_openid'=>$openid]);
        return [
            'changed'=>$this->repository->revokeMembership($userId, $adminId),
            'user_public_id'=>(string)($target['public_id'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    public function merchants(string $search, string $status, int $page): array
    {
        $search = mb_substr(trim($search), 0, 100);
        $status = trim($status);
        if ($status !== '' && !in_array($status, ['pending','approved','rejected','suspended','draft','none'], true)) $status = '';
        return $this->repository->merchantsPage($search, $status, max(1, $page), 20);
    }

    public function reviewMerchant(int $merchantId, string $decision, string $reason, int $adminId): void
    {
        $decision = trim($decision);
        $reason = mb_substr(trim($reason), 0, 500);
        if ($merchantId < 1) throw new RuntimeException('商家认证记录不存在。');
        if (!in_array($decision, ['approved','rejected','suspended'], true)) throw new RuntimeException('请选择有效的商家审核结果。');
        if (in_array($decision, ['rejected','suspended'], true) && $reason === '') throw new RuntimeException('驳回或暂停商家资格时必须填写原因。');
        $this->repository->reviewMerchant($merchantId, $decision, $reason, $adminId);
    }
}
