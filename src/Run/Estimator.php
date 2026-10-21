<?php

namespace Ernestdefoe\Phrasebook\Run;

use Ernestdefoe\Phrasebook\Icu\Segmenter;
use Ernestdefoe\Phrasebook\Provider\Job;
use Ernestdefoe\Phrasebook\Provider\Prompt;

/**
 * Token and character estimates: the dry run's cost range, and each batch's
 * worst case, which is what keeps a run under its budget.
 *
 * Tokens are estimated from UTF-8 bytes (about 3.5 per token for English,
 * fewer for other scripts, which byte length already accounts for). The
 * worst case of a batch assumes every output token the request allows is
 * used and the system prompt is written to the cache, so a batch that fits
 * the worst case can never take the run over its budget.
 */
final class Estimator
{
    public static function tokens(string $text): int
    {
        return (int) ceil(strlen($text) / 3.5);
    }

    /** The output allowance for a batch: room for the translations plus thinking. */
    public static function maxTokens(Job $job): int
    {
        $source = 0;
        foreach ($job->items as $item) {
            $source += self::tokens($item['text']) + 12;
        }

        return min(16000, max(4096, $source * 4));
    }

    /**
     * Worst-case cost of sending this batch, in USD.
     *
     * @param array{in?: float, out?: float, char?: float} $price
     */
    public static function worstCase(Job $job, array $price, string $kind): float
    {
        if ($kind === 'mt') {
            return self::characters($job) * ($price['char'] ?? 0) / 1e6;
        }

        $input = self::tokens(Prompt::system($job)) + self::tokens(Prompt::user($job));

        return ($input * 1.25 * ($price['in'] ?? 0) + $job->maxTokens * ($price['out'] ?? 0)) / 1e6;
    }

    /** Characters a machine translation engine will bill for this batch. */
    public static function characters(Job $job): int
    {
        $count = 0;

        foreach ($job->items as $item) {
            $split = Segmenter::split($item['text']);
            foreach ($split['units'] ?? [] as $unit) {
                $count += mb_strlen($unit);
            }
        }

        return $count;
    }

    /**
     * A dry run: what a run over these strings should cost.
     *
     * @param array<string, string> $sources key => English
     * @param array{in?: float, out?: float, char?: float}|null $price
     *
     * @return array{strings: int, batches: int, input_tokens: int, output_tokens: int, characters: int, low: float|null, high: float|null}
     */
    public static function dryRun(array $sources, int $batchSize, string $kind, ?array $price, Job $template): array
    {
        $strings = count($sources);
        $batches = (int) ceil($strings / max(1, $batchSize));
        $result = ['strings' => $strings, 'batches' => $batches, 'input_tokens' => 0, 'output_tokens' => 0, 'characters' => 0, 'low' => null, 'high' => null];

        if ($kind === 'mt') {
            $items = [];
            foreach ($sources as $key => $text) {
                $items[$key] = ['key' => $key, 'text' => $text, 'feedback' => null];
            }
            $chars = self::characters(new Job($items, $template->locale, $template->language, $template->model, $template->tone, $template->notes, $template->glossary, 0));
            $result['characters'] = $chars;

            if ($price !== null) {
                $result['low'] = $result['high'] = round($chars * ($price['char'] ?? 0) / 1e6, 4);
            }

            return $result;
        }

        $system = self::tokens(Prompt::system($template));
        $input = $system * $batches;
        $output = 0;

        foreach ($sources as $key => $text) {
            $input += self::tokens($key.$text) + 12;
            $output += self::tokens($text) + 8;
        }

        $result['input_tokens'] = $input;
        $result['output_tokens'] = $output;

        if ($price !== null) {
            $in = $price['in'] ?? 0;
            $out = $price['out'] ?? 0;
            // Low: translations about as long as the English (the system
            // prompt read from cache after the first batch). High: twice as
            // long in tokens, thinking as long again, and one string in ten
            // retried.
            $low = (($system + ($input - $system * $batches)) * $in + ($system * max(0, $batches - 1)) * $in * 0.1 + $output * 1.2 * $out) / 1e6;
            $high = ($input * 1.25 * $in + $output * 4 * $out) * 1.1 / 1e6;
            $result['low'] = round($low, 4);
            $result['high'] = round($high, 4);
        }

        return $result;
    }
}
