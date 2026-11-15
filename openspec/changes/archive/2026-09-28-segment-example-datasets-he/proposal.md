---
kind: config
depends_on:
  - segment-wizard-choice
  - segment-example-datasets-po
---

# Proposal: segment-example-datasets-he

## Summary
The higher education example set: `lib/Settings/profiles/he.json`, one fictional university of applied sciences (Voorbeeldhogeschool Esdoornstad in the fictional town of Esdoornstad) through the complete 2025-2026 academic year. Two faculties as locations, four bachelor programmes (HBO-V Verpleegkunde, Social Work, HBO-ICT, Werktuigbouwkunde) with ten learning outcomes each under the five Dublin descriptors, courses with ECTS credits, a cohort per programme per study year, 400 students with their course enrolments, a digital knowledge test per programme built from an item bank with item statistics, grade entries and final grades, binding study advice (BSA) for every first-year student with the flags and warnings before it, study advisers and an exam board on Staff, a peer reviewed group project, internship portfolios shared with workplace assessors, one proctoring session record and learning record exports: 5842 objects that agree with each other.

## Motivation
Decision D21 (Ruben, 2026-09-27): six example sets, one lane per set, chosen in the setup wizard; this lane builds the HBO/WO one. Recon A section 1 found that learniq ships the higher education schemas (`BsaTrajectory`, `BsaProgressFlag`, `BsaWarning`, `BsaDecision`, the item bank and item analysis schemas, portfolios, learning record exports) but only as generated placeholder rows mixed into the one demo register ("Voorbeeld Reporteruserid 1"), which shows the data model but not an institution (recon A section 6, "Scientific/domain validity"). A hogeschool that installs learniq today cannot see what a year of BSA, item analysis or internship portfolios looks like.

Evidence: recon A section 1, rows "Example datasets per organisation kind (6 kinds): Missing" and "Schemas resembling higher-ed / MBO in the SAME single dataset". Market (recon A section 2): Moodle's demo is one canned school, "Mount Orange School"; no competitor offers an example set per organisation kind, which is the gap D21 closes.

## Affected Projects
- [x] Project: `learniq`: new `lib/Settings/profiles/he.json` and its generator `scripts/example-sets/he.py`; a new content test `HigherEducationExampleSetTest`; one catalogue key pair (the card description, en and nl). No register, schema or code change.

## Scope

### In Scope
- The set, written against `openspec/changes/segment-wizard-choice/contract.md` and passing `ExampleSetDescriptorContractTest`.
- A deterministic generator (`python3 scripts/example-sets/he.py`, `--check` for CI and review), so the thousands of objects stay consistent and a reviewer reads rules instead of JSON.
- Derived values written exactly as learniq's own code computes them: final grades as `GradeAggregationEngine` does for the best-of-n formula, BSA credits as `BsaProgressEvaluator` does, item statistics as `ItemAnalysisService` does, revision flags as `ItemAnalysisRecomputeHandler` does.
- `HigherEducationExampleSetTest`: the set's promises and its internal consistency, running the real grade engine over the stored entries.

### Out of Scope
- The other five sets (sibling lanes).
- Signatures: `BsaWarning.signature`, `BsaDecision.signature` and `LearningRecordExport.bundleSignature` are computed with the tenant key when the transition fires on an instance; the set leaves them unset (see design Decision 7).
- `LearningRecordShare`: a share only verifies against a signed bundle file in the learner's own Nextcloud folder, which no example user has.
- A live import and purge on an instance (lanes keep off the shared instance).

## Approach
Generate, don't hand-write. One Python script builds the calendar (four blocks and two semesters between 1 September 2025 and 3 July 2026, minus the holidays), draws students with an ability that drives every grade, simulates the item bank exam response by response, grades group projects and internship portfolios, runs resits, then computes final grades, BSA credits, flags, warnings and decisions from those grades. Output is strict JSON with one object per line.

## New Dependencies
None. The generator uses the Python standard library only.

## Impact
- `lib/Settings/profiles/he.json` (new, about 4.4 MB, one object per line).
- `tests/Unit/Settings/HigherEducationExampleSetTest.php` (new).
- `l10n/en.json`, `l10n/nl.json` and the built `l10n/en.js`, `l10n/nl.js`: one key, the card description. The label reuses "Higher education (HBO or university)".

## Cross-Project Dependencies
None. Stacked on learniq #1031 (`segment-example-datasets-po`), which is stacked on #1028 and #1022: the loader, the contract test and the pattern come from there.

## Risks

### Risk 1: loading takes minutes
**Severity:** Medium. **Mitigation:** 5842 objects in one wizard request, about a quarter more than the primary school set. Seeding runs as a system operation, the import is idempotent by uuid (a timed-out request finishes on a second run), and the card shows the object count. Measured on a live instance: not in this lane.

### Risk 2: a schema change later invalidates objects
**Severity:** Medium. **Mitigation:** the contract test validates every object against its schema on every PR, and the content test runs the real grade engine, so a change that breaks the set fails in that PR; the generator is where the fix goes.

### Risk 3: a name resembles a real person or organisation
**Severity:** Low. **Mitigation:** first names are common Dutch names, surnames are invented compounds (Zilverdijk, Reigerdonk), every organisation name carries "Voorbeeld", the town and institution are invented, postcodes start with 0 and phone numbers with 06-0 (neither is issued), e-mail addresses use the reserved `.example` domain, and the BRIN ends in a digit (DUO assigns two letters). No BSN anywhere.

## Rollback Strategy
Revert the PR: the set disappears from the wizard. An instance that loaded the set removes it first with `occ learniq:example-set:remove he --apply`.
