---
kind: config
depends_on:
  - segment-wizard-choice
---

# Proposal: segment-example-datasets-po

## Summary
The primary school example set: `lib/Settings/profiles/po.json`, one fictional school (Voorbeeldschool De Wilgenboom in the fictional town of Wilgendam) through the complete 2025-2026 school year. Two locations, seven classes for groups 1 to 8 with a combined group 5/6, 198 pupils with their guardians, staff with subject assignments, a school day per class per day with the absences a school records, two report periods with report cards, Cito and doorstroomtoets results, a group plan, support requests and dossier notes: 4557 objects that agree with each other. The curated primary school seeds that sat dark in the register (OpenRegister never reads `x-openregister-seed`) move into it, and the tests that read them now read the set.

## Motivation
Decision D21 (Ruben, 2026-09-27): six example sets, one lane per set; this lane builds the primary school one. Recon A section 1 found the only primary school data in learniq in 14 `x-openregister-seed` blocks ("OBS De Wilgenboom", "Groep 5/6", "Herfstvakantie") that no import path reads: OpenRegister's `ImportHandler` processes `x-openregister.seedData`, never `x-openregister-seed`. Recon A open question 4 recommends promoting that seed into the primary school set rather than rewriting it, which is what decidesk's `seed-profiles` did with its 334 seeds. The generated demo register meanwhile mixes primary school, MBO, higher education and corporate schemas with placeholder values ("Voorbeeld Reporteruserid 1"), which shows the data model but not a school (recon A section 6).

Evidence: round 1 recon `legal-po-2026-09-25.md` fixes the structure the set follows (groep is a free name per location, leerjaar per pupil, combined groups registered as one groep). Market (recon A section 2): Moodle's demo is one canned school, "Mount Orange School"; no competitor offers a set per organisation kind, which is the gap D21 closes.

## Affected Projects
- [x] Project: `learniq`: new `lib/Settings/profiles/po.json` and its generator `scripts/example-sets/po.py`; the promoted seed blocks in `lib/Settings/learniq_register.json` emptied (10 schemas, patch version bumps, `info.version` 0.25.1); six register tests repointed to the set; a new content test; one catalogue key pair.

## Scope

### In Scope
- The set, written against `openspec/changes/segment-wizard-choice/contract.md` and passing `ExampleSetDescriptorContractTest`.
- A deterministic generator (`python3 scripts/example-sets/po.py`, `--check` for CI and review), so the thousands of objects stay consistent and a reviewer reads rules instead of JSON.
- Promoting the curated seed: De Wilgenboom, its two locations, "Groep 5/6" with its duo split, the "Groep 7" notes, the Herfstvakantie and study day, the technisch lezen group plan with its three subgroups and evaluation, the staff and subject assignments. Names, codes and ids become obviously fictional (see design.md).
- Retiring the ten promoted `x-openregister-seed` blocks and repointing the six tests that read them (by name or uuid, asserting floors, per the round 2 rule).
- `PrimarySchoolExampleSetTest`: the set's promises and its internal consistency.

### Out of Scope
- The other five sets (sibling lanes). The corporate `ExternalTrainingRecord` seed stays for the corporate lane.
- A live import and purge on an instance (lanes keep off the shared instance).
- Report card templates: `ReportCard.templateId` would make the filinq render look up a template slug the set would have to ship; the set leaves it unset so the default render path applies.

## Approach
Generate, don't hand-write. One Python script builds the calendar (school days minus the holidays and study days it also writes into the report periods), assigns pupils to classes and families, lets illness spells, appointments and late arrivals fall on school days, marks each with the teacher on duty that weekday, and counts the same marks into each report card. Output is strict JSON with one object per line.

## New Dependencies
None. The generator uses the Python standard library only.

## Impact
- `lib/Settings/profiles/po.json` (new, about 3 MB, one object per line).
- `lib/Settings/learniq_register.json`: ten seed blocks emptied, ten schema patch bumps, `info.version` 0.25.0 → 0.25.1. No property changes.
- Tests: six repointed methods, one new test class. Two of the repointed tests (`SubjectAndTeacherAssignmentRegisterTest`, `CohortGroupPagePolishRegisterTest`) were red on `development` because they read seeds by index after other lanes appended rows; reading by name makes them green.

## Cross-Project Dependencies
None.

## Risks

### Risk 1: loading takes minutes
**Severity:** Medium. **Mitigation:** 4557 objects in one wizard request. Seeding runs as a system operation (no lifecycle listeners), and the generated set's 405 objects took 40 to 50 seconds on siblings, so a few minutes is expected. The import is idempotent by uuid, so a timed-out request finishes on a second run. The card shows the object count so an admin knows what they start. Measured on a live instance: not in this lane.

### Risk 2: a schema change later invalidates objects
**Severity:** Medium. **Mitigation:** the contract test validates every object against its schema on every PR, so a schema change that breaks the set fails in that PR; the generator is where the fix goes. D7 retires `DataExchangeJob` from learniq later; the three LVS import jobs and the `dataExchangeJobId` on each result then move with that migration.

### Risk 3: a name resembles a real person
**Severity:** Low. **Mitigation:** first names are common Dutch names, surnames are invented compounds (Wilgenhof, Beekstein), the town and school are invented, postcodes start with 0 and phone numbers with 06-0 (neither is issued), the BRIN ends in a digit (DUO assigns two letters). No BSN anywhere.

## Rollback Strategy
Revert the PR: the set disappears from the wizard, the seed blocks return. An instance that loaded the set removes it first with `occ learniq:example-set:remove po --apply`.

## Open Questions
None.
