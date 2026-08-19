# Tasks: manifest-fragment-split

## Implementation Tasks

### Task 1: Create the six target-group fragments
- **spec_ref**: `openspec/changes/manifest-fragment-split/specs/navigation/spec.md#requirement-manifest-content-lives-in-boundary-scoped-fragments-not-the-monolith`
- **files**: `src/manifest.d/dashboard.json`, `src/manifest.d/learning.json`, `src/manifest.d/people.json`, `src/manifest.d/progress.json`, `src/manifest.d/compliance.json`, `src/manifest.d/my-learning.json` (new); `src/manifest.d/learning-dashboard.json`, `src/manifest.d/people-dashboard.json` (deleted, content folded into `learning.json`/`people.json`)
- **acceptance_criteria**:
  - GIVEN `dashboard.json` WHEN loaded THEN it declares `GroupInsight` with its full, unmodified 8-child `children[]` array (design.md Decision 2/3)
  - GIVEN `learning.json` WHEN loaded THEN it declares `GroupLearning` (17 children, unmodified order) and `GroupTimetabling` (3 children), plus the `LearningDashboard` page and the `GroupLearning.route` field previously carried by `learning-dashboard.json`
  - GIVEN `people.json` WHEN loaded THEN it declares `GroupPeople` (4 children) plus the `PeopleDashboard` page and route previously carried by `people-dashboard.json`
  - GIVEN `progress.json` WHEN loaded THEN it declares `GroupEngagement`, `GroupCourseEvaluation`, `GroupCompetency`, `GroupStudentAnalytics`, `GroupPortfolio`, each with its full unmodified children array
  - GIVEN `compliance.json` WHEN loaded THEN it declares `ExternalTraining` only (design.md Decision 3 — `Compliance`/Accessibility content stays inside `GroupInsight` in `dashboard.json` per Decision 1's no-cross-file-split rule)
  - GIVEN `my-learning.json` WHEN loaded THEN it declares `MyTimetableMenu` and `MyLearningRecordMenu`
- [ ] Implement
- [ ] Test

### Task 2: Create the six education-specific module fragments
- **spec_ref**: `openspec/changes/manifest-fragment-split/specs/navigation/spec.md#requirement-manifest-content-lives-in-boundary-scoped-fragments-not-the-monolith`
- **files**: `src/manifest.d/work-placement.json` (`GroupBpv`), `src/manifest.d/guardian-meetings.json` (`GroupConferences`), `src/manifest.d/admissions.json` (`GroupAdmissions`), `src/manifest.d/pupil-record.json` (`GroupPupilDossier`), `src/manifest.d/assessment-board.json` (`GroupExamBoard`), `src/manifest.d/progress-decisions.json` (`GroupStudyProgress`)
- **acceptance_criteria**:
  - GIVEN each file WHEN loaded THEN it declares exactly the one named top-level id with its full, unmodified `children[]` array copied verbatim from the current `manifest.json`
  - GIVEN all six files together WHEN merged THEN no id, page, or field differs from what `manifest.json` declares today for these six groups
- [ ] Implement
- [ ] Test

### Task 3: Create the two leaving-app fragments
- **spec_ref**: `openspec/changes/manifest-fragment-split/specs/navigation/spec.md#requirement-manifest-content-lives-in-boundary-scoped-fragments-not-the-monolith`
- **files**: `src/manifest.d/data-exchange.json` (`GroupDataExchange`), `src/manifest.d/payments.json` (`GroupPayments`)
- **acceptance_criteria**:
  - GIVEN `data-exchange.json` WHEN loaded THEN it declares `GroupDataExchange` with its full unmodified children and every page it references
  - GIVEN `payments.json` WHEN loaded THEN it declares `GroupPayments` with its full unmodified children and every page it references
  - GIVEN either file WHEN deleted alone (dry run, not committed) THEN the effective manifest loses exactly that group and its pages with no dangling `menu-layout.json` reference (test-plan.md TC-3)
- [ ] Implement
- [ ] Test

### Task 4: Strip `manifest.json` to its skeleton
- **spec_ref**: `openspec/changes/manifest-fragment-split/specs/navigation/spec.md#requirement-manifest-content-lives-in-boundary-scoped-fragments-not-the-monolith`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the post-split `manifest.json` WHEN inspected THEN `pages[]`/`menu[]` contain only the four utility singles (`Documentation`, `FeaturesRoadmapMenu`, `XapiStatementsMenu`, `Rollover`) and their pages, plus `$schema`/`version`/`dependencies`/`observability`/`deepLinks` unchanged
  - GIVEN `src/main.js` and `src/menu-layout.json` WHEN diffed against their pre-change state THEN there is no change
- [ ] Implement
- [ ] Test

### Task 5: Write and run the pre/post `buildManifest` deep-equal verification
- **spec_ref**: `openspec/changes/manifest-fragment-split/specs/navigation/spec.md#requirement-splitting-the-manifest-into-fragments-is-a-no-behaviour-change-refactor`
- **files**: a scratch verification script (not committed — CI/local tooling only, per test-plan.md TC-1), run against the pre-split git ref and the post-split working tree
- **acceptance_criteria**:
  - GIVEN both trees' `buildManifest()` output WHEN deep-compared (menu tree structure + order at every depth, full pages array) THEN the diff is empty
  - GIVEN the diff is non-empty WHEN found THEN the offending fragment is fixed before proceeding — this task does not pass on "looks close"
- [ ] Implement
- [ ] Test

### Task 6: Visual + e2e regression pass
- **spec_ref**: `openspec/changes/manifest-fragment-split/specs/navigation/spec.md#requirement-splitting-the-manifest-into-fragments-is-a-no-behaviour-change-refactor`
- **files**: none (verification only); reuses `tests/e2e/pages.spec.ts` unmodified
- **acceptance_criteria**:
  - GIVEN the app deployed on localhost:8080 WHEN an admin views the fully-expanded nav THEN it is pixel-for-pixel identical to the pre-change nav (test-plan.md TC-2)
  - GIVEN the existing Gate-19 route-smoke suite WHEN run against the post-split build THEN it passes with zero new failures and zero edits to the route table (test-plan.md TC-4)
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — N/A, no PHP touched
- New/changed API endpoints covered by Newman/Postman tests — N/A, no API touched
- UI changes covered by Playwright browser tests — existing Gate-19 route-smoke suite reused unmodified (Task 6)
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` if user-facing — N/A, no user-visible change (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings — N/A, no new strings, existing `l10n` keys unchanged (ADR-007)
- `openspec validate` passes
