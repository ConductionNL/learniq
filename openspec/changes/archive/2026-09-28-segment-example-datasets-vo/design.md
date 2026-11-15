# Design: segment-example-datasets-vo

The descriptor contract is `openspec/changes/segment-wizard-choice/contract.md`; the pattern is the primary school set (`segment-example-datasets-po`), whose generator and test this change mirrors.

## Architecture Overview

```
scripts/example-sets/vo.py  (deterministic, seed 20250818, stdlib only)
   │  calendar: 2025-08-18 .. 2026-07-10 minus holidays and study days
   │  exam classes stop after their last lesson day (2026-04-17)
   │  classes → pupils → families → guardians → enrolments → profiles and packages
   │  weekly timetable per class (lesson hours per weekday, subject teacher per slot)
   │  sessions (class × school day) → marks (spells, appointments, lates, unexcused)
   │  toetsweek papers → grade entries → final grades → report card subject lines
   │  marks → report card attendance summaries and verzuim flags
   ▼
lib/Settings/profiles/vo.json   one object per line, strict JSON
   │
   ├─ ExampleSetDescriptorContractTest   (loads: shape, namespace, references, schema validity)
   ├─ SecondarySchoolExampleSetTest      (story: class, teacher, calendar, grades, card = marks, removal)
   └─ SeedProfileService / wizard        (offered as "Secondary school", loaded, removed by occ)
```

## Decisions

### Decision 1: a generator, committed next to the set
Same reason as the po set: an absence must fall on a school day of the pupil's own class, a late arrival must be marked by a teacher who works that weekday, an SE final grade must be the weighted average of its SE grades, and a report card must count the marks and show the grades of its period. Across thousands of objects that is only reliable if one program derives all of it. `random.Random(20250818)`, `--check`, and a test that runs `--check` when `python3` is present.

### Decision 2: one havo/vwo school, eleven classes
A havo/vwo school is the most common shape of a Dutch secondary school with a six-year stream, so leerjaar 1 to 6 exist naturally: a combined havo/vwo brugklas (1HV1, 1HV2), then separate streams (2H1, 2V1, 3H1, 3V1, 4H1, 4V1, 5H1, 5V1, 6V1). Havo 5 and vwo 6 are the exam classes. Years 1 and 2 sit in the onderbouw building, years 3 to 6 in the main building. vmbo is left out: it would need its own streams, leerwegen and examens, and one school keeps the story readable.

### Decision 3: sessions per class per school day, marks only for exceptions
A secondary school timetables lessons per hour, and registers attendance per lesson. Storing a session per lesson would be about 12,000 sessions for eleven classes, more than the whole po set, for no gain in a demo. The set stores one session per class per school day ("3H1, dinsdag 11 november 2025"), from 08:30 to the end of that class's last lesson hour that weekday, and marks only the exceptions, exactly like the po set. The weekly timetable (lesson hours per weekday and the subject teacher per first hour) lives in the generator: it sets each session's end time and decides which teacher marks a late arrival. Absences and appointments are marked by the attendance desk (`vo-verzuim-01`), as secondary schools do with a verzuimbalie.

### Decision 4: grades are stored where a derived record depends on them
A secondary school records many grades per pupil per subject. The set stores `GradeEntry` rows in two places, each feeding a record the app derives:
- the exam classes (havo 5, vwo 6): SE1, SE2 and SE3 for every subject in each pupil's package, on a PTA plan per subject and stream (`kind: pta`), feeding a `FinalGrade` per subject (the SE final grade);
- leerjaar 3: the kernvakken Nederlands, Engels and wiskunde in each of the three toetsweken, feeding a `FinalGrade` per kernvak (the year grade so far), which the decaan reads next to the profielkeuze.

Every other subject and class shows its period average on the report card with no source entries, as the po set does. The PTA plans cover the exam year only: earlier SE results would fall before 2025-2026, which the contract's dates rule excludes. The central exam is out of scope; the SE final grade is the one a school knows before the CE starts.

