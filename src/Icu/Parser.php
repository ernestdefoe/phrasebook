<?php

namespace Ernestdefoe\Phrasebook\Icu;

/**
 * A small ICU MessageFormat parser, enough to check a translation against its
 * English source: which arguments it uses, of which type, with which select
 * keys, and which <tags> its text carries.
 *
 * Quoting follows ICU's default (DOUBLE_OPTIONAL) mode, the one PHP's
 * MessageFormatter and Symfony's polyfill use: '' is always one apostrophe;
 * a single apostrophe starts a quoted literal only when the next character
 * is { or } (or # directly inside a plural), otherwise it is just an
 * apostrophe. Flarum's browser formatter also quotes from "'<", which PHP
 * doesn't; read() reports that case so the Validator can refuse it.
 * That is why French "l'{username}" quietly turns the argument into text:
 * the parser reads it exactly as Flarum's translator would.
 *
 * The tree: a message is a list of nodes, each one of
 *   ['text', string]                      literal text, quoting resolved
 *   ['pound']                             # inside a plural branch
 *   ['arg', name, type, style|null]       {name} or {name, number, ...}
 *   ['plural'|'selectordinal'|'select', name, [selector => message], offset]
 */
final class Parser
{
    private const SIMPLE_TYPES = ['number', 'date', 'time', 'spellout', 'ordinal', 'duration'];

    private string $s;

    private int $i = 0;

    private int $n;

    /** A lone apostrophe directly before "<": fine for PHP, a quote for the browser's formatter. */
    private bool $apostropheBeforeTag = false;

    private function __construct(string $message)
    {
        $this->s = $message;
        $this->n = strlen($message);
    }

    /**
     * @return list<array<int, mixed>>
     *
     * @throws SyntaxError
     */
    public static function parse(string $message): array
    {
        return self::read($message)[0];
    }

