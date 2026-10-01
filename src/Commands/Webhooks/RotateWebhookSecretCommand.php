<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Commands\Webhooks;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Reshapify\SendSeven\Client;

final class RotateWebhookSecretCommand extends Command
{
    use InteractsWithWebhookEndpoints;

    protected $signature = 'sendseven:webhooks:rotate
        {id? : The endpoint ID; defaults to the one behind the sendseven.webhooks route}
        {--force : Skip the confirmation}';

    protected $description = "Replace a SendSeven webhook endpoint's signing secret";

    public function handle(Client $sendseven, Router $router): int
    {
        return $this->attempt(function () use ($sendseven, $router): int {
            $endpoint = $this->endpoint($sendseven, $router, $this->argument('id'));

            if (! $endpoint instanceof \Reshapify\SendSeven\Data\WebhookEndpoint) {
                return self::FAILURE;
            }

            if (! $this->option('force') && ! $this->confirm("SendSeven signs with the new secret straight away, so deliveries to {$endpoint->url} are rejected until the app has it. Rotate now?")) {
                return self::FAILURE;
            }

            $secret = $sendseven->webhooks()->regenerateSecret($endpoint->id);

            $this->components->info("New secret for {$endpoint->url}. Deploy it now; it is shown only once:");
            $this->line("SENDSEVEN_WEBHOOK_SECRET={$secret->secretKey}");
            $this->newLine();
            $this->line('Deliveries rejected in the meantime show as failed in php artisan sendseven:webhooks:deliveries --status=failed and can be re-sent with sendseven:webhooks:retry.');

            return self::SUCCESS;
        });
    }
}
