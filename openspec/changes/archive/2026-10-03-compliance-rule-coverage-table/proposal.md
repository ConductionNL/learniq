---
kind: code
---

# Read every rule's coverage next to each other on one page

## Why

`src/views/LearniqCompliance.vue` renders count tiles and a department roll-up widget (`DepartmentComplianceWidget`, learniq#951). A compliance officer asking "how covered is each rule" has to open regulations one by one. `ComplianceRollupService` already computes, for every regulation a learner is in scope of, whether the learner is covered (`regulationFigures`, `:165`); only the grouping by regulation is missing. Compliance is the core area and five competitors rate partial with none yes, so the core-area clause of the rule decides.

One row, one change.

### Matrix rows (`learniq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `comp-all-rules-one-page` | Read every rule's coverage next to each other on one page. | `partial`: `partial`: the compliance page shows count tiles and a per-department roll-up, not a per-rule coverage table |

### Demand

- `comp-all-rules-one-page`: no demand row.

### Competitors rated yes

- `comp-all-rules-one-page`: no competitor rated yes.

## What Changes

- Add `ComplianceRollupService::byRegulation()` that returns, per active regulation, the learners in scope, the covered count, the percentage and the RAG state against the regulation's own thresholds.
- Add a per-rule coverage table widget to the compliance page: regulation, in scope, covered, percent, RAG, with a row link to the regulation and a filter by department.

## Capabilities

### New Capabilities

- `compliance-rule-coverage`

### Modified Capabilities

- None in delta form.

## Impact

- **Backend**: `ComplianceRollupService::byRegulation`, a route beside the department roll-up route, same auth.
- **Frontend**: a new widget in `src/views/widgets/`, slot in `LearniqCompliance.vue`.
- **Database**: none.
