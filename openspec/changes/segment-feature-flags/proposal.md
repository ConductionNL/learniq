---
kind: config
---

# Proposal: segment-feature-flags

## Summary
Learniq has no segment concept (po, vo, mbo, he, corporate) anywhere in the register or manifest today; every menu is gated only by `user.primaryRole` (round-1 finding 14.6, NICE tier; corpus: Canvas/Moodle both ship feature options at account/site level). This change adds the admin-settable half only: a `LearniqSettings` singleton schema (`segment` enum, plus `setBy`/`setAt` for traceability, mirroring the existing `SovereigntyPolicy` singleton precedent) with an index+detail page under an admin-only menu entry, so a school administrator can record which segment their instance runs. **It does not add any `visibleIf` menu gating on the `segment` value** — see Scope and design.md for why building that half now would either do nothing or actively regress corporate tenants, and why it is tracked as a separate follow-up `code` change instead of shipped broken.

## Motivation
`visibleIf` conditions in this manifest schema resolve exclusively against `manifest.runtime.*` — a dot-path into an object each app populates itself in `src/main.js` from Nextcloud `IInitialState` values (confirmed by reading `src/main.js`'s own `runtime.user.primaryRole` population and its comment: "absent runtime would (by lib fail-safe) hide every role-gated menu item"). There is no generic, app-agnostic mechanism that publishes an OpenRegister object's field into `manifest.runtime` automatically; every existing `runtime.*` path in this app was wired by hand in `src/main.js` plus a PHP-side `IInitialState::provideInitialState()` call. Introducing a new `visibleIf: {"workspace.segment": ...}` condition on corporate-flavoured menu items in this `config`-kind change, with no code to ever populate `runtime.workspace.segment`, would make the library's documented fail-safe hide those items for **every** tenant permanently, including corporate tenants who should see them — an active regression, not a feature, and worse than doing nothing. Building the JS/PHP wiring in the same change would make it `mixed` under ADR-032 (this repo's `kind: config` vs `kind: code` split, already the reason this lane split `school-year-shape`'s `TimetableConflictQueue` row out the same way).

## Affected Projects
- [x] Project: `learniq` — `lib/Settings/learniq_register.json` gains a `LearniqSettings` schema; `src/manifest.d/compliance.json` gains an admin-only index+detail page pair for it.

## Scope

### In Scope
- `LearniqSettings` schema: `segment` (enum `po`/`vo`/`mbo`/`he`/`corporate`, default `corporate` — the safest no-behaviour-change default for existing customers, all of whom are today's undifferentiated corporate-flavoured build), `setBy`/`setAt` (nullable, derived, mirroring `SovereigntyPolicy`'s traceability fields). No lifecycle (flat singleton, same convention as `SovereigntyPolicy`).
- An admin-only index+detail page pair for `LearniqSettings` under the existing `GroupCompliance` menu area, following the plain declarative convention every other schema in this app uses (no custom Vue component, no bespoke PHP controller).
- A register-JSON unit test for the schema shape.

### Out of Scope (tracked as a follow-up `code` change)
- Any `visibleIf` condition reading `segment` — see Motivation. A follow-up `code` change must: (a) add a PHP `IInitialState::provideInitialState('learniq', 'segment', ...)` call reading `LearniqSettings.segment` (settings/page controller), (b) populate `runtime.workspace.segment` in `src/main.js` the same way `runtime.user.primaryRole` is populated today, and (c) only then add `visibleIf: {"workspace.segment": {"in": [...]}}` to the corporate-flavoured menu items. Only step (c) is `config`-shaped; (a)/(b) are not.
- Deciding exactly which menu items count as "corporate-flavoured" — that decision belongs with the follow-up change, once the gating mechanism actually exists to test against.
- "Tests on the manifest builder" (named in the round brief) for the `visibleIf` gating itself — nothing to test yet; the schema-shape test in this change is the honest scope.

## Approach
One new flat singleton schema (no lifecycle) and a declarative index+detail page pair, exactly matching this app's existing pattern for admin-configurable records (`SovereigntyPolicy`, `School`, `Staff`). Declarative only (ADR-031) — no PHP, no new Vue component, no runtime wiring.

## New Dependencies
None.

## Impact
- `lib/Settings/learniq_register.json`: one new schema (`LearniqSettings`); register version bump.
- `src/manifest.d/compliance.json`: one new admin-only menu entry, two new pages.
- `tests/Unit/Settings/SegmentFeatureFlagsRegisterTest.php`: new.

## Cross-Project Dependencies
None.

## Rollback Strategy
Revert the register-JSON and manifest-JSON diffs; additive/declarative, no migration to unwind.

## Open Questions
None — the scope split is a finding of this change, not an open question: see design.md for the full reasoning.
