# Design: segment-example-datasets-he

The descriptor contract is `openspec/changes/segment-wizard-choice/contract.md`; the pattern is `segment-example-datasets-po`. This change adds data and a test, no code path.

## Architecture Overview

```
scripts/example-sets/he.py  (deterministic, seed 20250901, stdlib only)
   │  calendar: 4 blocks + 2 semesters, 2025-09-01 .. 2026-07-03, minus holidays
   │  programmes → learning outcomes (Dublin descriptors) → courses (ECTS) → toetsplannen
   │  students (ability) → cohorts → course enrolments
   │  item bank exam, response by response → results → item statistics, reliability, revision flags
   │  group project → submissions → peer reviews; internship → portfolios → shares
   │  grade entries (+ resits, exemption, fraud) → final grades (as GradeAggregationEngine)
   │  final grades → BSA credits (as BsaProgressEvaluator) → flags → warnings → decisions
   ▼
lib/Settings/profiles/he.json   one object per line, strict JSON, 5842 objects
   │
   ├─ ExampleSetDescriptorContractTest   (loads: shape, namespace, references, schema validity)
   ├─ HigherEducationExampleSetTest      (story: real grade engine, BSA credits, item stats, peer review, removal list)
   └─ SeedProfileService / wizard        (offered as "Higher education (HBO or university)", loaded, removed by occ)
```

## Decisions

### Decision 1: a generator, committed next to the set
The set is only useful if its derived values are the ones learniq itself would compute. A final grade must be exactly the engine's best-of-n weighted average of its published entries, a BSA decision must count exactly the credits of the passed final grades, an item statistic must match the stored responses. Across 1710 grade entries and 1060 final grades that is only reliable if one program derives all of it. The script is deterministic (`random.Random(20250901)`), `--check` compares its output with the file, and the content test runs `--check` whenever `python3` is available.

Alternative considered: hand-curated JSON (as decidesk's `seed-profiles`). Rejected for the same reason the primary school set rejected it: thousands of interdependent rows drift.

### Decision 2: one hogeschool, four programmes, 15 EC blocks in the first year
Recon A leaves the choice between a university of applied sciences and a university open. A hogeschool shows more of what learniq built for this segment: BSA, internship portfolios with workplace assessors, group projects. Two faculties (Gezondheid en Welzijn, Techniek en ICT) are the two locations, each with two programmes.

The first year has four blocks of 15 EC, each with a knowledge test and a professional product (block 4: a group report weighted 2 and an individual presentation). Years 2 to 4 have two semester modules of 30 EC each (year 3: internship and minor; year 4: specialisation and graduation). This keeps the set near the size of the primary school set: with 7.5 EC courses the first year alone would add about 1600 enrolments and final grades. The BSA norm is 45 EC (three of four blocks), with an interim check on 9 February 2026 at 30 EC. The schema's own description says the norm is institution-set with no statutory default, so the set states one.

### Decision 3: per-course enrolments, one cohort per programme per study year
A student is enrolled in each course of their study year (1072 enrolments), so a course page lists its students. Cohorts are intake years ("HBO-ICT cohort 2025" for the first year), each with its study career coach as teacher. The primary school set enrols pupils on one umbrella course; higher education enrols per unit of study, and `BsaProgressEvaluator` reads course-level final grades, so the per-course shape is the one the code expects.

### Decision 4: final grades and item analysis mirror learniq's own code
- Final grades: best-of-n per component, then the weighted average, rounded to four decimals as PHP's `round()` does (half away from zero), with the breakdown shaped as `GradeAggregationEngine::weightedAverage()` returns it; an exemption entry contributes `{exempt: true}`. The content test runs the real `GradeAggregationEngine` and `GradePassEvaluator` over the stored entries.
- Item statistics: unrounded p-value (full-mark share), the corrected item-total correlation (0.0 when degenerate), the distractor analysis over the top and bottom 27 percent by total, and Cronbach's alpha, as `ItemAnalysisService` computes them. Revision flags follow `ItemAnalysisRecomputeHandler::determineFlagReasons()` with the default thresholds, so an item can carry a difficulty flag and a discrimination flag. Three items per bank are deliberately flawed (too easy, too difficult, reversed discrimination), so the analysis has something to find.
- Item markup: the XML the app's own item editor writes (`ItemAuthorView::buildQtiBody`, `simpleChoice` elements). The take view, the draw resolver and the item analysis all read that dialect; the QTI 3.0 kebab-case dialect would render as two placeholder options.
- Responses are stored as `{value: ...}` and `correctResponse` as `{value: ...}`, the shapes the take view posts and `AssessmentScoringHandler` compares.

### Decision 5: the BSA story is complete and lawful
Every first-year student who stayed has one decision (124 positive, 5 negative, 2 negative with a recommendation for the associate degree, 1 postponed for documented illness; one negative decision is appealed). One student per programme withdraws on 16 January 2026, before the check date, and gets none. Every student under the interim norm on 9 February is flagged and warned, with an improvement period and offered guidance. A negative advice without an earlier warning is unlawful and `BsaDecisionGuard` rejects it; the generator therefore lets a student who was on track in February but ends below the norm pass the resit of their last failed block, so the set never shows one.

### Decision 6: exam board cases and a proctored sitting
Two granted exemptions (a prior MBO diploma, an earlier certificate) produce `sourceKind: exemption` entries; one exemption based on work experience is rejected. One fraud case (a copied passage in a block 2 report) is decided fraud-proven with a resubmission sanction: the contested entry is `invalidated` and the resubmitted work is graded in the resit window. One ICT student sits the block 1 knowledge test in a separate room under native test mode, which gives the one `ProctoringSession`, with a window-blur flag an invigilator allowed.

### Decision 7: signatures stay unset
`BsaWarning.signature`, `BsaDecision.signature` and `LearningRecordExport.bundleSignature` are computed with the tenant's signing key by a guard or action when the transition fires. Seeding runs as a system operation that fires no transition, and a value invented here would be a signature nobody can verify. The rows carry `null`; an instance that re-signs them does so through the normal transitions. For the same reason six learning record exports are `generated` with a coverage report but no bundle file or signature, two are `requested`, and no `LearningRecordShare` ships (a share verifies only against a signed bundle file in the learner's own Nextcloud folder).

