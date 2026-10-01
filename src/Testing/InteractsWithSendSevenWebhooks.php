<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Testing;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Reshapify\SendSeven\Webhooks\Webhook;

/**
 * For feature tests of webhook handling: posts a delivery signed exactly as
 * SendSeven signs it.
 *
 *     $this->postSendSevenWebhook('/webhooks/sendseven', $this->sendSevenWebhookPayload('message.received', [
 *         'message' => ['id' => 'msg_1', 'direction' => 'inbound', 'message_type' => 'text', 'text' => 'Hi', 'from_id' => '+4915112345678'],
 *     ]))->assertOk();
 *
 * @mixin \Illuminate\Foundation\Testing\TestCase
 */
trait InteractsWithSendSevenWebhooks
{
    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postSendSevenWebhook(string $uri, array $payload, ?string $secret = null): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $configured = config('sendseven.webhooks.secret');
        $secret ??= is_string($configured) ? $configured : '';

        $headers = [...Webhook::sign($body, $secret), 'Content-Type' => 'application/json', 'Accept' => 'application/json'];

        return $this->call('POST', $uri, [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    /**
     * A webhook envelope: a fresh event ID, the type and its data object.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function sendSevenWebhookPayload(string $type, array $data, ?string $tenantId = null): array
    {
        return [
            'id' => 'evt_'.Str::random(16),
            'type' => $type,
            'event_id' => null,
            'created_at' => now()->toIso8601String(),
            'tenant_id' => $tenantId,
            'data' => $data,
        ];
    }
}
