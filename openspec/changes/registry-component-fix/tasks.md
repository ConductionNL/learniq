# Tasks: registry-component-fix

## Implementation Tasks

### Task 1: Register the eight missing library components
- **spec_ref**: `openspec/changes/registry-component-fix/specs/component-registry/spec.md#requirement-every-type-custom-manifest-pages-component-must-be-registered`
- **files**: `src/registry.js`
- **acceptance_criteria**:
  - GIVEN `src/registry.js` WHEN its exported map is inspected THEN it contains a
    `kind: "page"` entry for each of `CnDataMatrix`, `CnWizardDialog`,
    `CnRichSubmitDialog`, `CnExportWizard`, `CnSignatureCapture`, `CnTimelineView`,
    `CnStructuredDocReview`, `CnRelationshipGraph`, imported from
    `@conduction/nextcloud-vue`
  - GIVEN the app is built WHEN `npm run build` runs THEN it exits 0
- [x] Implement
- [x] Test

### Task 2: Add the registry-coverage regression test
- **spec_ref**: `openspec/changes/registry-component-fix/specs/component-registry/spec.md#requirement-a-regression-test-must-fail-when-a-custom-page-names-an-unregistered-component`
- **files**: `tests/unit-js/registryComponentCoverage.test.mjs`
- **acceptance_criteria**:
  - GIVEN every `type: "custom"` page across `src/manifest.json` and
    `src/manifest.d/*.json` WHEN the test runs THEN it asserts each page's
    `component` string is a `kind: "page"` key in `src/registry.js`'s exported map
  - GIVEN a temporarily-removed registry entry (simulated in the test itself via a
    fixture map, not by mutating the real registry) WHEN the same assertion runs
    against that fixture THEN it fails and names the missing component
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria
- [x] Code review against spec requirements

## Tests (company-wide ADR-009)
- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — N/A, no
  PHP changed in this fix
- [x] Newman/Postman tests for new/changed API endpoints — N/A, no API changed
  - N/A, confirmed in r5-structure: 955c1f56 (#907) changes only `src/registry.js` and a JS unit test; no API.
- [ ] Browser tests (Playwright MCP) for UI changes — deferred to the nightly
  Playwright matrix per the fleet's verification order; not run per-PR
  - Not run (r5-structure, 2026-09-28): the route smoke suite `tests/e2e/pages.spec.ts` covers these 14 pages, but it needs a live instance serving this code and lanes may not drive the shared instance on :8080. `node --test tests/unit-js/registryComponentCoverage.test.mjs` is the per-PR guard.
- [x] All tests pass (`node --test tests/unit-js/*.test.mjs`)

## Documentation (company-wide ADR-010)
- [x] Feature documentation updated in `docs/` — N/A, no user-facing behaviour
  description changes (the pages already appeared in the manifest/docs as
  planned; this fix makes them actually render)
  - N/A, confirmed in r5-structure: only `src/registry.js` changed; the pages were already documented.
- [x] Screenshot captured and committed to `docs/images/` — N/A, no visual design
  change, only a previously-blank page now rendering its already-documented
  content
  - N/A, confirmed in r5-structure: no design change, the pages now render the content already planned.

## i18n (company-wide ADR-005)
- [x] Dutch (`nl_NL`) and English (`en_US`) translation strings added — N/A, no
  new user-facing strings; the manifest labels already existed
  - N/A, confirmed in r5-structure: 955c1f56 adds no catalogue key; the manifest labels already existed.
