# AI-translated strings in the catalogue

AI writes many of learniq's Dutch interface texts. Each one stays marked until a translator has checked it. This page describes how that works, so any Conduction app can do the same.

## The list

`l10n/ai-translated.json` sits next to the catalogues:

```json
{
    "$comment": "What this file is and how to review it.",
    "language": "nl",
    "keys": [
        "Everyone has handed in.",
        "Publish marks"
    ]
}
```

- `language` names the catalogue the keys belong to (`l10n/nl.json`).
- `keys` lists catalogue keys whose Dutch value an AI wrote and no human has checked. Keep them sorted and unique.
- Nothing else goes in the file.

The name is not a locale code, so Nextcloud never loads it as a language.

## When you add a string

If an AI wrote the Dutch value, add the key to `keys` in the same pull request. If a person wrote or checked it, leave it out.

## Reviewing

Open **Administration settings > Learniq > AI-translated strings**. Each row shows the English source and the Dutch text. Fix a wrong value in `l10n/nl.json`, then press **Reviewed**: the key leaves the list.

The button edits the list in the app's own folder. Review on a development checkout and commit `l10n/ai-translated.json`. On an installation from a signed release the button says it cannot change the list, and the list stays readable. That holds even when the folder is writable, which it usually is: Nextcloud checks every file of a signed release, and a changed list would show as a code integrity warning.

You can also remove a key by hand in a pull request.

## What keeps it honest

- `tests/unit-js/aiTranslatedCatalogue.test.mjs` fails when the file has another shape, a duplicate, an unsorted key, or a key that is missing from `l10n/nl.json`. Rename a key and the list must follow.
- `scripts/build-l10n-js.js` reads only files named like a locale (`nl.json`, `en_US.json`), so the list is never built as a catalogue and `npm run check:l10n-js` keeps passing.

## Adopting it in another app

1. Add `l10n/ai-translated.json` with the keys your AI lanes wrote. `git log --since=<date> -p -- l10n/nl.json` shows the added lines; keep keys whose value is new or changed since that date.
2. Narrow the file filter in your `build-l10n-js.js` to `/^[a-z]{2,3}(_[A-Z]{2})?\.json$/`.
3. Copy the shape test and point it at your catalogue.
4. For a review screen, copy `AiTranslatedCatalogue`, `AiTranslationReviewController` (admin only) and `AiTranslationReviewSection.vue`.

## Where this comes from

Decision D24 of the learniq competitor round: AI-made translations are visible. Interface strings an AI wrote are flagged in the catalogue and listed for a human translator. Translated content (messages to parents) carries its own notice; see hermiq's message translation feature and portaliq's translated messages.
