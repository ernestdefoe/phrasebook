<?php

namespace Ernestdefoe\Phrasebook\Strings;

/**
 * Locale codes as Flarum language packs write them ("de", "pt_BR",
 * "zh_Hans", "zh-TW"), and what each provider calls the same language.
 */
final class Languages
{
    /** A locale code: language, then an optional script and/or region. */
    public const PATTERN = '/^[a-z]{2,3}(?:[_-][A-Z][a-z]{3})?(?:[_-](?:[A-Z]{2}|\d{3}))?$/';

    public static function valid(string $locale): bool
    {
        return $locale !== 'en' && strlen($locale) <= 10 && preg_match(self::PATTERN, $locale) === 1;
    }

    /** The language's English name, for prompts and the admin page. */
    public static function name(string $locale): string
    {
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayName(str_replace('-', '_', $locale), 'en');
            if (is_string($name) && $name !== '' && $name !== $locale) {
                return $name;
            }
        }

        return $locale;
    }

    /** The language's own name for itself ("Deutsch"), for a language pack. */
    public static function nativeName(string $locale): string
    {
        if (class_exists(\Locale::class)) {
            $id = str_replace('-', '_', $locale);
            $name = \Locale::getDisplayName($id, $id);
            if (is_string($name) && $name !== '' && $name !== $locale) {
                return mb_convert_case(mb_substr($name, 0, 1), MB_CASE_TITLE).mb_substr($name, 1);
            }
        }

        return self::name($locale);
    }

    /**
     * The plural categories whole numbers take in this language, plus
     * "other", as ICU (and so Flarum's translator) chooses them: German
     * one/other, Russian one/few/many/other, Arabic all six, Japanese only
     * other. Asked of ICU itself by formatting 0 to 220, so it is exactly
     * what the forum will look up. Null without ext-intl.
     *
     * @return list<string>|null
     */
    public static function pluralCategories(string $locale): ?array
    {
        static $cache = [];

        if (! class_exists(\MessageFormatter::class)) {
            return null;
        }

        if (isset($cache[$locale])) {
            return $cache[$locale];
        }

        $formatter = \MessageFormatter::create(str_replace('-', '_', $locale), '{n, plural, zero{zero} one{one} two{two} few{few} many{many} other{other}}');

        if ($formatter === null) {
            return null;
        }

        $found = ['other' => true];
        for ($n = 0; $n <= 220; $n++) {
            $found[(string) $formatter->format(['n' => $n])] = true;
        }

        $order = ['zero', 'one', 'two', 'few', 'many', 'other'];

        return $cache[$locale] = array_values(array_intersect($order, array_keys($found)));
    }

    /**
     * The installed locale an admin's code means: "pt_BR" when the pack is
     * "pt-BR" must be stored as "pt-BR", or Flarum never reads it back.
     *
     * @param list<string> $installed
     */
    public static function canonical(string $locale, array $installed): string
    {
        $key = strtolower(str_replace('-', '_', $locale));

        foreach ($installed as $code) {
            if (strtolower(str_replace('-', '_', $code)) === $key) {
                return $code;
            }
        }

        return $locale;
    }

    /** @return array{0: string, 1: string|null, 2: string|null} language, script, region */
    public static function parts(string $locale): array
    {
        $parts = preg_split('/[_-]/', $locale) ?: [$locale];
        $language = strtolower($parts[0]);
        $script = null;
        $region = null;

        foreach (array_slice($parts, 1) as $part) {
            if (strlen($part) === 4) {
                $script = ucfirst(strtolower($part));
            } else {
                $region = strtoupper($part);
            }
        }

        return [$language, $script, $region];
    }

    public static function deepl(string $locale): string
    {
        [$language, $script, $region] = self::parts($locale);

        return match (true) {
            $language === 'zh' => ($script === 'Hant' || in_array($region, ['TW', 'HK', 'MO'], true)) ? 'ZH-HANT' : 'ZH-HANS',
            $language === 'pt' => $region === 'BR' ? 'PT-BR' : 'PT-PT',
            $language === 'en' => $region === 'US' ? 'EN-US' : 'EN-GB',
            $language === 'es' && $region !== null && $region !== 'ES' => 'ES-419',
            $language === 'no', $language === 'nn' => 'NB',
            default => strtoupper($language),
        };
    }

    public static function google(string $locale): string
    {
        [$language, $script, $region] = self::parts($locale);

        return match (true) {
            $language === 'zh' => ($script === 'Hant' || in_array($region, ['TW', 'HK', 'MO'], true)) ? 'zh-TW' : 'zh-CN',
            $language === 'pt' => $region === 'PT' ? 'pt-PT' : 'pt',
            $language === 'fr' && $region === 'CA' => 'fr-CA',
            $language === 'nb', $language === 'nn' => 'no',
            default => $language,
        };
    }
}
