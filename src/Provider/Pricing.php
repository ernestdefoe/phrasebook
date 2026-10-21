<?php

namespace Ernestdefoe\Phrasebook\Provider;

use Ernestdefoe\Phrasebook\Settings\Settings;
use Ernestdefoe\Phrasebook\Settings\Vault;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * What a model costs, in USD: per million tokens in and out for language
 * models, per million characters for DeepL and Google.
 *
 * In order: the admin's own price for the model; the price OpenRouter lists
 * for it; Anthropic's published prices; DeepL's and Google's published
 * per-character prices; zero for models on the forum's own machine. Other
 * providers change prices too often to build in, so the admin enters them
 * (the settings page links the provider's price list), and a run won't start
 * without a price: the budget cap can't hold without one.
 */
class Pricing
{
    /** USD per million tokens, from Anthropic's price list (2026-10). */
    private const ANTHROPIC = [
        'claude-fable-5-1' => [10.0, 50.0],
        'claude-mythos-5-1' => [10.0, 50.0],
        'claude-fable-5' => [10.0, 50.0],
        'claude-opus-5-5' => [4.0, 20.0],
        'claude-opus-5' => [5.0, 25.0],
        'claude-opus-4-8' => [5.0, 25.0],
        'claude-opus-4-7' => [5.0, 25.0],
        'claude-opus-4-6' => [5.0, 25.0],
        'claude-sonnet-5-5' => [2.0, 10.0],
        'claude-sonnet-5' => [2.0, 10.0],
        'claude-sonnet-4-6' => [3.0, 15.0],
        'claude-haiku-5-5' => [0.10, 0.50],
        'claude-haiku-4-5' => [1.0, 5.0],
    ];

    /** USD per million characters. DeepL API Pro; Google Translation Basic. */
    private const PER_CHARACTER = ['deepl' => 25.0, 'google' => 20.0];

    public function __construct(private readonly Settings $settings, private readonly Cache $cache, private readonly Vault $vault)
    {
    }

    /**
     * @return array{in?: float, out?: float, char?: float, source: string}|null null when unknown
     */
    public function for(string $provider, string $model): ?array
    {
        $own = $this->settings->price($provider, $model);
        $def = Catalog::get($provider);

        if ($def && $def['kind'] === 'mt') {
            if (isset($own['char'])) {
                return ['char' => $own['char'], 'source' => 'admin'];
            }

            // DeepL's free plan costs nothing (within its monthly allowance).
            if ($provider === 'deepl' && $this->freeDeepL()) {
                return ['char' => 0.0, 'source' => 'free'];
            }

            return ['char' => self::PER_CHARACTER[$provider], 'source' => 'built_in'];
        }

        if (isset($own['in'], $own['out'])) {
            return ['in' => $own['in'], 'out' => $own['out'], 'source' => 'admin'];
        }

        if ($provider === 'openrouter') {
            $listed = $this->cache->get(self::listedKey($model));
            if (is_array($listed) && isset($listed['in'], $listed['out'])) {
                return ['in' => (float) $listed['in'], 'out' => (float) $listed['out'], 'source' => 'listed'];
            }
        }

        if ($provider === 'anthropic') {
            // Dated snapshots ("claude-haiku-4-5-20251001") price as their family.
            foreach (self::ANTHROPIC as $id => [$in, $out]) {
                if ($model === $id || str_starts_with($model, $id.'-2')) {
                    return ['in' => $in, 'out' => $out, 'source' => 'built_in'];
                }
            }
        }

        if (! empty($def['local'])) {
            return ['in' => 0.0, 'out' => 0.0, 'source' => 'local'];
        }

        return null;
    }

    /**
     * Cost of what a call consumed. Anthropic-style cache writes cost 1.25×
     * input and reads 0.1×; OpenAI-style cached tokens are already inside
     * the input count and are charged as input (a little high, never low).
     *
     * @param array{in?: float, out?: float, char?: float} $price
     */
    public static function cost(array $price, Usage $usage): float
    {
        if ($usage->cost !== null) {
            return $usage->cost;
        }

        if (isset($price['char'])) {
            return $usage->characters * $price['char'] / 1e6;
        }

        $in = $price['in'] ?? 0.0;

        return ($usage->input * $in
            + $usage->cacheWrite * $in * 1.25
            + $usage->cacheRead * $in * 0.1
            + $usage->output * ($price['out'] ?? 0.0)) / 1e6;
    }

    /**
     * Remembers OpenRouter's listed prices, read from its model list.
     *
     * @param list<array{id: string, label: string, price?: array{in: float, out: float}}> $models
     */
    public function remember(array $models): void
    {
        foreach ($models as $model) {
            if (isset($model['price'])) {
                $this->cache->put(self::listedKey($model['id']), $model['price'], 86400 * 7);
            }
        }
    }

    private static function listedKey(string $model): string
    {
        return 'phrasebook.price.openrouter.'.sha1($model);
    }

    private function freeDeepL(): bool
    {
        return str_ends_with($this->vault->hints()['deepl'] ?? '', ':fx');
    }
}
