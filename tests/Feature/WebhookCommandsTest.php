<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Reshapify\SendSeven\Http\Request;
use Reshapify\SendSeven\Http\Response;
use Reshapify\SendSeven\Laravel\Facades\SendSeven;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function deliveryPayload(array $overrides = []): array
{
    return [
        'id' => 'dlv_1', 'tenant_id' => 'tenant_shift', 'webhook_endpoint_id' => 'wh_1', 'event_type' => 'message.received',
        'event_id' => 'evt_1', 'attempt_number' => 3, 'status' => 'failed', 'response_status_code' => 500, 'response_body' => 'oops',
        'response_time_ms' => 120, 'request_payload' => [], 'error_message' => 'Server error', 'next_retry_at' => null,
        'created_at' => '2026-10-01T09:00:00Z', 'completed_at' => null, 'can_retry' => true, ...$overrides,
    ];
}

beforeEach(function (): void {
    Route::sendSevenWebhooks();
});

it('lists endpoints with their state', function (): void {
    SendSeven::fake(['GET /webhook-endpoints' => [
        webhookEndpointPayload(),
        webhookEndpointPayload(['id' => 'wh_2', 'url' => 'https://other.test/hook', 'suspended_at' => '2026-10-01T08:00:00Z', 'consecutive_failures' => 12, 'last_error' => 'Timed out']),
    ]]);

    // One expectation per table row: each output line can satisfy only one.
    $this->artisan('sendseven:webhooks:list')
        ->expectsOutputToContain('wh_1')
        ->expectsOutputToContain('suspended after 12 failures')
        ->assertSuccessful();
});

it('acts on the endpoint behind the webhook route when no ID is given', function (): void {
    SendSeven::fake(['GET /webhook-endpoints' => [
        webhookEndpointPayload(['id' => 'wh_other', 'url' => 'https://other.test/hook']),
        webhookEndpointPayload(),
    ]]);

    $this->artisan('sendseven:webhooks:show')
        ->expectsOutputToContain('wh_1')
        ->assertSuccessful();
});

it('asks for an ID when it cannot tell which endpoint is meant', function (): void {
    SendSeven::fake(['GET /webhook-endpoints' => [
        webhookEndpointPayload(['id' => 'wh_a', 'url' => 'https://a.test/hook']),
        webhookEndpointPayload(['id' => 'wh_b', 'url' => 'https://b.test/hook']),
    ]]);

    $this->artisan('sendseven:webhooks:show')
        ->expectsOutputToContain('pass the ID')
        ->assertFailed();
});

it('shows recent deliveries, filtered by status', function (): void {
    $fake = SendSeven::fake([
        'GET /webhook-endpoints/wh_1' => webhookEndpointPayload(),
        'GET /webhook-endpoints/wh_1/deliveries' => ['items' => [deliveryPayload()], 'total' => 1, 'page' => 1, 'page_size' => 20, 'has_more' => false],
    ]);

    $this->artisan('sendseven:webhooks:deliveries', ['id' => 'wh_1', '--status' => 'failed'])
        ->expectsOutputToContain('Server error')
        ->assertSuccessful();

    $fake->assertSent('GET /webhook-endpoints/wh_1/deliveries', fn (Request $request): bool => $request->query['status'] === 'failed');
});

it('re-sends a delivery', function (): void {
    $fake = SendSeven::fake([
        'GET /webhook-endpoints/wh_1' => webhookEndpointPayload(),
        'POST /webhook-endpoints/wh_1/deliveries/dlv_1/retry' => ['success' => true, 'delivery_id' => 'dlv_2', 'message' => 'Queued.', 'previous_delivery_id' => 'dlv_1'],
    ]);

    $this->artisan('sendseven:webhooks:retry', ['delivery' => 'dlv_1', 'id' => 'wh_1'])
        ->expectsOutputToContain('dlv_2')
        ->assertSuccessful();

    $fake->assertSent('POST /webhook-endpoints/wh_1/deliveries/dlv_1/retry');
});

it('reports a test event the app did not accept', function (): void {
    SendSeven::fake([
        'GET /webhook-endpoints/wh_1' => webhookEndpointPayload(),
        'POST /webhook-endpoints/wh_1/test' => ['success' => false, 'delivery_id' => 'dlv_9', 'response_status_code' => 403, 'response_time_ms' => 30, 'error' => 'Forbidden', 'message' => 'Failed'],
    ]);

    $this->artisan('sendseven:webhooks:test', ['id' => 'wh_1'])
        ->expectsOutputToContain('HTTP 403')
        ->assertFailed();
});

