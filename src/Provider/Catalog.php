<?php

namespace Ernestdefoe\Phrasebook\Provider;

/**
 * Every provider Phrasebook can call. Hosted providers have a fixed base URL
 * (an admin can't point a key meant for OpenAI somewhere else); local ones
 * and "OpenAI-compatible" take the admin's own base URL, checked by Http.
 *
 * keyUrl is where the admin makes a key, pricingUrl where the prices that
 * aren't built in are published; the settings page links both.
 */
final class Catalog
{
    public const PROVIDERS = [
        'anthropic' => [
            'label' => 'Anthropic (Claude)', 'kind' => 'llm', 'adapter' => 'anthropic',
            'base' => 'https://api.anthropic.com', 'editable' => false, 'key' => 'required',
            'keyUrl' => 'https://console.anthropic.com/settings/keys',
            'pricingUrl' => 'https://claude.com/pricing#api',
        ],
        'openai' => [
            'label' => 'OpenAI', 'kind' => 'llm', 'adapter' => 'openai',
            'base' => 'https://api.openai.com/v1', 'editable' => false, 'key' => 'required',
            'keyUrl' => 'https://platform.openai.com/api-keys',
            'pricingUrl' => 'https://openai.com/api/pricing/',
        ],
        'gemini' => [
            'label' => 'Google Gemini', 'kind' => 'llm', 'adapter' => 'gemini',
            'base' => 'https://generativelanguage.googleapis.com', 'editable' => false, 'key' => 'required',
            'keyUrl' => 'https://aistudio.google.com/apikey',
            'pricingUrl' => 'https://ai.google.dev/gemini-api/docs/pricing',
        ],
        'mistral' => [
            'label' => 'Mistral', 'kind' => 'llm', 'adapter' => 'openai',
            'base' => 'https://api.mistral.ai/v1', 'editable' => false, 'key' => 'required',
            'keyUrl' => 'https://console.mistral.ai/api-keys',
            'pricingUrl' => 'https://mistral.ai/pricing#api-pricing',
        ],
        'deepseek' => [
            'label' => 'DeepSeek', 'kind' => 'llm', 'adapter' => 'openai',
            'base' => 'https://api.deepseek.com/v1', 'editable' => false, 'key' => 'required',
            'keyUrl' => 'https://platform.deepseek.com/api_keys',
            'pricingUrl' => 'https://api-docs.deepseek.com/quick_start/pricing',
        ],
        'groq' => [
            'label' => 'Groq', 'kind' => 'llm', 'adapter' => 'openai',
            'base' => 'https://api.groq.com/openai/v1', 'editable' => false, 'key' => 'required',
            'keyUrl' => 'https://console.groq.com/keys',
            'pricingUrl' => 'https://groq.com/pricing',
        ],
        'openrouter' => [
            'label' => 'OpenRouter', 'kind' => 'llm', 'adapter' => 'openai',
            'base' => 'https://openrouter.ai/api/v1', 'editable' => false, 'key' => 'required',
            'keyUrl' => 'https://openrouter.ai/settings/keys',
            'pricingUrl' => 'https://openrouter.ai/models',
        ],
        'ollama' => [
            'label' => 'Ollama (local)', 'kind' => 'llm', 'adapter' => 'openai',
            'base' => 'http://localhost:11434/v1', 'editable' => true, 'key' => 'optional', 'local' => true,
            'keyUrl' => 'https://ollama.com/download',
            'pricingUrl' => null,
        ],
        'lmstudio' => [
            'label' => 'LM Studio (local)', 'kind' => 'llm', 'adapter' => 'openai',
            'base' => 'http://localhost:1234/v1', 'editable' => true, 'key' => 'optional', 'local' => true,
            'keyUrl' => 'https://lmstudio.ai/docs/app/api',
            'pricingUrl' => null,
        ],
        'custom' => [
            'label' => 'OpenAI-compatible endpoint', 'kind' => 'llm', 'adapter' => 'openai',
            'base' => '', 'editable' => true, 'key' => 'optional',
            'keyUrl' => null,
            'pricingUrl' => null,
        ],
        'deepl' => [
            'label' => 'DeepL', 'kind' => 'mt', 'adapter' => 'deepl',
            'base' => 'https://api.deepl.com', 'editable' => false, 'key' => 'required',
            'keyUrl' => 'https://www.deepl.com/your-account/keys',
            'pricingUrl' => 'https://www.deepl.com/pro-api',
        ],
        'google' => [
            'label' => 'Google Cloud Translation', 'kind' => 'mt', 'adapter' => 'google',
            'base' => 'https://translation.googleapis.com', 'editable' => false, 'key' => 'required',
            'keyUrl' => 'https://console.cloud.google.com/apis/credentials',
            'pricingUrl' => 'https://cloud.google.com/translate/pricing',
        ],
    ];

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $id): ?array
    {
        return self::PROVIDERS[$id] ?? null;
    }

    public static function exists(string $id): bool
    {
        return isset(self::PROVIDERS[$id]);
    }
}
