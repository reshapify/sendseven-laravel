<?php

declare(strict_types=1);

namespace Reshapify\SendSeven\Laravel;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Reshapify\SendSeven\Data\ChannelConnectTokenCreated;

/**
 * A connect link, ready to send the customer to. Return it from a controller
 * and it redirects to SendSeven's connect page, where Meta's Embedded Signup
 * (or the other platform's sign-in) runs. Inertia requests get a full-page
 * visit, since the page is on another site.
 *
 *     $connection = SendSeven::connect(ConnectLink::for(ChannelType::WhatsApp)->...);
 *     $workspace->update(['connect_link_id' => $connection->link->id]);
 *
 *     return $connection;
 */
final readonly class ConnectRedirect implements Responsable
{
    public function __construct(public ChannelConnectTokenCreated $link) {}

    public function url(): string
    {
        return $this->link->connectUrl;
    }

    /**
     * @param  Request  $request
     */
    public function toResponse($request): RedirectResponse|Response
    {
        if ($request->headers->has('X-Inertia')) {
            return new Response('', 409, ['X-Inertia-Location' => $this->link->connectUrl]);
        }

        return new RedirectResponse($this->link->connectUrl);
    }
}
