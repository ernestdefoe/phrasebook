<?php

namespace Ernestdefoe\Phrasebook\Provider;

use RuntimeException;

/**
 * A provider call that failed. The code decides what the run does next:
 *
 *  - auth, quota: stop the run until the admin fixes the key or the plan;
 *  - rate_limit, overloaded, server, network: wait (retryAfter) and try again;
 *  - too_large: the batch was too big for the model; split it;
 *  - refused, bad_request, invalid_response: split down to one string, then
 *    mark that string failed so the rest of the run carries on.
 *
 * The detail is the provider's own error text, shortened and stripped of
 * anything that looks like a key, for the admin to read. Never the request.
 */
final class ProviderError extends RuntimeException
{
    public function __construct(
        public readonly string $kind,
        public readonly int $status = 0,
        public readonly ?int $retryAfter = null,
        public readonly string $detail = ''
    ) {
        parent::__construct($kind);
    }

    public function retryable(): bool
    {
        return in_array($this->kind, ['rate_limit', 'overloaded', 'server', 'network'], true);
    }

    public function fatal(): bool
    {
        return in_array($this->kind, ['auth', 'quota', 'model', 'config'], true);
    }
}
