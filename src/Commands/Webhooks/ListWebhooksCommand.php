<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Commands\Webhooks;

use Illuminate\Console\Command;
use Reshapify\SendSeven\Client;
use Reshapify\SendSeven\Data\WebhookEndpoint;

final class ListWebhooksCommand extends Command
{
    use InteractsWithWebhookEndpoints;

    protected $signature = 'sendseven:webhooks:list';

    protected $description = 'List SendSeven webhook endpoints and their state';

    public function handle(Client $sendseven): int
    {
        return $this->attempt(function () use ($sendseven): int {
            $endpoints = $sendseven->webhooks()->listEndpoints();

            if ($endpoints === []) {
                $this->components->info('There are no webhook endpoints. Create one with php artisan sendseven:webhooks:register.');

                return self::SUCCESS;
            }

            $this->table(['ID', 'URL', 'State', 'Last success', 'Last error'], array_map(fn (WebhookEndpoint $endpoint): array => [
                $endpoint->id,
                $endpoint->url,
                $this->state($endpoint),
                $endpoint->lastSuccessAt ?? 'never',
                $endpoint->lastError ?? '',
            ], $endpoints));

            return self::SUCCESS;
        });
    }
}
