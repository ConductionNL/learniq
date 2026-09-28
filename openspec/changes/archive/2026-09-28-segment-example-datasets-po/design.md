# Design: segment-example-datasets-po

The descriptor contract is `openspec/changes/segment-wizard-choice/contract.md`; this change is its first user.

## Architecture Overview

```
scripts/example-sets/po.py  (deterministic, seed 20250818, stdlib only)
   │  calendar: 2025-08-18 .. 2026-07-10 minus holidays and study days = 197 school days
   │  classes → pupils → families → guardians → enrolments
   │  sessions (class × school day) → marks (spells, appointments, lates, unexcused)
   │  marks → report card attendance summaries; ability → grades and LVS scores
   ▼
lib/Settings/profiles/po.json   one object per line, strict JSON, 4557 objects
   │
   ├─ ExampleSetDescriptorContractTest   (loads: shape, namespace, references, schema validity)
   ├─ PrimarySchoolExampleSetTest        (story: class, teacher, calendar, card = marks, removal list)
   └─ SeedProfileService / wizard        (offered as "Primary school", loaded, removed by occ)
```

## Decisions

### Decision 1: a generator, committed next to the set
Consistency is the product here: an absence must fall on a school day of the pupil's own class, be marked by the teacher who works that weekday, and be counted on that pupil's report card. Across 1189 marks and 396 report cards that is only reliable if one program derives all of it. The script is deterministic (`random.Random(20250818)`), `--check` compares its output with the file, and a test runs `--check` whenever `python3` is available. Editing the JSON by hand is allowed only through the script.

### Decision 2: the complete 2025-2026 school year
Today (2026-09-27) the 2025-2026 year is complete, so the set can show a whole year: both report periods composed and published, midden and eind Cito results, a leerplicht case that opened and closed. The calendar uses the region zuid dates the promoted seed already implied (first day 18 August 2025), the Herfstvakantie of the seed (20 to 24 October), and national holidays for the rest. Classes, enrolments and cohorts stay `active`: the year has ended but no rollover has run.

### Decision 3: marks only for exceptions
A school registers everyone, but a present mark carries no information a demo needs, and storing one per pupil per day would add about 34,000 objects. The set stores sessions for every class and school day (1379) and marks only for exceptions: illness spells (`absent-excused`, clustered in winter), appointments (`left-early`), late arrivals, two families who took refused leave before the May holiday, and one leerplicht case (`absent-unexcused`, seven days in March, flagged by an `AttendanceFlag` with an intervention). Report card summaries count present days as school days minus absences.

### Decision 4: the promoted seed, made fictional
| Seed row (register, dark) | In the set |
|---|---|
| School "OBS De Wilgenboom", BRIN 02VG | "Voorbeeldschool De Wilgenboom", BRIN 00X1 (DUO never assigns a digit in the last position) |
| School "Montessorischool De Ontdekking", BRIN 14LM | dropped: the set is one school |
| Vestiging Hoofdlocatie / Dependance Noorderpark (Utrecht) | same names, in the fictional town of Wilgendam, codes 00X100 / 00X101 (+ onderwijslocatiecode 00X101-A) |
| Cohort "Groep 5/6" with a Mon to Wed / Thu to Fri duo | same, teachers `po-leerkracht-05` and `po-leerkracht-06` (were `staff-mentor-01`, `staff-duo-01`) |
| Cohort "Groep 7" with notes | same, notes reworded (groep 7 is not a combined class here) |
| Enrolments leerjaar 5 and 6 on Groep 5/6 | 30 enrolments on Groep 5/6, 15 per leerjaar |
| ReportPeriod "Rapport 1" with Herfstvakantie and study day 2025-11-14 | same, plus "Rapport 2" |
| GroupPlan technisch lezen (closed blok 3, active 2026-2027 blok 1), 4 subgroups, 1 evaluation | same structure on Groep 5/6, subgroup members are the class's own weakest and strongest readers |
| Staff (2), SubjectTeacherAssignment (2) | 15 staff, 19 subject assignments |

The ten seed blocks are emptied, not deleted, matching the 96 empty blocks the register already carries.

