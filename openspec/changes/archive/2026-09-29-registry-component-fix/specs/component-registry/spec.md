# component-registry

## ADDED Requirements

### Requirement: Every `type: "custom"` manifest page's component MUST be registered

`src/registry.js` SHALL export a `kind: "page"` entry, keyed by the exact
`component` string, for every page declared with `"type": "custom"` across
`src/manifest.json` and every fragment under `src/manifest.d/*.json`.
`CnPageRenderer.resolveCustomComponent()` resolves a custom page's component only
against the app-supplied `registry` prop (with a deprecated `customComponents`
fallback); it has no path back to a shared library's own export catalogue. A
`component` string with no matching registry key renders an empty page body and
logs `[CnPageRenderer] Custom component "${name}" not found in registry`.

#### Scenario: A manifest page names a library component

- **GIVEN** `src/manifest.d/learning.json` declares a `type: "custom"` page whose
  `component` is `"CnDataMatrix"`
- **WHEN** `src/registry.js` is loaded and its exported map is inspected
- **THEN** the map contains a `CnDataMatrix` key whose value has `kind: "page"`
  and a `component` field that is the real `CnDataMatrix` Vue component imported
  from `@conduction/nextcloud-vue`

#### Scenario: The eight previously-unregistered shared components are registered

- **GIVEN** the eight components `CnDataMatrix`, `CnWizardDialog`,
  `CnRichSubmitDialog`, `CnExportWizard`, `CnSignatureCapture`, `CnTimelineView`,
  `CnStructuredDocReview`, `CnRelationshipGraph` are each named by at least one
  `type: "custom"` manifest page's `component` field
- **WHEN** `src/registry.js`'s exported map is inspected
- **THEN** each of the eight names resolves to a `kind: "page"` entry

### Requirement: A regression test MUST fail when a custom page names an unregistered component

A test SHALL exist that reads every manifest page with `"type": "custom"`, collects
its `component` string, and asserts that string is a key in `src/registry.js`'s
exported map — so that adding a new custom page (or renaming/removing a registry
entry) without wiring the corresponding registry entry fails the test rather than
shipping an empty page.

#### Scenario: A hypothetical unregistered custom component fails the test

- **GIVEN** a manifest page declares `"type": "custom"` with a `component` string
  that has no matching key in `src/registry.js`'s exported map
- **WHEN** the registry-coverage test runs
- **THEN** the test fails and names the offending page id and component string

#### Scenario: The real manifest passes after the fix

- **GIVEN** the eight components from REQ-001 are registered
- **WHEN** the registry-coverage test runs against the real
  `src/manifest.json` and `src/manifest.d/*.json`
- **THEN** the test passes with zero missing components
