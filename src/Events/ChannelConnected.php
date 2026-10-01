<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel\Events;

use Reshapify\SendSeven\Webhooks\Data\Channel;
use Reshapify\SendSeven\Webhooks\Events\ChannelEvent;

/**
 * A channel was created: usually a customer finishing a connect link. Tie it
 * back to them with $event->connectLinkId(), the ID SendSeven::connect()
 * returned.
 */
final readonly class ChannelConnected
{
    public Channel $channel;

    /**
     * @param  array<string, mixed>  $parameters  the webhook route's parameters
     */
    public function __construct(public ChannelEvent $event, public array $parameters = [])
    {
        $this->channel = $event->channel;
    }

    /**
     * The connect link the channel was created through, if any.
     */
    public function connectLinkId(): ?string
    {
        return $this->channel->createdViaConnectTokenId;
    }
}
