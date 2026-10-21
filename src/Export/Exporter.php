<?php

namespace Ernestdefoe\Phrasebook\Export;

use Ernestdefoe\Phrasebook\Run\Runs;
use Ernestdefoe\Phrasebook\Strings\Languages;
use Ernestdefoe\Phrasebook\Strings\Scanner;
use Flarum\Extension\ExtensionManager;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Yaml\Yaml;
use ZipArchive;

/**
 * Exports a locale's translations as one YAML file (the shape Linguist's
 * import takes) or as a language pack extension, ready to install, publish
 * or hand to a pack's maintainers.
 *
 * "phrasebook": only what Phrasebook translated and the admin accepted (or
 * saved), the newest per key. "full": what the locale has today, with those
 * on top, so the export is a complete pack.
 */
class Exporter
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Scanner $scanner,
        private readonly ExtensionManager $extensions,
        private readonly Runs $runs
    ) {
    }

    /**
     * @return array<string, string> key => translation, sorted by key
     */
    public function strings(string $locale, bool $full): array
    {
        $english = $this->scanner->english();
        $ours = [];

        $rows = $this->db->table('phrasebook_items')
            ->join('phrasebook_runs', 'phrasebook_runs.id', '=', 'phrasebook_items.run_id')
            ->where('phrasebook_runs.locale', $locale)
            ->whereIn('phrasebook_items.status', ['accepted', 'saved'])
            ->orderBy('phrasebook_items.run_id')
            ->orderBy('phrasebook_items.id')
            ->get(['phrasebook_items.key', 'phrasebook_items.translation', 'phrasebook_items.source_hash']);

        foreach ($rows as $row) {
            $ours[(string) $row->key] = [(string) $row->translation, (string) $row->source_hash];
        }

        $strings = $full ? array_intersect_key($this->scanner->current($locale), $english) : [];

        $hashes = [];

        foreach ($ours as $key => [$translation, $hash]) {
            if (isset($english[$key])) {
                $strings[$key] = $translation;
                $hashes[$key] = $hash;
            }
        }

        $this->runs->remember($locale, $hashes);

        ksort($strings);

        return $strings;
    }

    /**
     * @param array<string, string> $strings
     */
    public function yaml(array $strings): string
    {
        return Yaml::dump(self::nest($strings), 20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    public static function zipAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * A language pack extension in a zip: composer.json, extend.php, one
     * YAML file per extension (named by its id, which is how Flarum decides
     * to load it only while that extension is enabled), README and LICENSE.
     *
     * @param array<string, string> $strings
     *
     * @return string path of a temporary zip; the caller deletes it
     */
    public function pack(string $locale, array $strings): string
    {
        $files = [];

        foreach ($strings as $key => $value) {
            $ns = Scanner::namespace($key);
            // Keys under a name no enabled extension has go in core.yml,
            // which is always loaded.
            // The namespace names a file in the zip: only a plain extension id
            // may, never anything with a path in it.
            $file = preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/i', $ns) && (in_array($ns, ['core', 'validation'], true) || $this->extensions->isEnabled($ns)) ? $ns : 'core';
            $files[$file][$key] = $value;
        }

        $slug = strtolower(str_replace('_', '-', $locale));
        $native = Languages::nativeName($locale);
        $english = Languages::name($locale);

        $composer = [
            'name' => 'your-vendor/flarum-lang-'.$slug,
            'description' => "$english ($native) language pack for Flarum.",
            'type' => 'flarum-extension',
            'keywords' => ['flarum', 'flarum-extension', 'language', 'locale', $locale],
            'license' => 'MIT',
            'require' => ['flarum/core' => '^2.0'],
            'extra' => [
                'flarum-extension' => [
                    'title' => "$native language pack",
                    'category' => 'language',
                    'icon' => ['name' => 'fas fa-language', 'backgroundColor' => '#4d698e', 'color' => '#ffffff'],
                ],
                'flarum-locale' => ['code' => $locale, 'title' => $native],
            ],
        ];

        $readme = "# $native language pack for Flarum\n\n"
            ."The $english interface strings for Flarum and the extensions this forum runs,\n"
            ."translated with [Phrasebook](https://github.com/ernestdefoe/phrasebook) and reviewed before export.\n\n"
            ."Before publishing: set your own vendor name in `composer.json`, read the strings through once more,\n"
            ."and add a LICENSE holder line.\n\n"
            ."## Install\n\n"
            ."Upload this folder to your server and add it as a path repository, or publish it and run:\n\n"
            ."```sh\ncomposer require your-vendor/flarum-lang-$slug\n```\n\n"
            ."Then enable the pack in the admin panel and pick the language under Basics.\n";

        $path = tempnam(sys_get_temp_dir(), 'phrasebook');
        $zip = new ZipArchive();

        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the zip.');
        }

        $root = "flarum-lang-$slug/";
        $zip->addFromString($root.'composer.json', (string) json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $zip->addFromString($root.'extend.php', "<?php\n\nuse Flarum\\Extend;\n\nreturn [\n    new Extend\\LanguagePack(),\n];\n");
        $zip->addFromString($root.'README.md', $readme);
        $zip->addFromString($root.'LICENSE', "MIT License\n\nPermission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the \"Software\"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:\n\nThe above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.\n\nTHE SOFTWARE IS PROVIDED \"AS IS\", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.\n");

        ksort($files);
        foreach ($files as $file => $group) {
            $zip->addFromString($root.'locale/'.$file.'.yml', $this->yaml($group));
        }

        $zip->close();

        return $path;
    }

    /**
     * Dotted keys into nested YAML, as language packs write them. A key that
     * is both a string and the parent of others (rare, but YAML can't nest
     * that) stays flat, which Flarum's loader reads just the same.
     *
     * @param array<string, string> $strings
     *
     * @return array<string, mixed>
     */
    public static function nest(array $strings): array
    {
        $tree = [];
        $flat = [];

        foreach ($strings as $key => $value) {
            $node = &$tree;
            $parts = explode('.', $key);
            $last = array_pop($parts);
            $ok = true;

            foreach ($parts as $part) {
                if (isset($node[$part]) && ! is_array($node[$part])) {
                    $ok = false;
                    break;
                }
                $node[$part] ??= [];
                $node = &$node[$part];
            }

            if ($ok && ! isset($node[$last])) {
                $node[$last] = $value;
            } else {
                $flat[$key] = $value;
            }

            unset($node);
        }

        return $tree + $flat;
    }
}