### Decision 8: attendance only where it is required, marks only for exceptions
Higher education records attendance for workgroups with an attendance requirement, not for lectures. The set has 117 weekly workgroup sessions for the four first-year cohorts plus nine exam sittings, marks only the exceptions (169: excused and unexcused absences, late arrivals), and one threshold of 80 percent per block, which flags 13 student-blocks.

### Decision 9: fictional by construction
Common Dutch first names; surnames are invented compounds of a tree, bird or field word and a place suffix; every organisation name carries "Voorbeeld"; the institution and town are invented; postcodes start with 0 and phone numbers with 06-0; e-mail addresses use `.example`; user ids are `he-student-NNN`, `he-docent-NN`, `he-studieadviseur-NN`, `he-examencommissie-NN`. No BSN, no ECK iD, no real address.

### Decision 10: forward references only within a pair
Parents load before children. The only forward references are back-pointers of a pair that point both ways (a programme lists its courses, an assessment result names the grade entry it produced, a portfolio names its grade entry, a result names its proctoring session). learniq declares no `validateReference` property, so the importer stores them as values.

## Declarative-vs-imperative decision (ADR-031)
No behaviour is added. The set is data; derived values a listener would normally compute (final grades, item statistics, revision flags, peer feedback summaries, BSA flags) are written consistently by the generator, because seeding runs as a system operation that withholds lifecycle events.

## Security Considerations
No code path changes. The data is fictional (Decision 9) and holds no credentials or signatures (Decision 7). Loading and removal stay admin-only (setup contract) and shell-only (occ), as defined by `segment-wizard-choice`.

## Seed Data
The set is the seed data. Counts per schema (bucket order is load order):

| schema | objects | notes |
|---|---|---|
| school | 1 | Voorbeeldhogeschool Esdoornstad, BRIN 00X4 |
| vestiging | 2 | Faculteit Gezondheid en Welzijn (00X400), Faculteit Techniek en ICT (00X401) |
| room | 11 | skills lab, ICT lab, workshop, workgroup rooms, lecture halls, exam halls, a quiet room |
| grade-scale | 1 | Dutch 1 to 10, pass 5.5 |
| competency-framework / competency | 4 / 60 | per programme: five Dublin descriptors, ten learning outcomes |
| programme | 4 | HBO-V Verpleegkunde, Social Work, HBO-ICT, Werktuigbouwkunde |
| course | 40 | ten per programme, ECTS 15 (first-year blocks) or 30 |
| curriculum-plan | 44 | an OER per programme and a toetsplan per course (best-of-n) |
| cohort | 16 | a cohort per programme per intake year |
| staff | 36 | 24 lecturers (16 also study career coach), 4 study advisers, 2 exam board chairs, 2 faculty directors, a student counsellor, administration, a test coordinator, an invigilator |
| learner-profile | 400 | students, no guardians |
| enrolment | 1072 | 1003 completed, 57 failed, 12 withdrawn |
| subjectteacherassignment | 40 | one teacher per course and cohort |
| session | 126 | 117 workgroups, 9 exam sittings |
| excuse-request / attendance-record | 24 / 169 | exceptions only |
| attendance-threshold / attendance-flag | 1 / 13 | 80 percent per block |
| item-bank / item | 4 / 72 | 16 closed and 2 open questions per bank |
| exam | 9 | main sitting and resit per programme, one adapted sitting |
| exam-accommodation | 10 | nine extra time, one separate room |
| assessment-result / proctoring-session | 156 / 1 | 135 first sittings, 21 resits |
| item-statistics / assessment-reliability / item-revision-flag | 48 / 8 / 16 | main sittings; resits too small for alpha |
| rubric / assignment / submission | 4 / 4 / 32 | the block 4 group project |
| peer-review / peer-feedback-summary | 64 / 32 | two blind reviews per group |
| portfolio-template / external-assessor / portfolio / portfolio-entry / portfolio-share | 4 / 8 / 88 / 176 / 88 | internship, every third-year student |
| exemption-case / fraud-case | 3 / 1 | two granted, one rejected; one fraud-proven |
| grade-entry | 1710 | one invalidated by the fraud case |
| final-grade | 1060 | 1003 passed |
| bsa-trajectory / bsa-progress-flag / bsa-warning / bsa-decision | 4 / 18 / 18 / 132 | norm 45 EC, interim 30 EC on 9 February |
| learning-record-export | 8 | graduates; six generated, two requested |

Related items (files, notes, tasks): none; the set carries no `_relatedItems` and no file attachments.

## Risks / Trade-offs
- [Import time] → see proposal Risk 1.
- [`best-of-n` passes a course on its weighted average only] → the engine checks per-component minimums only for `all-must-pass`, which averages every attempt instead of the best; the set follows the engine and states compensation within a course.
- [Unsigned BSA letters and exports] → Decision 7; the UI shows them as unsigned rather than showing a signature that fails verification.

## Migration Plan
No data migration and no schema change. Deploy is the PR. Rollback: revert; remove a loaded set with `occ learniq:example-set:remove he --apply` first.

## Open Questions
None.
