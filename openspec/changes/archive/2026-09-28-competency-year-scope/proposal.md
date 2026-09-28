---
kind: config
---

# Proposal: competency-year-scope

## Summary
A kerndoel or eindterm in learniq cannot say which year it is taught in, or which subject it belongs to. This change adds two optional properties to `Competency`: `applicableYears` (the years a goal is taught in, as free labels such as `groep 5` or `2026-2027`) and `subjectId` (the `Course` that stands for the subject). It is the first of four round 2 curriculum changes, and the field names are the contract the SLO importer in integriq builds against (D16).

## Motivation
Round 2 recon B (`/home/rubenlinde/memcap-work/learniq-mi/learniq/_round2/recon/B-curriculum-goals-coverage.md`, section 1, row "Goal-to-year (or -leerjaar) allocation") found the gap: "Neither `Competency` nor `CompetencyFramework` carries any `academicYear`/`leerjaar`/`grade` field. A kerndoel cannot today be said to belong to groep 5 vs groep 8." Without a year and a subject on the goal, the product owner's core question (is the goal set for this subject and year covered?) has nothing to group by.

Competitor evidence: SERA Datawijzer imports "SLO domains, kerndoelen and learning goals into the school's leerlijnen" (round 1 proposed row L-new-7, PO, `_round1/compare/proposed-rows.md:222`, https://sera.nl/datawijzer/leerlijnen/). Curriculum-mapping tools such as Atlas show coverage per grade and per discipline (recon B section 2, onatlas.com/atlas-features, read 2026-09-27). Both need a goal to carry a year and a subject.

Plan assumption A2 holds: kerndoelen and eindtermen live in the existing `CompetencyFramework` and `Competency` schemas, so no second goal tree is built. Decision D16 builds the SLO importer in parallel, so the names are published up front in `design.md` and in `/home/rubenlinde/memcap-work/lq-lanes/CONTRACT-competency-fields.md`.

## Affected Projects
- [x] Project: `learniq`: `lib/Settings/learniq_register.json` gains `Competency.applicableYears` and `Competency.subjectId`; `lib/Settings/learniq_mock_register.json` demo rows carry the two fields; `l10n/en.json` and `l10n/nl.json` gain the new labels; a register unit test pins the shape.

## Scope

### In Scope
- `Competency.applicableYears`: optional array of free string labels, default `[]`, unique items.
- `Competency.subjectId`: optional, nullable UUID reference to `Course`, since learniq models a subject as a `Course` (the same reference `SubjectTeacherAssignment.courseId` uses; PR 929 added no separate Subject schema).
- The inheritance rule a reader applies (an empty value inherits the nearest ancestor's value), stated in the spec so the coverage rollup and the importer agree.
- Register and per-schema version bumps, demo data, translation keys, a register unit test.

### Out of Scope
- Computing coverage. That is `curriculum-coverage-rollup`, a later change in this lane.
- A depth or weight on the lesson-to-goal link. That is `goal-alignment-depth`.
- The SLO importer itself. That is `slo-kerndoelen-import` in integriq (lane r2-slo).
- A year-level enum or a SchoolYear schema. Labels stay free, the same convention as `Cohort.academicYear`.

## Approach
Two additive properties on one existing schema, declared in the register JSON. No PHP, no new page: the existing `Competency` index and detail pages render every schema property through the data widget and the create and edit forms.

## New Dependencies
None.

## Impact
- `lib/Settings/learniq_register.json`: `Competency` gains two properties; `Competency.version` 0.1.0 to 0.2.0; `info.version` minor bump.
- `lib/Settings/learniq_mock_register.json`: the three `Competency` demo rows gain the two fields.
- `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`: keys for two titles and two descriptions.
- `tests/Unit/Settings/CompetencyYearScopeRegisterTest.php`: new.

## Cross-Project Dependencies
- `integriq` (lane r2-slo, change `slo-kerndoelen-import`) writes `applicableYears` and `subjectId` when it imports SLO kerndoelen. It consumes the names in `contract.md`. This change does not depend on integriq.

## Risks

### Risk 1: The importer and learniq drift on the label spelling
**Severity:** Medium. **Mitigation:** `design.md` fixes a canonical spelling (`groep 5`, `leerjaar 2`, `jaar 1`, or `YYYY-YYYY`), the rollup compares labels after trim and lower-casing, and the contract file is published before the importer is written.

### Risk 2: A subject is a Course, which reads oddly to a PO school
**Severity:** Low. **Mitigation:** this is the existing learniq convention (`SubjectTeacherAssignment.courseId`, `SubjectChoice`). The field is named `subjectId` so a later Subject schema can take over the reference without renaming the property.

## Rollback Strategy
Revert the register, mock and catalogue diffs. Both properties are optional and default to empty, so no stored object depends on them.

## Open Questions
None. The label scheme (year levels or academic years, one scheme per framework) is decided in `design.md`.
