<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Reshapify\SendSeven\Client;
use Reshapify\SendSeven\Laravel\Exceptions\MissingConfiguration;
use Reshapify\SendSeven\SendSeven;
use SensitiveParameter;

/**
 * Builds clients from config/sendseven.php: the default one the container
 * hands out, and one per token for apps where each customer has their own.
 */
final readonly class ClientFactory
{
    public function __construct(private Config $config, private CacheFactory $cache) {}

    public function make(#[SensitiveParameter] ?string $token = null, ?string $tenantId = null): Client
    {
        $token ??= $this->string('sendseven.token');

        if ($token === null) {
            throw MissingConfiguration::token();
        }

        $factory = SendSeven::factory()
            ->withToken($token)
            ->withBaseUri($this->string('sendseven.base_uri') ?? SendSeven::BASE_URI)
            ->withTenant($tenantId ?? $this->string('sendseven.tenant_id'))
            ->withRetries($this->int('sendseven.retries.max_attempts', 3))
            ->withSleeper(new LaravelSleeper);

        if ($this->config->get('sendseven.rate_limit.enabled', true) === true) {
            $factory->withRateLimiter($this->rateLimiter($token));
        }

        return $factory->make();
    }

    private function rateLimiter(#[SensitiveParameter] string $token): CacheRateLimiter
    {
        return new CacheRateLimiter(
            cache: $this->cache->store($this->string('sendseven.rate_limit.store')),
            budgetKey: hash('sha256', $token),
            perMinute: $this->int('sendseven.rate_limit.per_minute', 100),
            headroom: $this->float('sendseven.rate_limit.headroom', 0.9),
            tenantShare: $this->float('sendseven.rate_limit.tenant_share', 1.0),
            maxWaitSeconds: $this->int('sendseven.rate_limit.max_wait_seconds', 0),
        );
    }

    private function string(string $key): ?string
    {
        $value = $this->config->get($key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function int(string $key, int $default): int
    {
        $value = $this->config->get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function float(string $key, float $default): float
    {
        $value = $this->config->get($key);

        return is_numeric($value) ? (float) $value : $default;
    }
}
