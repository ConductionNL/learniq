---
kind: code
depends_on:
  - segment-example-datasets-training
  - grading-defects-from-example-sets
---

# Proposal: training-set-qti-2-1

## Summary
The training example set writes its 44 knowledge test items as QTI 3.0 markup (`qti-assessment-item`, namespace `imsqtiasi_v3p0`). The app reads QTI 2.1 (`assessmentItem`, `simpleChoice`, namespace `imsqti_v2p1`), which is what its item editor writes and, since learniq #1127, what it calls its dialect. So in the training set, no take view, draw resolver or item analysis can read a single item. This change regenerates the items as QTI 2.1, in the same form the HE set and the item editor use.

## Motivation
- TRACKER-R2 "ROUND 3 FINAL" follow-up: "training set writes QTI 3.0".
- #1127 (grading-defects-from-example-sets) regenerated the MBO and HE sets and relabelled the app's markup as QTI 2.1. The training set was not part of that change.

## Affected Projects
- [x] Project: `learniq`: `scripts/example-sets/training.py`, `lib/Settings/profiles/training.json` (regenerated, descriptor version 1.2.0), `tests/Unit/Settings/TrainingExampleSetTest.php`.

## Scope

### In Scope
- `qti()` in the training generator writes the QTI 2.1 choice item the item editor writes: `responseDeclaration`, a `SCORE` outcome, and `<p>` stem plus `choiceInteraction` with three `simpleChoice` options.
- Only the 44 item rows change. Every uuid, answer and other bucket stays the same.

### Out of Scope
- Other sets: MBO and HE already write QTI 2.1; PO, VO and company ship no items.

## Approach
Generator change and regeneration; a test runs the app's own `QtiChoiceOrderResolver` on every item.

## New Dependencies
None.

## Impact
An install that already loaded the training set gets the readable items on the next load of the set (fixed uuids, so the rows are updated in place).
