# Changelog

## 1.0.2

- Every extension is listed under every language. An extension with nothing left to translate is shown as up to date (greyed out, can't be ticked) instead of disappearing, so a language whose pack covers most extensions, like Chinese (Simplified), no longer looks like it is missing installed extensions.

## 1.0.1

- Each language's table now has its own ticks: the box at the top of a table ticks or unticks every extension in that table and no other, and shows a dash when only some are ticked. Before, all tables shared one set of ticks, so unticking one table changed rows in the others and left rows ticked that only appeared in other tables.
- A language with nothing ticked is skipped and its estimate says so, instead of showing a cost for 0 strings.
- You can type in the "Add language" field again (a key handler swallowed every key but Enter).
- Rebuilt with flarum-webpack-config 3.1.0.

## 1.0.0

First release.

- Find what each language is missing or has out of date, per extension, for core and every enabled extension; installed languages and new ones.
- Translate with your own provider: Anthropic (Claude), OpenAI, Google Gemini, Mistral, DeepSeek, Groq, OpenRouter, Ollama, LM Studio, any OpenAI-compatible server, DeepL, Google Cloud Translation. Models from the provider's own list.
- ICU MessageFormat checked on every answer (placeholders, plural and select branches, the plural forms the language needs, tags, markup); refused answers are retried with the reason.
- Glossary, tone and notes; dry-run cost estimate; a hard budget per language; queued, batched runs with rate-limit backoff.
- Review every string, then save to Linguist or export YAML or a ready-to-publish language pack.
- Admin only: nothing on forum pages.
