# Design: segment-example-datasets-training

The descriptor contract is `openspec/changes/segment-wizard-choice/contract.md`; the primary school set (`segment-example-datasets-po`) is the pattern this change follows.

## Architecture Overview

```
scripts/example-sets/training.py  (deterministic, seed 20250901, stdlib only)
   │  catalogue: 14 courses, prices, lessons, item banks; a leadership programme
   │  clients → staff per client → bookings per course (load-balanced, BHV basis and herhaling disjoint)
   │  scheduler: programme days first, then 40 editions on open days, no trainer or room twice per dagdeel
   │  per edition, in date order: marks per participant per session → complete, rebook, test, resit or fail
   │  completions → certificates, attestations; last year's certificates → renewals
   │  followed editions → invitations → anonymous responses → quality scores → improvement actions
   ▼
lib/Settings/profiles/training.json   one object per line, strict JSON, 3831 objects
   │
   ├─ ExampleSetDescriptorContractTest   (loads: shape, namespace, references, schema validity)
   ├─ TrainingExampleSetTest             (story: marks, timetable, certificates, renewals, intake, evaluations, packages, removal)
   └─ SeedProfileService / wizard        (offered as "Training institute", loaded, removed by occ)
```

## Decisions

### Decision 1: a generator, committed next to the set
Same reasoning as the primary school set: the objects must agree (a certificate only after a full edition and a passed test, a mark by the trainer who taught that edition, a quality score that counts the responses). Only one program deriving all of it keeps that true. `random.Random(20250901)`, `--check`, and a test that runs `--check` when `python3` is available.

### Decision 2: the complete 2025-2026 year
Training runs from 8 September 2025 to 11 June 2026, on weekdays outside the Christmas closure and the public holidays. Every edition is `completed`, every enrolment is `completed`, `withdrawn` or `failed`, and every evaluation campaign is `closed`. Only the intake round for September 2026 is `open`, with applications submitted in June 2026. Certificate expiry dates and renewal due dates lie after the year, as expiry dates do.

### Decision 3: a mark for every participant per session
The primary school set stores exceptions only. A training institute is different: the presence list decides the certificate, so a present mark carries information, and the volume stays small (1129 marks). A training day is two sessions (09:00 to 12:30 and 13:15 to 16:30; online half days 09:30 to 12:30 or 13:30 to 16:30), so a missed afternoon is visible. Absences are rare, as they are on short paid trainings: a sick day is `absent-excused` and backed by an `ExcuseRequest` from the participant or their company contact; a no-show is `absent-unexcused`.

### Decision 4: completion follows the rules an institute applies
A course with a regulation (BHV, VCA, heftruck, preventiemedewerker, AVG, informatiebeveiliging) needs every session; other courses need three quarters, programme modules 60 percent. Whoever misses too much is `withdrawn` with a reason and rebooked into the next edition of the same course that still has a place (6 rebookings); without one, the reason says so. A course with a knowledge test (BHV basis and herhaling, VCA, heftruck) runs an `Assessment` per edition with a pass mark and two attempts: 174 first attempts, 18 resits, 3 participants `failed`. Completion issues the certificate or badge two office days later and, for a regulated course, a signed `Attestation` on the course's closing lesson.

### Decision 5: client companies are departments, their contact persons are managers
Six fictional companies send 140 of the 150 participants; ten private individuals enrol from the public catalogue. A participant's `department` is `<company>/<unit>` (or `Particulier`), so compliance and reports roll up per client. Each company has a contact person, a `LearnerProfile` with role `manager`, named as `managerId` on its participants and enrolments. Enrolment `source` tells the channel: `hr` for company bookings, `self` for open enrolment, `admission` for the programme, `credential-renewal` for the BHV renewals.

### Decision 6: the waiting list lives on the programme intake
`Application` (`admission`) is the only schema with a waiting list (`waitlisted`), and it requires a programme. So the leadership programme carries the intake: three rounds with capacity 12, 24 converted applications that name the participant and the three module enrolments they created, two applicants moved from the full September group to February, three still waitlisted, one withdrawn before the intake, and four applications for September 2026. Single courses have open enrolment and no waiting list schema to show.

### Decision 7: course package export shows as its round trip
Learniq's export (`CoursePackageExportService`) streams a file and stores no record. The set therefore shows an export where learniq does record it: two courses exported as `scholiq-json` and read back in as next year's draft courses, each with a `succeeded` import report. Two more reports show the imports an institute runs: a partner's Common Cartridge package (`partial`: one weblink degraded, one LTI tool dropped, each with its reason) and a course migrated from the old Moodle environment (`succeeded`).

### Decision 8: last year's certificates are migrated and renewed
Every BHV herhaling participant holds last year's BHV certificate, migrated from the old environment (`source: migrated`, `expired`), whose `renewalEnrolmentId` names their herhaling enrolment (`source: credential-renewal`, due 90 days after the expiry). New certificates expire after 12 months (BHV), 60 months (heftruck) or 120 months (VCA); the AVG and informatiebeveiliging badges after 24 and 12 months.

