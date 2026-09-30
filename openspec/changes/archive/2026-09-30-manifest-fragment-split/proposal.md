---
kind: config
depends_on:
  - rename-to-learniq
---

# Proposal: manifest-fragment-split

## Summary

Scholiq's `src/main.js` already builds its effective navigation through the shared `buildManifest(base, fragments, menuLayout)` pipeline (ADR-044 pt.1), but `src/manifest.json` remains a single 14,663-line monolith carrying all 24 top-level menu groups and 275 pages inline — only two small fragments exist today (`learning-dashboard.json`, `people-dashboard.json`, added by `nav-restructure-dashboards` to declare the two domain-dashboard landing pages). ADR-044 §6 requires an app on a monolithic manifest to adopt the ADR-037 fragment pipeline in full *before* it can adopt the foldout/cards-collapse work. This change splits the monolith into fourteen `src/manifest.d/*.json` fragments — one per eventual top-level menu group and one per module that a later change will remove or gate per sector — with **zero behaviour change**: the manifest `buildManifest()` produces after the split must be byte-identical to what it produces today.

## Motivation

`menu-six-main-items` (the next change in this chain) needs to express the 24→6(+2) top-level collapse as `menu-layout.json` relocations over disjoint fragment files, not as surgery inside a 14k-line monolith. Splitting first, and separately from the reshuffle, means the risky part (touching all 275 pages) is a pure mechanical move with a diffable verification, and the risky-in-a-different-way part (changing what users see) is a small, reviewable `menu-layout.json` edit. It also means the modules already slated to leave the app (`data-exchange` → openconnector, `payments` → pipelinq — see `openconnector-flow-migration`) become **file deletions** in their own future changes instead of monolith surgery, and the education-specific modules that a sector-profile change will eventually gate on/off (BPV, guardian meetings, admissions, pupil record, exam board, BSA progress decisions) each already sit in their own file, ready for a `visibleIf`/inclusion toggle.

## Affected Projects

- [ ] Project: `scholiq` — `src/manifest.json` split into `src/manifest.d/*.json` fragments; `src/manifest.d/learning-dashboard.json` and `people-dashboard.json` renamed/consolidated into the new `learning.json` / `people.json` fragments; no PHP, no Vue component, no `src/main.js` change (the `buildManifest`/`require.context` wiring already exists).

No other apps-extra project is touched.

## Scope

### In Scope

1. Split `src/manifest.json`'s `menu[]` and `pages[]` content into fourteen new `src/manifest.d/*.json` fragments, one top-level menu id (and its full existing subtree, unchanged) assigned to exactly one fragment file:
   - **Six target-group fragments**: `dashboard.json`, `learning.json`, `people.json`, `progress.json`, `compliance.json`, `my-learning.json`
   - **Six education-specific module fragments** (sector-toggle candidates): `work-placement.json`, `guardian-meetings.json`, `admissions.json`, `pupil-record.json`, `assessment-board.json`, `progress-decisions.json`
   - **Two leaving-app fragments**: `data-exchange.json`, `payments.json`
2. Consolidate the two existing dashboard fragments into the new scheme: `learning-dashboard.json`'s content (the `LearningDashboard` page + the `GroupLearning` menu route) merges into `learning.json`; `people-dashboard.json`'s content merges into `people.json`. The two old filenames are removed.
3. Leave `src/manifest.json` holding only `$schema`, `version`, `dependencies`, `observability`, `deepLinks`, and empty `pages: []` / `menu: []` — plus the four utility menu singles that don't belong to any of the fourteen named boundaries (`Documentation`, `FeaturesRoadmapMenu`, `XapiStatementsMenu`, `Rollover` — footer/settings items untouched by the six-group or extraction-module boundaries; see design.md for the reasoning and the open question this leaves).
4. No edit to `src/menu-layout.json` — relocations/removals/settingsSection are untouched by this change; they operate on the merged output regardless of which file a node's source lives in.
5. No edit to `src/main.js` — the `require.context('./manifest.d/', false, /\.json$/)` collection already picks up every `*.json` fragment in the directory.
6. A verification step that computes the effective merged manifest (via `buildManifest`) from the pre-split tree and from the post-split tree and asserts the two are deep-equal — not "the app still boots," an actual diff.

### Out of Scope

