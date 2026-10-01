<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Router;
use Reshapify\SendSeven\Account\Capabilities;
use Reshapify\SendSeven\Client;
use Reshapify\SendSeven\Exceptions\SendSevenException;
use Reshapify\SendSeven\Laravel\Webhooks\WebhookSecretResolver;
use Throwable;

/**
 * Checks the whole setup against the live API: the token, what the plan
 * allows, the webhook secret, and whether SendSeven can reach the webhook.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'sendseven:doctor';

    protected $description = 'Check the SendSeven token, plan, tenancy and webhook setup';

    private bool $healthy = true;

    public function handle(Config $config, Container $container, WebhookSecretResolver $secrets, Router $router): int
    {
        if (! is_string($config->get('sendseven.token')) || $config->get('sendseven.token') === '') {
            $this->failed('API token', 'missing: set SENDSEVEN_API_TOKEN (SendSeven → Settings → API Tokens)');

            return self::FAILURE;
        }

        try {
            $sendseven = $container->make(Client::class);
            $capabilities = $sendseven->capabilities();
        } catch (Throwable $throwable) {
            $this->failed('API token', $throwable instanceof SendSevenException ? $throwable->getMessage() : 'the check failed in this app, not at SendSeven: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $this->passed('API token', 'accepted');
        $this->reportPlan($capabilities, $config);
        $this->reportWebhooks($sendseven, $config, $secrets, $router);
        $this->reportRateLimit($config);

        $this->newLine();

        if (! $this->healthy) {
            $this->components->error('Some checks failed; each says what to do.');

            return self::FAILURE;
        }

        $this->components->info('SendSeven is set up.');

        return self::SUCCESS;
    }

    private function reportPlan(Capabilities $capabilities, Config $config): void
    {
        $this->components->twoColumnDetail('Tenant', $capabilities->tenantId());
        $this->components->twoColumnDetail('Plan', $capabilities->package());
        $this->components->twoColumnDetail('SMS', $capabilities->sendsSms() ? 'enabled' : 'not enabled');
        $this->components->twoColumnDetail('RCS', $capabilities->sendsRcs() ? 'enabled' : 'not enabled (Germany only, set up by SendSeven)');
        $this->components->twoColumnDetail('Multi-tenant management', $capabilities->hasMultiTenant() ? 'yes' : 'no');
        $this->components->twoColumnDetail('Can create tenants', $capabilities->canCreateTenants() ? "plan and scopes allow it; SendSeven also requires the billing account owner's token, which can't be checked without creating one" : 'no: '.$capabilities->whyNotCreateTenants());

        $tenantId = $config->get('sendseven.tenant_id');

        if (is_string($tenantId) && $tenantId !== '' && ! $capabilities->canManageTenants()) {
            $this->failed('SENDSEVEN_TENANT_ID', "set to {$tenantId}, but this token can't act on other tenants (needs multi-tenant management and the tenants:manage grant)");
        }
    }

    private function reportWebhooks(Client $sendseven, Config $config, WebhookSecretResolver $secrets, Router $router): void
    {
        $router->getRoutes()->refreshNameLookups();
        $route = $router->getRoutes()->getByName('sendseven.webhooks');

        // Apps that receive webhooks on their own routes keep their own
        // secrets, so there is nothing of this package's to check.
        if ($route === null) {
            $this->components->twoColumnDetail('Webhook route', 'not registered: Route::sendSevenWebhooks() handles webhooks for you; skip this if your app has its own');

            return;
        }

        $secret = $config->get('sendseven.webhooks.secret');

        if ($secrets->hasCustomResolver()) {
            $this->passed('Webhook secret', 'resolved per endpoint by your resolver');
        } elseif (is_string($secret) && $secret !== '') {
            $this->passed('Webhook secret', 'configured');
        } else {
            $this->failed('Webhook secret', 'missing: run php artisan sendseven:webhooks:register, or set SENDSEVEN_WEBHOOK_SECRET');
        }

        $endpoints = $sendseven->webhooks()->listEndpoints();

        if ($route->parameterNames() !== []) {
            $this->components->twoColumnDetail('Webhook endpoints', count($endpoints).' registered for this tenant');

            return;
        }

        $url = url($route->uri());
        $endpoint = null;

        foreach ($endpoints as $candidate) {
            if (rtrim($candidate->url, '/') === rtrim($url, '/')) {
                $endpoint = $candidate;
            }
        }

        if ($endpoint === null) {
            $this->failed('Webhook endpoint', "none points at {$url}: run php artisan sendseven:webhooks:register");

            return;
        }

        match (true) {
            ! $endpoint->isVerified => $this->failed('Webhook endpoint', "{$url} isn't verified: SendSeven couldn't complete the challenge. Make sure the URL is public, then run sendseven:webhooks:register again"),
            ! $endpoint->isActive => $this->failed('Webhook endpoint', "{$url} is inactive".($endpoint->lastError === null ? '' : " (last error: {$endpoint->lastError})")),
            default => $this->passed('Webhook endpoint', "{$url}, verified and active"),
        };
    }

    private function reportRateLimit(Config $config): void
    {
        if ($config->get('sendseven.rate_limit.enabled', true) !== true) {
            $this->components->twoColumnDetail('Shared rate limit', 'off');

            return;
        }

        $store = $config->get('sendseven.rate_limit.store');
        $storeName = is_string($store) && $store !== '' ? $store : $config->get('cache.default');
        $driver = $config->get('cache.stores.'.(is_string($storeName) ? $storeName : '').'.driver');

        if (in_array($driver, ['array', 'null'], true)) {
            $this->warn("  Shared rate limit: the {$driver} cache store isn't shared between processes. Set SENDSEVEN_RATE_LIMIT_STORE to redis, database or similar.");

            return;
        }

        $this->passed('Shared rate limit', 'cache store '.(is_string($storeName) ? $storeName : 'default'));
    }

    private function passed(string $check, string $detail): void
    {
        $this->components->twoColumnDetail($check, "<fg=green>✓</> {$detail}");
    }

    private function failed(string $check, string $detail): void
    {
        $this->healthy = false;
        $this->components->twoColumnDetail($check, "<fg=red>✗</> {$detail}");
    }
}
