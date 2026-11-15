# Design: segment-menu-gating

## Architecture Overview

```
LearniqSettings.segment ─▶ SegmentService ─▶ PageController initial state 'segment'
                                               ─▶ src/main.js runtime.workspace.segment   (segment-runtime-bridge)
manifest.d/*.json menu entry
  visibleIf: { "user.primaryRole": {...}, "workspace.segment": { "in": [...] } }
                         │ implicit AND
                         ▼
CnAppNav::passesVisibleIf() ─▶ passesContextPredicates(visibleIf, manifest.runtime)
```

No code changes: `CnAppNav` already resolves any dot path into `manifest.runtime` (`@conduction/nextcloud-vue/src/utils/visibleIfContext.js`, operators `eq`, `in`, `notIn`, `gt`, `gte`, `lt`, `lte`, `truthy`), and conditions in one block combine with AND, so a segment key sits next to the existing role key.

## Decisions

### Decision 1: `corporate` stays in every gate
`segment-feature-flags` Decision 2 made `corporate` the default "because every existing customer runs the undifferentiated, corporate-flavoured build", and promised that the default "changes nothing about what any existing customer sees, even after the follow-up code change adds real gating". This change keeps that promise literally: every gate lists `corporate`, so an install that never chose a segment keeps every menu. The price is that a real company that picks "Company" also keeps every menu; narrowing it is an open question for Ruben (proposal), not a silent change.

### Decision 2: the matrix
| Entry (fragment) | po | vo | mbo | he | corporate | training | Why |
|---|---|---|---|---|---|---|---|
| GroupCompliance, ExternalTraining (compliance.json), Compliance overview (dashboard.json) | - | - | - | - | ✓ | ✓ | staff compliance training and external courses are an employer's concern |
| GroupEngagement, GroupCourseEvaluation (progress.json) | - | - | ✓ | ✓ | ✓ | ✓ | gamification and course evaluation campaigns (recon A names gamification as corporate-flavoured) fit adult and post-secondary learners |
| GroupBpv (work-placement.json) | - | - | ✓ | - | ✓ | - | beroepspraktijkvorming is MBO law |
| GroupStudyProgress (progress-decisions.json) | - | - | - | ✓ | ✓ | - | the binding study advice is HBO/WO |
| GroupExamBoard (assessment-board.json), ExamAccommodationsMenu (learning.json), ApplicationsMenu, AdmissionsRoundsMenu, AdmissionsReviewBoardMenu (admissions.json) | - | ✓ | ✓ | ✓ | ✓ | ✓ | exam boards, exam accommodations and intake start at secondary school |
| SubjectChoicesMenu, SubjectChoicePickerMenu (learning.json) | - | ✓ | ✓ | ✓ | ✓ | - | profile choice, keuzedelen, minors |
| SchoolAdviezenMenu (admissions.json) | ✓ | ✓ | - | - | ✓ | - | the primary school gives the school advies, the secondary school receives it |

A group's gate covers its children (BPV, BSA, exam board, engagement, course evaluation, compliance), so children carry no gate of their own. `GroupAdmissions` stays ungated by segment because its children split between po (school advies) and vo-and-up (intake).

### Decision 3: the school shape is the ungated rest
The primary school shape (people, classes, attendance, schools and locations, pupil dossier, group plans, support requests, report periods and cards, parent conferences) needs no gate: it shows for every segment today and keeps showing. `segmentMenuGates.test.mjs` asserts it for `po`.

### Decision 4: a validator, not a convention
`tests/validate-menu-role-gates.js` (`npm run check:menu-role-gates`, part of `check:specs`) now parses the segment enum from `learniq_register.json` and fails on an unknown literal or on a gate that hides an entry from `corporate`, the same way it already fails on a role literal the resolver can never emit. A negative test runs the validator on a copy with a broken gate.

### Decision 5: payments are left to the payments migration
D19 retires `Order`, `OrderLine` and `PaymentTransaction` from learniq; the payments lane removes their menus. Gating them here would conflict with that removal for no lasting value.

## Declarative-vs-imperative decision (ADR-031)
Declarative only: manifest `visibleIf` blocks. No code path is added; the runtime value comes from `segment-runtime-bridge`.

## Security Considerations
A hidden menu entry is not an access boundary; each page's data stays behind its schema authorization, as with the existing role gates. The docs say so.

## Seed Data
No schema change, no seed.

## Risks / Trade-offs
- [A school picks the wrong kind and misses a menu] → App settings changes it; the docs list the matrix.
- [A later lane adds a segment gate without `corporate`] → `check:menu-role-gates` fails in that PR.

## Migration Plan
None. Deploy is the PR; revert to show every menu again.

## Open Questions
See the proposal: should the company segment narrow?
