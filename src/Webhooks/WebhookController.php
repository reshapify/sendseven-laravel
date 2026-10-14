<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Webhooks;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Reshapify\SendSeven\Laravel\Events\ChannelConnected;
use Reshapify\SendSeven\Laravel\Events\WebhookReceived;
use Reshapify\SendSeven\Webhooks\Events\ChannelEvent;
use Reshapify\SendSeven\Webhooks\Events\Event;
use Reshapify\SendSeven\Webhooks\Events\MessageReceived;
use Reshapify\SendSeven\Webhooks\EventType;
use Throwable;

/**
 * Turns a verified delivery into Laravel events, once per event ID (and,
 * for received messages, once per message ID, since SendSeven can send the
 * same message under two event IDs):
 *
 * - WebhookReceived, with the route parameters, for every delivery;
 * - the SDK's typed event itself (MessageReceived, MessageStatusUpdated,
 *   ChannelEvent…), so listeners just type-hint the class they want;
 * - ChannelConnected when a channel is created.
 *
 * If a listener throws, the event is forgotten and the error answered, so
 * SendSeven's redelivery is handled rather than skipped as a duplicate.
 *
 * Dependencies are injected per call: the router keeps controller instances,
 * so under Octane a constructor would hold the first request's services.
 */
final readonly class WebhookController
{
    /**
     * @throws Throwable from a listener, after forgetting the event
     */
    public function __invoke(Request $request, Dispatcher $events, CacheFactory $cache, Config $config): JsonResponse
    {
        $event = VerifyWebhookSignature::event($request);
        $deduplication = $this->deduplication($cache, $config, $event);
        $claimed = [];

        if ($deduplication !== null) {
            foreach ($deduplication['keys'] as $key) {
                if (! $deduplication['store']->add($key, true, $deduplication['seconds'])) {
                    $this->forget($deduplication['store'], $claimed);

                    return new JsonResponse(['received' => true, 'duplicate' => true]);
                }

                $claimed[] = $key;
            }
        }

        /** @var array<string, mixed> $parameters */
        $parameters = $request->route()?->parameters() ?? [];

        try {
            $events->dispatch(new WebhookReceived($event, $parameters));
            $events->dispatch($event);

            if ($event instanceof ChannelEvent && $event->type === EventType::ChannelCreated) {
                $events->dispatch(new ChannelConnected($event, $parameters));
            }
        } catch (Throwable $throwable) {
            $this->forget($deduplication['store'] ?? null, $claimed);

            throw $throwable;
        }

        return new JsonResponse(['received' => true]);
    }

    /**
     * @param  list<string>  $keys
     */
    private function forget(?Repository $store, array $keys): void
    {
        foreach ($keys as $key) {
            $store?->forget($key);
        }
    }

    /**
     * @return array{store: Repository, keys: list<string>, seconds: int}|null
     */
    private function deduplication(CacheFactory $cache, Config $config, Event $event): ?array
    {
        $seconds = $config->get('sendseven.webhooks.deduplicate_for_seconds', 86400);

        if (! is_numeric($seconds) || (int) $seconds <= 0) {
            return null;
        }

        $store = $config->get('sendseven.webhooks.cache_store');
        $keys = ["sendseven:webhooks:{$event->id}"];

        if ($event instanceof MessageReceived && $event->message->id !== '') {
            $keys[] = "sendseven:webhooks:message:{$event->message->id}";
        }

        return [
            'store' => $cache->store(is_string($store) ? $store : null),
            'keys' => $keys,
            'seconds' => (int) $seconds,
        ];
    }
}
