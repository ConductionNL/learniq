---
kind: code
depends_on:
  - company-segment-menu-gating
  - example-set-removal-in-wizard
---

# Proposal: segment-tidy

## Summary
Decision D34 (Ruben, 2026-09-28) has two halves. A company that chose Company in the wizard stops seeing study advice (BSA) and subject choices, the two school surfaces company-segment-menu-gating (D26) left on. And the setup wizard lists every example set that was loaded, each with its own remove button, instead of one step that removes only the set picked last.

## Motivation
- D34 in `learniq/_round1/compare/decisions.md`: "The company segment also hides study advice (BSA) and subject choices; the wizard lists every loaded example set with its own remove button."
- TRACKER-R2 "ROUND 3 FINAL" follow-ups: "company segment still shows BSA and subject choices" and "wizard removes only the last loaded set". Both were raised as open questions in PR #1125 and PR #1138.
- Loading the company set and then the training set (the D29 pair) left one of them removable from the wizard only; the other needed `occ`.

## Affected Projects
- [x] Project: `learniq`: manifest gates (BSA menus and cards, the study progress risk report card, subject choice menus), `LoadedExampleSets` (new), `SeedProfileService`, `SetupController`, `PageController`, `src/utils/exampleSetSteps.js` (new), `src/main.js`, en and nl catalogue entries, `docs/installation.md`, tests.

## Scope

### In Scope
- `{"workspace.chosenSegment": {"notIn": ["corporate"]}}` on the four BSA menu entries (both the menu and the Progress landing cards), the `BsaRiskDashboard` Reports card, and the two subject choice menu entries.
- A loaded-set list in app config (`example_sets_loaded`, `{id, label}` per set): a load adds the set, a clean removal of a recorded import drops it.
- `POST /api/setup/action/remove-example-set-<id>` removes that set; the status reports every `remove-example-set-<id>` step done so none runs by itself.
- `loadedExampleSets` initial state; at page load the browser replaces the single removal step with one step per loaded set.

### Out of Scope
- A nextcloud-vue change so one run-action step can render a list of buttons: the shared wizard posts a step's action with no body, so one step per set is how this app gets one button per set.
- Sets loaded before this change: nothing recorded them, so they show under the single step until they are loaded again (a repeat load adds no objects and records the set).
- The `training` segment: D34 names only the company.

## Approach
Manifest: the same gate D26 put on the other school-only menus. Code: a small app-config service that has no OpenRegister dependency (PageController reads it on the default route, ADR-083 rule 3), recording in `SeedProfileService::install()` and `remove()`, one prefix-parsed action in `SetupController`, and a plain ES module that rewrites `manifest.setup.steps` at boot.

## New Dependencies
None.

## Impact
- A company that chose Company loses 4 BSA menu entries, 4 Progress landing cards, 1 Reports card and 2 subject choice menu entries. An install that never chose keeps them (the gate passes for null).
- The wizard gets N removal steps for N loaded sets, or the single step when none is recorded.
