# Design: company-segment-menu-gating

## Architecture Overview

```
LearniqSettings rows ─▶ SegmentService::workspace()
                          ├─ segment        (newest valid row, else 'corporate')
                          └─ chosenSegment  (that row's code if setBy is a real user, else null)
PageController initial state 'segment' + 'chosenSegment'
  ─▶ src/main.js buildWorkspaceRuntime() ─▶ runtime.workspace = { segment, chosenSegment }
  ─▶ applyReportCardGates(manifest)  (drops Reports cards whose visibleIf fails)
  ─▶ buildManifest(base, fragments, menuLayout) ─▶ CnAppRoot
        CnAppNav        evaluates menuItem.visibleIf
        CnNavCardGrid   evaluates navCardEntry.visibleIf (ProgressLanding, ComplianceLanding)
```

`visibleIf` combines its keys with AND and has no OR (`visibleIfContext.js`, operators `eq`, `in`, `notIn`, `gt`, `gte`, `lt`, `lte`, `truthy`). "Hide for a company that chose, keep for an install that never chose" is therefore one key whose value differs between those two states: `workspace.chosenSegment` is `null` for the second, and `notIn: ["corporate"]` passes for `null`.

## Decisions

### Decision 1: a chosen segment names a person
`SegmentService::chosenSegment()` counts the newest valid row as a choice only when `setBy` names an existing Nextcloud user (`IUserManager::userExists()`). The setup wizard always writes the admin's uid (`SetupController` passes `IUserSession::getUser()->getUID()`). The generated demo register (`learniq_mock_register.json`) ships three `LearniqSettings` rows with `segment: corporate` and `setBy: "Voorbeeld Setby N"`, which exist only to satisfy the mock generator; `segment-runtime-bridge` already promised that loading demo data changes no menus (`SegmentFeatureFlagsRegisterTest::testGenericDemoRowsKeepTheCorporateDefault`). Counting any row as a choice would break that promise under D26. Alternatives rejected: matching the demo slug pattern (couples the service to a generator's naming), deleting the demo rows (a generated file; installs that already loaded them keep them).

### Decision 2: one read per page
`SegmentService::workspace()` returns `['segment' => ..., 'chosenSegment' => ...]` from one `findAll()`, and PageController provides both initial states from it. `currentSegment()` and `hasSegment()` keep their meaning for their callers.

### Decision 3: the school-only surfaces
| Group (decision D26) | Surfaces that render | Gate added |
|---|---|---|
| attendance flags, leerplicht and verzuim | Reports card `AttendanceFlags` (category "Attendance and compulsory education") | `chosenSegment notIn [corporate]` |
| report cards | menu `ReportPeriodsMenu`, `ReportCardsMenu`, `ReportCardTemplatesMenu` | same |
| admissions | menu `ApplicationsMenu`, `AdmissionsRoundsMenu`, `AdmissionsReviewBoardMenu` | same, next to the existing vo-and-up segment gate |
| school advies | menu `SchoolAdviezenMenu` | same, next to `segment in [po, vo, corporate]` |
| guardians | menu `BookConferenceSlotsMenu`, `ConferenceRoundsMenu`, `TeacherAvailabilitiesMenu`, `ConferenceScheduleBoardMenu`, `ConferenceReportsMenu` (parent conferences) | same |
| BPV | ProgressLanding cards for the five BPV pages; Reports card `BpvVisitReports`; the five BPV menu leaves | `segment in [mbo, corporate]` + `chosenSegment notIn [corporate]` |
| exam board | ComplianceLanding cards `ExemptionCasesMenu`, `FraudCasesMenu`, `ItemRevisionFlagsMenu`; the same three menu leaves | `segment in [vo, mbo, he, corporate, training]` + `chosenSegment notIn [corporate]` |

Attendance records, the learner's Guardians tab, pupil dossier and group plans are not in the decision's list and stay.

### Decision 4: gates move off relocated groups (the #1041 dead gates)
`buildManifest()` runs `applyMenuRelocations()`: a relocated group "dissolves: its children merge (by id) into the target group and the now-empty shell is dropped" (`@conduction/nextcloud-vue` `src/utils/buildManifest.js`). Its own `visibleIf` goes with the shell. All five #1041 gated groups are relocated and their children are in `removals`, so their only rendering surface is a landing card. The group gates move to:

