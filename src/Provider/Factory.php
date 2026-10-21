<?php

namespace Ernestdefoe\Phrasebook\Provider;

use Ernestdefoe\Phrasebook\Net\Http;
use Ernestdefoe\Phrasebook\Settings\Settings;
use Ernestdefoe\Phrasebook\Settings\Vault;

class Factory
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Vault $vault,
        private readonly Http $http
    ) {
    }

    /**
     * @throws ProviderError 'config' when the provider is unknown or has no key
     */
    public function make(string $id): Provider
    {
        $def = Catalog::get($id) ?? throw new ProviderError('config', 0, null, 'unknown_provider');
        $key = $this->vault->get($id);

        if ($def['key'] === 'required' && ($key === null || $key === '')) {
            throw new ProviderError('config', 0, null, 'no_key');
        }

        return match ($def['adapter']) {
            'anthropic' => new AnthropicProvider($this->http, (string) $key),
            'gemini' => new GeminiProvider($this->http, (string) $key),
            'deepl' => new DeepLProvider($this->http, (string) $key),
            'google' => new GoogleProvider($this->http, (string) $key),
            default => new OpenAiProvider($this->http, $id, $this->settings->baseUrl($id), $key, $this->settings->allowLocal()),
        };
    }
}
