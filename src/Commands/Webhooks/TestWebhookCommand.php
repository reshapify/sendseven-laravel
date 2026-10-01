<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Commands\Webhooks;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Reshapify\SendSeven\Client;

final class TestWebhookCommand extends Command
{
    use InteractsWithWebhookEndpoints;

    protected $signature = 'sendseven:webhooks:test {id? : The endpoint ID; defaults to the one behind the sendseven.webhooks route}';

    protected $description = 'Have SendSeven send a test event to a webhook endpoint';

    public function handle(Client $sendseven, Router $router): int
    {
        return $this->attempt(function () use ($sendseven, $router): int {
            $endpoint = $this->endpoint($sendseven, $router, $this->argument('id'));

            if (! $endpoint instanceof \Reshapify\SendSeven\Data\WebhookEndpoint) {
                return self::FAILURE;
            }

            $test = $sendseven->webhooks()->sendTest($endpoint->id);
            $answer = $test->responseStatusCode === null ? 'no answer' : "HTTP {$test->responseStatusCode}";

            if (! $test->success) {
                $this->components->error("The test event to {$endpoint->url} failed ({$answer}): ".($test->error ?? $test->message));

                return self::FAILURE;
            }

            $this->components->info("{$endpoint->url} accepted the test event ({$answer}".($test->responseTimeMs === null ? '' : ", {$test->responseTimeMs} ms").').');

            return self::SUCCESS;
        });
    }
}