| #1041 group | Segments | New home |
|---|---|---|
| GroupBpv | mbo, corporate | 5 ProgressLanding cards, Reports `BpvVisitReports`, 5 leaves |
| GroupStudyProgress (BSA) | he, corporate | 4 ProgressLanding cards, Reports `BsaRiskDashboard`, 4 leaves |
| GroupEngagement | mbo, he, corporate, training | 6 ProgressLanding cards, 6 leaves |
| GroupCourseEvaluation | mbo, he, corporate, training | 4 ProgressLanding cards, Reports `CourseQualityReport`, 4 leaves |
| GroupExamBoard | vo, mbo, he, corporate, training | 3 ComplianceLanding cards, 3 leaves |

The leaves carry the gate too, so each fragment still says who its entries are for, and a later layout change that stops removing them cannot expose them.

`GroupCompliance` is a relocation target, so its gate did run, and hid the exam board, accessibility, AI disclosure and privacy cards from every school. Its segment gate moves to the `Compliance` (overview) and `ExternalTraining` cards, which already mirror the menu entries that carry the same gate.

### Decision 5: a filter for Reports cards
`CnReportsPage` 2.57.1 renders every `config.cards` entry; it has no `visibleIf` (checked on v2.57.1, development and beta). `src/utils/reportCardGates.js` exports `applyReportCardGates(manifest)`, which drops every card of a `type: "reports"` page whose `visibleIf` fails `passesContextPredicates(card.visibleIf, manifest.runtime)`. It runs in `src/main.js` after the runtime is built and before `buildManifest()`. The evaluator is the library's own, imported from `@conduction/nextcloud-vue/src/utils/visibleIfContext.js` (the package exports `./src/*`), so the semantics cannot drift. The page's category filter already hides a category that holds no card, so "Attendance and compulsory education" disappears with its one card. When the library honours card `visibleIf` natively the filter can go; the manifest does not change.

### Decision 6: the validator checks what renders
`tests/validate-menu-role-gates.js` now:
- collects nav-card-grid entries and Reports cards next to menu entries, and applies the segment rules to all of them;
- rule 5: a `workspace.chosenSegment` predicate is `{notIn: [...]}` with known codes, nothing else;
- rule 6: a `workspace.segment` or `workspace.chosenSegment` key on a group that `menu-layout.json` relocates is an error ("the gate never runs").
Rules 1 to 4 are unchanged.

### Decision 7: the matrix test builds the real menu
`segmentMenuGates.test.mjs` builds the menu with the library's `buildManifest()` and the app's `menu-layout.json`, then walks it: a child renders when its parent renders and its own gate passes; a landing card renders when the landing's menu entry renders and the card passes; a Reports card renders when the Reports entry renders and `applyReportCardGates()` keeps it. It evaluates seven runtime states for an admin: never chose (`corporate`, `null`) and each of the six codes chosen.

## Mixed-spec rationale
`kind: code` with JSON payload. The code is the enabler (the `chosenSegment` value and the Reports filter, about 60 lines over four files) and the JSON gates are useless without it; shipping the gates first would evaluate against an undefined value. They land together so the matrix test can assert the behaviour end to end.

## Declarative-vs-imperative decision (ADR-031)
- Menu, landing card and Reports card visibility: declarative, `visibleIf` in the manifest.
- The `chosenSegment` value: framework glue in SegmentService and PageController, the same category as the existing `segment` provider; OpenRegister has no declaration that pushes a field into another app's initial state.
- The Reports filter: a shim for a missing library feature, evaluating the declared `visibleIf` with the library's own function; no rule lives in the shim.

## Security Considerations
A hidden entry is not an access boundary; each page's data stays behind its schema authorization. `chosenSegment` is read without RBAC, like `segment`, and only a validated code or `null` leaves the service. `userExists()` reveals nothing to the browser beyond whether the row counts.

## Seed Data
No schema change and no new seed rows. The generated demo rows stay; Decision 1 is how they are handled.

## Risks / Trade-offs
- [An admin sets Company on the App settings page with an empty "Set by"] → school menus stay (pre-D26 behaviour); the docs say to use the wizard or fill "Set by".
- [LDAP-backed `userExists()` on every page load] → one lookup per page, cached by the user backend; only when a row exists.
- [A library upgrade changes relocation semantics] → the matrix test builds the menu with the installed library and fails on a change.

## Migration Plan
None. Existing installs have no row naming a real user unless an admin chose through the wizard, so they keep every menu.

## Open Questions
See the proposal.
