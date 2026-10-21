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
