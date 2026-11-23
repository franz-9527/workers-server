<?php
declare(strict_types=1);
namespace WorkerAdmin\Foundation;
use WorkerAdmin\Application\Chat\ChatAuditorGrants;
use WorkerAdmin\Application\Chat\ChatCallAdministration;
use WorkerAdmin\Application\Chat\ChatCollaboration;
use WorkerAdmin\Application\Chat\ChatCollaborationAdmin;
use WorkerAdmin\Application\Chat\ChatCredentialIssuer;
use WorkerAdmin\Application\Chat\ChatDirect;
use WorkerAdmin\Application\Chat\ChatIdentityDisplay;
use WorkerAdmin\Application\Chat\ChatReminderDispatch;
use WorkerAdmin\Application\Chat\ChatSupport;
use WorkerAdmin\Contracts\SubscribeMessageGateway;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Chat\ChatRecoveryGate;
use WorkerAdmin\Infrastructure\External\LegacyMiniProgramCodeGateway;
use WorkerAdmin\Infrastructure\External\TencentMapIpLocator;
use WorkerAdmin\Infrastructure\Messaging\ConfiguredChatInternalGateway;
use WorkerAdmin\Infrastructure\Messaging\DatabaseChatReminderChannel;
use WorkerAdmin\Infrastructure\Persistence\DatabaseChatAuditorGrantRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseCollaborationAuthority;
use WorkerAdmin\Infrastructure\Persistence\ChatRelationshipBindings;
use WorkerAdmin\Infrastructure\Persistence\DatabaseChatPlatformStaff;
/** Composition only. All ports share this request's database connection. */
final class ChatApplicationContainer
{
    private ApplicationContainer $shared;
    public function __construct(private Database $db){$this->shared=new ApplicationContainer($db);}
    public function credentials(?array $config=null,?string $environment=null):ChatCredentialIssuer
    {return new ChatCredentialIssuer($this->db,$config??(array)\app_config('chat',[]),$environment??(string)\app_config('app.env','production'),$this->shared->privateCipher(),new ChatRecoveryGate());}
    public function collaboration(?string $environment=null,?string $key=null):ChatCollaboration
    {
        $environment??=['testing'=>'test','development'=>'development','production'=>'production'][(string)\app_config('app.env','production')]??'';
        return new ChatCollaboration($this->db,$environment,$key??(string)\app_config('app.key',''),new DatabaseCollaborationAuthority($this->db),$this->shared->privateCipher(),new LegacyMiniProgramCodeGateway());
    }
    public function collaborationAdmin():ChatCollaborationAdmin
    {return new ChatCollaborationAdmin($this->db,new DatabaseCollaborationAuthority($this->db));}
    public function callCredentials():\WorkerAdmin\Application\Chat\ChatCallCredentials
    {return new \WorkerAdmin\Application\Chat\ChatCallCredentials($this->db,$this->shared->privateCipher());}
    public function calls():ChatCallAdministration
    {return new ChatCallAdministration($this->db,new ConfiguredChatInternalGateway($this->db),$this->callCredentials());}
    public function reminders(SubscribeMessageGateway $gateway,callable $eligible):ChatReminderDispatch
    {return new ChatReminderDispatch($this->db,$gateway,$eligible,new DatabaseChatReminderChannel($this->db));}
    public function support():ChatSupport
    {
        return new ChatSupport($this->db,(new DatabaseChatPlatformStaff($this->db))->readAuthorityLocked(...),
            (new ChatRelationshipBindings($this->db))->reserveFeedback(...));
    }
    public function identityDisplay():ChatIdentityDisplay
    {
        $locator=new TencentMapIpLocator(trim((string)$this->shared->settings()->get('map.tencent_key','32CBZ-FEPK6-POMSA-MCDE2-M2MIK-ZOBK2')));
        return new ChatIdentityDisplay($this->db,$this->shared->privateCipher(),
            (new DatabaseChatPlatformStaff($this->db))->readAuthorityLocked(...),
            $locator->locate(...));
    }
    public function direct():ChatDirect
    {
        return new ChatDirect($this->db,$this->shared->settings(),$this->identityDisplay(),
            (new ChatRelationshipBindings($this->db))->reserveDirect(...));
    }
    public function auditors():ChatAuditorGrants
    {return new ChatAuditorGrants(new DatabaseChatAuditorGrantRepository($this->db));}
}
