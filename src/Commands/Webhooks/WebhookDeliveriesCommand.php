<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Commands\Webhooks;

use BackedEnum;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Reshapify\SendSeven\Client;
use Reshapify\SendSeven\Data\WebhookDelivery;

final class WebhookDeliveriesCommand extends Command
{
    use InteractsWithWebhookEndpoints;

    protected $signature = 'sendseven:webhooks:deliveries
        {id? : The endpoint ID; defaults to the one behind the sendseven.webhooks route}
        {--status= : Only deliveries in this state: failed, dead_letter, retrying, pending, queued or success}
        {--event= : Only this event type, e.g. message.received}
        {--limit=20 : How many to show}';

    protected $description = 'Show recent deliveries to a SendSeven webhook endpoint';

    public function handle(Client $sendseven, Router $router): int
    {
        return $this->attempt(function () use ($sendseven, $router): int {
            $endpoint = $this->endpoint($sendseven, $router, $this->argument('id'));

            if (! $endpoint instanceof \Reshapify\SendSeven\Data\WebhookEndpoint) {
                return self::FAILURE;
            }

            $status = $this->option('status');
            $event = $this->option('event');
            $limit = $this->option('limit');

            $deliveries = $sendseven->webhooks()->getDeliveries(
                webhookId: $endpoint->id,
                pageSize: is_numeric($limit) ? max(1, (int) $limit) : 20,
                status: is_string($status) && $status !== '' ? $status : null,
                eventType: is_string($event) && $event !== '' ? $event : null,
            );

            if ($deliveries->items === []) {
                $this->components->info('No deliveries match.');

                return self::SUCCESS;
            }

            $this->table(['Delivery', 'When', 'Event', 'Status', 'HTTP', 'Attempt', 'Error'], array_map(static fn (WebhookDelivery $delivery): array => [
                $delivery->id,
                $delivery->createdAt,
                $delivery->eventType,
                $delivery->status instanceof BackedEnum ? $delivery->status->value : $delivery->status,
                (string) ($delivery->responseStatusCode ?? ''),
                (string) $delivery->attemptNumber,
                $delivery->errorMessage ?? '',
            ], $deliveries->items));

            $this->line('Showing '.count($deliveries->items)." of {$deliveries->total}. Re-send one with php artisan sendseven:webhooks:retry <delivery>.");

            return self::SUCCESS;
        });
    }
}
