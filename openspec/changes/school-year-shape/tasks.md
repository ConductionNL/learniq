# Tasks: school-year-shape

## Implementation Tasks

### Task 1: Add ReportPeriod.holidays and ReportPeriod.studyDays
- **spec_ref**: `openspec/changes/school-year-shape/specs/report-card/spec.md#requirement-reportperiod-declares-holidays-and-study-days`
- **files**: `lib/Settings/learniq_register.json` (`ReportPeriod.properties.holidays`, `.studyDays`, both additive arrays with default `[]`; register version bump; seed data per design.md)
- **acceptance_criteria**:
  - GIVEN `ReportPeriod` WHEN read THEN it declares `holidays` (array of `{name, startDate, endDate}`, default `[]`) and `studyDays` (array of `{date, description}`, default `[]`), and `required` is unchanged
  - GIVEN the seed data WHEN read THEN one `ReportPeriod` seed carries a non-empty `holidays` and `studyDays` array
- [x] Implement
- [x] Test

### Task 2: Add SessionsToday and SessionsThisWeek index pages
- **spec_ref**: `openspec/changes/school-year-shape/specs/timetabling/spec.md#requirement-sessions-has-today--and-week-scoped-index-views`
- **files**: `src/manifest.d/learning.json` (two new `type: index` pages on `Session` with token-resolved `config.filter.startsAt`; two menu entries under the existing `GroupTimetabling` menu group)
- **acceptance_criteria**:
  - GIVEN the manifest WHEN read THEN `SessionsToday`'s `config.filter.startsAt` is `{gte: "@today", lt: "@today+1d"}` and `SessionsThisWeek`'s is `{gte: "@today", lt: "@today+7d"}`
  - GIVEN `npm run check:manifest` WHEN run THEN it passes
- [x] Implement
- [x] Test

### Task 3: Document the TimetableConflictQueue deferral
- **spec_ref**: `openspec/changes/school-year-shape/specs/timetabling/spec.md#requirement-a-per-teacher-filter-on-timetableconflictqueue-is-out-of-scope-for-a-config-change`
- **files**: none (documentation-only task; `src/views/TimetableConflictQueue.vue` is confirmed unmodified)
- **acceptance_criteria**:
  - GIVEN this change's diff WHEN inspected THEN `src/views/TimetableConflictQueue.vue` does not appear
- [x] Implement (verified unmodified)
- [x] Test (blocked, documented — see proposal.md Out of Scope and design.md Decision 3)

### Task 4: Register-JSON unit tests
- **spec_ref**: `openspec/changes/school-year-shape/specs/report-card/spec.md` requirements above
- **files**: `tests/Unit/Settings/SchoolYearShapeRegisterTest.php` (new)
- **acceptance_criteria**:
  - GIVEN the test suite WHEN run THEN it asserts `ReportPeriod.holidays`/`.studyDays` shape and the seed fixture
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate --changes school-year-shape --strict` passes
- [x] `npm run check:manifest`, `npm run check:register`, `npm run check:json-strict` pass
- [x] `vendor/bin/phpunit --filter SchoolYearShapeRegisterTest` passes
- [x] `python3 vendor/conduction/hydra-gates/hydra-gates/scripts/lib/check_schema_property_meta.py lib/Settings/learniq_register.json`, `check_manifest_l10n_coverage.py .`, and `check_detail_page_discipline.py <logfile> src/manifest.json src/manifest.d/*.json` all clean before the first push

## Tests (company-wide ADR-009)
- `tests/Unit/Settings/SchoolYearShapeRegisterTest.php` covers `ReportPeriod.holidays`/`.studyDays` shape and the seed fixture.
- N/A — no new or changed API endpoints.
- N/A — no custom Vue component; manifest-declarative pages only.

## Documentation (company-wide ADR-010)
- N/A — no user-facing feature doc beyond the page/property labels.

## i18n (company-wide ADR-005)
- New property/page/menu labels get en/nl catalogue entries per ADR-007/025.

## Compliance
- `openspec validate --change school-year-shape --strict` passes before this change is marked ready for apply.
- Diff is confined to `lib/Settings/learniq_register.json`, `src/manifest.d/learning.json`, `l10n/*.json`, and the new test file (ADR-031, no PHP/Vue behaviour code — `TimetableConflictQueue.vue` explicitly untouched, per Task 3).
