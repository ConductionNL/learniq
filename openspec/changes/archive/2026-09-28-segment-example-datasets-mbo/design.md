# Design: segment-example-datasets-mbo

The descriptor contract is `openspec/changes/segment-wizard-choice/contract.md`; the pattern is `segment-example-datasets-po` (generator, one object per line, content test). This change is the third user of the contract, after the fixture and po.

## Architecture Overview

```
scripts/example-sets/mbo.py  (deterministic, seed 20250818, stdlib only)
   │  calendar: 2025-08-18 .. 2026-07-10 minus holidays and study days
   │  programmes → frameworks (kerntaken, werkprocessen) → units (courses) → one plan per unit
   │  classes → timetable per semester (school weekdays) and placement weekdays
   │  students → enrolments; placements → agreements → signatures, visits, assessments
   │  lessons (class × school day) → marks by the teacher of that lesson's unit
   │  unit results + resits → final grades (engine rules) → first-year advice (evaluator rules)
   ▼
lib/Settings/profiles/mbo.json   one object per line, strict JSON, 6175 objects
   │
   ├─ ExampleSetDescriptorContractTest     (loads: shape, namespace, references, schema validity)
   ├─ VocationalCollegeExampleSetTest      (story: timetable, marks, placements, engine, advice, removal list)
   └─ SeedProfileService / wizard          (offered as "Vocational education (MBO)", loaded, removed by occ)
```

## Decisions

### Decision 1: a generator, committed next to the set
The set only works if thousands of objects agree: a lesson never falls on a placement day, a mark is made by the teacher of that lesson's unit, an assessment falls on a placement day inside its period, a final grade is what the engine computes, and a first-year decision counts exactly the passed units. The script derives all of it, is deterministic (`random.Random(20250818)`), and `--check` compares its output with the file; a test runs `--check` whenever `python3` is available. Alternative considered: hand-curated JSON like decidesk's seed profiles. Rejected for the same reason po rejected it: at this size hand edits drift.

### Decision 2: the same reference year as po, two semesters, no report cards
The college sits in the same fictional region as the primary school, so it shares the 2025-2026 calendar (first day 18 August 2025, the same holidays) with its own three study days. The two `ReportPeriod` objects are the semesters (`S1` to 30 January, `S2` from 2 February), `archived` because the year is complete. The set writes no report cards: an MBO college reports progress through unit results and the study advice, which the set does carry. Classes and enrolments stay `active`, except the diploma classes (`completed`), graduates (`completed`) and the three students who left after a negative advice (`withdrawn`).

### Decision 3: a timetable around the placement days
Each class has school weekdays per semester, each weekday mapped to one unit; placement weekdays carry no lesson. Examples: LOG2-2A is at school Monday and Tuesday and at the leerbedrijf Wednesday to Friday all year; SD4-2A is at school four days in semester 1 and on placement Monday to Thursday in semester 2, with a Friday return day (09:00 to 13:00); SD4-3A is the mirror image. One `Session` per class per school day (911), titled with the class, the unit and the date. Alternative considered: one session per lesson hour. Rejected: three to four times the rows for no extra story.

### Decision 4: every unit is a course with its own plan
`GradeRollupHandler` computes one `FinalGrade` per learner per curriculum plan, and `BsaProgressEvaluator` counts `Course.ectsCredits` of the courses behind passed final grades. So each unit (onderwijseenheid) is a `Course` with credits and its own `CurriculumPlan` (`last-attempt`, so a resit replaces the first attempt), and each placement year has a PVB plan on the pass-fail scale (`all-must-pass`, one component per kerntaak) so a year's PVB final grade is complete. One `oer` plan per programme lists the whole exam plan for reading; no entries are graded against it. 30 courses, 30 plans.

### Decision 5: derived values written the way the code writes them
Seeding runs as a system operation that withholds lifecycle events, so the generator writes what the listeners would have written:

