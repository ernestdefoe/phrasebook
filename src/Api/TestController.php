<?php

namespace Ernestdefoe\Phrasebook\Api;

use Ernestdefoe\Phrasebook\Icu\Validator;
use Ernestdefoe\Phrasebook\Provider\BatchFailed;
use Ernestdefoe\Phrasebook\Provider\Catalog;
use Ernestdefoe\Phrasebook\Provider\Factory;
use Ernestdefoe\Phrasebook\Provider\Job;
use Ernestdefoe\Phrasebook\Provider\Pricing;
use Ernestdefoe\Phrasebook\Provider\ProviderError;
use Ernestdefoe\Phrasebook\Settings\Settings;
use Ernestdefoe\Phrasebook\Strings\Languages;
use Flarum\Foundation\ValidationException;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Tries the key and model on two real strings, one with a plural, so the
 * admin sees the provider work (and keep ICU intact) before starting a run.
 * Costs a fraction of a cent.
 */
class TestController extends Controller
{
    private const SAMPLE = [
        't1' => ['key' => 'core.forum.discussion_controls.reply_button', 'text' => 'Reply', 'feedback' => null],
        't2' => ['key' => 'core.forum.discussion_list.replied_text', 'text' => '{username} replied {ago}, {count, plural, one {# reply} other {# replies}} so far', 'feedback' => null],
    ];

    public function __construct(private readonly Factory $factory, private readonly Pricing $pricing, private readonly Settings $settings)
    {
    }

    protected function respond(ServerRequestInterface $request): mixed
    {
        $provider = (string) Arr::get($request->getQueryParams(), 'provider');
        $model = $this->input($request, 'model');
        $locale = $this->input($request, 'locale', 'de');

        if (! Catalog::exists($provider) || ! is_string($model) || $model === '' || strlen($model) > 191) {
            throw new ValidationException(['model' => 'invalid']);
        }

        $locale = $this->locale($locale);
        $job = new Job(self::SAMPLE, $locale, Languages::name($locale), $model, 'neutral', '', [], 4096, $this->settings->effort());

        try {
            $result = $this->factory->make($provider)->translate($job);
        } catch (BatchFailed $e) {
            return ['ok' => false, 'error' => ['kind' => $e->error->kind, 'detail' => $e->error->detail]];
        } catch (ProviderError $e) {
            return ['ok' => false, 'error' => ['kind' => $e->kind, 'detail' => $e->detail]];
        }

        $price = $this->pricing->for($provider, $model);
        $samples = [];

        foreach (self::SAMPLE as $id => $item) {
            $text = $result['translations'][$id] ?? null;
            $samples[] = [
                'source' => $item['text'],
                'translation' => $text,
                'errors' => $text === null ? [['code' => 'no_answer', 'detail' => '']] : Validator::check($item['text'], $text),
            ];
        }

        return [
            'ok' => true,
            'samples' => $samples,
            'model' => $result['usage']->model,
            'cost' => $price === null ? null : round(Pricing::cost($price, $result['usage']), 6),
        ];
    }
}
