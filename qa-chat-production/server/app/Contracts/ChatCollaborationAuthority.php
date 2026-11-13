<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
/** Operations share the caller's order transaction and database connection. */
interface ChatCollaborationAuthority
{
    public function synchronize(string $order):void;
    public function platformStaffLocked():array;
    public function event(string $order,string $actor,?string $target,string $type,?string $display,?string $version):void;
}
