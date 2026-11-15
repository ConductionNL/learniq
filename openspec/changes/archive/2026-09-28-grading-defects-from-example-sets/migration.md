# Migration: grading-defects-from-example-sets

## Current State

- `AttendanceFlag.flagKind` enum: `signal-verzuim`, `langdurig-relatief-verzuim`, `thuiszitter`; default `signal-verzuim`. Schema version 0.1.0.
- `Item`, `ItemBank` and `Assessment.shuffleAnswerOptions` descriptions say QTI 3.0.
- Stored `FinalGrade` rows written by the roll-up may carry an undeclared `cohortId`, and exemption-only plans carry `passed: null`.
- Stored `Item.qtiBody` values written by the editor or the Moodle mapper carry the QTI 3.0 namespace on QTI 2.1 markup.

## Target State

- `flagKind` enum gains `attendance-requirement`; the default is unchanged. `AttendanceFlag` 0.2.0.
- Descriptions name QTI 2.1; `Item`, `ItemBank` and `Assessment` get patch version bumps. Register `info.version` 0.28.1 to 0.29.0.
- New and recomputed FinalGrades carry no `cohortId`, and an exemption-only plan recomputes to `passed: true`.
- New items carry the QTI 2.1 namespace; old items are relabelled on export only.

## Migration Class

None. OpenRegister applies schema changes when the register JSON is imported on app upgrade (`occ upgrade` runs the repair step that imports `learniq_register.json`). No table or column is added.

## Migration Steps

1. The app upgrade imports the register: the enum and descriptions update in place.
2. No stored row is rewritten. `FinalGrade` rows are corrected on their next recompute (the next published GradeEntry for that learner and plan). `Item.qtiBody` rows keep their XML; the exporter relabels them.

## Data Impact

- Additive enum value: every existing `AttendanceFlag` stays valid.
- Existing `FinalGrade` rows with `cohortId` keep it until recomputed; OpenRegister ignores the undeclared key on read. Existing exemption-only final grades stay `passed: null` until a recompute; an institution that needs them now can republish one entry per affected learner.
- No data loss.

## Rollback Procedure

Revert the commit and re-import the previous register. Before that, set any `AttendanceFlag` with `flagKind: attendance-requirement` back to `signal-verzuim`, or those rows fail the old enum on their next save.

## Validation

- `GET /apps/openregister/api/schemas/{attendance-flag}` shows `attendance-requirement` in the `flagKind` enum and version 0.2.0.
- A `check-threshold` crossing on a `college-aanwezigheid` threshold creates a flag with `flagKind: attendance-requirement`.
- Republishing a GradeEntry on an exemption-only plan leaves its FinalGrade with `passed: true` and no `cohortId`.
