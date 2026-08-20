# Tasks: menu-six-main-items

## Implementation Tasks

### Task 1: Unified Dashboard entry; retire the three role-dashboard leaves
- **spec_ref**: `openspec/changes/menu-six-main-items/specs/navigation/spec.md#requirement-the-dashboard-entry-resolves-by-role-instead-of-three-separate-rows`
- **files**: `src/manifest.d/dashboard.json`, `src/menu-layout.json`
- **acceptance_criteria**:
  - GIVEN `dashboard.json` WHEN loaded THEN it declares one new top-level menu entry `Dashboard` routed to the existing `Dashboard` page (route `/`, component `ScholiqDashboards`)
  - GIVEN `menu-layout.json` WHEN loaded THEN `DashboardAdmin`/`DashboardTeacher`/`DashboardStudent` are removed from `relocations` and added to `removals`, and their pages remain untouched in `pages[]`
- [ ] Implement
- [ ] Test

### Task 2: Nest Timetabling under Learning
- **spec_ref**: `openspec/changes/menu-six-main-items/specs/navigation/spec.md#requirement-groups-with-children-preserve-their-identity-when-folded-into-a-new-parent`
- **files**: `src/manifest.d/learning.json`
- **acceptance_criteria**:
  - GIVEN `learning.json` WHEN loaded THEN `GroupTimetabling` (with its 3 unmodified children) is declared as a child of `GroupLearning`, not as an independent top-level id
- [ ] Implement
- [ ] Test

### Task 3: Nest Admissions, Pupil dossier, and Parent conferences under People
- **spec_ref**: `openspec/changes/menu-six-main-items/specs/navigation/spec.md#requirement-groups-with-children-preserve-their-identity-when-folded-into-a-new-parent`
- **files**: `src/manifest.d/people.json` (canonical `GroupPeople` declaration, unchanged label/icon), `src/manifest.d/admissions.json`, `src/manifest.d/pupil-record.json`, `src/manifest.d/guardian-meetings.json` (each restructured to declare `GroupPeople` as parent with their own group as one child, per design.md Decision 3)
- **acceptance_criteria**:
  - GIVEN the four files merged THEN `GroupPeople.children` contains `GroupAdmissions`, `GroupPupilDossier`, `GroupConferences` (each with its own unmodified children and label) alongside the 4 pre-existing People leaves
  - GIVEN only one of the four files sets `GroupPeople.label`/`icon`/`order` THEN the other three set nothing but their own nested group
- [ ] Implement
- [ ] Test

### Task 4: Create Progress; nest Engagement, Course evaluation, Competencies, Progress & analytics, Portfolios
- **spec_ref**: `openspec/changes/menu-six-main-items/specs/navigation/spec.md#requirement-groups-with-children-preserve-their-identity-when-folded-into-a-new-parent`
- **files**: `src/manifest.d/progress.json` (new canonical `GroupProgress` top-level entry)
- **acceptance_criteria**:
  - GIVEN `progress.json` WHEN loaded THEN it declares a new top-level `GroupProgress` (label "Progress") whose children are `GroupEngagement`, `GroupCourseEvaluation`, `GroupCompetency`, `GroupStudentAnalytics`, `GroupPortfolio`, each with its full unmodified children and label
- [ ] Implement
- [ ] Test

### Task 5: Nest Study progress (BSA) and BPV under Progress
- **spec_ref**: `openspec/changes/menu-six-main-items/specs/navigation/spec.md#requirement-groups-with-children-preserve-their-identity-when-folded-into-a-new-parent`
- **files**: `src/manifest.d/progress-decisions.json`, `src/manifest.d/work-placement.json` (each restructured to declare `GroupProgress` as parent with their own group as one child, per design.md Decision 3 and Decision 5)
- **acceptance_criteria**:
  - GIVEN both files merged into `progress.json`'s declaration THEN `GroupProgress.children` has exactly 7 entries total (the 5 from Task 4 plus `GroupStudyProgress` and `GroupBpv`), none flattened
