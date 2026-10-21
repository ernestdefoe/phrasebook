<?php

namespace Ernestdefoe\Phrasebook\Provider;

/** What one call consumed, as the provider reported it. */
final class Usage
{
    public function __construct(
        public int $input = 0,
        public int $output = 0,
        public int $cacheWrite = 0,
        public int $cacheRead = 0,
        public int $characters = 0,
        /** Exact cost when the provider reports one (OpenRouter), in USD. */
        public ?float $cost = null,
        public string $model = ''
    ) {
    }
}
