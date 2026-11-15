## Implementation Tasks

### Task 1: Build the query-builder and result-classifier module
- **spec_ref**: `openspec/specs/dashboard/spec.md#requirement-a-fast-finder-query-builder-splits-one-search-term-into-per-kind-openregister-requests`
- **files**: `src/utils/globalSearch.js`
- **acceptance_criteria**:
  - GIVEN a blank/whitespace term WHEN `buildGlobalSearchRequests` runs THEN it returns an empty array
  - GIVEN a real term WHEN `buildGlobalSearchRequests` runs THEN it returns one `learner-profile` and one
    `cohort` request, both carrying `_search`/`_limit`
  - GIVEN a `roles` array WHEN `classifyPersonKind` runs THEN it returns `staff` for any declared staff role
    and `learner` for an empty/missing array or a plain `learner`/`parent` role
- [x] Implement
- [x] Test

### Task 2: Unit-test the query builder and classifier
- **spec_ref**: `openspec/specs/dashboard/spec.md#requirement-a-fast-finder-query-builder-splits-one-search-term-into-per-kind-openregister-requests`
- **files**: `tests/unit-js/globalSearch.test.mjs`
- **acceptance_criteria**:
  - GIVEN `node --test tests/unit-js/globalSearch.test.mjs` WHEN run THEN every test passes, covering blank
    terms, per-kind request shape, every `STAFF_ROLES` value, and `groupGlobalSearchResults`' grouping
- [x] Implement
- [x] Test

### Task 3: Build the GlobalSearchWidget component
- **spec_ref**: `openspec/specs/dashboard/spec.md#requirement-a-fast-finder-widget-on-the-people-dashboard`
- **files**: `src/views/widgets/GlobalSearchWidget.vue`
- **acceptance_criteria**:
  - GIVEN a user types a query WHEN 300ms of inactivity elapses THEN the two searches run and results render
    grouped as Learners/Staff/Cohorts, each an `NcListItem` with a `to` route
  - GIVEN no results WHEN a search completes THEN a "No matches found." message renders
  - GIVEN the trailing clear button WHEN clicked THEN the query and results reset
- [x] Implement
- [x] Test

### Task 4: Wire the widget into PeopleDashboard
- **spec_ref**: `openspec/specs/dashboard/spec.md#requirement-a-fast-finder-widget-on-the-people-dashboard`
- **files**: `src/views/PeopleDashboard.vue`
- **acceptance_criteria**:
  - GIVEN `PeopleDashboard` renders WHEN the page loads THEN a full-width search widget appears above the KPI
    row, and no top-level menu entry is added (per `change-plan.md`'s explicit exclusion)
  - GIVEN the existing four KPI tiles and four manage-list widgets WHEN the page renders THEN they keep their
    relative order and content, only shifted down two grid rows
- [x] Implement
- [x] Test

## Verification

- All tasks checked off
- `openspec validate global-search --strict` passes
- `node --test tests/unit-js/globalSearch.test.mjs` passes
- `git diff` reviewed against every spec requirement before commit

## Tests (company-wide ADR-009)

- PHPUnit: N/A — no PHP touched by this change
- Newman/Postman: N/A — no new route; both queried schemas already serve the generic OpenRegister object API
- Browser (Playwright MCP): deferred this pass (see proposal Open Questions) — no local instance was
  exercised; the query-builder unit tests are the primary verification for this iteration
- `composer check:strict` (no PHP files touched, so no findings expected) and `npm run lint` run once before
  push per CLAUDE.md's verification order

## Documentation (company-wide ADR-010)

- N/A — an internal dashboard widget, no dedicated `docs/` feature page precedent exists for a single
  dashboard widget addition (KPI tiles and manage-list widgets on the same page have none either)

## i18n (company-wide ADR-005)

- All new user-facing strings (`Search learners, staff and cohorts`, `Searching…`, `No matches found.`,
  `Learners`, `Staff`, `Cohorts`) go through `t('learniq', ...)`, matching `PeopleDashboard.vue`'s existing
  convention; no hardcoded Dutch or English literal bypasses translation
