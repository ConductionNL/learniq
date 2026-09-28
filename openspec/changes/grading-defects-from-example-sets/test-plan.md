# Test Plan: grading-defects-from-example-sets

Every test case is a PHPUnit test (type: regression, command `vendor/bin/phpunit --filter <Test>`). Each is written first and run against the old code, where it MUST fail, then against the fix. No browser or API test: no endpoint or screen changes behaviour a person can see except the export file name.

### TC-1: A component below its minimum fails an all-must-pass plan
- **spec_ref**: `openspec/changes/grading-defects-from-example-sets/specs/grading/spec.md#requirement-pass-rules-apply-their-declared-minimum`
- **type**: regression
- **preconditions**: rules `comp-a` and `comp-b` at `minValue: 5.5`; entries 8.0 and 4.0; scale threshold 5.5
- **steps**: `GradePassEvaluator::evaluatePassed('all-must-pass', 6.0, ...)`
- **expected result**: `false` (old code: `true`, because it read `passThreshold` and compared against 0)
- **test command**: `vendor/bin/phpunit --filter GradePassEvaluatorTest`

### TC-2: A final-grade rule compares the final value
- **spec_ref**: `.../specs/grading/spec.md#requirement-pass-rules-apply-their-declared-minimum`
- **type**: regression
- **preconditions**: rule `{componentId: null, minValue: 5.5}`, entries on `comp-a` and `comp-b`
- **steps**: evaluate with final value 6.5, then 5.0 without a scale threshold
- **expected result**: `true`, then `false` (old code: `false` for 6.5, because component `''` has no entry)
- **test command**: `vendor/bin/phpunit --filter GradePassEvaluatorTest`

### TC-3: Exemption-only plans pass; partial exemptions stay open
- **spec_ref**: `.../specs/grading/spec.md#requirement-a-plan-satisfied-entirely-by-exemptions-passes`
- **type**: regression
- **steps**: evaluate a one-component plan with one exemption entry; a two-component plan with one exemption; a plan without components with exemption entries
- **expected result**: `true`, `null`, `true` (old code: `null` for all three)
- **test command**: `vendor/bin/phpunit --filter "GradePassEvaluatorTest|GradeFormulaEvaluatorTest"`

### TC-4: An exempted course earns its credits
- **spec_ref**: `.../specs/study-progress/spec.md#requirement-an-exempted-unit-earns-its-study-advice-credits`
- **type**: regression
- **steps**: `GradeFormulaEvaluator::evaluate()` on an exemption-only plan over a store double, then `BsaProgressEvaluator::evaluate()` over the resulting FinalGrade
- **expected result**: `passed: true` and the course's ECTS in `ectsEarned`
- **test command**: `vendor/bin/phpunit --filter GradeFormulaEvaluatorTest`

### TC-5: A recomputed final grade carries no cohortId
- **spec_ref**: `.../specs/grading/spec.md#requirement-the-final-grade-roll-up-writes-only-declared-properties`
- **type**: regression
- **steps**: publish a GradeEntry with `cohortId` while an existing FinalGrade carries `cohortId`
- **expected result**: the saved object has no `cohortId` key
- **test command**: `vendor/bin/phpunit --filter GradeRollupHandlerTest`

### TC-6: Werkproces code resolution stays inside the dossier
- **spec_ref**: `.../specs/bpv/spec.md#requirement-a-werkproces-code-resolves-inside-the-assessments-own-kwalificatiedossier`
- **type**: regression
- **steps**: three cases over a store double holding two SBB frameworks that share `B1-K1-W1`: dossier match, no dossier match (ambiguous), code known to one framework only
- **expected result**: the dossier's competency; no save; that competency (old code: the first framework's competency in case one and two)
- **test command**: `vendor/bin/phpunit --filter CompetencyAttainmentRollupHandlerTest`

### TC-7: The flag kind follows the threshold kind
- **spec_ref**: `.../specs/attendance/spec.md#requirement-an-attendance-flag-outside-the-leerplicht-carries-a-neutral-kind`
- **type**: regression
- **steps**: a crossing on `college-aanwezigheid`, `compliance-presence`, `leerplicht-16uur` and `generic` thresholds; a register test for the enum value
- **expected result**: `attendance-requirement`, `attendance-requirement`, `signal-verzuim`, no `flagKind` key
- **test command**: `vendor/bin/phpunit --filter AttendanceFlagCreationHandlerTest`

### TC-8: QTI writers and the exporter say QTI 2.1
- **spec_ref**: `.../specs/assessment/spec.md#requirement-items-are-stored-as-qti-21-and-labelled-as-qti-21`, `#requirement-itembank-exports-its-items-as-a-qti-21-package`
- **type**: regression
- **steps**: map a Moodle question; export a bank holding one item with the 3.0 label and one with the 2.1 label; a JS unit test for the editor's `buildQtiBody()` namespace
- **expected result**: the mapper writes `imsqti_v2p1`; the manifest declares `imsqti_v2p1` and `imsqti_item_xmlv2p1`; the relabelled item differs from its stored body only in the namespace
- **test command**: `vendor/bin/phpunit --filter "QtiExportServiceTest|MoodleQuizQuestionMapperTest"`

### TC-9: The example sets still agree with the code
- **type**: regression
- **steps**: run `VocationalCollegeExampleSetTest` and `HigherEducationExampleSetTest`, which recompute final grades and pass verdicts through the real engine and check the JSON against its generator
- **expected result**: green after regeneration
- **test command**: `vendor/bin/phpunit --filter "VocationalCollegeExampleSetTest|HigherEducationExampleSetTest"`

## Coverage Summary

| Requirement | Covered by |
|---|---|
| grading: pass rules apply their declared minimum | TC-1, TC-2 |
| grading: a plan satisfied entirely by exemptions passes | TC-3 |
| grading: the roll-up writes only declared properties | TC-5 |
| study-progress: an exempted unit earns its credits | TC-4 |
| bpv: werkproces code resolves inside the dossier | TC-6 |
| attendance: neutral flag kind | TC-7 |
| assessment: QTI 2.1 storage label and export package | TC-8 |

## Out of Scope

A live check on the instance on :8080: this lane does not touch the shared instance. The export file name is visible to a person but carries no behaviour worth a browser test.
