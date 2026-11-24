---
kind: code
---

# Merge a duplicate learner account from the learner's page

## Why

Merging two accounts of one person works on the server. `LearnerMergeGuard` refuses a merge into a missing, inactive or same profile and one that would leave two open seats in one course; `LearnerMergeHandler` then has `LearnerMergeService::moveRecords()` re-point every learner-owned record to the surviving profile. The main spec `openspec/specs/learner-account-merge` (written in spec round part 2) describes it. But `LearnerProfileDetail` (`src/manifest.d/people.json:1760`) declares no `lifecycleActions`, so no page offers the merge, and an administrator has to call OpenRegister's lifecycle API with the survivor's uuid by hand.

### Matrix rows (`openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `gov-merge-duplicate-accounts` | Merge two accounts of one person. | `partial`: the merge runs through the API only |

This row was not in the part 3 gap list (it links a main spec, not a change), but part 2 named the same missing part, so it is handled here.

## What changes

- `LearnerProfileDetail` offers "Merge into another account" in its header to `hr` and `compliance-officers`, on an active profile only.
- The action asks which profile survives, with a search on name and personal number limited to other active profiles, and asks to confirm with both names in the sentence.
- A refused merge shows the guard's reason ("both accounts hold an open seat in Wiskunde B") and leaves both profiles as they were.
- A merged profile shows "Merged into {name}" with a link to the survivor.
- Delete is not offered here; offboarding keeps its own flow.

## Capabilities

### Modified capabilities

- `learner-account-merge`: ADDED requirements for the page action.

## Impact

- **Frontend**: `src/manifest.d/people.json` LearnerProfileDetail `lifecycleActions` with an explicit `transitions` list holding `merge` only; l10n.
- **Register**: `merge.inputs: [{field: "mergedInto", required: true}]` and a `label`.
- **Cross-repo**: `CnTransitionInputDialog` draws text, number, checkbox and long text. Choosing a profile needs a reference picker for a uuid input; nextcloud-vue adds it (CROSS item in the PR).
