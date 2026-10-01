<?php

declare(strict_types=1);

use Reshapify\SendSeven\Client;
use Reshapify\SendSeven\Enums\ChannelType;
use Reshapify\SendSeven\Http\Request;
use Reshapify\SendSeven\Laravel\ConnectRedirect;
use Reshapify\SendSeven\Laravel\Exceptions\MissingConfiguration;
use Reshapify\SendSeven\Laravel\Facades\SendSeven;
use Reshapify\SendSeven\Onboarding\ConnectLink;

/**
 * @return array<string, mixed>
 */
function connectTokenResponse(): array
{
    return [
        'id' => 'cct_9a8b7c6d', 'name' => 'Acme', 'token_prefix' => 's7_cc_a1', 'allowed_channel_types' => ['whatsapp'],
        'allowed_channel_modes' => ['whatsapp' => ['classic']], 'partner_name' => 'Renotify', 'partner_redirect_url' => 'https://app.test/done',
        'connect_url' => 'https://app.sendseven.com/connect/s7_cc_a1b2', 'max_uses' => 1, 'current_uses' => 0, 'use_window_minutes' => 30,
        'first_used_at' => null, 'expires_at' => '2026-10-03T09:00:00Z', 'is_revoked' => false, 'is_locked' => false, 'is_valid' => true,
        'remaining_uses' => 1, 'created_at' => '2026-10-01T09:00:00Z', 'revoked_at' => null, 'created_by_user_id' => null,
        'token' => 's7_cc_a1b2', 'warning' => null,
    ];
}

it('builds the client from config', function (): void {
    config()->set('sendseven.tenant_id', 'tenant_acme');

    expect(app(Client::class))->toBeInstanceOf(Client::class)
        ->and(app(Client::class)->tenantId())->toBe('tenant_acme')
        ->and(SendSeven::getFacadeRoot())->toBe(app(Client::class));
});

it('says how to fix a missing token', function (): void {
    config()->set('sendseven.token', '');

    app(Client::class);
})->throws(MissingConfiguration::class, 'SENDSEVEN_API_TOKEN');

it('builds clients for other tokens', function (): void {
    config()->set('sendseven.token');

    expect(SendSeven::forToken('s7_api_customer', 'tenant_customer')->tenantId())->toBe('tenant_customer');
});

it('fakes the client wherever it is resolved', function (): void {
    $fake = SendSeven::fake(['POST /messages' => ['id' => 'msg_1', 'direction' => 'outbound', 'message_type' => 'text', 'status' => 'queued', 'created_at' => '2026-10-01T09:00:00Z']]);

    app(Client::class)->messages()->send(to: '+4915112345678', channelId: 'ch_1', text: 'Hi');

    $fake->assertSent('POST /messages', fn (Request $request): bool => $request->body['to'] === '+4915112345678');
});

it('redirects to a new connect link', function (): void {
    $fake = SendSeven::fake(['POST /channel-connect-tokens' => connectTokenResponse()]);

    $connection = SendSeven::connect(ConnectLink::for(ChannelType::WhatsApp)->singleUse());
    $response = $connection->toResponse(request());

    expect($connection)->toBeInstanceOf(ConnectRedirect::class)
        ->and($connection->link->id)->toBe('cct_9a8b7c6d')
        ->and($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe('https://app.sendseven.com/connect/s7_cc_a1b2');

    $fake->assertSent('POST /channel-connect-tokens');
});

it('sends Inertia visits to the connect page as a full page load', function (): void {
    SendSeven::fake(['POST /channel-connect-tokens' => connectTokenResponse()]);
    $request = Illuminate\Http\Request::create('/channels/connect', 'POST', server: ['HTTP_X_INERTIA' => 'true']);

    $response = SendSeven::connect(ConnectLink::for(ChannelType::WhatsApp))->toResponse($request);

    expect($response->getStatusCode())->toBe(409)
        ->and($response->headers->get('X-Inertia-Location'))->toBe('https://app.sendseven.com/connect/s7_cc_a1b2');
});
