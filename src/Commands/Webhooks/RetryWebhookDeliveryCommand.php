<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Commands\Webhooks;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Reshapify\SendSeven\Client;

final class RetryWebhookDeliveryCommand extends Command
{
    use InteractsWithWebhookEndpoints;

    protected $signature = 'sendseven:webhooks:retry
        {delivery : The delivery ID, from sendseven:webhooks:deliveries}
        {id? : The endpoint ID; defaults to the one behind the sendseven.webhooks route}';

    protected $description = 'Re-send a SendSeven webhook delivery';

    public function handle(Client $sendseven, Router $router): int
    {
        return $this->attempt(function () use ($sendseven, $router): int {
            $endpoint = $this->endpoint($sendseven, $router, $this->argument('id'));

            if (! $endpoint instanceof \Reshapify\SendSeven\Data\WebhookEndpoint) {
                return self::FAILURE;
            }

            $retry = $sendseven->webhooks()->retryDelivery($endpoint->id, $this->argument('delivery'));

            if (! $retry->success) {
                $this->components->error($retry->message);

                return self::FAILURE;
            }

            $this->components->info("Re-sent as delivery {$retry->deliveryId}. {$retry->message}");

            return self::SUCCESS;
        });
    }
}
