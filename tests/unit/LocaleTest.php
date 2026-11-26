<?php

namespace Ernestdefoe\Phrasebook\Tests\unit;

use Ernestdefoe\Phrasebook\Strings\Scanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every piece of text the admin shows comes from locale/en.yml, so Phrasebook
 * can translate itself: a key the code uses but the file lacks shows raw, and
 * text written straight into the code can't be translated at all.
 */
class LocaleTest extends TestCase
{
    /** @var array<string, string> */
    private static array $strings;

    public static function setUpBeforeClass(): void
    {
        $flat = [];
        $walk = function (array $node, string $prefix) use (&$walk, &$flat): void {
            foreach ($node as $key => $value) {
                is_array($value) ? $walk($value, "$prefix$key.") : $flat["$prefix$key"] = (string) $value;
            }
        };
        $walk(Yaml::parseFile(__DIR__.'/../../locale/en.yml')['ernestdefoe-phrasebook']['admin'], '');
        self::$strings = $flat;
    }

    #[Test]
    public function every_key_the_admin_uses_is_in_the_locale_file(): void
    {
        $missing = [];

        foreach (glob(__DIR__.'/../../js/src/admin/components/*.js') ?: [] as $file) {
            preg_match_all("/\\b(?:t|known)\\('([a-z0-9_.]+)'/", (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $key) {
                if (! isset(self::$strings[$key])) {
                    $missing[] = basename($file).": $key";
                }
            }
        }

        $this->assertSame([], $missing);
    }

    #[Test]
    public function keys_built_from_a_value_exist_for_every_value(): void
    {
        $families = [
            'reasons' => Scanner::REASONS,
            'status' => ['queued', 'running', 'waiting', 'paused', 'review', 'cancelled'],
            'items' => ['translated', 'failed', 'pending', 'accepted', 'rejected', 'saved'],
            'tabs' => ['translate', 'runs', 'provider'],
        ];

        foreach ($families as $family => $values) {
            foreach ($values as $value) {
                $this->assertArrayHasKey("$family.$value", self::$strings);
            }
        }
    }

    #[Test]
    public function no_text_is_written_into_the_admin_code(): void
    {
        $found = [];

        foreach (glob(__DIR__.'/../../js/src/admin/components/*.js') ?: [] as $file) {
            foreach (file($file) ?: [] as $n => $line) {
                // JSX text between tags, or a text attribute, with a real word in it.
                if (preg_match('/>\s*[A-Za-z][a-z]+(?: [a-z]+)+\s*</', $line) || preg_match('/\b(?:title|aria-label|label)="[A-Za-z][a-z]+ /', $line)) {
                    $found[] = basename($file).':'.($n + 1).': '.trim($line);
                }
            }
        }

        $this->assertSame([], $found);
    }
}
