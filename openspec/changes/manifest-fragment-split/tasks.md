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
- [x] Implement
  - Done in 4e49128f (2026-08-20): at that commit `dashboard.json` held `GroupInsight` (8 children), `learning.json` `GroupLearning` (17) + `GroupTimetabling` (3), `people.json` `GroupPeople` (4), `progress.json` the five progress groups, `compliance.json` `ExternalTraining` only, `my-learning.json` the two My menus; `learning-dashboard.json` and `people-dashboard.json` were deleted in the same commit.
- [x] Test
  - Verified in round 5 by the deep-equal under task 5, run against 4e49128f^ and 4e49128f.

### Task 2: Create the six education-specific module fragments
- **spec_ref**: `openspec/changes/manifest-fragment-split/specs/navigation/spec.md#requirement-manifest-content-lives-in-boundary-scoped-fragments-not-the-monolith`
- **files**: `src/manifest.d/work-placement.json` (`GroupBpv`), `src/manifest.d/guardian-meetings.json` (`GroupConferences`), `src/manifest.d/admissions.json` (`GroupAdmissions`), `src/manifest.d/pupil-record.json` (`GroupPupilDossier`), `src/manifest.d/assessment-board.json` (`GroupExamBoard`), `src/manifest.d/progress-decisions.json` (`GroupStudyProgress`)
- **acceptance_criteria**:
  - GIVEN each file WHEN loaded THEN it declares exactly the one named top-level id with its full, unmodified `children[]` array copied verbatim from the current `manifest.json`
  - GIVEN all six files together WHEN merged THEN no id, page, or field differs from what `manifest.json` declares today for these six groups
- [x] Implement
  - Done in 4e49128f: `work-placement.json` (GroupBpv, 5), `guardian-meetings.json` (GroupConferences, 5), `admissions.json` (GroupAdmissions, 3), `pupil-record.json` (GroupPupilDossier, 3), `assessment-board.json` (GroupExamBoard, 3), `progress-decisions.json` (GroupStudyProgress, 4).
- [x] Test
  - Verified by the task 5 deep-equal.

### Task 3: Create the two leaving-app fragments
- **spec_ref**: `openspec/changes/manifest-fragment-split/specs/navigation/spec.md#requirement-manifest-content-lives-in-boundary-scoped-fragments-not-the-monolith`
- **files**: `src/manifest.d/data-exchange.json` (`GroupDataExchange`), `src/manifest.d/payments.json` (`GroupPayments`)
- **acceptance_criteria**:
  - GIVEN `data-exchange.json` WHEN loaded THEN it declares `GroupDataExchange` with its full unmodified children and every page it references
  - GIVEN `payments.json` WHEN loaded THEN it declares `GroupPayments` with its full unmodified children and every page it references
  - GIVEN either file WHEN deleted alone (dry run, not committed) THEN the effective manifest loses exactly that group and its pages with no dangling `menu-layout.json` reference (test-plan.md TC-3)
- [x] Implement
  - Done in 4e49128f: `data-exchange.json` (GroupDataExchange, 10 pages) and `payments.json` (GroupPayments, 11 pages). Payments later moved to shillinq and data exchange to integriq; both fragments still exist in their later shape.
- [x] Test
  - Verified in round 5 (TC-3 dry run, scratch script, not committed): building the manifest at 4e49128f without `data-exchange.json` loses exactly its 10 pages and its 5 menu ids; without `payments.json` exactly its 11 pages and 6 menu ids; zero `menu-layout.json` references dangle in either case.

### Task 4: Strip `manifest.json` to its skeleton
- **spec_ref**: `openspec/changes/manifest-fragment-split/specs/navigation/spec.md#requirement-manifest-content-lives-in-boundary-scoped-fragments-not-the-monolith`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the post-split `manifest.json` WHEN inspected THEN `pages[]`/`menu[]` contain only the four utility singles (`Documentation`, `FeaturesRoadmapMenu`, `XapiStatementsMenu`, `Rollover`) and their pages, plus `$schema`/`version`/`dependencies`/`observability`/`deepLinks` unchanged
  - GIVEN `src/main.js` and `src/menu-layout.json` WHEN diffed against their pre-change state THEN there is no change
