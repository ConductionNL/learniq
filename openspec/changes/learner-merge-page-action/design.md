# Design: merge a duplicate learner account from the learner's page

## Context

At development `de2d7388`:

- `LearnerProfile` lifecycle: `merge` (active to merged, `requires: LearnerMergeGuard`) and `delete` (active to deleted). The guard receives the object at its target state with the transition inputs merged in, so `mergedInto` arrives as an input.
- Authorization on `learner-profile`: update for `hr` and `compliance-officers`.
- `LearnerProfileDetail` `_note`: "No lifecycleActions ... status changes are handled by dedicated flows". That note predates the merge guard and handler.
- nextcloud-vue `CnLifecycleActions` accepts an explicit `transitions: [{from, to, action, label, confirm, variant, inputs}]` list and opens `CnTransitionInputDialog` for declared inputs. The dialog has no reference picker today.

## Screen

No board draws it. The canvas draws the teacher; `LqLeerling` has "Bewerken" and "Meer" in the header, and the merge belongs under "Meer" (the Actions menu), which is where `CnDetailPage` puts lifecycle actions. Labels: "Merge into another account" / "Samenvoegen met ander account", "Which account stays?" / "Welk account blijft?", confirm "Merge {this} into {survivor}? Enrolments, grades and attendance move to {survivor}. This cannot be undone from this page." / "{this} samenvoegen met {survivor}? Inschrijvingen, cijfers en aanwezigheid gaan naar {survivor}. Dit kun je hier niet terugdraaien.", "Merged into {name}" / "Samengevoegd met {name}".

## Decisions

### D1: An explicit list with merge only

`{field: "lifecycle"}` alone would also offer `delete`, which belongs to offboarding with its retention rules. The explicit list keeps this change to the one missing action.

### D2: Pick, never type, the survivor

A uuid typed by hand is the most likely way to merge into the wrong person. The input is a picker over `learner-profile` filtered on `lifecycle: active` and excluding the current profile, showing `fullName`, `personalNumber` and `groupLabel`. The guard still checks; the picker only makes the right choice easy.

### D3: The confirmation names both people

A merge moves records and is undone only by an administrator in OpenRegister. The confirm sentence names both accounts and what moves.

### D4: The merged profile points forward

The data widget shows `mergedInto` as a link to the survivor, so anyone landing on the old account (an old link, a search hit) is sent on.
