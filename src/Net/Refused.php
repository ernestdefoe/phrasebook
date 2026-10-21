<?php

namespace Ernestdefoe\Phrasebook\Net;

use RuntimeException;

/**
 * A URL Phrasebook will not call, or a call that never got an HTTP answer.
 * The reason is a short code translated in the admin page; the message never
 * carries a URL, header or key.
 */
final class Refused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
