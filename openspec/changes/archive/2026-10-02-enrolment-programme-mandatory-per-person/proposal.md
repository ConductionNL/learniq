---
kind: code
---

# Make a part of a learning path mandatory for one person and optional for another

## Why

ProRail requirement 84949 (TenderNed) asks for a learning path with mandatory and optional parts, set individually per employee. In learniq a `Programme` is a plain list of `courseIds` (`lib/Settings/learniq_register.json` `Programme`) and `Enrolment.mandatory` is a boolean per course enrolment, so the per-person switch exists but a path has no notion of which parts are required, the enrolment form does not expose the switch per part, and programme progress counts every course alike. The row is a tender demand row, so it is built; ILIAS rates yes.

One row, one change.

### Matrix rows (`learniq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `enr-path-mandatory-per-person` | Make a part of a learning path mandatory for one person and optional for another. | `partial`: `partial`: `Enrolment.mandatory` is set per enrolment, but a programme cannot mark parts mandatory or optional and progress does not tell them apart |

### Demand

- `enr-path-mandatory-per-person`: tender, https://www.tenderned.nl/aankondigingen/overzicht/411287

### Competitors rated yes

- `enr-path-mandatory-per-person`, ilias: "source read at ILIAS-eLearning/ILIAS v11.4: components/ILIAS/StudyProgramme/classes/class.ilObjStudyProgrammeIndividualPlanGUI.php:139 (manage) and :204-235 (updateStatus sets a node not relevant or relevant per assignment) and :2"

## What Changes

- Add `mandatoryCourseIds` to `Programme`: the courses that are mandatory by default; the others are optional (design D3 replaces the `courseRequirements` map named here first).
- When a person is enrolled in a programme, create each course enrolment with `mandatory` from the default, and let the manager change it per person in the enrolment form.
- Programme progress and the learner home widget count mandatory parts for completion and list optional parts separately.

## Capabilities

### New Capabilities

- `programme-mandatory-parts`

### Modified Capabilities

- None in delta form.

## Impact

- **Register**: `Programme.mandatoryCourseIds`; no migration: an existing programme has no list, so nothing changes until an author sets it.
- **Backend**: both programme enrolment paths (catalogue sign-up, admission placement) and a new progress calculation with its endpoint.
- **Frontend**: programme form, enrolment form at /enrolments, learner home widget.
- **Cross-row**: none.