### Decision 5: fictional by construction
Common Dutch first names; surnames are invented compounds of a tree or landscape word and a place suffix (Wilgenhof, Beekstein); the school, town and streets are invented; postcodes start with 0 and phone numbers with 06-0, neither of which the Netherlands issues; user ids are `po-leerling-NNN`, `po-ouder-NNN`, `po-leerkracht-NN`. No BSN, no ECK iD, no real address.

### Decision 6: no report card template
`ReportCard.templateId` makes `ReportCardPdfDelegationService` ask filinq for the template's `slug`, and the contract fixes an example object's slug as `po-report-card-template-NNN`, which names no filinq template. The set leaves `templateId` and `Cohort.reportCardTemplateId` unset so the existing default render path (`report-card`) applies.

### Decision 7: one object per line
Pretty-printing every field gave a 115,000-line, 4.5 MB file; one compact object per line gives 4634 lines and 3.1 MB, and a diff touches only the objects that changed. It is still strict JSON (`check:json-strict` passes).

## Declarative-vs-imperative decision (ADR-031)
No behaviour is added. The set is data; derived values a listener would normally compute (report card attendance summaries, the attendance flag, LVS job totals) are written consistently by the generator, because seeding runs as a system operation that withholds lifecycle events.

## Security Considerations
No code path changes. The data is fictional (Decision 5). The set holds no credentials. Loading and removal stay admin-only (setup contract) and shell-only (occ), as defined by `segment-wizard-choice`.

## Seed Data
The set is the seed data. Counts per schema (bucket order is load order):

| schema | objects | notes |
|---|---|---|
| school | 1 | Voorbeeldschool De Wilgenboom, 00X1 |
| vestiging | 2 | Hoofdlocatie, Dependance Noorderpark |
| room | 8 | a classroom per class, a gym |
| course | 9 | Basisonderwijs (enrolment), six report subjects, Engels, Bewegingsonderwijs |
| curriculum-plan | 6 | one per report subject, two periods, pass rule 5.5 |
| cohort | 7 | Groep 1, 2, 3, 4, 5/6, 7, 8 |
| staff | 15 | director, intern begeleider, ten class teachers, gym teacher, assistant, administration |
| learner-profile | 466 | 198 pupils, 268 guardians |
| enrolment | 198 | one per pupil, volgnummer by inschrijving date |
| subjectteacherassignment | 19 | rekenen, taal, engels, bewegingsonderwijs |
| report-period | 2 | Rapport 1 (Aug to Jan), Rapport 2 (Feb to Jul), with holidays and study days |
| data-exchange-job | 3 | LVS midden, LVS eind, doorstroomtoets imports |
| session | 1379 | a school day per class |
| excuse-request | 15 | guardians report illness spells |
| attendance-record | 1189 | exceptions only |
| attendance-threshold | 1 | leerplicht 16 hours in 4 weeks |
| attendance-flag | 1 | the March leerplicht case, resolved |
| lvs-result | 822 | Cito M and E for groups 3 to 7, M8 and the doorstroomtoets for group 8 |
| report-card | 396 | two per pupil, grades for groups 3 to 8, comments for all |
| group-plan / subgroup / evaluation | 2 / 4 / 1 | promoted technisch lezen plan |
| support-request | 3 | dyslexie, behaviour, verzuim follow-up |
| dossier-note | 8 | observations, calls, conversations |

Related items (files, notes, tasks): none; the set carries no `_relatedItems`.

## Risks / Trade-offs
- [Import time] → see proposal Risk 1.
- [`DataExchangeJob` leaves learniq later (D7)] → the three jobs and each result's `dataExchangeJobId` move with that migration; the contract test flags the set the moment the schema changes.
- [Groep 1 pupils who turn four after 18 August] → enrolled on their fourth birthday; their sessions before that date carry no marks and their first report card counts from the inschrijving.

## Migration Plan
No data migration: the retired seed blocks were never imported. Deploy is the PR. Rollback: revert; remove a loaded set with `occ learniq:example-set:remove po --apply` first.

## Open Questions
None.
