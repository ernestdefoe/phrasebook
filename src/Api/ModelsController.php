<?php

namespace Ernestdefoe\Phrasebook\Api;

use Ernestdefoe\Phrasebook\Provider\Catalog;
use Ernestdefoe\Phrasebook\Provider\Factory;
use Ernestdefoe\Phrasebook\Provider\Pricing;
use Ernestdefoe\Phrasebook\Provider\ProviderError;
use Ernestdefoe\Phrasebook\Settings\Settings;
use Flarum\Foundation\ValidationException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The provider's own model list, for the model picker. Cached for ten
 * minutes per provider and base URL; a failure is reported, not cached, and
 * the page falls back to typing a model id.
 */
class ModelsController extends Controller
{
    public function __construct(
        private readonly Factory $factory,
        private readonly Cache $cache,
        private readonly Settings $settings,
        private readonly Pricing $pricing
    ) {
    }

    public static function cacheKey(string $provider): string
    {
        return 'phrasebook.models.'.$provider;
    }

    protected function respond(ServerRequestInterface $request): mixed
    {
        $provider = (string) Arr::get($request->getQueryParams(), 'provider');

        if (! Catalog::exists($provider)) {
            throw new ValidationException(['provider' => 'invalid']);
        }

        $base = $this->settings->baseUrl($provider);
        $cached = $this->cache->get(self::cacheKey($provider));

        if (is_array($cached) && ($cached['base'] ?? null) === $base && $this->query($request, 'refresh') !== '1') {
            return ['models' => $cached['models'], 'error' => null];
        }

        try {
            $models = $this->factory->make($provider)->models();
        } catch (ProviderError $e) {
            return ['models' => [], 'error' => ['kind' => $e->kind, 'detail' => $e->detail]];
        }

        $models = array_slice($models, 0, 2000);
        $this->pricing->remember($models);
        $this->cache->put(self::cacheKey($provider), ['base' => $base, 'models' => $models], 600);

        return ['models' => $models, 'error' => null];
    }
}
