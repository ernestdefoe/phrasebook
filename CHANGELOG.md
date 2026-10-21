# Changelog

## 1.0.0

First release.

- Find what each language is missing or has out of date, per extension, for core and every enabled extension; installed languages and new ones.
- Translate with your own provider: Anthropic (Claude), OpenAI, Google Gemini, Mistral, DeepSeek, Groq, OpenRouter, Ollama, LM Studio, any OpenAI-compatible server, DeepL, Google Cloud Translation. Models from the provider's own list.
- ICU MessageFormat checked on every answer (placeholders, plural and select branches, the plural forms the language needs, tags, markup); refused answers are retried with the reason.
- Glossary, tone and notes; dry-run cost estimate; a hard budget per language; queued, batched runs with rate-limit backoff.
- Review every string, then save to Linguist or export YAML or a ready-to-publish language pack.
- Admin only: nothing on forum pages.