| value | written as | by |
|---|---|---|
| `FinalGrade` value, passed, breakdown | weighted average of the published entries (last attempt per component for unit plans, exemptions skipped), pass on the scale threshold and, for `all-must-pass`, an entry per pass-rule component | `GradeAggregationEngine`, `GradePassEvaluator` |
| PVB `GradeEntry` | one per student and component, value 1.0 or 0.0 from the last confirmed werkproces assessment, `grader: praktijkopleider`, `sourceKind: manual`, then published | `WerkprocesGradeEmitHandler` |
| `BsaProgressFlag.ectsEarned`, `BsaDecision.ectsAchieved` | credits of the programme's courses with a passed final grade at that moment | `BsaProgressEvaluator` |
| `AttendanceFlag.metricValue` | unexcused hours in the window (leerplicht), attendance percent in semester 1 (college) | threshold rules |

`VocationalCollegeExampleSetTest` checks the final grades against the real `GradeAggregationEngine` and `GradePassEvaluator` classes, not a copy of their rules.

### Decision 6: what the set leaves out
- `CompetencyAttainment`: a read-only roll-up that `CompetencyAttainmentRollupHandler` recomputes on every confirmed assessment. Writing 600 rows by hand would duplicate a derivation the next confirm overwrites.
- `Credential` (the diploma) and the `signature`/`signingKeyId` of `BsaWarning` and `BsaDecision`: these are HMAC signatures computed by guards with the tenant key at a transition. A seeded value would be a signature nobody can verify, so warnings are `acknowledged` and decisions `decided` without a signature, and graduates show as `completed` enrolments.
- `DataExchangeJob`: D7 retires it from learniq; the DUO verzuim report appears as an intervention note on the leerplicht flag instead.

### Decision 7: students report absence themselves; no guardian profiles
MBO students are 15 to 20. The set creates no parent profiles; a minor's parent is an emergency contact (`relationship: ouder`) on the student profile (198 of 250 students are minors on the first school day). Excuse requests are submitted by the student and decided by the verzuimcoördinator; one is rejected (a driving test), and that day stays unexcused.

### Decision 8: the study advice story
The three trajectories use `kind: mbo-studieadvies`, a norm of 35 of the 50 first-year credits, and an interim norm of 15 at a check six months in (18 February 2026, after the semester 1 resits). Eight first-years fail their semester 1 unit after the resit: they are flagged and warned. Four recover; two get a negative decision, one a negative decision with advice for a niveau 2 programme, and one is postponed for documented illness. Every other first-year passes their resits, and the generator raises an error rather than write a negative decision without a warning, the rule `BsaDecisionGuard` enforces live.

### Decision 9: fictional by construction
Common Dutch first names; surnames are invented water-landscape compounds (Vaartzicht, Kreekstee); the college, town, streets and all 18 leerbedrijven are invented and carry the fictional place names; postcodes start with 0, phone numbers with 06-0000, KvK numbers with 0000 and SBB erkenning numbers with 000; company mail uses the reserved `.example` domain; BRIN 00X3 and vestigingen 00X300 and 00X301; crebo codes 90201, 90302 and 90403 start with 9 and each framework calls itself an example. User ids are `mbo-student-NNN`, `mbo-docent-NN`, `mbo-stagecoordinator-01` and so on. No BSN, no ECK iD.

## Found while building (reported, not fixed)
The set follows the code as it is; these defects surfaced and belong to their own changes:
1. `lib/Grading/GradePassEvaluator.php` reads `passRules[].passThreshold`, but the schema declares `passRules[].minValue`, so a per-component minimum in an `all-must-pass` plan is never applied (every value clears 0).
2. `lib/Listener/CompetencyAttainmentRollupHandler.php` `findCompetencyByCode()` looks a `werkprocesCode` up across every `sbb-kwalificatiedossier` framework of the tenant and takes the first hit. SBB codes such as `B1-K1-W1` repeat in every dossier, so a college with more than one programme links an assessment to another programme's werkproces. The set writes `competencyId` explicitly.
3. `lib/Listener/GradeRollupHandler.php` writes `cohortId` into `FinalGrade`, which the schema does not declare.
4. `PokSignature.signerRole` offers `student`, `school` and `praktijkopleider` only; a minor's agreement is usually co-signed by a parent and the schema has no role for that.
5. `WerkprocesGradeEmitHandler` keeps one grade entry per component and overwrites its value with each confirmed werkproces, so the component result is the last werkproces confirmed, not "all competent". The set follows the handler and keeps the last assessment decisive.
6. An exemption-only plan gets a `FinalGrade` with `passed: null`, and `BsaProgressEvaluator` counts only `passed: true`, so an exempted first-year unit earns no advice credits. The set exempts only second- and third-year components.

