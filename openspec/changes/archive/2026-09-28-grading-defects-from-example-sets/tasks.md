# Tasks: grading-defects-from-example-sets

Every task writes its test first and runs it against the old code, where it must fail.

## Implementation Tasks

### Task 1: Pass rules read minValue, and a null componentId compares the final value
- **spec_ref**: `openspec/changes/grading-defects-from-example-sets/specs/grading/spec.md#requirement-pass-rules-apply-their-declared-minimum`
- **files**: `lib/Grading/GradePassEvaluator.php`, `tests/Unit/Grading/GradePassEvaluatorTest.php`, `tests/Unit/Grading/GradeFormulaEvaluatorTest.php`
- **acceptance_criteria**:
  - GIVEN an all-must-pass plan with a component at 4.0 under `minValue: 5.5` WHEN evaluated THEN `passed` is false
  - GIVEN a rule with `componentId: null` WHEN evaluated THEN the final value is compared
  - GradeFormulaEvaluatorTest fixtures use `minValue`, the register's name
- [x] Implement
- [x] Test

### Task 2: An exemption-only plan passes when exemptions cover every declared component
- **spec_ref**: `openspec/changes/grading-defects-from-example-sets/specs/grading/spec.md#requirement-a-plan-satisfied-entirely-by-exemptions-passes`, `.../specs/study-progress/spec.md#requirement-an-exempted-unit-earns-its-study-advice-credits`
- **files**: `lib/Grading/GradePassEvaluator.php`, `lib/Grading/GradeFormulaEvaluator.php`, `tests/Unit/Grading/GradePassEvaluatorTest.php`, `tests/Unit/Grading/GradeFormulaEvaluatorTest.php`
- **acceptance_criteria**:
  - GIVEN a one-component plan with one exemption entry WHEN evaluated THEN `passed: true`, `value: null`
  - GIVEN a two-component plan with one exemption WHEN evaluated THEN `passed: null`
  - GIVEN that FinalGrade WHEN BsaProgressEvaluator sums credits THEN the course counts
- [x] Implement
- [x] Test

### Task 3: The final grade roll-up stops writing cohortId
- **spec_ref**: `openspec/changes/grading-defects-from-example-sets/specs/grading/spec.md#requirement-the-final-grade-roll-up-writes-only-declared-properties`
- **files**: `lib/Listener/GradeRollupHandler.php`, `tests/Unit/Listener/GradeRollupHandlerTest.php`
- **acceptance_criteria**:
  - GIVEN an entry and an existing FinalGrade both with `cohortId` WHEN recomputed THEN the saved object has no `cohortId`
- [x] Implement
- [x] Test

### Task 4: A werkproces code resolves inside the assessment's dossier, never the first of several
- **spec_ref**: `openspec/changes/grading-defects-from-example-sets/specs/bpv/spec.md#requirement-a-werkproces-code-resolves-inside-the-assessments-own-kwalificatiedossier`
- **files**: `lib/Listener/CompetencyAttainmentRollupHandler.php`, `tests/Unit/Listener/CompetencyAttainmentRollupHandlerTest.php`
- **acceptance_criteria**:
  - GIVEN two frameworks sharing a code WHEN the assessment names one dossier THEN that dossier's competency is linked
  - GIVEN two frameworks sharing a code and no dossier match WHEN resolved THEN `competencyId` stays null
- [x] Implement
- [x] Test

### Task 5: AttendanceFlag.flagKind gains attendance-requirement, set from the threshold kind
- **spec_ref**: `openspec/changes/grading-defects-from-example-sets/specs/attendance/spec.md#requirement-an-attendance-flag-outside-the-leerplicht-carries-a-neutral-kind`
- **files**: `lib/Settings/learniq_register.json`, `lib/Lifecycle/AttendanceFlagCreationHandler.php`, `tests/Unit/Lifecycle/AttendanceFlagCreationHandlerTest.php`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a `college-aanwezigheid` threshold crossing WHEN the flag is created THEN `flagKind: attendance-requirement`
  - GIVEN a `leerplicht-16uur` crossing THEN `signal-verzuim`; GIVEN `generic` THEN no `flagKind` key
  - `AttendanceFlag` version 0.2.0, register `info.version` bumped, the changed description has catalogue values
- [x] Implement
- [x] Test

### Task 6: QTI writers, exporter and register text say QTI 2.1
- **spec_ref**: `openspec/changes/grading-defects-from-example-sets/specs/assessment/spec.md#requirement-items-are-stored-as-qti-21-and-labelled-as-qti-21`, `#requirement-itembank-exports-its-items-as-a-qti-21-package`
- **files**: `src/views/ItemAuthorView.vue`, `lib/Service/MoodleQuizQuestionMapper.php`, `lib/Service/QtiExportService.php`, `lib/Controller/QtiExportController.php`, `lib/Settings/learniq_register.json`, QTI docblocks, their tests
- **acceptance_criteria**:
  - GIVEN a Moodle choice question WHEN mapped THEN `qtiBody` carries `imsqti_v2p1`
  - GIVEN a bank with an item under the 3.0 label WHEN exported THEN the manifest declares QTI 2.1 and the item differs from its stored body only in the namespace
  - `Item`, `ItemBank`, `Assessment` descriptions name QTI 2.1, with patch version bumps
- [x] Implement
- [x] Test

### Task 7: The MBO and HE example sets follow the code
- **spec_ref**: `openspec/changes/grading-defects-from-example-sets/design.md#seed-data`
- **files**: `scripts/example-sets/mbo.py`, `scripts/example-sets/he.py`, `lib/Settings/profiles/mbo.json`, `lib/Settings/profiles/he.json`
- **acceptance_criteria**:
  - MBO frameworks carry the bare dossier code in `sourceRef`; flags from a non-leerplicht threshold carry `attendance-requirement`
  - HE items carry the QTI 2.1 namespace; HE flags carry `attendance-requirement`
  - Both example set tests are green, including their generator check and their recomputed pass verdicts
- [x] Implement
- [x] Test

## Verification
- [x] `openspec validate grading-defects-from-example-sets` passes
- [x] Diff-scoped checks, then `composer check:strict`, `npm run lint`, `npm run format`, `npm run check:schema-l10n`, hydra gates, each with its exit code in the PR body
- [x] Full PHPUnit failure set equals development's inherited set

## Quality checklist

- New and changed business logic covered by PHPUnit tests that fail on the old code
- No new endpoint, so no Newman test; no new screen, so no Playwright test
- Dutch catalogue value for the changed `flagKind` description (ADR-007)
- No em-dashes, sentence case in any new description
