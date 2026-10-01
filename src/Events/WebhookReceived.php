<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Events;

use Reshapify\SendSeven\Webhooks\Events\Event;

/**
 * Every verified webhook delivery, with the route's parameters (e.g. the
 * workspace in webhooks/sendseven/{workspace}).
 */
final readonly class WebhookReceived
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(public Event $event, public array $parameters = []) {}
}
