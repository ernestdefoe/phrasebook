<?php

namespace Ernestdefoe\Phrasebook\Provider;

use RuntimeException;

/**
 * A call the provider answered (and billed) whose answer can't be used: cut
 * off, declined or not JSON. Carries what it cost so the budget still counts it.
 */
final class BatchFailed extends RuntimeException
{
    public function __construct(public readonly ProviderError $error, public readonly Usage $usage)
    {
        parent::__construct($error->kind);
    }
}
