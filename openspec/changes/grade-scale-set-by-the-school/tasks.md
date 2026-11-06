# Tasks: grade-scale-set-by-the-school

## 1. The scale decides rounding and passing

- [ ] 1.1 `lib/Grading/GradeFormulaEvaluator.php`: read the plan's whole `GradeScale`, not only `passThreshold`, and round the final value by its `roundingRule` (`none`, `half-up-1dp`, `half-up-int`, `nearest-half`) before the pass check and before it is stored. Verify: PHPUnit `GradeFormulaEvaluatorTest`: 5.45 with `half-up-1dp` and a pass at 5.5 stores 5.5 and passes; with `none` it fails.
- [ ] 1.2 `lib/Grading/GradePassEvaluator.php`: for a `letter`, `ects` or `band` scale, pass on the `pass` flag of the band the value falls in. Verify: PHPUnit: a value in a band with `pass: false` fails.

## 2. Tags

- [ ] 2.1 Add `@spec openspec/specs/grading/spec.md#...` tags to `GradeFormulaEvaluator::evaluate()`, `GradePassEvaluator::evaluatePassed()` and the `GradeScaleDetail` page `_note`.

## 3. Matrix

- [ ] 3.1 When the tasks are done, archive this change, repoint `grd-your-own-grade-scale` to `openspec/specs/grading` and drop its `built.plan`.
