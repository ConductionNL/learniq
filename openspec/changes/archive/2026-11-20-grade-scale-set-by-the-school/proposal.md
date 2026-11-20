---
kind: code
---

# Proposal: grade-scale-set-by-the-school

## Summary

A school marks on its own scale: 1 to 10 with a pass at 5.5, letters, pass or fail, or bands of its own. learniq has the `GradeScale` schema with an index and detail page, and a curriculum plan names its scale. No requirement says a school sets its own scale. The only scale requirement in `grading` adds two kinds for later LVS data. This change writes it down.

Reading the code showed one part is stored but not applied: `roundingRule` is read by no code, and `bands` only feed the histogram on the publish screen. The pass limit is applied (`GradeFormulaEvaluator::fetchPassThreshold()`). The rounding and the band pass flags get tasks.

## The rows this covers

| row | capability | built |
|---|---|---|
| `grd-your-own-grade-scale` | Use your own marking scale, not someone else's. | `GradeScale` (kind, bands, min, max, pass limit, rounding), pages `GradeScales` and `GradeScaleDetail`; the pass limit is applied, the rounding and band pass flags are not |

## What changes

1. ADDED requirements on `grading`: a school defines its own scales, and the scale decides how a mark is rounded and whether it passes.
2. Tasks to apply the rounding rule and the band pass flags in the final grade.

## Screens

LqCijferschaal draws the scale: see `screens.md`.
