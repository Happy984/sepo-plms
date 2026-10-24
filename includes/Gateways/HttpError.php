<?php

declare(strict_types=1);

namespace SepoLms\Gateways;

/**
 * A payment service answered with an error, or did not answer at all.
 */
final class HttpError extends \RuntimeException
{
    public function __construct(string $message, private int $httpStatus)
    {
        parent::__construct($message, $httpStatus);
    }

    public function status(): int
    {
        return $this->httpStatus;
    }

    /**
     * True when the service definitely created nothing: a 4xx that is not a
     * timeout, an idempotency conflict or a rate limit. Anything else — a
     * 5xx, a timeout, a dropped connection — means the payment may exist.
     */
    public function isDefinitiveRejection(): bool
    {
        return $this->httpStatus >= 400 && $this->httpStatus < 500
            && !in_array($this->httpStatus, [408, 409, 425, 429], true);
    }
}
