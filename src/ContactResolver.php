<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Reshapify\SendSeven\Client;
use SensitiveParameter;

/**
 * Finds (or creates) the contact for a phone number and remembers its ID,
 * so sending to a number doesn't search the contacts every time.
 *
 * Cache keys include the token and tenant: the same number is a different
 * contact in another account. With a SendSeven::forToken() client, pass the
 * token too.
 */
final readonly class ContactResolver
{
    public function __construct(private Config $config, private CacheFactory $cache) {}

    public function idForPhone(string $phone, ?string $name = null, ?Client $client = null, #[SensitiveParameter] ?string $token = null): string
    {
        $client ??= $this->client();

        $seconds = $this->config->get('sendseven.contacts.cache_for_seconds', 3600);
        $seconds = is_numeric($seconds) ? (int) $seconds : 0;

        if ($seconds <= 0) {
            return $client->contacts()->findOrCreateByPhone($phone, $name)->id;
        }

        $store = $this->config->get('sendseven.contacts.cache_store');
        $cache = $this->cache->store(is_string($store) ? $store : null);
        $key = $this->key($client, $phone, $token);

        $cached = $cache->get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $id = $client->contacts()->findOrCreateByPhone($phone, $name)->id;
        $cache->put($key, $id, $seconds);

        return $id;
    }

    /**
     * Forget a remembered contact, e.g. after it was deleted in SendSeven.
     */
    public function forget(string $phone, ?Client $client = null, #[SensitiveParameter] ?string $token = null): void
    {
        $client ??= $this->client();
        $store = $this->config->get('sendseven.contacts.cache_store');

        $this->cache->store(is_string($store) ? $store : null)->forget($this->key($client, $phone, $token));
    }

    private function client(): Client
    {
        return Container::getInstance()->make(Client::class);
    }

    private function key(Client $client, string $phone, ?string $token): string
    {
        $token ??= $this->config->get('sendseven.token');
        $account = hash('xxh128', (is_string($token) ? $token : '').'|'.($client->tenantId() ?? ''));

        return "sendseven:contacts:{$account}:".(preg_replace('/\D/', '', $phone) ?? '');
    }
}
