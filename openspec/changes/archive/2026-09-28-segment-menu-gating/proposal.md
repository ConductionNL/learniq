---
kind: config
depends_on:
  - segment-runtime-bridge
  - segment-wizard-choice
---

# Proposal: segment-menu-gating

## Summary
Menus now follow the kind of organisation. Company-flavoured entries (staff compliance, external training, gamification, course evaluation), MBO entries (work placements, BPV), higher education entries (binding study advice, BSA) and secondary-and-up entries (exam board, exam accommodations, subject choices, admissions) carry `visibleIf: {"workspace.segment": {"in": [...]}}`, so a primary school sees the school shape: people, classes, attendance, report cards, pupil dossier, group plans, parent conferences, and the school advies it gives. `corporate`, the default of every existing install, stays in every list, so nothing changes for anyone who has not chosen a segment. A validator keeps it that way.

## Motivation
This is step (c) of the chain `segment-feature-flags` named: "(a) a PHP IInitialState provider, (b) runtime.workspace.segment in src/main.js, and (c) only then add visibleIf: {"workspace.segment": ...}". Steps (a) and (b) are `segment-runtime-bridge` (#1022); the wizard that sets the segment is `segment-wizard-choice` (#1028). Round 1 finding 14.6 ("feature flags per segment: hide corporate menus for a school", `_round1/compare/findings.md:51`, rung 1 per `placement.md:68`): Canvas configures feature options per account and sub-account (vendor claim) and Moodle enables or disables features per site (`public/admin/plugins.php`, code path). Recon A lists the change as `segment-menu-gating` and names the corporate, MBO and HE entries.

## Affected Projects
- [x] Project: `learniq`: `visibleIf` on 15 menu entries across 8 `src/manifest.d/` fragments; the wizard's segment step copy; `tests/validate-menu-role-gates.js` learns segment literals and the corporate invariant; a node test pins the matrix; `docs/installation.md`.

## Scope

### In Scope
- `workspace.segment` gates, per the matrix in design.md. Every gate includes `corporate`.
- `validate-menu-role-gates.js`: every `workspace.segment` literal is one of the six schema codes, and every segment gate includes `corporate` (the no-behaviour-change promise, checked on every PR).
- The wizard's segment step says what the answer does now: "The app shows the menus that fit it."
- Docs: which menus each kind of organisation sees.

### Out of Scope
- Narrowing the company segment itself (hiding pupil dossier or BPV for a real company). `corporate` doubles as the undifferentiated default of every existing install (segment-feature-flags Decision 2); narrowing it would take menus away from existing customers. Named as an open question for Ruben.
- The payment menus (orders, order lines, payment transactions): D19 retires them from learniq in the payments migration lane; gating them here would conflict with that removal.
- Pages stay routable: a hidden menu entry is not an access boundary (every page's data stays behind its schema authorization), exactly like the existing role gates.
- Label switching per segment: `segment-vocabulary-labels` (wave 2).

## Approach
JSON only: add the segment key to each entry's existing `visibleIf` (conditions combine with implicit AND in `CnAppNav::passesVisibleIf()`), or to a group so its children follow. The runtime value is always defined since `segment-runtime-bridge`, so the library's undefined-runtime fail-safe can no longer hide an item for everyone.

## New Dependencies
None.

## Impact
- `src/manifest.d/{compliance,dashboard,progress,work-placement,progress-decisions,assessment-board,learning,admissions}.json`: `visibleIf` gains `workspace.segment` on 15 entries.
- `src/manifest.json`: the segment step body; `docs/installation.md`: the matrix.
- `tests/validate-menu-role-gates.js`, new `tests/unit-js/segmentMenuGates.test.mjs`.
- `l10n/`: the changed step body in en and nl.

## Cross-Project Dependencies
None.

## Risks

### Risk 1: a school loses a menu it uses
**Severity:** Medium. **Mitigation:** only entries whose subject belongs to other kinds of organisation are gated, a school that picks the wrong kind changes it under App settings, and the matrix is in design.md and the docs. `corporate` keeps everything.

### Risk 2: a gate forgets `corporate`
**Severity:** Medium. **Mitigation:** `check:menu-role-gates` fails when a segment gate omits `corporate` or names a code the schema does not know.

## Rollback Strategy
Revert the PR; every menu is visible again for every segment.

## Open Questions
- Should the company segment narrow too (hide pupil dossier, group plans, school advies, BPV, BSA for a company), accepting that existing installs on the `corporate` default would lose those menus? This change keeps `corporate` undifferentiated.