    /**
     * @return array{0: list<array<int, mixed>>, 1: bool} the tree, and whether a
     *         lone apostrophe sits directly before "<"
     *
     * @throws SyntaxError
     */
    public static function read(string $message): array
    {
        $parser = new self($message);
        $nodes = $parser->message(0, false);

        if ($parser->i < $parser->n) {
            // Only a stray } can stop the top level early.
            throw new SyntaxError('unmatched_brace', $parser->i);
        }

        return [$nodes, $parser->apostropheBeforeTag];
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function message(int $depth, bool $inPlural): array
    {
        if ($depth > 8) {
            throw new SyntaxError('too_deep', $this->i);
        }

        $nodes = [];
        $text = '';

        while ($this->i < $this->n) {
            $c = $this->s[$this->i];

            if ($c === "'") {
                $text .= $this->apostrophe($inPlural);
                continue;
            }

            if ($c === '{') {
                if ($text !== '') {
                    $nodes[] = ['text', $text];
                    $text = '';
                }
                $nodes[] = $this->argument($depth, $inPlural);
                continue;
            }

            if ($c === '}') {
                break;
            }

            if ($c === '#' && $inPlural) {
                if ($text !== '') {
                    $nodes[] = ['text', $text];
                    $text = '';
                }
                $nodes[] = ['pound'];
                $this->i++;
                continue;
            }

            $text .= $c;
            $this->i++;
        }

        if ($text !== '') {
            $nodes[] = ['text', $text];
        }

        return $nodes;
    }

    private function apostrophe(bool $inPlural): string
    {
        $next = $this->s[$this->i + 1] ?? '';

        if ($next === "'") {
            $this->i += 2;

            return "'";
        }

        if ($next === '{' || $next === '}' || ($inPlural && $next === '#')) {
            // Quoted literal up to the next lone apostrophe ('' inside stays one).
            $this->i++;
            $out = '';

            while ($this->i < $this->n) {
                $c = $this->s[$this->i];

                if ($c === "'") {
                    if (($this->s[$this->i + 1] ?? '') === "'") {
                        $out .= "'";
                        $this->i += 2;
                        continue;
                    }
                    $this->i++;

                    return $out;
                }
                $out .= $c;
                $this->i++;
            }

            // ICU quotes to the end of the message when the quote never closes.
            return $out;
        }

        if ($next === '<') {
            $this->apostropheBeforeTag = true;
        }

        $this->i++;

        return "'";
    }

    /**
     * @return array<int, mixed>
     */
    private function argument(int $depth, bool $inPlural): array
    {
        $start = $this->i;
        $this->i++; // {
        $this->space();
        $name = $this->identifier();

        if ($name === '') {
            throw new SyntaxError('bad_argument', $start);
        }

        $this->space();

        if ($this->peek() === '}') {
            $this->i++;

            return ['arg', $name, null, null];
        }

        if ($this->peek() !== ',') {
            throw new SyntaxError('bad_argument', $start);
        }

        $this->i++;
        $this->space();
        $type = strtolower($this->identifier());
        $this->space();

        if (in_array($type, self::SIMPLE_TYPES, true)) {
            if ($this->peek() === '}') {
                $this->i++;

                return ['arg', $name, $type, null];
            }

            if ($this->peek() !== ',') {
                throw new SyntaxError('bad_argument', $start);
            }

            $this->i++;

            return ['arg', $name, $type, $this->style($start)];
        }

        if (! in_array($type, ['plural', 'selectordinal', 'select'], true)) {
            throw new SyntaxError('unknown_type', $start);
        }

        if ($this->peek() !== ',') {
            throw new SyntaxError('bad_argument', $start);
        }

        $this->i++;
        $this->space();

        $offset = 0;
        if ($type !== 'select' && substr($this->s, $this->i, 7) === 'offset:') {
            $this->i += 7;
            $this->space();
            $digits = $this->digits();
            if ($digits === '') {
                throw new SyntaxError('bad_argument', $start);
            }
            $offset = (int) $digits;
            $this->space();
        }

        $branches = [];

        while (true) {
            $this->space();

            if ($this->peek() === '}') {
                $this->i++;
                break;
            }

            if ($this->i >= $this->n) {
                throw new SyntaxError('unclosed_argument', $start);
            }

            $selector = $this->selector();

            if ($selector === '' || isset($branches[$selector])) {
                throw new SyntaxError('bad_selector', $this->i);
            }

            $this->space();

            if ($this->peek() !== '{') {
                throw new SyntaxError('bad_selector', $this->i);
            }

            $this->i++;
            // # is the number only directly inside a plural (or selectordinal)
            // branch, not inside a select nested in one: ICU and the
            // browser's formatter agree on that.
            $branches[$selector] = $this->message($depth + 1, $type !== 'select');

            if ($this->peek() !== '}') {
                throw new SyntaxError('unclosed_argument', $start);
            }

            $this->i++;
        }

        if (! isset($branches['other'])) {
            throw new SyntaxError('missing_other', $start);
        }

        return [$type, $name, $branches, $offset];
    }

    /** A simple argument's style: everything up to the matching brace. */
    private function style(int $start): string
    {
        $level = 0;
        $out = '';

        while ($this->i < $this->n) {
            $c = $this->s[$this->i];

            if ($c === "'") {
                $out .= $this->apostrophe(false);
                continue;
            }

            if ($c === '{') {
                $level++;
            } elseif ($c === '}') {
                if ($level === 0) {
                    $this->i++;

                    return trim($out);
                }
                $level--;
            }

            $out .= $c;
            $this->i++;
        }

        throw new SyntaxError('unclosed_argument', $start);
    }

    private function selector(): string
    {
        $start = $this->i;

        if ($this->peek() === '=') {
            $this->i++;
            $digits = $this->digits();

            return $digits === '' ? '' : '='.$digits;
        }

        while ($this->i < $this->n && preg_match('/[\w-]/', $this->s[$this->i])) {
            $this->i++;
        }

        return substr($this->s, $start, $this->i - $start);
    }

    private function identifier(): string
    {
        $start = $this->i;

        while ($this->i < $this->n && preg_match('/[\w-]/', $this->s[$this->i])) {
            $this->i++;
        }

        return substr($this->s, $start, $this->i - $start);
    }

    private function digits(): string
    {
        $start = $this->i;

        while ($this->i < $this->n && ctype_digit($this->s[$this->i])) {
            $this->i++;
        }

        return substr($this->s, $start, $this->i - $start);
    }

    private function space(): void
    {
        while ($this->i < $this->n && ctype_space($this->s[$this->i])) {
            $this->i++;
        }
    }

    private function peek(): string
    {
        return $this->s[$this->i] ?? '';
    }
}
