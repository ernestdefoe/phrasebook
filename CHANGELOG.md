# Changelog

## 1.1.0

- **Accept and push all.** One button on the Runs tab accepts every valid translation in each finished run and saves it to Linguist, after a confirm that lists each language and how many strings it will save.
- **Pushed languages are cleared from the list.** A run with nothing left to review leaves the Runs list; one with failed strings stays, showing how many failed, and each failed string keeps its reason. "Show cleared runs" brings cleared ones back, and their strings stay available for export.
- **New strings show on the forum without a cache clear.** After saving to Linguist, the forum rebuilds its translations on the next page load.
- **Tick only extensions with missing strings**, in one language's table or in every language at once.
- The Runs list shows each run's strings: to push, failed and saved.
- Error text uses Flarum's error color, so it shows in light themes too.

Thanks @"dutchbavarian"#p269888 for the suggestions.

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