- [x] Implement
  - Done in 4e49128f: `src/manifest.json` kept `$schema`, `version`, `dependencies`, `observability`, `deepLinks` and only the four utility singles (Documentation, FeaturesRoadmapMenu, XapiStatementsMenu, Rollover) with their 4 pages; `git diff 4e49128f^ 4e49128f -- src/main.js src/menu-layout.json` is empty.
- [x] Test
  - Verified with the commands above in round 5.

### Task 5: Write and run the pre/post `buildManifest` deep-equal verification
- **spec_ref**: `openspec/changes/manifest-fragment-split/specs/navigation/spec.md#requirement-splitting-the-manifest-into-fragments-is-a-no-behaviour-change-refactor`
- **files**: a scratch verification script (not committed — CI/local tooling only, per test-plan.md TC-1), run against the pre-split git ref and the post-split working tree
- **acceptance_criteria**:
  - GIVEN both trees' `buildManifest()` output WHEN deep-compared (menu tree structure + order at every depth, full pages array) THEN the diff is empty
  - GIVEN the diff is non-empty WHEN found THEN the offending fragment is fixed before proceeding — this task does not pass on "looks close"
- [x] Implement
  - Round 5 scratch script (not committed, as the task says): `buildManifest()` from `@conduction/nextcloud-vue` over `git show <ref>:` of the base manifest, every fragment and `menu-layout.json`, for 4e49128f^ and 4e49128f.
- [x] Test
  - Result: pages deep-equal by id (277 = 277); menu deep-equal once each level is sorted by `order`, which is how CnAppNav renders it. The raw arrays differ only in position, which the split commit message already states and which does not change what renders.

### Task 6: Visual + e2e regression pass
- **spec_ref**: `openspec/changes/manifest-fragment-split/specs/navigation/spec.md#requirement-splitting-the-manifest-into-fragments-is-a-no-behaviour-change-refactor`
- **files**: none (verification only); reuses `tests/e2e/pages.spec.ts` unmodified
- **acceptance_criteria**:
  - GIVEN the app deployed on localhost:8080 WHEN an admin views the fully-expanded nav THEN it is pixel-for-pixel identical to the pre-change nav (test-plan.md TC-2)
  - GIVEN the existing Gate-19 route-smoke suite WHEN run against the post-split build THEN it passes with zero new failures and zero edits to the route table (test-plan.md TC-4)
- [ ] Implement
  - r5-live, 2026-09-29, shared dev instance: TC-4 half done: `tests/e2e/pages.spec.ts` 24 passed, route table unchanged. TC-2 (pixel-identical nav against the pre-change build) still open: it needs the pre-split build served, and the shared checkout may not be switched.
  - Not run: needs the app deployed on a live instance serving this ref; lane rules forbid touching the shared instance on :8080. The route smoke suite is `tests/e2e/pages.spec.ts`, unchanged.
- [ ] Test
  - Not run, same reason as the box above.
  - Throwaway instance, 2026-09-29, TC-2 run and FAILED, so both boxes stay open. Same backend, same data and the same 1440 px viewport as admin; only the frontend bundle changed, built from 4e49128f^ and from 4e49128f. Every group was opened and the full nav screenshotted (300 x 4304 px). Pre-split against a second pre-split run: 0 differing pixels. Pre-split against post-split: 29,345 of 1,291,200 pixels (2.27%), all in y 1182 to 2019. What moved: "My learning record" is above "BPV", and "Pupil dossier" and "Parent conferences" traded places around "Portfolios". Cause: those entries share an `order` (26, and 27 for three of them), so array position breaks the tie, and the split moved array positions. Task 5's comparison sorted by `order`, so it could not see this. Development still has such ties (ConductionNL/learniq#1476). Images: `docs/images/manifest-fragment-split/nav-before-split-4e49128f-parent.png`, `nav-after-split-4e49128f.png`, `nav-diff-highlight.png`.

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — N/A, no PHP touched
- New/changed API endpoints covered by Newman/Postman tests — N/A, no API touched
- UI changes covered by Playwright browser tests — existing Gate-19 route-smoke suite reused unmodified (Task 6)
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` if user-facing — N/A, no user-visible change (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings — N/A, no new strings, existing `l10n` keys unchanged (ADR-007)
- `openspec validate` passes
