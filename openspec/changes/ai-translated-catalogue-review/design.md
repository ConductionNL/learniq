# Design: ai-translated-catalogue-review

## Context

Learniq ships 27 catalogues in `l10n/`; `nl.json` is the one every build lane writes. Rounds 1 and 2 (2026-09-25 to 2026-09-27) added or rewrote 1006 Dutch values. `scripts/build-l10n-js.js` turns every `l10n/*.json` into a `.js` bundle and `--check` fails on a stale one; it already skips dotfiles for `.schema-l10n-baseline.json`. The learniq admin settings (`src/views/settings/AdminRoot.vue`, mounted on Nextcloud's Administration > Learniq page) already host several `NcSettingsSection` blocks. Gate 69 forbids growing the count of manifest `custom` pages.

## Goals / Non-Goals

**Goals:** a committed, testable list of unreviewed AI-written keys; a place a translator works through it; a convention another app can copy.

**Non-Goals:** editing values in the page; languages other than Dutch; other apps.

## Decisions

### D1. A sidecar, not a marker inside the catalogue

Nextcloud reads `l10n/nl.json` as `{translations, pluralForm}`; an extra member per key would be dropped or break the loader, and Transifex-style tooling rewrites the file. A separate file keeps the catalogue untouched. Its name is not a locale code, so Nextcloud never loads it as a language.

### D2. The review action writes the file in the app directory

A review has to survive the next release, and only the repository does that. So the action edits the sidecar where the app is installed: on a development checkout the translator reviews, then commits `l10n/ai-translated.json`. The write goes to a temporary file in the same directory and is renamed over the old one. When the directory is not writable (an app store install), the action answers 409 `read-only` and the list stays readable. Alternative rejected: keeping reviewed keys in `IAppConfig`. It works on any instance, but the next release ships the key again and the review is lost where it matters.

### D3. The admin settings, not a manifest page

The list is administration, and Nextcloud's admin settings page for learniq is where learniq's other admin sections live. A manifest `custom` page would trip gate 69's ratchet; no typed archetype fits a file-backed list. `AiTranslationReviewSection.vue` sits in `AdminRoot.vue` after the data exchange section.

### D4. The l10n build reads only locale-named files

`build-l10n-js.js` filters on `^[a-z]{2,3}(_[A-Z]{2})?\.json$` instead of "any non-dotfile `.json`". Every catalogue in `l10n/` matches; the sidecar and the baseline do not. Alternative rejected: a dotfile name (`.ai-translated.json`), which the brief did not ask for and which hides the file from a translator browsing `l10n/`.

### D5. Seeding rule

Keys on added lines of `git log origin/development --since=2026-09-25 -p -- l10n/nl.json` (string and plural values), kept when the current Dutch value is new or differs from the catalogue at 97f5129c, the last commit before 2026-09-25. 1014 keys appear on added lines; 1006 remain after the rule. A set difference of the two catalogues gives the same 1006, which cross-checks the parse (the first parse missed the 13 plural keys, whose values are arrays). "Status message" appears on an added line but kept its pre-round value, so it is excluded.

### D6. 50 rows per page, filter on key, source and value

1006 rows in one table is unusable and slow. The section filters client-side on any of the three texts and pages the result.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| The list of unreviewed keys | A committed data file | It is repository data, not OpenRegister data. |
| Reading and rewriting it | Imperative service | File I/O on the app's own directory; no register involved. |

## Seed Data (ADR-001)

No OpenRegister schema changes. The sidecar's own seed is D5's 1006 keys plus the 15 Dutch keys this change adds, 1021 in all.

## Risks / Trade-offs

- [Concurrent reviews on one checkout] → each write rereads the file first; the last rename wins, and the losing key stays listed rather than lost.
- [A branch that still carries an old list] → shows as a conflict on the same lines at landing.

## Migration Plan

No migration. Rollback: revert the PR.

## Open Questions

None blocking.