### Decision 9: evaluations per quarter, with their consequences
Four `EvaluationCampaign`s (one per quarter, built-in questions in Dutch and English) invite everyone who followed an edition to the end. 292 of 421 respond (69 percent); responses are anonymous and name only the trainer. `CourseQualityScore` per course and quarter counts the invitations and responses and carries the mean. Four `ImprovementAction`s answer what the scores showed (the spreadsheet course's pace, too little driving time on the forklift course, a cold practice hall, missing sector cases in AVG); the first two show a higher score the quarter after.

### Decision 10: what the set leaves out
`DataExchangeJob` (D7), `Order`, `OrderLine`, `PaymentTransaction` (D19) and `Entitlement` (needs an OrderLine). Prices live on `FeeItem`, which D19 keeps. `Regulation` is left out because its own `slug` property must match `^[A-Z0-9_-]+$`, which the contract's envelope slug `training-regulation-NNN` can never do; courses, lessons, enrolments and attestations carry the regulation as the plain slug string the schema allows. A private training institute has no DUO BRIN; `School.brin` is required, so the set uses `00X6`, which ends in a digit and is never assigned (the contract lists `00X1` to `00X4` for the four school sets).

### Decision 11: fictional by construction
Common Dutch first names; surnames are invented compounds of a harbour word and a place suffix (Ankerstede, Getijwerf); the institute, the town Kompasveen and every company named after it are invented; postcodes start with 0; web addresses end in `.example`; IP addresses on attestations come from the documentation ranges (192.0.2.0/24, 198.51.100.0/24); signatures read `voorbeeld-...-niet-geldig`. User ids are `training-deelnemer-NNN`, `training-contactpersoon-NNN`, `training-trainer-NN`. No BSN, no ECK iD.

### Decision 12: one object per line
As in the primary school set: 3918 lines, 3.1 MB, strict JSON, and a diff touches only the objects that changed.

## Declarative-vs-imperative decision (ADR-031)
No behaviour is added. The set is data; derived values a listener would normally compute (quality scores, credential expiry, renewal links, test scores) are written consistently by the generator, because seeding runs as a system operation that withholds lifecycle events.

## Security Considerations
No code path changes. The data is fictional (Decision 11) and holds no working credential: every signature is a visible placeholder that verifies nothing. Loading and removal stay admin-only (setup contract) and shell-only (occ), as defined by `segment-wizard-choice`.

## Seed Data
The set is the seed data. Counts per schema (bucket order is load order):

| schema | objects | notes |
|---|---|---|
| school | 1 | Voorbeeld Opleidingscentrum Het Kompas, 00X6 |
| vestiging | 1 | Hoofdlocatie Havenkade, Kompasveen |
| room | 7 | two training rooms, meeting room, computer room, practice hall, forklift course, online classroom |
| course | 16 | 14 published, 2 drafts for 2026-2027 |
| lesson | 44 | 37 published, 7 draft copies |
| course-package-import-report | 4 | Common Cartridge (partial), Moodle (succeeded), two scholiq-json round trips |
| programme | 1 | Leergang Leidinggeven, three modules |
| fee-item | 13 | a price per open course and one for the programme |
| item-bank / item | 3 / 44 | BHV, VCA, heftruck questions (QTI 3.0 choice items) |
| staff | 13 | director, planner, quality officer, administration, nine trainers |
| learner-profile | 156 | 150 participants, 6 company contact persons |
| cohort | 42 | 40 open editions, 2 programme starts |
| enrolment | 430 | 418 completed, 9 withdrawn, 3 failed |
| admissions-round / admission | 3 / 34 | 24 converted, 3 waitlisted, 3 withdrawn, 4 for September 2026 |
| subjectteacherassignment | 46 | the trainer of each edition and programme module |
| session | 127 | two per training day, one per online half day; 2 cancelled by a storm, 2 taught by a stand-in |
| exam / assessment-result | 20 / 192 | a knowledge test per tested edition; 174 first attempts, 18 resits |
| excuse-request | 9 | sick days reported by the participant or the company contact |
| attendance-record | 1129 | every participant, every held session |
| attestation | 269 | signed, per completed regulated course |
| credential | 468 | 185 certificates, 233 badges, 50 migrated and expired |
| evaluation-campaign / invitation / response | 4 / 421 / 292 | one campaign per quarter |
| course-quality-score / improvement-action | 38 / 4 | per course and quarter |

Related items (files, notes, tasks): none; the set carries no `_relatedItems`.

## Risks / Trade-offs
- [Import time] → see proposal Risk 1.
- [A later schema change] → the contract test fails in that PR; fix it in the generator.
- [No `Regulation` objects] → a compliance view that lists regulations shows none for this set; courses still carry their regulation slug. A later contract change that allows an uppercase slug property would let the set add them.

## Migration Plan
No data migration: nothing in the register changes. Deploy is the PR. Rollback: revert; remove a loaded set with `occ learniq:example-set:remove training --apply` first.

## Open Questions
None.
