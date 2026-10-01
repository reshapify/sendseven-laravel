<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Reshapify\SendSeven\Http\Request;
use Reshapify\SendSeven\Http\Response;

beforeEach(function (): void {
    Route::sendSevenWebhooks();
});

it('reports a healthy setup', function (): void {
    config()->set('cache.stores.redis', ['driver' => 'redis']);
    config()->set('sendseven.rate_limit.store', 'redis');
    accountFake();

    $this->artisan('sendseven:doctor')
        ->expectsOutputToContain('API_ONLY')
        ->expectsOutputToContain('verified and active')
        ->expectsOutputToContain('SendSeven is set up.')
        ->assertSuccessful();
});

it('explains why tenants cannot be created on this plan', function (): void {
    accountFake(multiTenant: false);

    $this->artisan('sendseven:doctor')
        ->expectsOutputToContain("plan (BASIC) doesn't include multi-tenant management");
});

it('fails when the token is missing', function (): void {
    config()->set('sendseven.token');

    $this->artisan('sendseven:doctor')
        ->expectsOutputToContain('SENDSEVEN_API_TOKEN')
        ->assertFailed();
});

it('fails when SendSeven rejects the token', function (): void {
    accountFake(responses: ['GET /permissions/me/scopes' => new Response(401, '{"detail":"Invalid token"}')]);

    $this->artisan('sendseven:doctor')
        ->expectsOutputToContain('Invalid token')
        ->assertFailed();
});

it('fails when no endpoint points at the webhook route', function (): void {
    accountFake(responses: ['GET /webhook-endpoints' => [webhookEndpointPayload(['url' => 'https://elsewhere.test/hook'])]]);

    $this->artisan('sendseven:doctor')
        ->expectsOutputToContain('sendseven:webhooks:register')
        ->assertFailed();
});

it('fails when an endpoint is set as the default tenant but the token cannot manage tenants', function (): void {
    config()->set('sendseven.tenant_id', 'tenant_acme');
    accountFake(multiTenant: false);

    $this->artisan('sendseven:doctor')
        ->expectsOutputToContain("can't act on other tenants")
        ->assertFailed();
});

it('registers the webhook route and prints its secret once', function (): void {
    $fake = accountFake(responses: [
        'GET /webhook-endpoints' => [],
        'POST /webhook-endpoints' => ['webhook_id' => 'wh_9', 'secret_key' => 'whsec_new', 'message' => null],
        'POST /webhook-endpoints/wh_9/verify' => ['webhook_id' => 'wh_9', 'is_verified' => true, 'verification_pending' => false, 'verification_expires_at' => null, 'message' => 'Verified'],
    ]);

    $this->artisan('sendseven:webhooks:register', ['url' => 'https://app.test/webhooks/sendseven'])
        ->expectsOutputToContain('SENDSEVEN_WEBHOOK_SECRET=whsec_new')
        ->expectsOutputToContain('SendSeven verified the endpoint.')
        ->assertSuccessful();

    $fake->assertSent('POST /webhook-endpoints', fn (Request $request): bool => $request->body['url'] === 'https://app.test/webhooks/sendseven'
        && in_array('message.received', $request->body['subscribed_events'], true)
        && in_array('channel.created', $request->body['subscribed_events'], true));
});

it('does not register the same URL twice', function (): void {
    $fake = accountFake(responses: ['GET /webhook-endpoints' => [webhookEndpointPayload(['url' => 'https://app.test/webhooks/sendseven'])]]);

    $this->artisan('sendseven:webhooks:register', ['url' => 'https://app.test/webhooks/sendseven'])
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    $fake->assertNotSent('POST /webhook-endpoints');
});

it('shows SMS prices for a country', function (): void {
    accountFake(responses: ['GET /sms/pricelist' => [
        'countries' => [
            ['iso' => 'DE', 'name' => 'Germany', 'prefix' => '49', 'platform_fee_eur' => '0.07', 'sendseven_fee_eur' => '0.005', 'total_per_segment_eur' => '0.075', 'is_supported' => true],
            ['iso' => 'JM', 'name' => 'Jamaica', 'prefix' => '1876', 'platform_fee_eur' => '0.10', 'sendseven_fee_eur' => '0.005', 'total_per_segment_eur' => '0.105', 'is_supported' => true],
        ],
        'currency' => 'EUR', 'sendseven_margin_eur' => '0.005', 'source' => 'carrier', 'last_updated' => '2026-09-30', 'supported_countries' => ['DE', 'JM'],
    ]]);

    $this->artisan('sendseven:sms-prices', ['country' => 'de'])
        ->expectsTable(['Country', 'ISO', 'Prefix', 'Per segment (EUR)', 'Supported'], [['Germany', 'DE', '49', '0.075', 'yes']])
        ->assertSuccessful();
});
