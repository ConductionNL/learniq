---
kind: config
depends_on:
  - segment-example-datasets-po
---

# Proposal: segment-example-datasets-mbo

## Summary
The vocational college example set: `lib/Settings/profiles/mbo.json`, one fictional MBO college (Voorbeeldcollege Vaartveld in the fictional town of Vaartdam) through the complete 2025-2026 school year. Two locations, three programmes at niveau 2, 3 and 4 (Logistiek medewerker, Verzorgende IG, Software developer), each with a kwalificatiedossier-style framework of kerntaken and werkprocessen, eight classes (one per programme and leerjaar), 250 students, staff with a studieloopbaanbegeleider per class and a stagecoordinator, a timetable of school days around the placement days, the absences a college records, unit results with resits and final grades, 151 work placements with praktijkopleiders, signed praktijkovereenkomsten, visit reports and werkproces assessments, the first-year study advice (flags, warnings and a decision per first-year student) and exam board cases: 6175 objects that agree with each other.

## Motivation
Decision D21 (Ruben, 2026-09-27): six example sets, one lane per set; this lane builds the MBO one. Recon A section 1 found that learniq already carries the MBO data model (`BpvPlacement`, `Praktijkopleider`, `Praktijkovereenkomst`, `PokSignature`, `BpvVisitReport`, `WerkprocesAssessment`, the `mbo-studieadvies` BSA profile, `ExemptionCase`, `FraudCase`), but the only data for it is three generated placeholder objects per schema in `learniq_mock_register.json` ("Voorbeeld Reporteruserid 1"), mixed in with primary school and corporate schemas. An MBO team leader who opens the app sees the schemas, not a college. Recon A section 6 names the risk: a generated set is conformant but semantically arbitrary, and a set per segment must look like that segment.

Evidence: recon A (`/home/rubenlinde/memcap-work/learniq-mi/learniq/_round2/recon/A-segments-wizard-datasets.md`) section 1 rows "Example datasets per organisation kind (6 kinds)" (missing) and "Schemas resembling higher-ed / MBO in the SAME single dataset" (BPV, BSA schemas present, no curated data). Market (recon A section 2): Moodle's demo is one canned school, "Mount Orange School"; no competitor offers a set per organisation kind, which is the gap D21 closes.

## Affected Projects
- [x] Project: `learniq`: new `lib/Settings/profiles/mbo.json` and its generator `scripts/example-sets/mbo.py`; a new content test `VocationalCollegeExampleSetTest`; one catalogue key pair (the wizard card description).

## Scope

### In Scope
- The set, written against `openspec/changes/segment-wizard-choice/contract.md` and passing `ExampleSetDescriptorContractTest`.
- A deterministic generator (`python3 scripts/example-sets/mbo.py`, `--check` for CI and review).
- Values a listener or engine would normally derive, written the way that code writes them: final grades as `GradeFormulaEvaluator` computes them, PVB results as `WerkprocesGradeEmitHandler` writes them, study advice credits as `BsaProgressEvaluator` counts them.
- `VocationalCollegeExampleSetTest`: the set's promises and its internal consistency, with the final grades checked against the real grade engine classes.

### Out of Scope
- The other sets (sibling lanes) and any register or schema change: this set uses the schemas as they are on the po branch.
- A live import and purge on an instance (lanes keep off the shared instance).
- `CompetencyAttainment`, `Credential` and signatures on `BsaWarning`/`BsaDecision`: derived or signed by code at a transition with the tenant key; see design.md Decision 6.
- Fixing the defects this set surfaced (design.md, "Found while building"); they are reported, not fixed here.

## Approach
Generate, don't hand-write. One Python script builds the calendar (school days minus the holidays and study days it also writes into the two semester periods), the timetable per class (school weekdays per semester, placement weekdays never carry a lesson), students per class, placements with their agreements, signatures, visits and assessments on placement days, marks on lessons marked by the teacher of that lesson, unit results with resits, final grades by the engine's own rules, and the first-year advice from those final grades. Output is strict JSON with one object per line, like `po.json`.

## New Dependencies
None. The generator uses the Python standard library only.

## Impact
- `lib/Settings/profiles/mbo.json` (new, about 4.3 MB, one object per line).
- `l10n/en.json`, `l10n/nl.json` and the built `.js` catalogues: one key for the wizard card description. The label reuses "Vocational education (MBO)".
- Tests: one new test class; `ExampleSetDescriptorContractTest` picks the file up through its glob.
- No register, schema, PHP or frontend change.

## Cross-Project Dependencies
None. Stacked on learniq #1031 (`segment-example-datasets-po`), which is stacked on #1028 (`segment-wizard-choice`) and #1022 (`segment-runtime-bridge`).

## Risks

### Risk 1: loading takes minutes
**Severity:** Medium. **Mitigation:** 6175 objects is a third more than the primary school set (4557). Seeding runs as a system operation without lifecycle listeners, and the import is idempotent by uuid, so a timed-out request finishes on a second run. The wizard card shows the object count. Measured on a live instance: not in this lane.

### Risk 2: a schema change later invalidates objects
**Severity:** Medium. **Mitigation:** the contract test validates every object against its schema on every PR, and `VocationalCollegeExampleSetTest` checks the final grades against the live engine classes, so a schema or engine change that breaks the set fails in that PR; the generator is where the fix goes.

### Risk 3: a name resembles a real person, company or dossier
**Severity:** Low. **Mitigation:** first names are common Dutch names, surnames are invented water-landscape compounds (Vaartzicht, Kreekstee); the college, town, streets and companies are invented and every company name carries the fictional place name; postcodes start with 0, phone numbers with 06-0000, KvK numbers with 0000, company mail uses `.example`; the BRIN 00X3 ends in a digit; crebo codes start with 9 and each framework says it is an example, not an official dossier. No BSN anywhere.

## Rollback Strategy
Revert the PR: the set disappears from the wizard. An instance that loaded the set removes it first with `occ learniq:example-set:remove mbo --apply`.

## Open Questions
None.
