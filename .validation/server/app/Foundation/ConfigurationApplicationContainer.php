<?php
declare(strict_types=1);
namespace WorkerAdmin\Foundation;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Settings\DatabaseAdminConfigurationRepository;
use WorkerAdmin\Infrastructure\Settings\DatabaseConfigurationWriteGuard;
use WorkerAdmin\Infrastructure\Settings\GlobalAdminConfigurationAudit;
use WorkerAdmin\Infrastructure\External\WorkerRuntimeConfigurationAdapter;
use WorkerAdmin\Infrastructure\Messaging\SubscriptionMetadataAdapter;
use WorkerAdmin\Infrastructure\Messaging\DatabaseSubscriptionQuota;
use WorkerAdmin\Application\Messaging\SettingsRead;
use WorkerAdmin\Application\Messaging\SettingsSave;
use WorkerAdmin\Application\Messaging\SubscriptionTemplateManager;
use WorkerAdmin\Application\Messaging\SubscriptionLibrarySearch;
use WorkerAdmin\Application\Messaging\AdminMessageTest;
use WorkerAdmin\Application\Messaging\SmsSettingsManager;
use WorkerAdmin\Application\Messaging\SubscriptionTestData;
final class ConfigurationApplicationContainer
{
    private Database $db;
    public function __construct(Database $db) { $this->db=$db; }
    public function runtimeSync(): \WorkerAdmin\Application\Configuration\WorkerRuntimeConfigSync
    {
        return new \WorkerAdmin\Application\Configuration\WorkerRuntimeConfigSync(
            new DatabaseAdminConfigurationRepository($this->db), new WorkerRuntimeConfigurationAdapter()
        );
    }
    public function services():array
    {
        $shared=new ApplicationContainer($this->db);$repo=new DatabaseAdminConfigurationRepository($this->db);
        $catalog=new SubscriptionMetadataAdapter();$settings=$shared->settings();$audit=new GlobalAdminConfigurationAudit();
        $args=[$repo,$catalog,$settings,$shared->subscribeMessages(),$shared->smsGateway(),new DatabaseSubscriptionQuota($this->db),new SubscriptionTestData($settings),$audit,$shared->adminAlerts()];
        return ['read'=>new SettingsRead($repo),
            'save'=>new SettingsSave($repo,$catalog,$settings,new DatabaseConfigurationWriteGuard($this->db),new WorkerRuntimeConfigurationAdapter(),$audit),
            'templates'=>new SubscriptionTemplateManager($repo,$catalog,$settings,$shared->subscribeMessages(),$audit),'library'=>new SubscriptionLibrarySearch($catalog,$shared->subscribeMessages()),
            'test'=>new AdminMessageTest(...$args),'sms'=>new SmsSettingsManager($repo,$catalog,$settings,$audit)];
    }
}
