<?php

declare(strict_types=1);

use Reshapify\SendSeven\Laravel\Facades\SendSeven;
use Reshapify\SendSeven\Laravel\Tests\TestCase;
use Reshapify\SendSeven\Testing\Fake;

uses(TestCase::class)->in(__DIR__);

/**
 * Shapes captured from the live API on 1 Oct 2026, with IDs replaced.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tenantPayload(array $overrides = []): array
{
    return [
        'id' => 'tenant_shift', 'name' => 'Shift Workspace', 'slug' => 'shift', 'billing_account_id' => 'ba_1', 'pricing_model_id' => null,
        'is_trial' => false, 'trial_ends_at' => null, 'is_active' => true, 'subscription_tier' => 'professional', 'multi_agent_mode' => true,
        'auto_summarize_on_close' => 'ask', 'auto_summarize_live_chat' => false, 'allow_messaging_other_agents_conversations' => true,
        'sms_enabled' => true, 'rcs_enabled' => false, 'created_at' => '2026-09-09T20:45:11', ...$overrides,
    ];
}

/**
 * @return array<string, mixed>
 */
function featuresPayload(bool $multiTenant, string $package = 'API_ONLY'): array
{
    return [
        'package_type' => $package, 'is_api_only' => true, 'ai_enabled' => true, 'is_trial' => false, 'subscription_status' => 'active',
        'ui_features' => ['multi_tenant' => $multiTenant, 'browser_push' => true],
        'api_features' => ['api_messages' => true],
        'disabled_features' => $multiTenant ? [] : ['multi_tenant'],
        'pricing_model' => ['name' => 'API Only', 'cost_message_millicents' => 400],
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function webhookEndpointPayload(array $overrides = []): array
{
    return [
        'id' => 'wh_1', 'tenant_id' => 'tenant_shift', 'name' => 'localhost', 'url' => url('webhooks/sendseven'),
        'has_authorization_header' => false, 'subscribed_events' => ['message.received'], 'source_filter_mode' => null,
        'filtered_channel_ids' => [], 'filtered_email_integration_ids' => [], 'is_active' => true, 'is_verified' => true,
        'verification_method' => 'challenge', 'retry_strategy' => 'exponential', 'max_retries' => 5, 'timeout_seconds' => 30,
        'last_success_at' => null, 'last_failure_at' => null, 'last_error' => null, 'consecutive_failures' => 0, 'suspended_at' => null,
        'next_reactivation_at' => null, 'reactivation_pending_at' => null, 'incident_started_at' => null, 'queued_events_count' => 0,
        'queued_events_dropped' => 0, 'created_at' => '2026-10-01T09:00:00Z', 'updated_at' => null, 'created_by' => null, ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $responses  extra or overriding scripted responses
 */
function accountFake(bool $multiTenant = true, array $responses = []): Fake
{
    return SendSeven::fake([
        'GET /permissions/me/scopes' => ['tenant_id' => 'tenant_shift', 'scopes' => ['*:*'], 'total' => 1],
        'GET /tenants/me' => [tenantPayload()],
        'GET /tenants/features' => featuresPayload($multiTenant, $multiTenant ? 'API_ONLY' : 'BASIC'),
        'GET /webhook-endpoints' => [webhookEndpointPayload()],
        ...$responses,
    ]);
}
