<?php

namespace Ernestdefoe\Phrasebook\Api;

use Ernestdefoe\Phrasebook\Provider\Catalog;
use Ernestdefoe\Phrasebook\Provider\Job;
use Ernestdefoe\Phrasebook\Provider\Pricing;
use Ernestdefoe\Phrasebook\Run\Estimator;
use Ernestdefoe\Phrasebook\Run\Run;
use Ernestdefoe\Phrasebook\Run\Runs;
use Ernestdefoe\Phrasebook\Settings\Settings;
use Ernestdefoe\Phrasebook\Settings\Vault;
use Ernestdefoe\Phrasebook\Strings\Languages;
use Ernestdefoe\Phrasebook\Strings\Scanner;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Estimates (dry: true) or starts a run for each chosen locale.
 */
class StartController extends Controller
{
    public function __construct(
        private readonly Scanner $scanner,
        private readonly Runs $runs,
        private readonly Pricing $pricing,
        private readonly Settings $settings,
        private readonly Vault $vault
    ) {
    }

    protected function respond(ServerRequestInterface $request): mixed
    {
        $locales = array_map(fn ($l) => $this->locale($l), $this->strings($this->input($request, 'locales'), 20));
        $namespaces = $this->input($request, 'namespaces');
        $namespaces = is_array($namespaces) ? $this->strings($namespaces) : null;
        $reasons = array_values(array_intersect($this->strings($this->input($request, 'reasons')), Scanner::REASONS));
        $provider = (string) $this->input($request, 'provider', '');
        $model = trim((string) $this->input($request, 'model', ''));
        $tone = (string) $this->input($request, 'tone', 'neutral');
        $notes = trim((string) $this->input($request, 'notes', ''));
        $glossary = trim((string) $this->input($request, 'glossary', ''));
        $budget = $this->input($request, 'budget');
        $dry = (bool) $this->input($request, 'dry', false);

        $def = Catalog::get($provider);

        $errors = array_filter([
            'locales' => $locales === [] ? 'required' : null,
            'reasons' => $reasons === [] ? 'required' : null,
            'provider' => $def === null ? 'invalid' : null,
            'model' => $model === '' || strlen($model) > 191 || preg_match('/[\x00-\x1f\x7f]/', $model) ? 'invalid' : null,
            'tone' => in_array($tone, ['formal', 'informal', 'neutral'], true) ? null : 'invalid',
            'notes' => mb_strlen($notes) > 1000 ? 'too_long' : null,
            'glossary' => mb_strlen($glossary) > 10000 ? 'too_long' : null,
        ]);

        if ($errors) {
            throw new ValidationException($errors);
        }

        $price = $this->pricing->for($provider, $model);
        $template = new Job([], $locales[0], Languages::name($locales[0]), $model, $tone, $notes, Settings::glossary($glossary), 0);
        $plans = [];

        foreach ($locales as $locale) {
            $strings = $this->scanner->scan($locale, $reasons, $namespaces);
            $sources = array_map(fn ($row) => $row['source'], $strings);
            $plans[$locale] = [
                'strings' => $strings,
                'estimate' => Estimator::dryRun($sources, $this->settings->batchSize(), $def['kind'], $price, $template),
            ];
        }

        if ($dry) {
            return [
                'price' => $price,
                'locales' => array_map(fn ($plan) => $plan['estimate'], $plans),
            ];
        }

        if ($price === null) {
            throw new ValidationException(['price' => 'required']);
        }

        if ($def['key'] === 'required' && $this->vault->get($provider) === null) {
            throw new ValidationException(['key' => 'required']);
        }

        if (! is_numeric($budget) || (float) $budget < 0 || (float) $budget > 10000 || ((float) $budget <= 0 && (($price['in'] ?? 0) + ($price['out'] ?? 0) + ($price['char'] ?? 0)) > 0)) {
            throw new ValidationException(['budget' => 'invalid']);
        }

        foreach ($locales as $locale) {
            if (Run::query()->where('locale', $locale)->whereIn('status', [...Run::ACTIVE, 'paused'])->exists()) {
                throw new ValidationException(['locales' => 'run_active']);
            }
        }

        $started = [];
        $actor = RequestUtil::getActor($request);

        foreach ($plans as $locale => $plan) {
            if ($plan['strings'] === []) {
                continue;
            }

            $this->settings->saveStyle($locale, ['tone' => $tone, 'notes' => $notes, 'glossary' => $glossary]);

            $run = $this->runs->start($locale, $plan['strings'], [
                'provider' => $provider,
                'model' => $model,
                'budget' => round((float) $budget, 4),
                'tone' => $tone,
                'notes' => $notes,
                'glossary' => $glossary,
            ], $actor->id);

            $started[] = self::present($run);
        }

        if ($started === []) {
            throw new ValidationException(['strings' => 'nothing_to_translate']);
        }

        return ['runs' => $started, 'queued' => $this->runs->queued()];
    }
}
