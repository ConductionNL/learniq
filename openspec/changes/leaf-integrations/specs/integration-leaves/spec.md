# Integration Leaves

Which OpenRegister integration leaves Scholiq declares, on which archetypes, and why the rest are OFF. A leaf is declared in two places and only two places: a `linkedTypes` entry on the schema in `lib/Settings/scholiq_register.json` (stage 2 of OpenRegister's three-stage filter) and an `{"type": "integration", "integrationId": "..."}` widget in `src/manifest.json` (stage 3). Scholiq ships no leaf provider code of its own.

## ADDED Requirements

### Requirement: Leaves are declared, not coded (REQ-001)
Every Scholiq integration leaf MUST consist solely of a `linkedTypes` entry on a schema in `lib/Settings/scholiq_register.json` plus zero or more `{"type": "integration", "integrationId": "..."}` widgets in `src/manifest.json`. Scholiq MUST NOT implement an `IntegrationProvider`, MUST NOT call `IntegrationRegistry::addProvider()`, and MUST NOT add per-leaf Vue components. Every declared `integrationId` and `linkedTypes` value MUST be an id served by OpenRegister's `IntegrationRegistry::listIds()` (unknown ids fail register import via `Schema::validateLinkedTypesValue()`). The single existing exception for server-side leaf logic — `CohortTalkMembershipHandler`, which syncs Talk membership with active Enrolments — remains, and no new leaf may add listener logic without a spec change.

#### Scenario: The leaf surface is enumerable from two files
- GIVEN the Scholiq repository at this change's completion
- WHEN `lib/Settings/scholiq_register.json` is searched for `linkedTypes` and `src/manifest.json` for `"type": "integration"`
- THEN every leaf Scholiq consumes appears in those results
- AND `lib/` contains no `IntegrationProvider` implementation
<!-- @e2e exclude static repo-shape assertion — verified by grep in the task acceptance criteria and the register-import validation, not a DOM behaviour -->

#### Scenario: An unknown leaf id fails the import loudly
- GIVEN a schema declaring a `linkedTypes` value that no registered provider serves
- WHEN the Scholiq register is imported into OpenRegister
- THEN `Schema::validateLinkedTypesValue()` rejects it with the list of valid ids
- AND the import fails rather than silently dropping the leaf
<!-- @e2e exclude backend import validation — covered by OpenRegister's own suite; Scholiq only supplies valid ids -->

### Requirement: Calendar leaves on Session, Cohort, Assignment, and Credential (REQ-002)
The schemas `Session`, `Cohort`, `Assignment`, and `Credential` MUST declare `calendar` in `linkedTypes`, and the detail pages SessionDetail, CohortDetail, AssignmentDetail, and CredentialDetail MUST each carry one calendar integration widget. The leaf links user-curated CalDAV events (room changes, excursions, deadline checkpoints, renewal planning) to the object; it MUST NOT auto-create events from object properties (`Session.startsAt`/`endsAt`, `Assignment.dueAt`, `Credential.expiresAt` remain authoritative in their own fields, and derivation is out of scope for this change). No catalog-definition schema (`Course`, `Programme`, `CurriculumPlan`, `CourseTemplate`, `Regulation`) may declare `calendar`.

#### Scenario: A teacher links a renewal event to an expiring credential
- GIVEN a Credential with an `expiresAt` in three months
- WHEN a user with read access opens CredentialDetail
- THEN a calendar leaf widget is shown and lets the user link a CalDAV event to the credential
- AND the linked event appears in the widget on the next visit
<!-- @e2e tests/e2e/spec-coverage/integration-leaves.spec.ts -->

#### Scenario: Courses carry no calendar leaf
- GIVEN the imported register and the built manifest
- WHEN the `Course` schema and the CourseDetail page are inspected
- THEN `Course.linkedTypes` contains no `calendar` entry and CourseDetail has no calendar widget
<!-- @e2e exclude static register/manifest-shape assertion (absence), covered by the task acceptance criteria grep, not a positive DOM behaviour -->

### Requirement: Contacts leaves on LearnerProfile and Praktijkopleider link, never copy (REQ-003)
The schemas `LearnerProfile` and `Praktijkopleider` MUST declare `contacts` in `linkedTypes`, with one contacts widget each on LearnerProfileDetail and PraktijkopleiderDetail. The leaf MUST only link an NC Contacts card to the object; no register property may be written from a contact card and no contact-card field may be copied into the register by the leaf. The leaf MUST render only after the OpenRegister RBAC read of the parent object has succeeded, so no user gains sight of a learner or trainer record through the leaf that they could not already open.

#### Scenario: A BPV coordinator links the practical trainer's contact card
- GIVEN a Praktijkopleider object for a training company
- WHEN the coordinator opens PraktijkopleiderDetail
- THEN the contacts leaf lets them link an NC Contacts card
- AND the register object's own properties are unchanged by the linking act
<!-- @e2e tests/e2e/spec-coverage/integration-leaves.spec.ts -->

#### Scenario: No leaf renders on an object the caller may not read
- GIVEN a user without read access to a LearnerProfile object
- WHEN that user attempts to open the object's detail page
- THEN the object read is denied by OpenRegister RBAC before any leaf resolves
- AND the contacts leaf discloses nothing about the object
<!-- @e2e exclude negative-access path — RBAC denial happens at the object read, upstream of any leaf; covered by OpenRegister RBAC tests and Scholiq's existing access e2e, not reproducible as a leaf-specific DOM assertion -->

### Requirement: Forms leaves on Assignment and Cohort for structured intake (REQ-004)
The schemas `Assignment` and `Cohort` MUST declare `forms` in `linkedTypes`, with one forms widget on AssignmentDetail (structured submission intake alongside the existing `asn-files` file-drop) and one on CohortDetail (class-level excuse-request intake). The leaf links NC Forms; submitted answers stay in the Forms app. The leaf MUST NOT be presented as a grading surface: a form linked to an Assignment collects submissions or declarations, and grading remains exclusively the guarded grade-entry flow (`AssessmentGradeGuard`).

#### Scenario: An assignment gains a structured intake form
- GIVEN an Assignment whose teacher has created an NC Form
- WHEN the teacher opens AssignmentDetail
- THEN the forms leaf lets them link the form next to the existing briefing-materials file widget
- AND learners with read access to the assignment see the linked form in the widget
<!-- @e2e tests/e2e/spec-coverage/integration-leaves.spec.ts -->

### Requirement: Deck leaf on BpvPlacement for follow-up work (REQ-005)
The schema `BpvPlacement` MUST declare `deck` in `linkedTypes`, with one deck widget on BpvPlacementDetail, linking Deck cards for placement follow-ups (visit planning, contract chase, company feedback). Card content and card access control are Deck's; the leaf MUST NOT mirror card state into register properties, and the placement's own lifecycle remains governed by `BpvConfirmationGuard` regardless of any linked card's state.

#### Scenario: A school coach tracks a placement chase as a card
- GIVEN a BpvPlacement in period
- WHEN the school coach opens BpvPlacementDetail
- THEN the deck leaf lets them link a Deck card for the pending company visit
- AND completing the card does not change the placement's lifecycle
<!-- @e2e tests/e2e/spec-coverage/integration-leaves.spec.ts -->

### Requirement: Polls leaves exist only on delivery-run archetypes and are not assessments (REQ-006)
The schemas `Session` and `Cohort` MUST declare `polls` in `linkedTypes`, with one polls widget each on SessionDetail and CohortDetail whose title carries the suffix "(not graded)". No schema in the assessment family (`Assessment`, `Assignment`, `Item`, `ItemBank`, `Submission`, `GradeEntry`, or any schema whose objects feed grading) may ever declare `polls`; adding one is a spec violation requiring a change to this requirement, not a judgment call. Poll answers stay in the Polls app and MUST NOT be written to any Scholiq schema.

#### Scenario: A quick poll on a session is visibly not a graded artefact
- GIVEN a Session with a linked poll
- WHEN a learner opens SessionDetail
- THEN the polls widget renders with a "(not graded)" title
- AND no grade, submission, or assessment object is created by voting
<!-- @e2e tests/e2e/spec-coverage/integration-leaves.spec.ts -->

#### Scenario: The assessment family derives no polls surface
- GIVEN the imported register
- WHEN the `Assessment`, `Assignment`, `Item`, and `ItemBank` schemas are inspected
- THEN none of them carries a `polls` entry in `linkedTypes`
<!-- @e2e exclude static register-shape assertion (absence) — covered by the task acceptance criteria grep -->
