<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface AdminConfigurationRepository
{
    public function pageRows(array $groups,bool $messages=false):array;
    public function ensureInternalDefaults():void;
    public function categories():array;
    public function fieldDictionary():array;
    public function settingsForGroup(string $group):array;
    public function saveMany(array $updates,int $adminId):void;
    public function set(string $key,string $value,int $adminId):void;
    public function userForOpenid(string $openid):int;
    public function runtimeConfigRows():array;
    public function assertSendBoundary():void;
}
