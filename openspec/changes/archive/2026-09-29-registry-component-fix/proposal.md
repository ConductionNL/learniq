---
kind: code
---

# Proposal: registry-component-fix

## Summary

`src/registry.js` registers 45 of learniq's own `./views/*.vue` files under `kind:
"page"`, plus one `kind: "widget"` entry, but never registers the eight shared
`@conduction/nextcloud-vue` components that 14 `type: "custom"` manifest pages name
directly by their exact export string. `CnPageRenderer.resolveCustomComponent()`
(`node_modules/@conduction/nextcloud-vue/dist/esm/components/CnPageRenderer/CnPageRenderer.vue2.js:1181-1199`)
only resolves a name against the app-supplied `registry` prop (and the deprecated
`customComponents` prop); it has no third path back to the library's own component
catalogue. A miss there returns `null` and the route mounts an empty page body. This
change adds the eight missing entries to `src/registry.js` and a regression test that
diffs every declared `type: "custom"` page's `component` string against the registry
keys, so a future manifest page naming an unregistered component fails a test instead
of shipping a blank page.

## Motivation

Learniq round-1 defect triage (`learniq-defect-triage.md`, entry 1, "Fourteen custom
pages mount a library component `src/registry.js` never registers") found this live in
the browser: CohortTimetable renders "This page is empty" (cited in the same triage
entry, and independently in the M1 baseline capture header). The same root cause is
static-read across 13 more pages by grepping the manifest for the eight component
names. `change-plan.md`'s "Foundational / defects" table lists this as the first,
smallest fix, and the triage's own summary ranks it as the cheapest defect by rows
recovered per unit of effort: it recovers three M1 rows outright (`3.10` bulk enrol,
`7.1` gradebook, `16.4` audit pack export) and is a prerequisite, though not
sufficient alone, for seven more.

The components already exist in the installed library —
`node_modules/@conduction/nextcloud-vue/dist/esm/index.js` lines 42, 51, 87, 88, 91,
92, 97, 117 each export exactly the name the manifest already asks for
(`CnDataMatrix`, `CnStructuredDocReview`, `CnExportWizard`, `CnWizardDialog`,
`CnRichSubmitDialog`, `CnSignatureCapture`, `CnRelationshipGraph`, `CnTimelineView`).
No manifest edit and no new dependency is needed — only the registry wiring the
triage calls "simply never written."

## Affected Projects

- [x] Project: scholiq (app id `learniq`) — `src/registry.js` gains eight registry
  entries; a new regression test is added under `tests/unit-js/`.

## Capabilities

- Added: `component-registry` (the `src/registry.js` kind-tagged map CnAppRoot
  resolves manifest `component` strings against; no existing spec covered this
  file, so this is captured as a new capability rather than a delta)

## Scope

### In Scope

- Import the eight components (`CnDataMatrix`, `CnWizardDialog`,
  `CnRichSubmitDialog`, `CnExportWizard`, `CnSignatureCapture`, `CnTimelineView`,
  `CnStructuredDocReview`, `CnRelationshipGraph`) from `@conduction/nextcloud-vue`
  in `src/registry.js` and register each under `kind: "page"` using the file's
  existing `page()` helper, keyed by the exact export name (matching the
  `component` string already declared in the 14 manifest pages).
- A regression test (`tests/unit-js/registryComponentCoverage.test.mjs`, following
  the existing `connectionRegistry.test.mjs` convention — this repo runs
  `node --test tests/unit-js/*.test.mjs`, there is no vitest binary or config in
  this repo) that reads `src/manifest.json` and every `src/manifest.d/*.json`
  fragment, collects the `component` string of every `type: "custom"` page, and
  fails if any name is missing from `src/registry.js`'s exported map.
- One `npm run build` to prove the webpack bundle still builds with the two new
  imports resolved.

### Out of Scope

- Fixing the two routes the triage calls out as staying partial even after this
  registration fix: `OsoDossierReviewView` (its adapter is delegated to integriq,
  a separate cross-repo dependency) and the QTI export path behind
  `ImportQtiModal`'s sibling row (no manifest page exists for QTI export at all,
  dead or otherwise). Registering the component makes both pages *render*; it does
  not complete the endpoints behind them.
- Any manifest content change — the `component` strings already match the library
  export names verbatim, so no `src/manifest.d/*.json` edit is needed.
- The `$ref` slugification defect (triage entry 7, Related-panel 404s) — unrelated
  root cause, tracked separately.

## Approach

Sixteen lines in `src/registry.js`: one multi-name `import { ... } from
'@conduction/nextcloud-vue'` plus one `Name: page(Name)` entry per component,
placed alongside the existing alphabetised page entries. The regression test is a
static, dependency-free Node script (no browser, no build step) that can run in the
diff-check's `test:js-unit`-equivalent lane.

## New Dependencies

None — all eight components are already shipped by the installed
`@conduction/nextcloud-vue` package; nothing is added to `package.json`.

## Impact

`src/registry.js` (the app-wide component registry passed to `CnAppRoot`); the 14
manifest pages listed in the triage (`learning.json` x8, `people.json` x2,
`data-exchange.json` x2, `dashboard.json` x1, `work-placement.json` x1) start
resolving their `component` instead of mounting an empty body; `tests/unit-js/`
gains one new test file.

## Cross-Project Dependencies

None — this is a self-contained frontend fix within learniq. The `@conduction/nextcloud-vue`
package itself is unchanged; only learniq's own registration of already-shipped
exports is added.

## Risks

### Risk 1: A registered-but-unexercised component surfaces a runtime prop mismatch

**Severity:** Low — **Mitigation:** the manifest already supplies the `props`/`config`
each page passes to its `component`; registering it only changes name resolution, not
prop-passing. `npm run build` plus the new coverage test catch a missing export; a full
runtime smoke test of all 14 pages is out of scope for this S-effort fix and left to the
nightly Playwright matrix per the fleet's verification order.

## Rollback Strategy

Revert the `src/registry.js` diff (a pure addition, no existing entries are touched)
and delete the new test file. No data, schema, or manifest changes to unwind.

## Open Questions

None.
