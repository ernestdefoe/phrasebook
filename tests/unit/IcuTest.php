<?php

namespace Ernestdefoe\Phrasebook\Tests\unit;

use Ernestdefoe\Phrasebook\Icu\Parser;
use Ernestdefoe\Phrasebook\Icu\Segmenter;
use Ernestdefoe\Phrasebook\Icu\SyntaxError;
use Ernestdefoe\Phrasebook\Icu\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IcuTest extends TestCase
{
    private const PLURAL = '{count, plural, one {<b>#</b> reply by {username}} other {# replies}}';

    /** @return list<array{0: string}> */
    public static function valid(): array
    {
        return [
            ['Reply'],
            ["Don't panic"],
            ['{username} replied'],
            [self::PLURAL],
            ['{count, plural, =0 {none} one {# item} other {# items}}'],
            ['{n, selectordinal, one {#st} two {#nd} few {#rd} other {#th}}'],
            ['{gender, select, male {he} female {she} other {they}}'],
            ['{time, date, short} at {time, time, short}'],
            ["Write '{'braces'}' like this"],
            ['{count, plural, offset:1 other {# others}}'],
            ['<a>Read the rules</a> before posting.'],
        ];
    }

    #[DataProvider('valid')]
    public function test_parses_what_message_formatter_parses(string $message): void
    {
        Parser::parse($message);
        $this->assertSame([], Validator::check($message, $message));

        if (class_exists(\MessageFormatter::class)) {
            $this->assertNotNull(\MessageFormatter::create('en', $message));
        }
    }

    /** @return list<array{0: string, 1: string}> */
    public static function invalid(): array
    {
        return [
            ['{username', 'bad_argument'],
            ['{count, plural, one {a}', 'unclosed_argument'],
            ['oops}', 'unmatched_brace'],
            ['{count, plural, one {#}}', 'missing_other'],
            ['{count, bogus}', 'unknown_type'],
            ['{count, plural, one {a} one {b} other {c}}', 'bad_selector'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_rejects_broken_syntax(string $message, string $reason): void
    {
        try {
            Parser::parse($message);
            $this->fail('parsed');
        } catch (SyntaxError $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_an_apostrophe_before_a_brace_hides_the_placeholder(): void
    {
        // French "l'{username}" is how real translations break: ICU reads it as quoted text.
        $errors = Validator::check('the {username}', "l'{username}");
        $this->assertSame('argument_missing', $errors[0]['code'] ?? null);
        $this->assertSame([], Validator::check('the {username}', "l''{username}"));
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function rejected(): array
    {
        return [
            'dropped' => ['{username} replied', 'a répondu', 'argument_missing'],
            'added' => ['Reply', 'Répondre {x}', 'argument_added'],
            'renamed' => ['{username} replied', '{utilisateur} a répondu', 'argument_missing'],
            'retyped' => [self::PLURAL, '{count} réponses', 'argument_type'],
            'select key' => ['{g, select, male {he} other {they}}', '{g, select, homme {il} other {iel}}', 'select_missing'],
            'plural key' => ['{n, plural, one {#} other {#}}', '{n, plural, single {#} other {#}}', 'plural_key'],
            'exact case' => ['{n, plural, =0 {none} other {#}}', '{n, plural, other {#}}', 'plural_exact_missing'],
            'tag dropped' => ['<a>rules</a>', 'règles', 'tag_missing'],
            'tag added' => ['rules', '<b>règles</b>', 'tag_added'],
            'attribute' => ['<a>rules</a>', '<a href="x">règles</a>', 'tag_missing'],
            'script' => ['Reply', 'Répondre<script>alert(1)</script>', 'tag_added'],
            'img' => ['Reply', 'Répondre <img src=x onerror=alert(1)>', 'markup'],
            'unbalanced' => ['<a>rules</a>', '<a>règles<a>', 'tag_unbalanced'],
            'reference' => ['Delete', '=> core.ref.delete', 'reference'],
            'control' => ['Reply', "Ré\x07pondre", 'control_characters'],
            'empty' => ['Reply', '  ', 'empty'],
            'syntax' => ['{username} replied', '{username a répondu', 'syntax'],
        ];
    }

    #[DataProvider('rejected')]
    public function test_rejects_translations_that_break_the_source(string $source, string $translation, string $code): void
    {
        $codes = array_column(Validator::check($source, $translation), 'code');
        $this->assertContains($code, $codes);
    }

    public function test_plural_categories_may_differ_from_english(): void
    {
        $this->assertSame([], Validator::check(
            '{count, plural, one {# reply} other {# replies}}',
            '{count, plural, one {# ответ} few {# ответа} many {# ответов} other {# ответа}}'
        ));
    }

    public function test_markup_like_text_in_the_source_may_be_translated_but_not_weaponised(): void
    {
        $source = '<preview will appear here after posting>';
        $this->assertSame([], Validator::check($source, '<la vista previa aparecerá aquí>'));
        $this->assertContains('markup', array_column(Validator::check($source, '<img src=x onerror=alert(1)>'), 'code'));
    }

    public function test_segmenter_round_trips_and_escapes_engine_text(): void
    {
        $split = Segmenter::split(self::PLURAL." - don't '{'x'}'");
        $this->assertNotNull($split);
        $this->assertSame(Parser::parse(self::PLURAL." - don't '{'x'}'"), Parser::parse((string) $split['plan']->join($split['units'])));

        $joined = $split['plan']->join(['<x i="0"/> - ne pas {x}', "<b><x i=\"0\"/></b> réponse de l'<x i=\"1\"/>", '<x i="0"/> réponses']);
        $this->assertSame("{count, plural, one {<b>#</b> réponse de l''{username}} other {# réponses}} - ne pas '{'x'}'", $joined);
        $this->assertSame([], Validator::check(self::PLURAL." - don't '{'x'}'", (string) $joined));
    }

    public function test_segmenter_refuses_a_lost_or_doubled_placeholder(): void
    {
        $split = Segmenter::split('{username} replied');
        $this->assertNull($split['plan']->join(['a répondu']));
        $this->assertNull($split['plan']->join(['<x i="0"/> <x i="0"/> a répondu']));
        $this->assertNull($split['plan']->join(['<x i="7"/> a répondu']));
        $this->assertSame('{username} a répondu', $split['plan']->join(['<x i="0"></x> a répondu']));
    }

    public function test_segmenter_decodes_entities_without_letting_markup_through(): void
    {
        $split = Segmenter::split('Fish & chips');
        $this->assertSame('Fish &amp; chips', $split['units'][0]);
        $joined = (string) $split['plan']->join(['Poisson &amp; frites &lt;script&gt;']);
        $this->assertSame('Poisson & frites <script>', $joined);
        // Decoded markup is still caught by the Validator before anything is saved.
        $this->assertNotSame([], Validator::check('Fish & chips', $joined));
    }

    public function test_explain_gives_the_model_something_to_act_on(): void
    {
        $text = Validator::explain([['code' => 'argument_missing', 'detail' => 'username']]);
        $this->assertStringContainsString('{username}', $text);
    }
}
