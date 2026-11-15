# Design: assign mandatory training automatically from job and role data in the HR system

## Context

At development `24b9ae95`:

- `lib/Service/RegulationAssignmentService.php::assign(array $regulation)` enrols every covered learner in the regulation's mandatory courses; run on publish by `lib/Listener/RegulationAssignmentHandler.php` and by `POST /api/compliance/regulations/{id}/assign`.
- `lib/Service/RegulationAudienceResolver.php::covers(regulation, profile)` and `departmentLevels(department)`.
- `LearnerProfile`: `department`, `roles`, `managerId`; create and update for hr and compliance-officers.
- integriq `openspec/changes/connectors-hr-data-exchange`: humaniq people feed to Studytube, and completed trainings back as `TrainingRecord`. Same pattern, other target.

## Screen

No board. The canvas (`5NkFW28vZUUij43xzxHg5a`) draws the teacher; the compliance officer's and hr's screens are not drawn yet (`capabilities-learniq.md` section 4). The compliance page keeps its layout; the enrolment list gets one column, "Niet meer in scope sinds".

## Decisions

### D1: integriq writes the profile, learniq reacts to the profile

learniq does not call AFAS. integriq's synchronization writes the HR fields onto `LearnerProfile` through the objects API. learniq listens to the profile's updated event, which fires whoever writes it. That way the same code path runs for a feed, an import and a manual edit.

### D2: Add, never remove

A wrong HR record must not silently withdraw someone from a safety training. The handler enrols a person who comes into scope and only marks one who drops out. The compliance officer decides.

### D3: The HR system owns its fields

A profile with `hrSource` set refuses manual edits to `department`, `roles`, `managerId` and `jobTitle`, with a message that names the source. Otherwise the next sync overwrites the edit and the person loses or gains training for no visible reason.

### D4: Per person, not per regulation

Re-running `assign(regulation)` for every regulation on every profile change scales with the whole staff. `assignPerson(profile)` checks the published regulations against one profile.

## Risks

- A bulk HR import fires one event per profile. `assignPerson` is idempotent (an existing open enrolment is kept), so a replay costs time, not duplicates.
