---
kind: code
---

# Record an exemption from a mandatory training, with the reason

## Why

ProRail requirement 84940 (TenderNed) asks for compliance processes with recertification, signals and recording of exceptions and exemptions. In learniq an exemption exists only as `ExemptionCase`, an exam board decision that grants a grade for a curriculum component (`lib/Settings/learniq_register.json` `ExemptionCase`, `lib/Lifecycle/ExemptionDecisionGuard.php`). A manager cannot record that an employee is exempt from a regulation (for example a medical reason or a role change), so `ComplianceRollupService::isCovered` (`:222`) counts that person as not covered forever. The row is a tender demand row, so it is built; Moodle, ILIAS and iSpring rate partial.

One row, one change.

### Matrix rows (`learniq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `comp-record-exemption` | Record an exemption from a mandatory training, with the reason. | `partial`: `partial`: exemptions exist only as exam board grade exemptions; a compliance regulation has no exemption for a learner and the roll-up cannot excuse one |

### Demand

- `comp-record-exemption`: tender, https://www.tenderned.nl/aankondigingen/overzicht/411287

### Competitors rated yes

- `comp-record-exemption`: no competitor rated yes.

## What Changes

- Add a `RegulationExemption` schema: learner, regulation, reason kind and text, decided by, valid from, valid until, lifecycle `requested`, `granted`, `rejected`, `expired`.
- Reuse the decision guard rule: a grant needs a written rationale and a policy reference.
- Make the roll-up treat a granted, unexpired exemption as excused: not counted as covered and not counted as an obligation, and shown as its own figure.
- Add an Exemptions page for compliance officers and a request action for managers.

## Capabilities

### New Capabilities

- `compliance-exemptions`

### Modified Capabilities

- None in delta form.

## Impact

- **Register**: new schema `RegulationExemption` in `lib/Settings/learniq_register.json` with a lifecycle and guard reference.
- **Backend**: a guard class beside `ExemptionDecisionGuard`, `ComplianceRollupService` and the coverage figure of `ExternalTrainingService::isLearnerCovered` callers.
- **Frontend**: an Exemptions page in `src/manifest.d/`, a request dialog in its own file.
- **Cross-row**: `compliance-rule-coverage-table` shows the excused figure once both are built; either order works.
- **Security**: manager requests are scoped to the manager's direct reports; hydra gate 7 (IDOR) on the request route.
