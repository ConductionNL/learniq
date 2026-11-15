---
kind: code
depends_on: []
---

# Proposal: segment-runtime-bridge

## Summary
Make the stored segment real at runtime. A new `SegmentService` reads the `LearniqSettings` singleton, `PageController::index()` hands the value to the browser as initial state (`segment`), and `src/main.js` publishes it at `manifest.runtime.workspace.segment`, the path a menu `visibleIf` resolves against. The segment enum gains its sixth value, `training` (training institute), and every value gets a display label with Dutch catalogue keys. This is the follow-up `code` change that the merged `segment-feature-flags` change named in its Out of Scope section; it ships no `visibleIf` of its own.

## Motivation
`segment-feature-flags` (merged as learniq #935) stored the segment and deliberately stopped there. Its design.md traced why: `visibleIf` resolves only against `manifest.runtime.*`, and nothing populates `runtime.workspace`. A `visibleIf: {"workspace.segment": …}` declared today would hit the library's undefined-runtime fail-safe and hide the item for every tenant. The stored value therefore has zero readers.

Round 2 decision D21 (Ruben, 2026-09-27) asks for six organisation kinds chosen in the setup wizard, with one example set each. The enum has five values; "training institute" has none. Recon A open question 1 recommends adding the value now, while nothing reads the field yet, before any `visibleIf` cites the five-value list.

Evidence, round 1 finding 14.6 (`_round1/compare/findings.md:51`), rung 1 per `_round1/compare/placement.md:68` ("a segment flag alongside the existing visibleIf role gating"): Canvas ships "feature options at account, sub-account, course and user level" (vendor claim, community.canvaslms.com Admin Guide); Moodle enables or disables features per site in `public/admin/plugins.php` (code path). Learniq's column read "no: nearest manifest `visibleIf` on `user.primaryRole`", which is still true on `origin/development` today.

## Affected Projects
- [x] Project: `learniq`: new `lib/Service/SegmentService.php`; `lib/Controller/PageController.php` provides `segment` initial state; `src/main.js` and a new `src/utils/workspaceRuntime.js` populate `runtime.workspace.segment`; `lib/Settings/learniq_register.json` `LearniqSettings.segment` gains `training` and `x-enum-labels`; three `LearniqSettings` demo rows move to `corporate`; catalogue keys in `l10n/`.

## Scope

### In Scope
- `SegmentService::currentSegment()`: reads `LearniqSettings` with `_rbac: false` (every signed-in user's menu depends on it and the value is not personal data), returns the most recently updated row with a valid value, and falls back to `corporate` when no row exists, the read fails, or the value is unknown.
- `PageController::index()` provides `segment` as initial state for a signed-in user, next to `primaryRole`.
- `src/main.js` sets `runtime.workspace.segment` from `loadState('learniq', 'segment', 'corporate')`, through a small pure helper that also rejects an unknown value.
- `LearniqSettings.segment` enum: `po`, `vo`, `mbo`, `he`, `corporate`, `training`, with `x-enum-labels` for all six and en/nl catalogue keys; `LearniqSettings.version` and the register `info.version` bumped.
- The three generated `LearniqSettings` demo rows carry `corporate`, so loading the generic demo data never changes what menus an instance shows.
- Unit tests: `SegmentServiceTest`, a new `PageControllerTest` case, the updated `SegmentFeatureFlagsRegisterTest`, and a node test for the runtime helper, each asserting the PHP list, the JS list and the schema enum agree.

### Out of Scope
- Any `visibleIf` on `workspace.segment`: that is `segment-menu-gating`, the fourth change in this lane.
- The setup wizard question that writes the segment: that is `segment-wizard-choice`, the second change in this lane.
- Label switching per segment (pupil vs student vs employee): that is `segment-vocabulary-labels` (wave 2).
- Tightening who may write `LearniqSettings` (it inherits the register cascade today, like `SovereigntyPolicy`); named in design.md as a follow-up.

## Approach
Mirror the one runtime path that already works. `runtime.user.primaryRole` is fed by `PageController` through `IInitialState` and read in `main.js` with `loadState`; the segment takes the same road under a new `workspace` key. The singleton read mirrors `SovereigntyPolicyService::currentPolicy()` (schema-default fallback on miss or failure), with one difference that design.md explains: several rows can exist, so the service picks the newest one instead of the first one.

## New Dependencies
None.

## Impact
- PHP: one new service, one changed controller (one constructor argument, one `provideInitialState` call).
- JS: `main.js` builds `runtime.workspace`; one new utility module.
- Register: one enum value and one `x-enum-labels` map on an existing property; version bumps. No new schema, no migration.
- Demo register: three existing rows change one enum value.

## Cross-Project Dependencies
None. `@conduction/nextcloud-vue` already resolves any `runtime.*` dot path in `visibleIf`; nothing in the shared library changes.

## Risks

### Risk 1: several LearniqSettings rows
**Severity:** Medium. **Mitigation:** the demo import creates three rows and OpenRegister does not enforce a singleton. The service picks the most recently updated valid row, so the last deliberate write wins; the demo rows carry `corporate`, so the worst case is the no-change default. Tested with several rows.

### Risk 2: the menu a user sees depends on a record they cannot read
**Severity:** Low. **Mitigation:** the read runs server-side with `_rbac: false` and only the segment code leaves the server; no other field of the record reaches the browser.

### Risk 3: an admin sets a segment and sees no change yet
**Severity:** Low. **Mitigation:** accepted for one change: `segment-menu-gating` follows in the same lane. The runtime path is observable in the browser (`runtime.workspace.segment`) and covered by tests.

## Rollback Strategy
Revert the PR. The enum value is additive; a `training` row written in the meantime would fail validation after a revert, so a revert should first set such a row back to another value.

## Open Questions
None. D21 settles the sixth value and the single-select segment.
