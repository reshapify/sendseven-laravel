<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Reshapify\SendSeven\Laravel\SendSevenServiceProvider;
use Reshapify\SendSeven\Laravel\Testing\InteractsWithSendSevenWebhooks;

abstract class TestCase extends Orchestra
{
    use InteractsWithSendSevenWebhooks;

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SendSevenServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('sendseven.token', 's7_api_test');
        $app['config']->set('sendseven.webhooks.secret', 'whsec_test');
        $app['config']->set('cache.default', 'array');
    }
}
