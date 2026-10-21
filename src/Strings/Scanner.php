<?php

namespace Ernestdefoe\Phrasebook\Strings;

use Ernestdefoe\Phrasebook\Icu\Validator;
use Flarum\Extension\ExtensionManager;
use Flarum\Locale\LocaleManager;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;

/**
 * Compares a locale with English, string by string, across core and every
 * enabled extension.
 *
 *  - missing: English has it, the locale doesn't;
 *  - stale: the locale has it, but either the English changed since
 *    Phrasebook saved it (ledger), or it no longer fits the English: a
 *    placeholder, tag or select branch the English now has is missing, or
 *    one it no longer has is still there. These break or mislead today;
 *  - identical: the same text as English (often untranslated, sometimes
 *    rightly so: "OK", brand names), only when asked for.
 *
 * "Has it" counts the language pack, Linguist's overrides (read from its
 * table, so ones saved a moment ago count before the cache is cleared) and
 * nothing else.
 */
class Scanner
{
    public const REASONS = ['missing', 'stale', 'identical'];

    public function __construct(
        private readonly LocaleManager $locales,
        private readonly ConnectionInterface $db,
        private readonly ExtensionManager $extensions
    ) {
    }

    /**
     * @return array<string, string> key => English, references resolved
     */
    public function english(): array
    {
        $strings = [];

        foreach (Arr::get($this->locales->getTranslator()->getCatalogue('en')->all(), 'messages', []) as $key => $value) {
            if (is_string($key) && is_string($value) && trim($value) !== '' && str_contains($key, '.')) {
                $strings[$key] = $value;
            }
        }

        ksort($strings);

        return $strings;
    }

    /**
     * What the locale has today: its pack plus Linguist's overrides.
     *
     * @return array<string, string>
     */
    public function current(string $locale): array
    {
        $strings = [];

        if ($this->locales->hasLocale($locale)) {
            foreach (Arr::get($this->locales->getTranslator()->getCatalogue($locale)->all(), 'messages', []) as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $strings[$key] = $value;
                }
            }
        }

        if ($this->extensions->isEnabled('fof-linguist')) {
            // Locale-specific overrides win over the all-locales ones.
            $rows = $this->db->table('fof_linguist_strings')
                ->where(fn ($q) => $q->where('locale', $locale)->orWhereNull('locale'))
                ->orderByRaw($this->db->getQueryGrammar()->wrap('locale').' is null desc')
                ->get(['key', 'value']);

            foreach ($rows as $row) {
                $strings[(string) $row->key] = (string) $row->value;
            }
        }

        return $strings;
    }

    /**
     * Every string that needs work, with why.
     *
     * @param list<string> $reasons
     * @param list<string>|null $namespaces null for all
     *
     * @return array<string, array{source: string, current: string|null, reason: string}>
     */
    public function scan(string $locale, array $reasons, ?array $namespaces = null): array
    {
        $english = $this->english();
        $current = $this->current($locale);
        $ledger = $this->db->table('phrasebook_ledger')->where('locale', $locale)->pluck('source_hash', 'key')->all();
        $wanted = $namespaces === null ? null : array_flip($namespaces);
        $out = [];

        foreach ($english as $key => $source) {
            if ($wanted !== null && ! isset($wanted[self::namespace($key)])) {
                continue;
            }

            $have = $current[$key] ?? null;
            $reason = null;

            if ($have === null || trim($have) === '') {
                $reason = 'missing';
            } elseif ((isset($ledger[$key]) && $ledger[$key] !== sha1($source)) || self::outOfStep($source, $have)) {
                $reason = 'stale';
            } elseif ($have === $source && preg_match('/\p{L}{2}/u', $source)) {
                $reason = 'identical';
            }

            if ($reason !== null && in_array($reason, $reasons, true)) {
                $out[$key] = ['source' => $source, 'current' => $have, 'reason' => $reason];
            }
        }

        return $out;
    }

    /**
     * Counts per namespace (core, validation, each extension), for the
     * admin's overview and picker.
     *
     * @return list<array{namespace: string, title: string, total: int, missing: int, stale: int, identical: int}>
     */
    public function summary(string $locale): array
    {
        /** @var array<string, array{namespace: string, title: string, total: int, missing: int, stale: int, identical: int}> $groups */
        $groups = [];

        foreach ($this->english() as $key => $source) {
            $ns = self::namespace($key);
            $groups[$ns] ??= ['namespace' => $ns, 'title' => $this->title($ns), 'total' => 0, 'missing' => 0, 'stale' => 0, 'identical' => 0];
            $groups[$ns]['total']++;
        }

        foreach ($this->scan($locale, self::REASONS) as $key => $row) {
            $ns = self::namespace($key);
            $reason = $row['reason'];
            if (isset($groups[$ns]) && in_array($reason, self::REASONS, true)) {
                $groups[$ns][$reason] = $groups[$ns][$reason] + 1;
            }
        }

        $rank = fn (string $ns): int => $ns === 'core' ? 0 : ($ns === 'validation' ? 1 : 2);
        uasort($groups, fn (array $a, array $b): int => [$rank($a['namespace']), $a['title']] <=> [$rank($b['namespace']), $b['title']]);

        return array_values($groups);
    }

    public static function namespace(string $key): string
    {
        return substr(strstr($key, '.', true) ?: $key, 0, 100);
    }

    /**
     * True when a translation no longer fits its English: the arguments,
     * tags or select branches differ. Its own wording is not judged.
     */
    public static function outOfStep(string $source, string $translation): bool
    {
        foreach (Validator::check($source, $translation) as $error) {
            if (in_array($error['code'], ['syntax', 'argument_missing', 'argument_added', 'argument_type', 'select_missing', 'select_added', 'tag_missing', 'tag_added', 'plural_exact_missing'], true)) {
                return true;
            }
        }

        return false;
    }

    private function title(string $namespace): string
    {
        if ($namespace === 'core') {
            return 'Flarum';
        }

        if ($namespace === 'validation') {
            return 'Validation messages';
        }

        $extension = $this->extensions->getExtension($namespace);

        return $extension ? (string) $extension->getTitle() : $namespace;
    }
}