it('verifies an unverified endpoint before activating it', function (): void {
    $fake = SendSeven::fake([
        'GET /webhook-endpoints/wh_1' => webhookEndpointPayload(['is_verified' => false, 'is_active' => false]),
        'POST /webhook-endpoints/wh_1/verify' => ['webhook_id' => 'wh_1', 'is_verified' => true, 'verification_pending' => false, 'verification_expires_at' => null, 'message' => 'Verified'],
        'PATCH /webhook-endpoints/wh_1' => webhookEndpointPayload(),
    ]);

    $this->artisan('sendseven:webhooks:activate', ['id' => 'wh_1'])
        ->expectsOutputToContain('is active')
        ->assertSuccessful();

    $fake->assertSent('PATCH /webhook-endpoints/wh_1', fn (Request $request): bool => $request->body === ['is_active' => true]);
});

it('does not activate an endpoint SendSeven cannot verify', function (): void {
    $fake = SendSeven::fake([
        'GET /webhook-endpoints/wh_1' => webhookEndpointPayload(['is_verified' => false]),
        'POST /webhook-endpoints/wh_1/verify' => ['webhook_id' => 'wh_1', 'is_verified' => false, 'verification_pending' => true, 'verification_expires_at' => null, 'message' => 'No challenge response.'],
    ]);

    $this->artisan('sendseven:webhooks:activate', ['id' => 'wh_1'])
        ->expectsOutputToContain('No challenge response.')
        ->assertFailed();

    $fake->assertNotSent('PATCH *');
});

it('changes only what is passed', function (): void {
    $fake = SendSeven::fake([
        'GET /webhook-endpoints/wh_1' => webhookEndpointPayload(),
        'PATCH /webhook-endpoints/wh_1' => webhookEndpointPayload(['url' => 'https://new.test/hook']),
    ]);

    $this->artisan('sendseven:webhooks:update', ['id' => 'wh_1', '--url' => 'https://new.test/hook'])->assertSuccessful();

    $fake->assertSent('PATCH /webhook-endpoints/wh_1', fn (Request $request): bool => $request->body === ['url' => 'https://new.test/hook']);
});

it('refuses an update with nothing to change', function (): void {
    $fake = SendSeven::fake();

    $this->artisan('sendseven:webhooks:update', ['id' => 'wh_1'])->assertFailed();

    $fake->assertNothingSent();
});

it('rotates the secret only once confirmed, and prints the new one', function (): void {
    $fake = SendSeven::fake([
        'GET /webhook-endpoints/wh_1' => webhookEndpointPayload(),
        'POST /webhook-endpoints/wh_1/regenerate-secret' => ['webhook_id' => 'wh_1', 'secret_key' => 'whsec_rotated', 'message' => null],
    ]);

    $this->artisan('sendseven:webhooks:rotate', ['id' => 'wh_1'])
        ->expectsConfirmation('SendSeven signs with the new secret straight away, so deliveries to '.url('webhooks/sendseven').' are rejected until the app has it. Rotate now?', 'no')
        ->assertFailed();

    $fake->assertNotSent('POST /webhook-endpoints/wh_1/regenerate-secret');

    $this->artisan('sendseven:webhooks:rotate', ['id' => 'wh_1', '--force' => true])
        ->expectsOutputToContain('SENDSEVEN_WEBHOOK_SECRET=whsec_rotated')
        ->assertSuccessful();
});

it('deletes an endpoint with --force', function (): void {
    $fake = SendSeven::fake([
        'GET /webhook-endpoints/wh_1' => webhookEndpointPayload(),
        'DELETE /webhook-endpoints/wh_1' => new Response(204),
    ]);

    $this->artisan('sendseven:webhooks:delete', ['id' => 'wh_1', '--force' => true])->assertSuccessful();

    $fake->assertSent('DELETE /webhook-endpoints/wh_1');
});

it('reports SendSeven refusing the call', function (): void {
    SendSeven::fake(['GET /webhook-endpoints/wh_missing' => new Response(404, '{"detail":"Webhook endpoint not found"}')]);

    $this->artisan('sendseven:webhooks:show', ['id' => 'wh_missing'])
        ->expectsOutputToContain('Webhook endpoint not found')
        ->assertFailed();
});
