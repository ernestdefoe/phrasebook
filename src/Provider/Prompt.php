<?php

namespace Ernestdefoe\Phrasebook\Provider;

use Ernestdefoe\Phrasebook\Net\Response;
use Ernestdefoe\Phrasebook\Strings\Languages;

/**
 * What every language model is told, and how its answer is read back.
 *
 * The system prompt is the same for every batch of a run (glossary and tone
 * included), so providers that cache prompts only pay for it once.
 */
final class Prompt
{
    public const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'translations' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'text' => ['type' => 'string'],
                    ],
                    'required' => ['id', 'text'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['translations'],
        'additionalProperties' => false,
    ];

    public static function system(Job $job): string
    {
        $tone = match ($job->tone) {
            'formal' => 'Address the reader formally (the polite form of "you" where the language has one).',
            'informal' => 'Address the reader informally and warmly (the familiar form of "you" where the language has one).',
            default => 'Use the neutral, friendly register usual for software interfaces in this language.',
        };

        $lines = [
            "You translate the user-interface strings of a Flarum web forum from English into {$job->language} (locale code {$job->locale}).",
            '',
            'Every string is ICU MessageFormat. Rules:',
            '- Keep every placeholder in braces exactly as written, e.g. {username}, {count}, {time, date, short}. Never translate placeholder names.',
            '- In {x, plural, ...} and {x, selectordinal, ...}, translate the text inside each branch and use the plural categories '.$job->language.' needs (zero, one, two, few, many, other). Always keep "other" and any exact cases such as =0.',
            '- In {x, select, ...}, keep every branch keyword unchanged and translate only the text inside the branches.',
            '- Inside a plural branch, # stands for the number. Keep it.',
            ...self::pluralRule($job),
            '- Keep tags such as <a>...</a>, <b>...</b> or <username>...</username> exactly as written, with no attributes; translate the words between them. Add no other HTML or Markdown.',
            "- In ICU an apostrophe directly before {, } or a tag starts quoted text and would hide what follows. Write two apostrophes there instead, e.g. l''{username} or l''<a>.",
            '- Never write a bare < in the text (it starts a tag); say "less than" in words.',
            '- The key shows where a string appears (a button, a label, a help text, an email). Keep buttons and labels as short as the English.',
            '- Keep leading and trailing spaces, line breaks and end punctuation as in the English.',
            '- If a string should stay as it is (a brand name, a code, "OK" where the language uses it), return it unchanged.',
            '- '.$tone,
        ];

        $notes = trim($job->notes);
        if ($notes !== '') {
            $lines[] = '- Notes from the forum administrator: '.$notes;
        }

        if ($job->glossary) {
            $lines[] = '';
            $lines[] = 'Glossary. Always translate these terms this way (a term mapped to itself stays untranslated):';
            foreach ($job->glossary as [$term, $translation]) {
                $lines[] = "- $term => $translation";
            }
        }

        $lines[] = '';
        $lines[] = 'The user sends a JSON array of strings, each with an id, its key and its English text. Some carry "feedback": why your last translation of that string was rejected; fix exactly that.';
        $lines[] = 'Reply with JSON only: {"translations":[{"id":"...","text":"..."}]}, one entry for every id, nothing else.';
        // From convoro-translate: a quotation mark inside a translation, left
        // unescaped, made the whole reply unreadable on a live run.
        $lines[] = 'If a translation contains a quotation mark, escape it as \\" inside the JSON string.';

        return implode("\n", $lines);
    }

    /**
     * The plural forms the language needs, named: "use as many as your
     * language needs" is answered inconsistently, a list is followed.
     *
     * @return list<string>
     */
    private static function pluralRule(Job $job): array
    {
        $forms = Languages::pluralCategories($job->locale);

        if ($forms === null) {
            return [];
        }

        return ['- In '.$job->language.' every {x, plural, ...} needs exactly these forms: '.implode(', ', $forms).'. Give each of them, even where two read the same.'];
    }

    public static function user(Job $job): string
    {
        $items = [];

        foreach ($job->items as $id => $item) {
            $entry = ['id' => (string) $id, 'key' => $item['key'], 'text' => $item['text']];
            if ($item['feedback']) {
                $entry['feedback'] = $item['feedback'];
            }
            $items[] = $entry;
        }

        return (string) json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Reads {"translations":[{id,text}]} (or a plain {id: text} object) out of
     * a model's reply, tolerating a ```json fence or words around it. Ids the
     * batch did not ask for are dropped.
     *
     * @return array<string, string>
     *
     * @throws ProviderError
     */
    public static function parse(string $reply, Job $job): array
    {
        $reply = trim($reply);
        $data = json_decode($reply, true);

        if (! is_array($data)) {
            $start = strpos($reply, '{');
            $end = strrpos($reply, '}');
            $data = $start !== false && $end !== false && $end > $start
                ? json_decode(substr($reply, $start, $end - $start + 1), true)
                : null;
        }

        if (! is_array($data)) {
            throw new ProviderError('invalid_response', 0, null, 'The model did not answer with JSON.');
        }

        $list = $data['translations'] ?? null;
        $out = [];

        if (is_array($list) && array_is_list($list)) {
            foreach ($list as $row) {
                if (is_array($row) && isset($row['id'], $row['text']) && is_scalar($row['id']) && is_string($row['text'])) {
                    $out[(string) $row['id']] = $row['text'];
                }
            }
        } else {
            foreach (is_array($list) ? $list : $data as $id => $text) {
                if (is_string($text)) {
                    $out[(string) $id] = $text;
                }
            }
        }

        return array_intersect_key($out, $job->items);
    }

    /**
     * Turns a non-2xx answer into a ProviderError, from the status code and
     * the error text the provider sent.
     */
    public static function error(Response $response, string $network = ''): ProviderError
    {
        $data = $response->json() ?? [];
        $error = $data['error'] ?? $data;
        $message = is_array($error) ? (string) ($error['message'] ?? $error['detail'] ?? $data['message'] ?? '') : (string) $error;
        $message = self::clean($message);
        $status = $response->status;
        $retry = self::retryAfter($response);
        $lower = strtolower($message);

        $kind = match (true) {
            $status === 401, $status === 403 => 'auth',
            $status === 402, $status === 456 => 'quota',
            $status === 404 => 'model',
            $status === 413 => 'too_large',
            $status === 429 && (str_contains($lower, 'quota') && ! str_contains($lower, 'rate')) => 'quota',
            $status === 429 => 'rate_limit',
            $status === 529, $status === 503 => 'overloaded',
            $status >= 500 => 'server',
            str_contains($lower, 'context') || str_contains($lower, 'too long') || str_contains($lower, 'too many tokens') || str_contains($lower, 'max_tokens') => 'too_large',
            default => 'bad_request',
        };

        return new ProviderError($kind, $status, $retry, $message);
    }

    /**
     * Provider error text for the admin: one line, no longer than 300
     * characters, with anything shaped like a key or token masked.
     */
    public static function clean(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', strip_tags($message)) ?? '';
        $message = preg_replace('/\b(sk|pk|rk|gsk|xai|key|AIza)[-_A-Za-z0-9]{12,}/', '[key]', $message) ?? '';
        $message = preg_replace('/[A-Za-z0-9_\-]{32,}(:fx)?/', '[…]', $message) ?? '';

        return mb_substr(trim($message), 0, 300);
    }

    private static function retryAfter(Response $response): ?int
    {
        $ms = $response->headers['retry-after-ms'] ?? null;
        if (is_numeric($ms)) {
            return max(1, (int) ceil(((float) $ms) / 1000));
        }

        $value = $response->headers['retry-after'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return max(1, (int) ceil((float) $value));
        }

        $at = strtotime($value);

        return $at === false ? null : max(1, $at - time());
    }
}
