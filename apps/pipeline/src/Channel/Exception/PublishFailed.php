<?php

declare(strict_types=1);

namespace App\Channel\Exception;

/**
 * Thrown by a ChannelPublisher when a publish attempt does not succeed.
 *
 * Covers every failure mode of HttpChannelPublisher: an unmet precondition
 * (missing affiliate link or price), a non-2xx gateway response, or a transport
 * error reaching the gateway. The controller catches it, flashes the message,
 * and leaves the deal unpublished — no posted_deal row, no publish-requested mark.
 */
final class PublishFailed extends \RuntimeException
{
}
