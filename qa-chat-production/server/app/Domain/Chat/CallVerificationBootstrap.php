<?php
declare(strict_types=1);

namespace WorkerAdmin\Domain\Chat;

/**
 * Test-site only bridge for proving the real Shengwang stop path.
 * Production admission remains governed by CallResourcePolicy without relaxation.
 */
final class CallVerificationBootstrap
{
    public const USAGE_FRESHNESS_SECONDS=86400;

    public static function effectiveFacts(array $facts,array $profile,int $now,bool $testing,bool $allowUnverifiedStop=true):array
    {
        if(!$testing||!self::eligible($facts,$profile,$now))return $facts;
        $facts['max_age_seconds']=max((int)($facts['max_age_seconds']??0),self::USAGE_FRESHNESS_SECONDS);
        if($allowUnverifiedStop&&empty($facts['hard_stop_verified'])&&self::usageFresh($facts,$now))$facts['hard_stop_verified']=true;
        return $facts;
    }

    public static function requiresStopVerification(array $facts,array $profile,int $now,bool $testing):bool
    {
        return $testing&&self::eligible($facts,$profile,$now)&&empty($facts['hard_stop_verified'])&&self::usageFresh($facts,$now);
    }

    private static function eligible(array $facts,array $profile,int $now):bool
    {
        if(($facts['provider']??'')!=='shengwang'||($profile['usage_known']??true)!==true)return false;
        if(empty($facts['enabled'])||empty($facts['verified'])||empty($facts['healthy']))return false;
        $from=(int)($facts['valid_from']??0);$until=(int)($facts['expires_at']??0);$observed=(int)($facts['usage_observed_at']??0);
        return $from>0&&$until>$from&&$observed>0&&$observed<=$now&&$now>=$from&&$now<$until;
    }

    private static function usageFresh(array $facts,int $now):bool
    {
        $observed=(int)($facts['usage_observed_at']??0);
        return $observed>0&&$observed<=$now&&$now-$observed<self::USAGE_FRESHNESS_SECONDS;
    }
}
