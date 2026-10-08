---
kind: code
---

# Assign mandatory training automatically from job and role data in the HR system

## Why

ProRail requirement 84953 asks for a link with AFAS: staff, function and role data decide who must follow which training, and the LMS keeps that up to date by itself. learniq#951 built half of it. Publishing a regulation enrols every learner its audience covers (department with sub-departments, or roles) in its mandatory courses (`RegulationAssignmentService::assign()`, `RegulationAudienceResolver::covers()`). Two things are missing:

1. Department, roles and manager on a `LearnerProfile` are typed in by hand. Nothing brings them over from the HR system.
2. The assignment only runs when a regulation is published or when someone presses assign. A new colleague, or one who moves to another department, gets no mandatory training until somebody presses the button again.

One row, one change.

### Matrix rows (`learniq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `comp-assign-from-hr-roles` | Assign mandatory training automatically from job and role data in the HR system. | `partial`: audience-based assignment on publish (learniq#951); no HR connection and no re-run when a person's data changes |

### Demand

- Tender: ProRail requirement 84953.

## What changes

- integriq keeps `LearnerProfile.department`, `roles`, `managerId` and a new `jobTitle` in step with the HR system. learniq declares the fields and the contract; integriq carries AFAS (and humaniq, the fleet's HR app) as sources.
- A profile the HR feed writes carries `hrSource` and `hrSyncedAt`, and those four fields become read only for people in learniq, so the HR system stays the single source.
- When department, roles or job title change on a profile, learniq re-runs the assignment for every published regulation for that one person. A person newly in scope is enrolled with the regulation's deadline; nobody is withdrawn automatically.
- A person who drops out of a regulation's scope gets a "no longer in scope" mark on the open mandatory enrolment, for the compliance officer to withdraw or keep.

## Capabilities

### Modified capabilities

- `compliance-rule-coverage`: ADDED requirements for HR-fed profile data and per-person re-assignment.

## Impact

- **Register**: `LearnerProfile.jobTitle`, `hrSource`, `hrSyncedAt`; `Enrolment.outOfScopeSince`. `Regulation.audienceJobTitles` (optional, a third audience criterion next to departments and roles).
- **Backend**: `lib/Listener/ProfileScopeChangeHandler.php` on the profile updated event; `RegulationAssignmentService` gains `assignPerson(profile)` next to `assign(regulation)`; `RegulationAudienceResolver::covers()` reads `audienceJobTitles`.
- **Cross app**: integriq source templates and mappings for AFAS and humaniq into learniq's `LearnerProfile` (integriq's `connectors-hr-data-exchange` already maps humaniq to an LMS, Studytube; this is the same shape with learniq as target).
