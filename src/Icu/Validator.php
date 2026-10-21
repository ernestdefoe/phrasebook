<?php

namespace Ernestdefoe\Phrasebook\Icu;

/**
 * Checks a translation against its English source before anyone can save it.
 *
 * A translation is rejected when it would break the forum or change what the
 * code puts into it: a syntax error, an argument dropped, added, renamed or
 * retyped, a select branch missing, a plural without "other", an exact (=0)
 * plural case lost, a tag the source does not have (or one left unclosed),
 * anything that looks like markup, control characters, or a value Flarum
 * would read as a reference to another key ("=> core.ref.x").
 *
 * Plural categories may differ from English: Russian needs one/few/many/other
 * where English has one/other, so only "other" and the source's =N cases are
 * required.
 */
final class Validator
{
    private const PLURAL_KEYS = ['zero', 'one', 'two', 'few', 'many', 'other'];

    private const TAG = '~<(/?)([a-zA-Z][a-zA-Z0-9_-]*)\s*(/?)>~';

    /**
     * @return list<array{code: string, detail: string}> empty when valid
     */
    public static function check(string $source, string $translation): array
    {
        if (trim($translation) === '') {
            return trim($source) === '' ? [] : [self::error('empty')];
        }

        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $translation)) {
            return [self::error('control_characters')];
        }

        if (! mb_check_encoding($translation, 'UTF-8')) {
            return [self::error('encoding')];
        }

        if (str_starts_with(ltrim($translation), '=>')) {
            return [self::error('reference')];
        }

        if (mb_strlen($translation) > 10 * mb_strlen($source) + 200) {
            return [self::error('too_long')];
        }

        try {
            $want = self::signature(Parser::parse($source));
        } catch (SyntaxError) {
            // The English itself is not valid ICU (some extension's typo):
            // all that can be asked is that the translation keeps every
            // brace-wrapped name and tag the source has, as plain text.
            return self::checkLoose($source, $translation);
        }

        try {
            $got = self::signature(Parser::parse($translation));
        } catch (SyntaxError $e) {
            return [self::error('syntax', $e->reason)];
        }

        $errors = [];

        foreach ($want['args'] as $name => $types) {
            if (! isset($got['args'][$name])) {
                $errors[] = self::error('argument_missing', $name);
            } elseif ($got['args'][$name] !== $types) {
                $errors[] = self::error('argument_type', $name);
            }
        }

        foreach (array_keys($got['args']) as $name) {
            if (! isset($want['args'][$name])) {
                $errors[] = self::error('argument_added', (string) $name);
            }
        }

        foreach ($want['select'] as $name => $keys) {
            foreach (array_diff($keys, $got['select'][$name] ?? []) as $key) {
                $errors[] = self::error('select_missing', "$name: $key");
            }
            foreach (array_diff($got['select'][$name] ?? [], $keys) as $key) {
                $errors[] = self::error('select_added', "$name: $key");
            }
        }

        foreach ($got['plural'] as $name => $keys) {
            foreach ($keys as $key) {
                if (! in_array($key, self::PLURAL_KEYS, true) && ! preg_match('/^=\d+$/', $key)) {
                    $errors[] = self::error('plural_key', "$name: $key");
                }
            }
            foreach ($want['plural'][$name] ?? [] as $key) {
                if ($key[0] === '=' && ! in_array($key, $keys, true)) {
                    $errors[] = self::error('plural_exact_missing', "$name: $key");
                }
            }
        }

        foreach (array_diff($want['tags'], $got['tags']) as $tag) {
            $errors[] = self::error('tag_missing', $tag);
        }

        foreach (array_diff($got['tags'], $want['tags']) as $tag) {
            $errors[] = self::error('tag_added', $tag);
        }

        // A source with markup-like text ("<preview will appear here>") may
        // keep it translated, as long as it cannot become a real element.
        if ($got['markup'] && (! $want['markup'] || self::dangerous($translation))) {
            $errors[] = self::error('markup');
        }

        if ($want['balanced'] && ! $got['balanced']) {
            $errors[] = self::error('tag_unbalanced');
        }

        return $errors;
    }

    /**
     * English feedback for the model's retry: what to fix, in its terms.
     *
     * @param list<array{code: string, detail: string}> $errors
     */
    public static function explain(array $errors): string
    {
        $lines = [];

        foreach ($errors as $error) {
            $d = $error['detail'];
            $lines[] = match ($error['code']) {
                'empty' => 'The translation was empty.',
                'control_characters' => 'It contained control characters.',
                'encoding' => 'It was not valid UTF-8.',
                'reference' => 'It must not start with "=>".',
                'too_long' => 'It was far longer than the source.',
                'syntax' => "It is not valid ICU MessageFormat ($d). Remember: an apostrophe directly before { or } starts a quoted literal; write two apostrophes ('') there.",
                'argument_missing' => "The placeholder {{$d}} is missing (or was hidden by an apostrophe directly before it; write '' instead).",
                'argument_type' => "The placeholder {{$d}} must keep the same form (plural/select/number) as in the source.",
                'argument_added' => "{{$d}} is not a placeholder of the source; do not invent placeholders.",
                'select_missing' => "The select branch \"$d\" must be kept, with its keyword unchanged.",
                'select_added' => "The select branch \"$d\" is not in the source.",
                'plural_key' => "\"$d\" is not a plural category (use zero, one, two, few, many, other or =N).",
                'plural_exact_missing' => "The exact plural case \"$d\" must be kept.",
                'tag_missing' => "The tag $d must be kept exactly as written.",
                'tag_added' => "The tag $d is not in the source.",
                'tag_unbalanced' => 'Every opening tag must be closed, as in the source.',
                'markup' => 'It contains markup that is not in the source.',
                'no_answer' => 'Your reply had no translation for this id.',
                default => 'It was rejected.',
            };
        }

        return implode(' ', array_unique($lines));
    }

    /**
     * @param list<array<int, mixed>> $nodes
     *
     * @return array{args: array<string, list<string>>, select: array<string, list<string>>, plural: array<string, list<string>>, tags: list<string>, markup: bool, balanced: bool}
     */
    private static function signature(array $nodes): array
    {
        $sig = ['args' => [], 'select' => [], 'plural' => [], 'tags' => [], 'markup' => false, 'balanced' => true];
        self::walk($nodes, $sig);

        foreach ($sig['args'] as $name => $types) {
            $types = array_values(array_unique($types));
            sort($types);
            $sig['args'][$name] = $types;
        }

        $sig['tags'] = array_values(array_unique($sig['tags']));

        return $sig;
    }

    /**
     * @param list<array<int, mixed>> $nodes
     * @param array<string, mixed> $sig
     */
    private static function walk(array $nodes, array &$sig): void
    {
        $text = '';

        foreach ($nodes as $node) {
            switch ($node[0]) {
                case 'text':
                    $text .= $node[1];
                    break;
                case 'arg':
                    $sig['args'][$node[1]][] = $node[2] === null ? 'simple' : $node[2].($node[3] === null ? '' : ':'.$node[3]);
                    break;
                case 'plural':
                case 'selectordinal':
                case 'select':
                    $sig['args'][$node[1]][] = $node[0];
                    $key = $node[0] === 'select' ? 'select' : 'plural';
                    $sig[$key][$node[1]] = array_values(array_unique(array_merge($sig[$key][$node[1]] ?? [], array_map('strval', array_keys($node[2])))));
                    foreach ($node[2] as $branch) {
                        self::walk($branch, $sig);
                    }
                    break;
            }
        }

        self::scanText($text, $sig);
    }

    /**
     * @param array<string, mixed> $sig
     */
    private static function scanText(string $text, array &$sig): void
    {
        preg_match_all(self::TAG, $text, $matches, PREG_SET_ORDER);

        $stack = [];

        foreach ($matches as $m) {
            $sig['tags'][] = $m[0];

            if ($m[3] === '/') {
                continue;
            }

            if ($m[1] === '/') {
                if (array_pop($stack) !== $m[2]) {
                    $sig['balanced'] = false;
                }
            } else {
                $stack[] = $m[2];
            }
        }

        if ($stack) {
            $sig['balanced'] = false;
        }

        // Any other "<" that starts something tag-like (attributes, comments,
        // doctype, processing instructions) is markup the source never had.
        if (preg_match('~<[a-zA-Z/!?]~', preg_replace(self::TAG, '', $text) ?? '')) {
            $sig['markup'] = true;
        }
    }

    private static function dangerous(string $text): bool
    {
        $rest = preg_replace(self::TAG, '', $text) ?? '';

        return (bool) preg_match('~<[^>]*[=\'"&]|<\s*/?\s*(script|style|iframe|img|svg|math|object|embed|link|meta|base|form|input|button|a|on\w+)\b~i', $rest);
    }

    /**
     * @return list<array{code: string, detail: string}>
     */
    private static function checkLoose(string $source, string $translation): array
    {
        $errors = [];
        preg_match_all('/\{\s*([\w-]+)/', $source, $want);
        preg_match_all('/\{\s*([\w-]+)/', $translation, $got);

        foreach (array_diff(array_unique($want[1]), $got[1]) as $name) {
            $errors[] = self::error('argument_missing', $name);
        }

        preg_match_all(self::TAG, $source, $wantTags);
        preg_match_all(self::TAG, $translation, $gotTags);

        foreach (array_diff(array_unique($gotTags[0]), $wantTags[0]) as $tag) {
            $errors[] = self::error('tag_added', $tag);
        }

        if (preg_match('~<[a-zA-Z/!?]~', preg_replace(self::TAG, '', $translation) ?? '')) {
            $errors[] = self::error('markup');
        }

        return $errors;
    }

    /**
     * @return array{code: string, detail: string}
     */
    private static function error(string $code, string $detail = ''): array
    {
        return ['code' => $code, 'detail' => $detail];
    }
}
