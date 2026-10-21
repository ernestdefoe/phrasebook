<?php

namespace Ernestdefoe\Phrasebook\Icu;

use RuntimeException;

/** A message ICU cannot parse. The code is translated in the admin page. */
final class SyntaxError extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $offset)
    {
        parent::__construct($reason);
    }
}