- [ ] Implement
- [ ] Test

### Task 6: Create Compliance; relocate flat leaves; nest Exam board; relabel the Compliance overview leaf
- **spec_ref**: `openspec/changes/menu-six-main-items/specs/navigation/spec.md#requirement-the-top-level-nav-presents-six-named-destinations-plus-two-pending-extractions`
- **files**: `src/manifest.d/compliance.json` (new canonical `GroupCompliance` top-level entry; relabel the `Compliance` leaf's `label` to "Overview" with a new `l10n` key, `id`/`route` unchanged), `src/manifest.d/assessment-board.json` (restructured to nest `GroupExamBoard` under `GroupCompliance`), `src/menu-layout.json` (relocations: `Compliance`, `ExternalTraining`, `Accessibility`, `AccessibilityLimitationsMenu`, `AiProcessingDisclosureMenu`, `AccessibilityFeedbacksMenu` → `GroupCompliance`)
- **acceptance_criteria**:
  - GIVEN the merged manifest THEN `GroupCompliance` has 6 direct-leaf children (the relocated flat leaves, with the former `Compliance` leaf now labeled "Overview") plus 1 nested child (`GroupExamBoard`, unmodified)
  - GIVEN `GroupInsight` WHEN all 8 of its original children have been relocated (Task 1 + this task) THEN it no longer appears in the effective menu (empty-shell pruning)
- [ ] Implement
- [ ] Test

### Task 7: Wrap My timetable and My learning record under My learning
- **spec_ref**: `openspec/changes/menu-six-main-items/specs/navigation/spec.md#requirement-the-top-level-nav-presents-six-named-destinations-plus-two-pending-extractions`
- **files**: `src/manifest.d/my-learning.json`, `src/menu-layout.json` (relocations for `MyTimetableMenu`/`MyLearningRecordMenu` if not already declared as children in the fragment)
- **acceptance_criteria**:
  - GIVEN the merged manifest THEN a top-level `My learning` group exists with `MyTimetableMenu` and `MyLearningRecordMenu` as its two children
- [ ] Implement
- [ ] Test

### Task 8: Structural verification
- **spec_ref**: `openspec/changes/menu-six-main-items/specs/navigation/spec.md#requirement-no-page-or-route-is-dropped-by-the-six-group-collapse`
- **files**: scratch verification script (not committed, mirrors `manifest-fragment-split` Task 5's approach)
- **acceptance_criteria**:
  - GIVEN the pre-change and post-change effective manifests THEN the full `pages[]` id-set diff is empty
  - GIVEN the post-change effective menu THEN it has exactly 8 top-level main-nav items, `GroupInsight` is absent, and `GroupProgress.children.length === 7`
- [ ] Implement
- [ ] Test

### Task 9: Visual + e2e regression pass
- **spec_ref**: `openspec/changes/menu-six-main-items/specs/navigation/spec.md#requirement-no-page-or-route-is-dropped-by-the-six-group-collapse`
- **files**: none (verification only); reuses `tests/e2e/pages.spec.ts` unmodified
- **acceptance_criteria**:
  - GIVEN localhost:8080 WHEN an admin, teacher, and learner each view the nav THEN each sees the target 8-item top level per test-plan.md TC-1/TC-2
  - GIVEN the existing Gate-19 route-smoke suite WHEN run against the post-change build THEN it passes with zero new failures
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) — N/A, no PHP touched
- New/changed API endpoints covered by Newman/Postman tests — N/A, no API touched
- UI changes covered by Playwright browser tests — existing Gate-19 suite reused (Task 9); persona/accessibility passes per test-plan.md TC-2/TC-7
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` if user-facing — recommended: update any nav/IA screenshots in `docs/` given the top-level structure changed (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for the one new string ("Overview," Task 6) and the one new top-level label not previously in the menu tree ("Progress," "Compliance," "My learning" — confirm existing keys don't already cover these before adding new ones) (ADR-007)
- `openspec validate` passes