## Declarative-vs-imperative decision (ADR-031)
No behaviour is added. The set is data; derived values are written consistently by the generator (Decision 5).

## Security Considerations
No code path changes. The data is fictional (Decision 9) and holds no credentials. Loading and removal stay admin-only (setup contract) and shell-only (occ), as defined by `segment-wizard-choice`.

## Seed Data
The set is the seed data. Counts per schema (bucket order is load order):

| schema | objects | notes |
|---|---|---|
| school | 1 | Voorbeeldcollege Vaartveld, 00X3 |
| vestiging | 2 | Locatie Centrum (zorg), Locatie Techniekpark (logistiek, software) |
| room | 8 | a home room per class: skillslab, praktijkhal, two ICT labs, four classrooms |
| competency-framework | 3 | one kwalificatiedossier-style framework per programme |
| competency | 29 | 7 kerntaken, 22 werkprocessen |
| grade-scale | 2 | cijfer 1 to 10 (pass 5.5), competent or nog niet competent |
| programme | 3 | Logistiek medewerker (2), Verzorgende IG (3), Software developer (4) |
| course | 30 | 3 programme enrolments, 22 units with credits, 5 placement units |
| curriculum-plan | 30 | 3 exam plans, 22 unit plans, 5 PVB plans |
| cohort | 8 | LOG2-1A/2A, VIG3-1A/2A/3A, SD4-1A/2A/3A |
| staff | 24 | 14 teachers (8 SLB, 5 BPV-docent), team leaders, stagecoordinator, exam board chair and secretary, student counsellor, verzuimcoördinator, administration, 2 instructors |
| learner-profile | 250 | students only; parents of minors as emergency contacts |
| enrolment | 250 | one per student, volgnummer by start date |
| subjectteacherassignment | 29 | per class and unit, plus the BPV-docent per placement class |
| report-period | 2 | the semesters, with holidays and study days |
| praktijkopleider | 36 | two per leerbedrijf, 18 leerbedrijven |
| bpv-placement | 151 | 150 completed, 1 terminated in November and replaced |
| praktijkovereenkomst | 151 | one per placement |
| pok-signature | 453 | student, school, praktijkopleider per agreement |
| bpv-visit-report | 313 | voortgangsbezoek and eindgesprek per placement, 11 mid-term talks, 1 incident |
| werkproces-assessment | 660 | 639 competent, 21 nog niet competent (18 retaken, 3 open at year end) |
| session | 911 | a school day per class |
| excuse-request | 17 | 16 approved illness reports, 1 rejected |
| attendance-record | 792 | exceptions only |
| attendance-threshold | 2 | leerplicht 16 hours in 4 weeks; attendance under 80 percent |
| attendance-flag | 2 | a minor's unexcused week in March; an adult at 79.5 percent in semester 1 |
| exam-accommodation | 7 | extra time for dyslexia, one separate room |
| exemption-case | 4 | 2 granted (English), 2 rejected |
| grade-entry | 1034 | unit results, 82 resits, 202 PVB results, 2 exemptions, 1 invalidated |
| fraud-case | 2 | one proven (grade annulled, resit), one unfounded |
| final-grade | 834 | one per learner per plan with a published entry |
| bsa-trajectory / flag / warning / decision | 3 / 8 / 8 / 100 | see Decision 8 |
| support-request | 4 | attendance, study progress, dyslexia, placement restart |
| dossier-note | 12 | SLB conversations, calls, placement notes |

Related items (files, notes, tasks): none; the set carries no `_relatedItems`.

## Risks / Trade-offs
- [Import time: 6175 objects] → proposal Risk 1.
- [Engine defects 1, 5 and 6 get fixed] → the final grades or PVB entries may then compute differently; the engine test fails in that fix's PR, and the generator is where the set follows.
- [Werkproces codes repeat across the three frameworks] → intended (SBB style); a UI-created assessment hits defect 2 until it is fixed.

## Migration Plan
No data migration. Deploy is the PR. Rollback: revert; remove a loaded set with `occ learniq:example-set:remove mbo --apply` first.

## Open Questions
None.