- Any menu-layout relocation, removal, or settings-foldout change — that is `menu-six-main-items`, the next change in this chain.
- Deleting the `data-exchange` or `payments` fragments (their removal is `openconnector-flow-migration` and a future pipelinq-extraction change respectively) — this change only makes their eventual deletion a one-file operation.
- Any sector-profile visibility toggle on the six module fragments — this change only gives them a file boundary; wiring `visibleIf`/inclusion logic is a future change.
- Any new page, component, or feature. This is a structural refactor of existing declarations only.

## Approach

Mechanical JSON-patch split, `kind: config` per ADR-031: move each top-level `menu[]` entry (and every `pages[]` entry it or its children reference) verbatim into its assigned fragment file, then empty `manifest.json`'s `pages`/`menu` down to the remainder. No JS logic changes anywhere — `buildManifest`'s existing `mergePages`/`mergeMenuItems` (id-keyed merge, first-fragment-wins on scalar fields, children unioned) already does the reassembly; the split only has to avoid two hazards: (a) never split one top-level node's `children[]` across two fragment files (array order is significant and fragment merge order depends on `require.context`'s sorted filenames, so a cross-file split of one node's children risks silently reordering the rendered menu), and (b) never let two fragments declare conflicting top-level content for the same id. Full boundary mapping and the GroupInsight handling decision are in design.md.

## New Dependencies

None.

## Impact

- `src/manifest.json` — shrinks from 14,663 lines to a skeleton (five metadata keys + four utility menu singles + their handful of pages).
- `src/manifest.d/` — grows from 2 files to 14 (the 2 existing ones are consolidated into 2 of the 14, not left as extras).
- `src/main.js` — unchanged.
- `src/menu-layout.json` — unchanged.
- No route, page id, or menu id changes — every existing id keeps its current value; only the *file* it's declared in moves.

## Cross-Project Dependencies

None directly. This change is a prerequisite for `menu-six-main-items` (same repo) and makes the pending `openconnector-flow-migration` data-exchange extraction and a future pipelinq payments extraction cheaper (file deletion instead of monolith surgery), but does not itself touch either.

## Risks

### Risk 1: A split reorders a group's children and silently changes the rendered nav
**Severity:** Medium — **Mitigation:** the hard rule in design.md — one top-level node's full subtree lives in exactly one fragment file, never split across two — removes the hazard by construction. The verification task diffs the full merged manifest (menu tree included, in order) before vs. after, so an accidental reorder fails the task rather than shipping.

### Risk 2: `require.context`'s webpack-time file enumeration silently drops a new fragment (e.g. a typo'd extension) and a group goes missing from the merged menu
**Severity:** Low — **Mitigation:** `require.context('./manifest.d/', false, /\.json$/)` already globs the directory; the verification task's deep-equal check against the pre-split baseline catches a dropped fragment as a missing top-level id, not a green build.

### Risk 3: The four leftover utility singles (Documentation, Features & roadmap, xAPI statements, Rollover) sit in an ambiguous "no fragment" state
**Severity:** Low — **Mitigation:** documented explicitly in design.md and proposal scope rather than silently left; recorded as a DEFERRED_QUESTION for a human decision (own fragment vs. staying in the base manifest permanently).

## Rollback Strategy

Pure frontend/config change, no data migration. Revert the change branch (restores the monolithic `manifest.json` and the two original fragment files) and rebuild; `menu-layout.json` and `main.js` are untouched by this change so nothing else needs to unwind.

## Open Questions

- Should the four utility singles (`Documentation`, `FeaturesRoadmapMenu`, `XapiStatementsMenu`, `Rollover`) get their own fragment file (e.g. `shell.json`) for consistency, or is leaving them in the now-skeleton `manifest.json` the intended long-term home since they don't belong to any of the fourteen named boundaries? Provisionally left in `manifest.json`; see DEFERRED_QUESTIONS in the final report.
- `GroupInsight` (the still-intact legacy group holding `Compliance`, three Accessibility leaves, and the AI-processing-disclosure leaf as of this measurement) is assigned wholesale to `dashboard.json` in this change rather than being pre-split toward its eventual Dashboard/Compliance destinations — see design.md "GroupInsight handling" for why, and DEFERRED_QUESTIONS for the provisional call.
