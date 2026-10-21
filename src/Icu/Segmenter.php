<?php

namespace Ernestdefoe\Phrasebook\Icu;

/**
 * Prepares ICU messages for machine translation engines (DeepL, Google), which
 * would translate "plural", "other" and argument names along with the text.
 *
 * A message becomes one or more units of XML-ish text: placeholders, # and
 * whole plural/select blocks become <x i="N"/> (which the engines are told
 * not to touch), each plural/select branch becomes a unit of its own, and the
 * message's own tags (<a>…</a>) stay as tags so the engine can move words
 * around them. After translation the units are put back together into ICU,
 * with apostrophes and braces in the translated text escaped the way ICU
 * reads them. The result is still checked by the Validator like any other.
 */
final class Segmenter
{
    private const TAG = '~<(/?)([a-zA-Z][a-zA-Z0-9_-]*)\s*(/?)>~';

    /** @var list<string> */
    private array $units = [];

    /** @var list<array{nodes: list<array<int, mixed>>, plural: bool, slots: list<array<int, mixed>>}> */
    private array $plans = [];

    /**
     * @return array{units: list<string>, plan: self}|null null when the source is not valid ICU
     */
    public static function split(string $source): ?array
    {
        try {
            $nodes = Parser::parse($source);
        } catch (SyntaxError) {
            return null;
        }

        $self = new self();
        $self->plan($nodes, false);

        return ['units' => $self->units, 'plan' => $self];
    }

    /**
     * @param list<string> $translated one per unit, in order
     *
     * @return string|null null when the engine lost or duplicated a placeholder
     */
    public function join(array $translated): ?string
    {
        if (count($translated) !== count($this->units)) {
            return null;
        }

        $rendered = [];

        // Units are planned parent-first, so children (higher indexes) are
        // rendered first and parents can drop them in.
        for ($u = count($this->plans) - 1; $u >= 0; $u--) {
            $out = $this->render($u, (string) $translated[$u], $rendered);

            if ($out === null) {
                return null;
            }

            $rendered[$u] = $out;
        }

        return $rendered[0] ?? '';
    }

    /**
     * @param list<array<int, mixed>> $nodes
     */
    private function plan(array $nodes, bool $plural): int
    {
        $index = count($this->units);
        $this->units[] = '';
        $this->plans[] = ['nodes' => $nodes, 'plural' => $plural, 'slots' => []];

        $text = '';
        foreach ($nodes as $node) {
            if ($node[0] === 'text') {
                $text .= $node[1];
            }
        }
        $keepTags = self::balanced($text);

        $unit = '';
        $slots = [];

        foreach ($nodes as $node) {
            if ($node[0] === 'text') {
                $unit .= $this->textToXml($node[1], $keepTags, $slots);
                continue;
            }

            if (in_array($node[0], ['plural', 'selectordinal', 'select'], true)) {
                $branches = [];
                foreach ($node[2] as $selector => $branch) {
                    $branches[(string) $selector] = $this->plan($branch, $node[0] !== 'select' || $plural);
                }
                $node[2] = $branches;
            }

            $slots[] = $node;
            $unit .= '<x i="'.(count($slots) - 1).'"/>';
        }

        $this->units[$index] = $unit;
        $this->plans[$index]['slots'] = $slots;

        return $index;
    }

    /**
     * @param list<array<int, mixed>> $slots
     */
    private function textToXml(string $text, bool $keepTags, array &$slots): string
    {
        $out = '';
        $offset = 0;

        preg_match_all(self::TAG, $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$tag, $at]) {
            $out .= htmlspecialchars(substr($text, $offset, $at - $offset), ENT_XML1 | ENT_QUOTES);

            if ($keepTags) {
                $out .= $tag;
            } else {
                $slots[] = ['tag', $tag];
                $out .= '<x i="'.(count($slots) - 1).'"/>';
            }

            $offset = $at + strlen($tag);
        }

