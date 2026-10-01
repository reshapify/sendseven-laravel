<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Sleep;
use Reshapify\SendSeven\Http\RateLimiter;
use Reshapify\SendSeven\Http\Request;
use Reshapify\SendSeven\Http\Response;
use Reshapify\SendSeven\Laravel\Exceptions\RateLimitExceeded;

/**
 * Shares one token's request budget between every process using it (web
 * requests, queue workers, the scheduler) through a cache all of them see.
 *
 * - The token never spends more than its budget, minus headroom, in a minute.
 * - With a partner token, one tenant may use only its share, so a busy
 *   tenant slows down alone.
 * - When SendSeven itself says stop (a 429, or no requests remaining) every
 *   process pauses until the window resets.
 */
final readonly class CacheRateLimiter implements RateLimiter
{
    private const int WINDOW_SECONDS = 60;

    public function __construct(
        private Repository $cache,
        private string $budgetKey,
        private int $perMinute = 100,
        private float $headroom = 0.9,
        private float $tenantShare = 1.0,
        private int $maxWaitSeconds = 0,
    ) {}

    public function acquire(Request $request): void
    {
        $refusal = $this->attempt($request);

        if ($refusal === null) {
            return;
        }

        [$seconds, $reason] = $refusal;

        if ($seconds > $this->maxWaitSeconds) {
            throw new RateLimitExceeded($seconds, $reason);
        }

        Sleep::for($seconds)->seconds();

        $refusal = $this->attempt($request);

        if ($refusal !== null) {
            throw new RateLimitExceeded(...$refusal);
        }
    }

    public function observe(Request $request, Response $response): void
    {
        if ($response->status === 429) {
            $retryAfter = $response->header('Retry-After');
            $this->pauseFor(is_numeric($retryAfter) ? (int) ceil((float) $retryAfter) : self::WINDOW_SECONDS);

            return;
        }

        if ($response->header('X-RateLimit-Remaining') === '0') {
            $this->pauseFor($this->secondsUntilReset($response->header('X-RateLimit-Reset')));
        }
    }

    /**
     * The budget per minute after headroom.
     */
    public function budget(): int
    {
        return max(1, (int) floor(max(1, $this->perMinute) * $this->clamp($this->headroom)));
    }

    /**
     * Takes one request from the budget, or says how long to wait and why.
     *
     * @return array{int, string}|null
     */
    private function attempt(Request $request): ?array
    {
        $now = $this->now();
        $blockedUntil = $this->cache->get($this->key('blocked-until'));

        if (is_int($blockedUntil) && $blockedUntil > $now) {
            return [$blockedUntil - $now, 'SendSeven asked us to pause.'];
        }

        $window = intdiv($now, self::WINDOW_SECONDS);
        $untilNextWindow = self::WINDOW_SECONDS - ($now % self::WINDOW_SECONDS);
        $budget = $this->budget();
        $tenantId = $request->header('X-Tenant-ID');

        $globalKey = $this->key("window:{$window}");

        if ($this->take($globalKey) > $budget) {
            $this->cache->decrement($globalKey);

            return [$untilNextWindow, "The SendSeven request budget ({$budget} a minute) is spent for this minute."];
        }

        if ($tenantId !== null && $this->tenantShare < 1.0) {
            $tenantKey = $this->key("tenant:{$tenantId}:window:{$window}");
            $ceiling = max(1, (int) floor($budget * $this->clamp($this->tenantShare)));

            if ($this->take($tenantKey) > $ceiling) {
                $this->cache->decrement($tenantKey);
                $this->cache->decrement($globalKey);

                return [$untilNextWindow, "Tenant {$tenantId} has used its share ({$ceiling} a minute) of the SendSeven request budget."];
            }
        }

        return null;
    }

    private function take(string $key): int
    {
        $this->cache->add($key, 0, self::WINDOW_SECONDS * 2);
        $count = $this->cache->increment($key);

        return is_int($count) ? $count : PHP_INT_MAX;
    }

    private function pauseFor(int $seconds): void
    {
        $until = $this->now() + max(1, $seconds);
        $current = $this->cache->get($this->key('blocked-until'));

        if (! is_int($current) || $current < $until) {
            $this->cache->put($this->key('blocked-until'), $until, max(1, $seconds));
        }
    }

    /**
     * X-RateLimit-Reset may be a Unix time or seconds from now.
     */
    private function secondsUntilReset(?string $reset): int
    {
        if (! is_numeric($reset)) {
            return self::WINDOW_SECONDS;
        }

        $value = (int) $reset;

        return $value > $this->now() ? $value - $this->now() : max(1, min($value, self::WINDOW_SECONDS));
    }

    private function key(string $suffix): string
    {
        return "sendseven:rate-limit:{$this->budgetKey}:{$suffix}";
    }

    private function now(): int
    {
        return Date::now()->getTimestamp();
    }

    private function clamp(float $fraction): float
    {
        return min(1.0, max(0.0, $fraction));
    }
}
