---
kind: config
depends_on:
  - segment-wizard-choice
  - segment-example-datasets-po
---

# Proposal: segment-example-datasets-vo

## Summary
The secondary school example set: `lib/Settings/profiles/vo.json`, one fictional havo/vwo school (Voorbeeldcollege Esdoornveen in the fictional town of Esdoornveen) through the complete 2025-2026 school year. Two locations (an onderbouw building for years 1 and 2, the main building for years 3 to 6), eleven classes from the havo/vwo brugklas to havo 5 and vwo 6, about 290 pupils with their guardians, a mentor and subject teachers per class, a school day per class per day with the absences a secondary school records and three verzuim flags, three report periods with report cards, the leerjaar 3 profielkeuze, the exam classes' schoolexamen (SE) weeks with PTA grades and SE final grades, one incoming schooladvies with its converted application and the 2026-2027 brugklas intake, dyslexia exam accommodations, and a decaan and a zorgcoördinator on Staff: several thousand objects that agree with each other.

## Motivation
Decision D21 (Ruben, 2026-09-27): six example sets, one lane per set; this lane builds the secondary school one. Recon A section 1 found no secondary school data anywhere in learniq: the curated seeds are primary school flavoured and the generated demo register fills each schema with placeholder values ("Voorbeeld Reporteruserid 1"), which shows the data model but not a school (recon A section 6). A havo/vwo school evaluating learniq today sees no class list, no PTA, no profielkeuze and no toetsweek, although the schemas for each already exist (`CurriculumPlan.kind: pta`, `SubjectChoice`, `SchoolAdvies`, `Application`, `ExamAccommodation`).

Evidence: round 1 recon `landscape-vo-mbo.md` section 1 names the parity features a VO school expects from its LAS: "grades and PTA/examendossier, absence, studiewijzer, mentoraat" and the DUO verzuimloket (section 7), served today by Magister (70 to 80 percent of schools) and Somtoday. Market (recon A section 2): no competitor offers an example set per organisation kind; Moodle's demo is one canned school. Rung: D21 decision, M1 row "example datasets per organisation kind".

## Affected Projects
- [x] Project: `learniq`: new `lib/Settings/profiles/vo.json` and its generator `scripts/example-sets/vo.py`; a new content test `SecondarySchoolExampleSetTest`; one catalogue key pair for the card description (the label reuses "Secondary school").

## Scope

### In Scope
- The set, written against `openspec/changes/segment-wizard-choice/contract.md` and passing `ExampleSetDescriptorContractTest`.
- A deterministic generator (`python3 scripts/example-sets/vo.py`, `--check` for CI and review), so the thousands of objects stay consistent and a reviewer reads rules instead of JSON.
- The secondary school records the brief names: classes per leerjaar and stream, mentors and subject teachers, a timetable of school days, attendance with verzuim flags, the profielkeuze, SE weeks with PTA grades and SE final grades, report periods and report cards, one schooladvies received at intake, and a decaan and a zorgcoördinator.
- `SecondarySchoolExampleSetTest`: the set's promises and its internal consistency.

### Out of Scope
- The other sets (sibling lanes); the po set this branch is stacked on is untouched.
- Register or schema changes: the set uses the schemas as they are on its base.
- A live import and purge on an instance (lanes keep off the shared instance).
- Lesson-level timetables and per-lesson attendance (see design Decision 3), grades for classes and subjects outside the exam classes and the leerjaar 3 kernvakken (design Decision 4), and the central exam (CE), which falls after the last lesson day.

## Approach
Generate, do not hand-write. One Python script builds the calendar (school days minus the holidays and study days it also writes into the report periods), places pupils in classes and families, gives every bovenbouw pupil a profile and a subject package, lets illness spells, appointments and late arrivals fall on school days of the pupil's own class, schedules the toetsweek papers on days the class is in school, derives the SE final grades and report card averages from the stored grades the way `GradeAggregationEngine` and `ReportCardComposer` would, and counts the same marks into each report card. Output is strict JSON with one object per line, like the po set.

## New Dependencies
None. The generator uses the Python standard library only.

## Impact
- `lib/Settings/profiles/vo.json` (new, one object per line).
- `scripts/example-sets/vo.py` (new).
- `tests/Unit/Settings/SecondarySchoolExampleSetTest.php` (new).
- `l10n/en.json`, `l10n/nl.json` and the built `l10n/*.js`: one key for the card description.
- No register, schema, code or manifest change.

## Cross-Project Dependencies
None. Stacked on learniq `feat/segment-example-datasets-po` (learniq #1031), which is stacked on #1028 and #1022; it uses the profiles directory, `SeedProfileService` and the descriptor contract test from those.

## Risks

### Risk 1: loading takes minutes
**Severity:** Medium. **Mitigation:** the set holds 7841 objects, about 1.7 times the po set, because a secondary school has more pupils, classes and grades; OpenRegister's `ImportHandler::importSeedDataObjects()` inserts them one at a time. Seeding runs as a system operation (no lifecycle listeners), the import is idempotent by uuid so a timed-out request finishes on a second run, and the wizard card shows the object count. Measured on a live instance: not in this lane.

### Risk 2: a schema change later invalidates objects
**Severity:** Medium. **Mitigation:** the contract test validates every object against its schema on every PR, so a schema change that breaks the set fails in that PR; the generator is where the fix goes.

### Risk 3: a name resembles a real person or school
**Severity:** Low. **Mitigation:** first names are common Dutch names, surnames are invented compounds of a plant or landscape word and a place suffix, the town and school are invented, postcodes start with 0 and phone numbers with 06-0 (neither is issued), the BRIN 00X2 ends in a digit (DUO assigns two letters). No BSN and no ECK iD anywhere.

## Rollback Strategy
Revert the PR: the set disappears from the wizard. An instance that loaded the set removes it first with `occ learniq:example-set:remove vo --apply`.

## Open Questions
None.
