<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Webhooks;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Reshapify\SendSeven\Laravel\Events\ChannelConnected;
use Reshapify\SendSeven\Laravel\Events\WebhookReceived;
use Reshapify\SendSeven\Webhooks\Events\ChannelEvent;
use Reshapify\SendSeven\Webhooks\Events\Event;
use Reshapify\SendSeven\Webhooks\EventType;

/**
 * Turns a verified delivery into Laravel events, once per event ID:
 *
 * - WebhookReceived, with the route parameters, for every delivery;
 * - the SDK's typed event itself (MessageReceived, MessageStatusUpdated,
 *   ChannelEvent…), so listeners just type-hint the class they want;
 * - ChannelConnected when a channel is created.
 */
final readonly class WebhookController
{
    public function __construct(private Dispatcher $events, private CacheFactory $cache, private Config $config) {}

    public function __invoke(Request $request): JsonResponse
    {
        $event = VerifyWebhookSignature::event($request);

        if ($this->isRedelivery($event)) {
            return new JsonResponse(['received' => true, 'duplicate' => true]);
        }

        /** @var array<string, mixed> $parameters */
        $parameters = $request->route()?->parameters() ?? [];

        $this->events->dispatch(new WebhookReceived($event, $parameters));
        $this->events->dispatch($event);

        if ($event instanceof ChannelEvent && $event->type === EventType::ChannelCreated) {
            $this->events->dispatch(new ChannelConnected($event, $parameters));
        }

        return new JsonResponse(['received' => true]);
    }

    private function isRedelivery(Event $event): bool
    {
        $seconds = $this->config->get('sendseven.webhooks.deduplicate_for_seconds', 86400);

        if (! is_numeric($seconds) || (int) $seconds <= 0) {
            return false;
        }

        $store = $this->config->get('sendseven.webhooks.cache_store');

        return ! $this->cache->store(is_string($store) ? $store : null)
            ->add("sendseven:webhooks:{$event->id}", true, (int) $seconds);
    }
}