        return $out.htmlspecialchars(substr($text, $offset), ENT_XML1 | ENT_QUOTES);
    }

    /**
     * @param array<int, string> $rendered
     */
    private function render(int $u, string $translated, array $rendered): ?string
    {
        $plan = $this->plans[$u];
        $slots = $plan['slots'];

        $parts = preg_split('~<x\s+i\s*=\s*"(\d+)"\s*/?>(?:\s*</x>)?~', $translated, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return null;
        }

        $seen = [];
        $pieces = [];

        foreach ($parts as $k => $part) {
            if ($k % 2 === 0) {
                if ($part !== '') {
                    $pieces[] = ['text', $part];
                }
                continue;
            }

            $slot = (int) $part;

            if (! isset($slots[$slot]) || isset($seen[$slot])) {
                return null;
            }

            $seen[$slot] = true;
            $pieces[] = ['slot', $slots[$slot]];
        }

        if (count($seen) !== count($slots)) {
            return null;
        }

        $out = '';
        $count = count($pieces);

        for ($k = 0; $k < $count; $k++) {
            $piece = $pieces[$k];

            if ($piece[0] === 'slot') {
                $out .= $this->slot($piece[1], $rendered);
                continue;
            }

            // Text: the engine's markup is tags we kept and escaped entities.
            $next = $pieces[$k + 1] ?? null;
            $nextIsSyntax = $next !== null && $next[0] === 'slot' && $next[1][0] !== 'tag';
            $out .= self::escapeText($piece[1], $plan['plural'], $nextIsSyntax);
        }

        return $out;
    }

    /**
     * @param array<int, mixed> $node
     * @param array<int, string> $rendered
     */
    private function slot(array $node, array $rendered): string
    {
        return match ($node[0]) {
            'tag' => $node[1],
            'pound' => '#',
            'arg' => '{'.$node[1].($node[2] === null ? '' : ', '.$node[2].($node[3] === null ? '' : ', '.self::escapeStyle((string) $node[3]))).'}',
            default => '{'.$node[1].', '.$node[0].', '.($node[3] ? 'offset:'.$node[3].' ' : '').implode(' ', array_map(
                fn ($selector, $unit) => $selector.' {'.$rendered[$unit].'}',
                array_keys($node[2]),
                $node[2]
            )).'}',
        };
    }

    /**
     * Engine text back to ICU text: entities decoded (tags kept), then
     * apostrophes doubled wherever ICU would otherwise read them as quotes,
     * and stray braces (or # in a plural) quoted.
     */
    private static function escapeText(string $xml, bool $plural, bool $nextIsSyntax): string
    {
        $out = '';
        $offset = 0;

        preg_match_all(self::TAG, $xml, $matches, PREG_OFFSET_CAPTURE);
        $chunks = [];

        foreach ($matches[0] as [$tag, $at]) {
            $chunks[] = [false, substr($xml, $offset, $at - $offset)];
            $chunks[] = [true, $tag];
            $offset = $at + strlen($tag);
        }
        $chunks[] = [false, substr($xml, $offset)];

        foreach ($chunks as [$isTag, $chunk]) {
            $out .= $isTag ? $chunk : html_entity_decode($chunk, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $special = $plural ? '{}#|' : '{}';
        $result = '';
        $len = strlen($out);

        for ($i = 0; $i < $len; $i++) {
            $c = $out[$i];

            if ($c === "'") {
                $next = $i + 1 < $len ? $out[$i + 1] : ($nextIsSyntax ? '{' : '');
                $result .= ($next === "'" || ($next !== '' && str_contains($special, $next))) ? "''" : "'";
                continue;
            }

            if (str_contains($special, $c)) {
                $result .= "'".$c."'";
                continue;
            }

            $result .= $c;
        }

        return $result;
    }

    private static function escapeStyle(string $style): string
    {
        return str_replace("'", "''", $style);
    }

    private static function balanced(string $text): bool
    {
        preg_match_all(self::TAG, $text, $matches, PREG_SET_ORDER);
        $stack = [];

        foreach ($matches as $m) {
            if ($m[3] === '/') {
                continue;
            }
            if ($m[1] === '/') {
                if (array_pop($stack) !== $m[2]) {
                    return false;
                }
            } else {
                $stack[] = $m[2];
            }
        }

        return $stack === [];
    }
}
