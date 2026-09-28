---
kind: code
depends_on: []
---

# Proposal: grading-defects-from-example-sets

## Summary

Building the six example sets (learniq #1031, #1051 to #1055) meant making every derived value agree with the code that derives it. That surfaced six defects the unit tests never saw, because their fixtures used the same wrong names as the code. This change fixes all six: per-component pass minimums, werkproces code resolution across dossiers, an undeclared `FinalGrade.cohortId`, study advice credit for an exempted unit, a neutral attendance flag kind for higher education and companies, and the QTI version label on items.

## Motivation

Each defect is reported in the "Found while building" section of an example set PR and repeated in TRACKER-R2 (17:15 ds-mbo, 18:00 ds-he):

1. `GradePassEvaluator::everyComponentRuleWith()` reads `passRules[].passThreshold`. The register declares `passRules[].minValue` (required). The evaluator therefore compares every component against `0`, so an `all-must-pass` plan never applies its component minimums. A rule with `componentId: null` is meant for the final grade, but the evaluator looks it up as component `''` and fails the learner whenever entries carry a component id. `GradeFormulaEvaluatorTest` passed because its fixtures also wrote `passThreshold`. (learniq #1052 finding 1, #1055 finding 1.)
2. `CompetencyAttainmentRollupHandler::findCompetencyByCode()` walks every `sbb-kwalificatiedossier` framework of the tenant and returns the first competency whose code matches. SBB codes such as `B1-K1-W1` repeat in every dossier: the MBO set has three frameworks sharing eight codes. A college with more than one programme links a werkproces assessment to another dossier's competency, and the attainment roll-up credits the wrong outcome. (#1052 finding 2.)
3. `GradeRollupHandler::recomputeFinalGrade()` writes `cohortId` onto `FinalGrade`, which the schema does not declare. No reader uses it: the one manifest reader filters final grades on `programmeId`, and every example set ships `programmeId` and no `cohortId`. (#1052 finding 3.)
4. An exemption-only plan aggregates to `value: null`, so `evaluatePassed()` returns `passed: null`. `BsaProgressEvaluator` counts only `passed: true`, so a first-year unit the exam board exempted earns no study advice credits. The MBO set works around it by exempting only second- and third-year units. (#1052 finding 5.)
5. `AttendanceFlag.flagKind` knows only primary and secondary school concerns and defaults to `signal-verzuim`. A university student under the 80 percent workgroup requirement is flagged as a leerplicht signal. Nothing sets the field, so every flag takes the default. (#1055 finding 2.)
6. The register, the item editor, the Moodle mapper and the exporter label item XML as QTI 3.0 and stamp the QTI 3.0 namespace. The markup itself is QTI 2.1: `assessmentItem`, `responseDeclaration`, `choiceInteraction`, `simpleChoice`, `adaptive`, `timeDependent`. The importer, the take view, `QtiChoiceOrderResolver` and `ItemAnalysisService` read those 2.1 element names only. A real QTI 3.0 item (`qti-assessment-item`, `qti-simple-choice`) is skipped on import and renders as placeholder options. The export package then claims QTI 3.0 for content no QTI 3.0 tool can read. (#1055 finding 3.)

PokSignature's missing parent role (#1052 finding 4) is change `pok-signature-parent-role`, not this one.

## Affected Projects

- [ ] Project: `learniq` — grading evaluator, competency roll-up, final grade roll-up, attendance flag creation, QTI writers and labels, register (`AttendanceFlag`, `Item`, `ItemBank` versions), the HE and MBO example set generators.

## Scope

### In Scope

- `GradePassEvaluator` reads `minValue`; a rule with `componentId: null` compares the final value.
- `GradePassEvaluator` returns `passed: true` when every component the plan declares (or, with none declared, every entry) is satisfied by an exemption and nothing numeric exists.
- `CompetencyAttainmentRollupHandler` resolves a werkproces code inside the framework whose `sourceRef` equals the assessment's `kwalificatiedossierCode`. Without that match it accepts only a code that exactly one SBB framework knows, and otherwise leaves `competencyId` null.
- `GradeRollupHandler` stops writing `cohortId`.
- `AttendanceFlag.flagKind` gains `attendance-requirement`; `AttendanceFlagCreationHandler` sets the kind from `AttendanceThreshold.kind`.
- QTI labels say QTI 2.1 wherever the content is 2.1: register descriptions, the editor and Moodle mapper namespace, the export manifest, the import dialog text, docblocks. The exporter rewrites the namespace of items stored with the old hybrid label.
- Example sets: the MBO frameworks carry the bare dossier code in `sourceRef`; HE and MBO attendance flags from a non-leerplicht threshold carry `attendance-requirement`. Both regenerated from their generator scripts.

### Out of Scope

- Reading real QTI 3.0 markup (kebab-case elements). Supporting both dialects in the importer, editor, take view and analysis is a feature of its own; this change makes the label honest.
- Applying `passRules` to formulas other than `all-must-pass`. The final-grade minimum for those formulas stays `GradeScale.passThreshold`.
- Backfilling existing `FinalGrade` rows. A row is recomputed on its next published entry; `occ` repair is not added here.
- Writing `FinalGrade.programmeId` from the roll-up. The handler has no programme in hand today; noted as a follow-up.
- `PokSignature` parent role (change `pok-signature-parent-role`).

## Approach

Fix each defect at the place that produces the wrong value, and prove it with a test that fails on the old code. Fixtures that used the wrong field names (`passThreshold` in `GradeFormulaEvaluatorTest`) are corrected to the register's names. Example set generators change only where the code now writes a different value, and the example set tests (which recompute derived values through the real classes) confirm the sets still agree with the code.

## New Dependencies

None.

## Impact

- `lib/Grading/GradePassEvaluator.php`, `lib/Grading/GradeFormulaEvaluator.php` (passes the component index).
- `lib/Listener/CompetencyAttainmentRollupHandler.php`, `lib/Listener/GradeRollupHandler.php`, `lib/Lifecycle/AttendanceFlagCreationHandler.php`.
- `lib/Service/QtiExportService.php`, `lib/Service/MoodleQuizQuestionMapper.php`, `src/views/ItemAuthorView.vue`, QTI docblocks.
- `lib/Settings/learniq_register.json` and the mock register: `AttendanceFlag` 0.1.0 to 0.2.0, `Item` and `ItemBank` description changes with version bumps, `info.version` bump.
- `scripts/example-sets/mbo.py`, `scripts/example-sets/he.py` and their JSON.

## Cross-Project Dependencies

None. Other round 3 lanes touching the same files are named in the PR body so the landing can order them.

## Risks

### Risk 1: Component minimums now bite

**Severity:** Medium. **Mitigation:** An `all-must-pass` plan with a component below its `minValue` now fails where it passed before. That is the rule the plan declares. The example set tests recompute every shipped final grade with the real evaluator and show which rows change.

### Risk 2: A werkproces code that used to resolve now stays unresolved

**Severity:** Medium. **Mitigation:** When a tenant's SBB frameworks carry no dossier code in `sourceRef` and share the code, `competencyId` stays null and an info log names the ambiguity. Null is the documented miss state: grading and the portal flow never depend on it. A wrong link, which is what happens today, credits the wrong competency silently.

### Risk 3: External tools reading exported packages

**Severity:** Low. **Mitigation:** The package now says QTI 2.1, which matches its markup, so a QTI 2.1 importer accepts it. A tool that only reads QTI 3.0 could not read the old package either.

## Rollback Strategy

Revert the merge commit. The register change is additive (one enum value, description text); rows written with `attendance-requirement` would then fail the old enum on their next save, so a rollback after flags were created needs those rows set back to `signal-verzuim`.
