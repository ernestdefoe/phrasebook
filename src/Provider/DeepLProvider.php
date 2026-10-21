<?php

namespace Ernestdefoe\Phrasebook\Provider;

use Ernestdefoe\Phrasebook\Strings\Languages;

/**
 * DeepL. Free-plan keys (ending ":fx") go to api-free.deepl.com. The tone
 * becomes DeepL's formality (the "prefer_" forms, which languages without a
 * formal form simply ignore), and the glossary becomes a DeepL glossary made
 * for the run and deleted when it ends.
 */
final class DeepLProvider extends MachineTranslation
{
    public const MODELS = ['quality_optimized', 'prefer_quality_optimized', 'latency_optimized'];

    public function models(): array
    {
        return array_map(fn ($id) => ['id' => $id, 'label' => $id], self::MODELS);
    }

    protected function send(array $texts, Job $job): array
    {
        $payload = [
            'text' => $texts,
            'source_lang' => 'EN',
            'target_lang' => Languages::deepl($job->locale),
            'tag_handling' => 'xml',
            'ignore_tags' => ['x'],
        ];

        if (in_array($job->model, self::MODELS, true)) {
            $payload['model_type'] = $job->model;
        }

        if ($job->tone === 'formal' || $job->tone === 'informal') {
            $payload['formality'] = $job->tone === 'formal' ? 'prefer_more' : 'prefer_less';
        }

        $glossary = $this->glossary($job);
        if ($glossary !== null) {
            $payload['glossary_id'] = $glossary;
        }

        $data = $this->call('POST', $this->base().'/v2/translate', $this->headers(), $payload);

        return array_map(fn ($row) => is_array($row) ? (string) ($row['text'] ?? '') : '', $data['translations'] ?? []);
    }

    public function finish(Job $job): void
    {
        $id = $job->state['deepl_glossary'] ?? null;

        if (is_string($id) && preg_match('/^[a-f0-9-]{36}$/', $id)) {
            try {
                $this->call('DELETE', $this->base().'/v2/glossaries/'.$id, $this->headers(), null);
            } catch (ProviderError) {
                // Left behind on DeepL's side; harmless, and visible in the account.
            }
        }
    }

    private function glossary(Job $job): ?string
    {
        if (! $job->glossary) {
            return null;
        }

        if (array_key_exists('deepl_glossary', $job->state)) {
            return $job->state['deepl_glossary'];
        }

        $rows = [];
        foreach ($job->glossary as [$term, $translation]) {
            $rows[] = str_replace(["\t", "\n", "\r"], ' ', $term)."\t".str_replace(["\t", "\n", "\r"], ' ', $translation);
        }

        try {
            $data = $this->call('POST', $this->base().'/v2/glossaries', $this->headers(), [
                'name' => 'Phrasebook '.date('Y-m-d H:i'),
                'source_lang' => 'en',
                'target_lang' => strtolower(explode('-', Languages::deepl($job->locale))[0]),
                'entries' => implode("\n", $rows),
                'entries_format' => 'tsv',
            ]);
            $id = is_string($data['glossary_id'] ?? null) ? $data['glossary_id'] : null;
        } catch (ProviderError $e) {
            if ($e->fatal()) {
                throw $e;
            }
            // A language pair DeepL has no glossaries for: carry on without.
            $id = null;
        }

        $job->state['deepl_glossary'] = $id;

        return $id;
    }

    private function base(): string
    {
        return str_ends_with($this->key, ':fx') ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Authorization' => 'DeepL-Auth-Key '.$this->key];
    }
}
