<?php

namespace Ernestdefoe\Phrasebook\Provider;

/**
 * One batch of strings to translate and how. Items are
 * [id => ['key' => ..., 'text' => English ICU, 'feedback' => why the last try was rejected|null]].
 */
final class Job
{
    /**
     * @param array<string, array{key: string, text: string, feedback: string|null}> $items
     * @param list<array{0: string, 1: string}> $glossary [English term, translation]
     */
    public function __construct(
        public readonly array $items,
        public readonly string $locale,
        public readonly string $language,
        public readonly string $model,
        public readonly string $tone,
        public readonly string $notes,
        public readonly array $glossary,
        public readonly int $maxTokens,
        public readonly string $effort = 'low',
        /** Provider state kept on the run between batches (a DeepL glossary id). */
        public array $state = []
    ) {
    }
}
