---
kind: code
depends_on: []
---

# Proposal: ai-translated-catalogue-review

## Summary

Learniq marks every Dutch catalogue value an AI lane wrote and no human has checked. A sidecar file, `l10n/ai-translated.json`, lists those keys; a section in the learniq admin settings lists each with its English source and Dutch value, and a "Reviewed" button takes the key off the list. The sidecar is seeded with the 1006 keys that the round 1 and round 2 lanes added or rewrote in `l10n/nl.json`. A docs page describes the convention so other apps can adopt it. This is decision D24 of the learniq competitor round, interface side.

## Motivation

Decision D24 (Ruben, 2026-09-27, `learniq-mi/learniq/_round1/compare/decisions.md`): interface strings translated by AI are flagged in the catalogue and listed on a review page for a human translator. Rounds 1 and 2 landed 65 commits that touched `l10n/nl.json` in two days, every Dutch value written by a build lane. Nothing in the catalogue says so. A translator who wants to check them has to reconstruct the list from git history, and the next lane's strings disappear into the same pile.

Competitor evidence is indirect: finding row 9.4 (`_round1/compare/findings.md`) records that incumbents translate content with AI and do not label it. D24 applies the same honesty to learniq's own interface.

## Affected Projects

- [ ] Project: `learniq`: the sidecar, a service and admin-only endpoints that read and update it, an admin settings section, a node test on the sidecar's shape, a one-line filter change in `scripts/build-l10n-js.js`, and a docs page.

## Scope

### In Scope

- `l10n/ai-translated.json`: `{ "language": "nl", "keys": [...] }` plus a `$comment`, keys sorted and unique, each present in `l10n/nl.json`. Seeded with the 1006 keys derived from `git log origin/development --since=2026-09-25 -p -- l10n/nl.json`, plus the keys this change adds.
- `AiTranslatedCatalogue` service: the list with English source and Dutch value; `markReviewed(key)` removes one key and rewrites the file atomically, or refuses with a named reason when the app directory is read-only.
- `GET /api/l10n/ai-translated` and `POST /api/l10n/ai-translated/reviewed`, admin only (`#[AuthorizedAdminSetting]`).
- `AiTranslationReviewSection.vue` in `AdminRoot.vue`: a filter, the list in pages of 50, a "Reviewed" button per row.
- `scripts/build-l10n-js.js` reads only files named like a locale, so the sidecar is never taken for a catalogue and `npm run check:l10n-js` keeps passing.
- `tests/unit-js/aiTranslatedCatalogue.test.mjs` guards the sidecar's shape.
- `docs/Technical/ai-translated-catalogue.md`.

### Out of Scope

- Editing a Dutch value from the page. The translator edits `l10n/nl.json` in a pull request; the page only records that a value was checked.
- Other languages than Dutch. The shape carries `language`, so a second sidecar can follow.
- Adopting the convention in other apps.

## Approach

The sidecar lives next to the catalogues it describes, so it moves with every pull request that adds a string. The review action writes the sidecar in the app's own directory: a translator reviews on a development checkout and commits the changed file, which is the only place a review survives the next release. On an instance where the app directory is read-only the action says so, and the list stays readable. The admin settings section uses the existing Nextcloud admin page, so no custom page is added (gate 69 ratchet).

## New Dependencies

None.

## Impact

- New files: sidecar, service, controller, Vue section, node test, PHP tests, docs page.
- `appinfo/routes.php` gains two routes; `AdminRoot.vue` gains one section; `build-l10n-js.js` narrows its file filter.
- No register or schema change.

## Cross-Project Dependencies

None. hermiq (#971) and portaliq (#811) implement the content side of D24.

## Risks

### Risk 1: A reviewed key comes back
**Severity:** Medium. **Mitigation:** the sidecar is committed; a later branch that still carries the old list conflicts on the same lines, so the landing sees it.

### Risk 2: Writing into the app directory
**Severity:** Low. **Mitigation:** admin only; the key must already be in the list; the write goes to a temporary file in the same directory and is renamed into place; a read-only directory is refused, not worked around.

### Risk 3: The list goes stale when a key is renamed
**Severity:** Low. **Mitigation:** the node test fails when a listed key is missing from `l10n/nl.json`, so the renaming pull request fixes the list.

## Rollback Strategy

Revert the PR. The sidecar is inert to Nextcloud (not a locale code) and to the l10n build once the filter is in; without the filter change it must go with it.

## Open Questions

None blocking.
