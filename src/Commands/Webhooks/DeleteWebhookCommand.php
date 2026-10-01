<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Commands\Webhooks;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Reshapify\SendSeven\Client;

final class DeleteWebhookCommand extends Command
{
    use InteractsWithWebhookEndpoints;

    protected $signature = 'sendseven:webhooks:delete
        {id? : The endpoint ID; defaults to the one behind the sendseven.webhooks route}
        {--force : Skip the confirmation}';

    protected $description = 'Delete a SendSeven webhook endpoint';

    public function handle(Client $sendseven, Router $router): int
    {
        return $this->attempt(function () use ($sendseven, $router): int {
            $endpoint = $this->endpoint($sendseven, $router, $this->argument('id'));

            if (! $endpoint instanceof \Reshapify\SendSeven\Data\WebhookEndpoint) {
                return self::FAILURE;
            }

            if (! $this->option('force') && ! $this->confirm("Delete the endpoint for {$endpoint->url}? SendSeven stops delivering to it.")) {
                return self::FAILURE;
            }

            $sendseven->webhooks()->deleteEndpoint($endpoint->id);

            $this->components->info("Deleted {$endpoint->id} ({$endpoint->url}).");

            return self::SUCCESS;
        });
    }
}
