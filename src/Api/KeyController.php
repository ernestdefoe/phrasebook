<?php

namespace Ernestdefoe\Phrasebook\Api;

use Ernestdefoe\Phrasebook\Provider\Catalog;
use Ernestdefoe\Phrasebook\Settings\Vault;
use Flarum\Foundation\ValidationException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Saves (POST) or removes (DELETE) a provider's API key. The key comes in,
 * is encrypted, and never goes out again.
 */
class KeyController extends Controller
{
    public function __construct(private readonly Vault $vault, private readonly Cache $cache)
    {
    }

    protected function respond(ServerRequestInterface $request): mixed
    {
        $provider = (string) Arr::get($request->getQueryParams(), 'provider');

        if (! Catalog::exists($provider)) {
            throw new ValidationException(['provider' => 'invalid']);
        }

        // Models listed under the old key may not be the new key's.
        $this->cache->forget(ModelsController::cacheKey($provider));

        if ($request->getMethod() === 'DELETE') {
            $this->vault->forget($provider);

            return ['hasKey' => false, 'keyHint' => null];
        }

        $key = $this->input($request, 'key');

        if (! is_string($key) || ($key = trim($key)) === '' || strlen($key) > 500 || preg_match('/[\x00-\x20\x7f]/', $key)) {
            throw new ValidationException(['key' => 'invalid']);
        }

        $this->vault->put($provider, $key);

        return ['hasKey' => true, 'keyHint' => '…'.mb_substr($key, -4)];
    }
}