### Decision 5: derived values written the way the app would derive them
Seeding runs as a system operation, so no listener recomputes anything. The generator writes each `FinalGrade` the way `GradeAggregationEngine::weightedAverage()` would (value and per-period averages rounded to four decimals, components with value, weight and contribution), and each report card subject line the way `ReportCardComposer::buildSubjectGrades()` would (period average from the final grade's breakdown, source entries of that period, `passed` from the average up to that period). A later recompute therefore changes nothing.

### Decision 6: the calendar of the po set, with toetsweken
The same fictional region as the po set (first day 18 August 2025, the same holidays), so the two sets agree when an operator loads both. Three report periods end in three toetsweken: 10 to 14 November 2025, 2 to 6 March 2026 and 15 to 19 June 2026. The exam classes sit SE1 and SE2 in the first two toetsweken and SE3 from 30 March to 2 April 2026; their last lesson day is 17 April 2026.

### Decision 7: the records a secondary school keeps
- **Profielkeuze**: every leerjaar 3 pupil chooses a package for 2026-2027 against a package plan per stream with `electiveRules` (four or five electives, wiskunde A and B mutually exclusive). Most are approved; a few sit in `needs-revision` with the validator's own message.
- **Schooladvies in reverse**: one brugklas pupil's advies arrives from the primary school (voorlopig havo, doorstroomtoets vwo, definitief vwo after heroverweging), with the converted application of the 2025-2026 intake round that created the pupil's profile and enrolment. The 2026-2027 intake round holds this year's applications for next year's brugklas.
- **Verzuim**: a leerplicht threshold (16 hours unexcused in four weeks) and a zorgwekend ziekteverzuim threshold (attendance under 90 percent in a period), with flags for a reported leerplicht case, a resolved one, and a pupil in care for frequent illness.
- **Care and exams**: exam accommodations for pupils with a dyslexia statement, support requests and dossier notes by the mentor, the decaan and the zorgcoördinator.

### Decision 8: fictional by construction
Common Dutch first names; surnames are invented compounds of a plant or landscape word and a place suffix; the school, town and streets are invented; postcodes start with 0 and phone numbers with 06-0; the BRIN is 00X2 and the vestigingen 00X200 and 00X201. User ids are `vo-leerling-NNN`, `vo-ouder-NNN`, `vo-docent-NN`, `vo-decaan-01`, `vo-zorgcoordinator-01` and so on. No BSN, no ECK iD, no real address. Prior primary schools on the applications are free-text fictional names.

### Decision 9: one object per line
As in the po set: strict JSON, one compact object per line, so a diff shows only the objects that changed.

## Declarative-vs-imperative decision (ADR-031)
No behaviour is added. The set is data; derived values a listener would normally compute (final grades, report card lines and attendance summaries, attendance flags) are written consistently by the generator, because seeding runs as a system operation that withholds lifecycle events.

## Security Considerations
No code path changes. The data is fictional (Decision 8) and holds no credentials. Loading and removal stay admin-only (setup contract) and shell-only (occ), as defined by `segment-wizard-choice`.

## Seed Data
The set is the seed data: 7841 objects. Counts per schema (bucket order is load order), as the generator writes them; `SecondarySchoolExampleSetTest` asserts floors, not these exact numbers.

| schema | objects | notes |
|---|---|---|
| school | 1 | Voorbeeldcollege Esdoornveen, BRIN 00X2 |
| vestiging | 2 | Hoofdgebouw (00X200), Onderbouwlocatie Varenhof (00X201) |
| room | 19 | a home room per class, three science labs and an onderbouw lab, two gyms, the aula, the mediatheek |
| grade-scale | 1 | Cijfer 1 tot en met 10, pass 5.5 |
| course | 23 | three enrolment courses (brugklas havo/vwo, havo, vwo) and twenty subjects |
| curriculum-plan | 50 | a toetsplan per subject (tw1 to tw3), a PTA per exam class subject (se1 to se3, weights 2, 3, 3), two package plans with electiveRules |
| programme | 3 | Brugklas havo/vwo, Havo, Vwo |
| cohort | 11 | 1HV1, 1HV2, 2H1, 2V1, 3H1, 3V1, 4H1, 4V1, 5H1, 5V1, 6V1 |
| staff | 32 | rector, two teamleiders, decaan, zorgcoördinator, attendance desk, examensecretaris, administration, TOA, conciërge, 22 teachers (11 mentors) |
| learner-profile | 707 | 294 pupils, 413 guardians |
| enrolment | 294 | one per pupil, three joined late (two moves, one opstromer) |
| subjectteacherassignment | 150 | a teacher per class per subject taught |
| report-period | 3 | Periode 1, 2 and 3, with holidays and study days |
| admissions-round | 2 | 2025-2026 (archived), 2026-2027 (closed) |
| admission | 74 | the brugklas pupils' converted applications, and this year's applications for 2026-2027 (placed, waitlisted, one rejected) |
| school-advies | 1 | the one advies received in reverse: havo, doorstroomtoets vwo, definitief vwo |
| subject-choice | 53 | the leerjaar 3 profielkeuze for 2026-2027; two in needs-revision, three validated |
| session | 2064 | a school day per class; exam classes until 17 April 2026 |
| excuse-request | 25 | illness spells reported by guardians or 18+ pupils, Suikerfeest leave, two refused holiday requests |
| attendance-record | 1348 | exceptions only |
| attendance-threshold | 2 | leerplicht 16 hours in 4 weeks; zorgwekend ziekteverzuim under 90 percent |
| attendance-flag | 3 | a reported and a resolved leerplicht case, one pupil in care for frequent illness |
| exam | 102 | the toetsweek papers of 3H1 and 3V1 (kernvakken) and the SE papers of 5H1 and 6V1 |
| exam-accommodation | 16 | extra time for pupils with a dyslexia statement, reading software, a separate room |
| grade-entry | 1467 | leerjaar 3 kernvakken per toetsweek, exam classes SE1 to SE3 per package subject, inhaaltoetsen after absence |
| final-grade | 490 | year grade so far (leerjaar 3 kernvakken), SE final grade (exam classes) |
| report-card | 880 | one per pupil per period enrolled |
| support-request | 4 | zorgwekend verzuim, leerplicht follow-up, dyslexia test, faalangst |
| dossier-note | 14 | mentor, decaan, zorgcoördinator and attendance desk notes |

Related items (files, notes, tasks): none; the set carries no `_relatedItems`.

## Risks / Trade-offs
- [Import time] → see proposal Risk 1.
- [Per-day sessions instead of per-lesson] → Decision 3; a school that wants a lesson timetable imports it from its roostering package.
- [Grades only where something depends on them] → Decision 4; the report cards still show every subject.

## Migration Plan
No data migration: the set is new and opt-in. Deploy is the PR. Rollback: revert; remove a loaded set with `occ learniq:example-set:remove vo --apply` first.

## Open Questions
None.
