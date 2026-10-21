<?php

namespace Ernestdefoe\Phrasebook\Settings;

use Ernestdefoe\Phrasebook\Provider\Catalog;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Phrasebook's settings, all admin-only and none of them secret (API keys
 * are in the Vault). JSON values are decoded defensively: a hand-edited row
 * reads as empty rather than breaking the page.
 */
class Settings
{
    public const PREFIX = 'ernestdefoe-phrasebook.';

    public const DEFAULTS = [
        'provider' => 'anthropic',
        'models' => '{"anthropic":"claude-opus-5-5","deepl":"quality_optimized","google":"nmt"}',
        'base_urls' => '{"ollama":"http://localhost:11434/v1","lmstudio":"http://localhost:1234/v1"}',
        'allow_local' => '0',
        'effort' => 'low',
        'prices' => '{}',
        'style' => '{}',
        'budget' => '5',
        'batch_size' => '25',
    ];

    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    public function provider(): string
    {
        $id = (string) $this->settings->get(self::PREFIX.'provider');

        return Catalog::exists($id) ? $id : 'anthropic';
    }

    public function model(string $provider): string
    {
        return (string) ($this->json('models')[$provider] ?? '');
    }

    public function baseUrl(string $provider): string
    {
        $def = Catalog::get($provider);

        if (! $def || ! $def['editable']) {
            return (string) ($def['base'] ?? '');
        }

        return trim((string) ($this->json('base_urls')[$provider] ?? $def['base']));
    }

    public function allowLocal(): bool
    {
        return (bool) $this->settings->get(self::PREFIX.'allow_local');
    }

    public function effort(): string
    {
        $effort = (string) $this->settings->get(self::PREFIX.'effort');

        return in_array($effort, ['low', 'medium', 'high'], true) ? $effort : 'low';
    }

    public function batchSize(): int
    {
        return max(1, min(100, (int) $this->settings->get(self::PREFIX.'batch_size')));
    }

    public function defaultBudget(): float
    {
        return max(0, (float) $this->settings->get(self::PREFIX.'budget'));
    }

    /**
     * The admin's own prices for a model, overriding any built in.
     *
     * @return array{in?: float, out?: float, char?: float}|null
     */
    public function price(string $provider, string $model): ?array
    {
        $row = $this->json('prices')["$provider|$model"] ?? null;

        if (! is_array($row)) {
            return null;
        }

        $out = [];
        foreach (['in', 'out', 'char'] as $field) {
            if (isset($row[$field]) && is_numeric($row[$field]) && $row[$field] >= 0) {
                $out[$field] = (float) $row[$field];
            }
        }

        return $out ?: null;
    }

    /**
     * Tone, notes and glossary remembered per target locale.
     *
     * @return array{tone: string, notes: string, glossary: string}
     */
    public function style(string $locale): array
    {
        $row = $this->json('style')[$locale] ?? [];
        $row = is_array($row) ? $row : [];

        return [
            'tone' => in_array($row['tone'] ?? '', ['formal', 'informal', 'neutral'], true) ? $row['tone'] : 'neutral',
            'notes' => is_string($row['notes'] ?? null) ? $row['notes'] : '',
            'glossary' => is_string($row['glossary'] ?? null) ? $row['glossary'] : '',
        ];
    }

    /**
     * @param array{tone: string, notes: string, glossary: string} $style
     */
    public function saveStyle(string $locale, array $style): void
    {
        $all = $this->json('style');
        $all[$locale] = $style;
        $this->settings->set(self::PREFIX.'style', json_encode($all, JSON_UNESCAPED_UNICODE));
    }

    /**
     * "term = translation" lines into pairs. A line without "=" keeps the
     * term untranslated (brand names).
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function glossary(string $text): array
    {
        $pairs = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$term, $translation] = array_pad(array_map('trim', explode('=', $line, 2)), 2, null);
            if ($term !== '') {
                $pairs[] = [mb_substr($term, 0, 100), mb_substr((string) ($translation ?: $term), 0, 100)];
            }
            if (count($pairs) >= 200) {
                break;
            }
        }

        return $pairs;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $key): array
    {
        $value = json_decode((string) $this->settings->get(self::PREFIX.$key), true);

        return is_array($value) ? $value : [];
    }
}
