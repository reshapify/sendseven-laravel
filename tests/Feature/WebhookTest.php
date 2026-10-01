<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Reshapify\SendSeven\Laravel\Events\ChannelConnected;
use Reshapify\SendSeven\Laravel\Events\WebhookReceived;
use Reshapify\SendSeven\Laravel\Facades\SendSeven;
use Reshapify\SendSeven\Webhooks\Events\ChannelEvent;
use Reshapify\SendSeven\Webhooks\Events\MessageReceived;
use Reshapify\SendSeven\Webhooks\Events\MessageStatusUpdated;
use Reshapify\SendSeven\Webhooks\Webhook;

/**
 * @return array<string, mixed>
 */
function inboundMessage(): array
{
    return ['message' => ['id' => 'msg_1', 'direction' => 'inbound', 'message_type' => 'text', 'text' => 'Hi', 'from_id' => '+4915112345678']];
}

beforeEach(function (): void {
    Route::middleware('web')->group(function (): void {
        Route::sendSevenWebhooks();
    });
});

it('answers the verification challenge without a signature', function (): void {
    Event::fake();

    $this->postJson('/webhooks/sendseven', ['type' => 'sendseven_verification', 'challenge' => 'abc123'], ['X-SendSeven-Event' => 'verification'])
        ->assertOk()
        ->assertExactJson(['challenge' => 'abc123']);

    Event::assertNotDispatched(WebhookReceived::class);
});

it('dispatches a signed delivery as typed Laravel events', function (): void {
    Event::fake();

    $this->postSendSevenWebhook('/webhooks/sendseven', $this->sendSevenWebhookPayload('message.received', inboundMessage()))
        ->assertOk()
        ->assertJson(['received' => true]);

    Event::assertDispatched(MessageReceived::class, fn (MessageReceived $event): bool => $event->message->fromId === '+4915112345678');
    Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event): bool => $event->event instanceof MessageReceived);
    Event::assertNotDispatched(ChannelConnected::class);
});

it('maps delivery updates to MessageStatusUpdated', function (): void {
    Event::fake();

    $this->postSendSevenWebhook('/webhooks/sendseven', $this->sendSevenWebhookPayload('message.failed', [
        'message' => ['id' => 'msg_2', 'direction' => 'outbound', 'message_type' => 'text', 'meta' => ['error_code' => '131047']],
    ]))->assertOk();

    Event::assertDispatched(MessageStatusUpdated::class, fn (MessageStatusUpdated $event): bool => $event->failed() && $event->message->errorCode() === '131047');
});

it('rejects a delivery signed with the wrong secret', function (): void {
    Event::fake();

    $this->postSendSevenWebhook('/webhooks/sendseven', $this->sendSevenWebhookPayload('message.received', inboundMessage()), secret: 'whsec_wrong')
        ->assertForbidden();

    Event::assertNotDispatched(WebhookReceived::class);
});

it('rejects an unsigned delivery', function (): void {
    $this->postJson('/webhooks/sendseven', $this->sendSevenWebhookPayload('message.received', inboundMessage()))
        ->assertForbidden();
});

it('rejects a replayed delivery whose timestamp is too old', function (): void {
    $body = json_encode($this->sendSevenWebhookPayload('message.received', inboundMessage()), JSON_THROW_ON_ERROR);
    $headers = Webhook::sign($body, 'whsec_test', time() - 600);

    $this->call('POST', '/webhooks/sendseven', [], [], [], $this->transformHeadersToServerVars([...$headers, 'Content-Type' => 'application/json']), $body)
        ->assertForbidden();
});

it('handles a redelivered event only once', function (): void {
    Event::fake();
    $payload = $this->sendSevenWebhookPayload('message.received', inboundMessage());

    $this->postSendSevenWebhook('/webhooks/sendseven', $payload)->assertOk();
    $this->postSendSevenWebhook('/webhooks/sendseven', $payload)->assertOk()->assertJson(['duplicate' => true]);

    Event::assertDispatchedTimes(MessageReceived::class, 1);
});

it('handles redeliveries again when deduplication is off', function (): void {
    Event::fake();
    config()->set('sendseven.webhooks.deduplicate_for_seconds', 0);
    $payload = $this->sendSevenWebhookPayload('message.received', inboundMessage());

    $this->postSendSevenWebhook('/webhooks/sendseven', $payload)->assertOk();
    $this->postSendSevenWebhook('/webhooks/sendseven', $payload)->assertOk();

    Event::assertDispatchedTimes(MessageReceived::class, 2);
});

it('announces a channel connected through a connect link', function (): void {
    Event::fake();

    $this->postSendSevenWebhook('/webhooks/sendseven', $this->sendSevenWebhookPayload('channel.created', [
        'channel' => ['id' => 'ch_1', 'platform' => 'whatsapp', 'is_active' => true, 'created_via_connect_token_id' => 'cct_9a8b7c6d'],
    ]))->assertOk();

    Event::assertDispatched(ChannelConnected::class, fn (ChannelConnected $event): bool => $event->connectLinkId() === 'cct_9a8b7c6d' && $event->channel->id === 'ch_1');
    Event::assertDispatched(ChannelEvent::class);
});

it('checks the static Authorization header when one is configured', function (): void {
    config()->set('sendseven.webhooks.authorization', 'Bearer shared');
    $payload = $this->sendSevenWebhookPayload('message.received', inboundMessage());
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $signed = [...Webhook::sign($body, 'whsec_test'), 'Content-Type' => 'application/json'];

    $this->call('POST', '/webhooks/sendseven', [], [], [], $this->transformHeadersToServerVars($signed), $body)->assertForbidden();
    $this->call('POST', '/webhooks/sendseven', [], [], [], $this->transformHeadersToServerVars([...$signed, 'Authorization' => 'Bearer shared']), $body)->assertOk();
});

it('resolves a secret per endpoint from the route', function (): void {
    Event::fake();
    Route::post('webhooks/sendseven/{workspace}', Reshapify\SendSeven\Laravel\Webhooks\WebhookController::class)
        ->middleware(Reshapify\SendSeven\Laravel\Webhooks\VerifyWebhookSignature::class);
    SendSeven::resolveWebhookSecretUsing(fn (Request $request): ?string => ['acme' => 'whsec_acme'][$request->route('workspace')] ?? null);

    $payload = $this->sendSevenWebhookPayload('message.received', inboundMessage());

    $this->postSendSevenWebhook('/webhooks/sendseven/acme', $payload, secret: 'whsec_acme')->assertOk();
    $this->postSendSevenWebhook('/webhooks/sendseven/unknown', $payload, secret: 'whsec_acme')->assertNotFound();

    Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event): bool => $event->parameters === ['workspace' => 'acme']);
});

it('says how to fix a missing webhook secret', function (): void {
    config()->set('sendseven.webhooks.secret');

    $this->withoutExceptionHandling();

    $this->postSendSevenWebhook('/webhooks/sendseven', $this->sendSevenWebhookPayload('message.received', inboundMessage()), secret: 'anything');
})->throws(Reshapify\SendSeven\Laravel\Exceptions\MissingConfiguration::class, 'SENDSEVEN_WEBHOOK_SECRET');
