<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Chat;

use InvalidArgumentException;
use OverflowException;

/**
 * Pure EXT03.a resource arithmetic, not a reservation or authorization.
 * Call only with PHP-owned verified facts. EXT04 must lock and persist the
 * pool and ledger before acknowledging a reservation. No wallet writes here.
 */
final class CallResourcePolicy
{
    private const UNITS = ['wechat'=>'wechat_equivalent', 'shengwang'=>'shengwang_standard'];
    private const AMOUNTS = [
        'free_total','budget_limit','official_used','local_uncovered',
        'reserved','safety_margin','external_uncertainty',
    ];

    /** Internal PHP -> Go snapshot. Never expose platform amounts to clients. */
    public function availability(array $pool, int $now): array
    {
        $required = array_merge(self::AMOUNTS, [
            'schema_version','provider','quota_scope_id','cycle_id','config_revision','unit',
            'enabled','verified','hard_stop_verified','healthy','valid_from','expires_at',
            'verified_at','usage_observed_at','max_age_seconds',
        ]);
        $this->keys($pool, $required);
        $provider = $pool['provider'];
        if ($pool['schema_version'] !== 1 || !is_string($provider)
            || !isset(self::UNITS[$provider]) || $pool['unit'] !== self::UNITS[$provider]
            || !$this->key($pool['quota_scope_id']) || !$this->key($pool['cycle_id'])
            || !$this->id($pool['config_revision']) || $now < 1) $this->invalid();
        foreach (['enabled','verified','hard_stop_verified','healthy'] as $field) {
            if (!is_bool($pool[$field])) $this->invalid();
        }
        foreach (['valid_from','expires_at','verified_at','usage_observed_at','max_age_seconds'] as $field) {
            if (!is_int($pool[$field]) || $pool[$field] < 1) $this->invalid();
        }
        if ($pool['valid_from'] >= $pool['expires_at'] || $pool['verified_at'] > $now
            || $pool['usage_observed_at'] > $now) $this->invalid();
        $n = [];
        foreach (self::AMOUNTS as $field) $n[$field] = $this->amount($pool[$field]);
        if ($n['budget_limit'] > $n['free_total']) $this->invalid();

        $available = 0;
        if (!$pool['enabled']) $status = 'disabled';
        elseif (!$pool['verified'] || !$pool['hard_stop_verified']) $status = 'waiting_verification';
        elseif (!$pool['healthy'] || $now < $pool['valid_from'] || $now >= $pool['expires_at']
            || $now - $pool['usage_observed_at'] > $pool['max_age_seconds']) $status = 'unavailable';
        else {
            // Saturating sequential subtraction avoids overflow when independent
            // conservative counters exceed the original budget.
            $unspent = $this->subtract($n['budget_limit'], $n['official_used'], $n['local_uncovered']);
            $safe = $this->subtract($unspent, $n['safety_margin'], $n['external_uncertainty']);
            $available = $this->subtract($safe, $n['reserved']);
            $status = $unspent === 0 ? 'exhausted' : ($safe === 0 ? 'safety_insufficient'
                : ($available === 0 ? 'busy' : 'available'));
        }
        return [
            'schema_version'=>1, 'provider'=>$provider,
            'quota_scope_id'=>$pool['quota_scope_id'], 'cycle_id'=>$pool['cycle_id'],
            'config_revision'=>$pool['config_revision'], 'unit'=>$pool['unit'],
            'status'=>$status, 'available_units'=>(string)$available,
            // Go must reject a snapshot after the usage freshness boundary too.
            'expires_at'=>min($pool['expires_at'], $pool['usage_observed_at']
                + min($pool['max_age_seconds'], PHP_INT_MAX - $pool['usage_observed_at'])),
        ];
    }

    /**
     * Incremental resource units, 1 unit = 1/100 equivalent/standard second.
     * Each leg contains whole-attempt cumulative durations; quantum rounding is
     * per leg across the entire attempt, never per heartbeat window.
     * Rates/quantum come from a verified immutable provider config, not defaults.
     */
    public function incrementalCost(array $metering, array $legs): int
    {
        $this->keys($metering, ['rate_numerator','rate_denominator','quantum_seconds']);
        foreach ($metering as $value) if (!is_int($value) || $value < 1) $this->invalid();
        if (!array_is_list($legs) || count($legs) < 1 || count($legs) > 2) $this->invalid();
        $total = 0;
        foreach ($legs as $leg) {
            if (!is_array($leg)) $this->invalid();
            $this->keys($leg, ['previous_seconds','current_seconds']);
            $before = $leg['previous_seconds']; $after = $leg['current_seconds'];
            if (!is_int($before) || !is_int($after) || $before < 0 || $after < $before) $this->invalid();
            $delta = $this->cost($after, $metering) - $this->cost($before, $metering);
            $total = $this->add($total, $delta);
        }
        return $total;
    }

    private function cost(int $seconds, array $metering): int
    {
        $rounded = $this->multiply($this->ceil($seconds, $metering['quantum_seconds']), $metering['quantum_seconds']);
        $scaled = $this->multiply($this->multiply($rounded, $metering['rate_numerator']), 100);
        return $this->ceil($scaled, $metering['rate_denominator']);
    }

    private function ceil(int $n, int $d): int { return intdiv($n, $d) + ($n % $d === 0 ? 0 : 1); }
    private function subtract(int $n, int ...$values): int
    {
        foreach ($values as $value) $n = $value >= $n ? 0 : $n - $value;
        return $n;
    }
    private function add(int $a, int $b): int
    {
        if ($b > PHP_INT_MAX - $a) throw new OverflowException('CALL_RESOURCE_OVERFLOW');
        return $a + $b;
    }
    private function multiply(int $a, int $b): int
    {
        if ($a !== 0 && $b > intdiv(PHP_INT_MAX, $a)) throw new OverflowException('CALL_RESOURCE_OVERFLOW');
        return $a * $b;
    }
    private function amount($value): int
    {
        // Decimal strings survive JSON/SQL/Go without float conversion.
        if (!is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1
            || strlen($value) > strlen((string)PHP_INT_MAX)
            || (strlen($value) === strlen((string)PHP_INT_MAX) && strcmp($value, (string)PHP_INT_MAX) > 0)) $this->invalid();
        return (int)$value;
    }
    private function id($value): bool
    {
        return is_string($value) && preg_match('/^[1-9][0-9]{0,19}$/D', $value) === 1
            && (strlen($value) < 20 || strcmp($value, '18446744073709551615') <= 0);
    }
    private function key($value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]{0,127}$/D', $value) === 1;
    }
    private function keys(array $value, array $required): void
    {
        if (count($value) !== count($required)) $this->invalid();
        foreach ($required as $key) if (!array_key_exists($key, $value) || $value[$key] === null) $this->invalid();
    }
    private function invalid(): never { throw new InvalidArgumentException('CALL_RESOURCE_INVALID'); }
}
