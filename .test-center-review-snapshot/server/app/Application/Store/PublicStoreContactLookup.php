<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Store;

use RuntimeException;
use WorkerAdmin\Contracts\ActiveSessionGateway;
use WorkerAdmin\Contracts\PublicStoreContactGateway;
use WorkerAdmin\Contracts\SettingsProvider;

final class PublicStoreContactLookup
{
    private ActiveSessionGateway $sessions;
    private PublicStoreContactGateway $contacts;
    private SettingsProvider $settings;

    public function __construct(ActiveSessionGateway $sessions, PublicStoreContactGateway $contacts, SettingsProvider $settings)
    {
        $this->sessions = $sessions;
        $this->contacts = $contacts;
        $this->settings = $settings;
    }

    /** @return array{enabled:bool,mobile:string,has_mobile:bool} */
    public function lookup(string $token, string $storeId): array
    {
        $this->sessions->assertActive($token);
        if (!$this->settings->bool('store.phone_public_enabled', false)) {
            return ['enabled' => false, 'mobile' => '', 'has_mobile' => false];
        }
        $storeId = trim($storeId);
        if ($storeId === '' || strlen($storeId) > 128 || preg_match('/^[A-Za-z0-9_-]+$/', $storeId) !== 1) {
            throw new RuntimeException('门店编号无效。', 422);
        }
        $mobile = $this->contacts->fetchMobile($storeId);
        return ['enabled' => true, 'mobile' => $mobile, 'has_mobile' => $mobile !== ''];
    }
}
