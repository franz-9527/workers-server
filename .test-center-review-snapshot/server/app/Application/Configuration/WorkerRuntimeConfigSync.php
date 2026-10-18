<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Configuration;
use RuntimeException;
use WorkerAdmin\Contracts\AdminConfigurationRepository;
use WorkerAdmin\Contracts\WorkerRuntimeConfigurationGateway;
/** 同步已保存的管理员身份和师傅电话查看策略，不修改本地配置。 */
final class WorkerRuntimeConfigSync
{
    private AdminConfigurationRepository $repo;
    private WorkerRuntimeConfigurationGateway $runtime;
    public function __construct(AdminConfigurationRepository $repo, WorkerRuntimeConfigurationGateway $runtime)
    { $this->repo=$repo; $this->runtime=$runtime; }
    public function execute():array
    {
        $rows=$this->repo->runtimeConfigRows();
        $values = [];
        foreach ($rows as $row) {
            $key = (string)$row['setting_key']; $raw = (string)$row['setting_value']; $type = (string)$row['value_type'];
            if ($type === 'json') {
                $decoded = json_decode($raw, true);
                $values[$key] = is_array($decoded) ? $decoded : [];
            } elseif (in_array($type, ['bool','boolean'], true)) {
                $values[$key] = in_array(strtolower($raw), ['1','true','yes','on'], true);
            } elseif (in_array($type, ['int','integer'], true)) {
                $values[$key] = (int)$raw;
            } else {
                $values[$key] = $raw;
            }
        }
        $admins = isset($values['wechat.admin_openids']) && is_array($values['wechat.admin_openids']) ? $values['wechat.admin_openids'] : [];
        if (!$admins) throw new RuntimeException('后台尚未配置小程序管理员 OpenID，不能同步云端管理员权限。');

        return $this->runtime->sync([
            'admin_openids'=>$admins,
            'phone_reveal_enabled'=>(bool)($values['worker.phone_reveal_enabled']??true),
            'phone_reveal_admin_enabled'=>(bool)($values['worker.phone_reveal_admin_enabled']??true),
            'phone_reveal_member_enabled'=>(bool)($values['worker.phone_reveal_member_enabled']??true),
            'phone_reveal_member_24h_limit'=>max(0,min(500,(int)($values['worker.phone_reveal_member_24h_limit']??8))),
        ]);

    }
}
