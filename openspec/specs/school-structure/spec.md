---
slug: school-structure
title: School Structure — Programmes, Curriculum Plans, Cohorts, Sessions
status: done
feature_tier: must
depends_on_adrs: [ADR-022, ADR-024, ADR-031]
created: 2026-05-12
updated: 2026-05-12
profiles: [pta-vo, oer-he, opleidingsplan-mbo, training-curriculum]
---

# School Structure

@e2e exclude Pure backend/data-model spec. All requirements define OpenRegister schema shapes, recursive Course schema, and material attachment config — no `#### Scenario:` headings exist in this spec.

## Purpose

Every educational institution — a school, a university faculty, or a corporate training department — runs the same backbone: a **programme** (a degree / diploma / certification track) is described by a **governing plan** (which courses are required, what the assessment components are, how component grades roll up to a final grade, what the period structure is), learners take courses inside **cohorts** (a klas, a werkgroep, a training group), and a cohort meets in scheduled **sessions** (a les, a hoorcollege, a workshop) that carry materials and assignments. Scholiq's built register has `Course` + `Lesson` but no programme, no governing plan, no cohort, no session — so it can hold *content* but cannot model how a real institution *runs*. This spec adds that backbone in a jurisdiction-neutral way: the Dutch **PTA** (Programma van Toetsing en Afsluiting) is one profile of `CurriculumPlan`; an HE **OER/studiegids**, an MBO **opleidingsplan**, and a corporate **training curriculum** are others.

## What

- **Programme** — a named track (`HBO-V bachelor`, `NIS2 board certification`, `vmbo-tl examenjaar`) that aggregates courses, declares a credential to issue on completion, and points at one `CurriculumPlan`.
- **CurriculumPlan** — the governing document: ordered list of required + elective courses, the assessment-component definitions (with per-component weights, periods, and a roll-up formula), the grading scale, and pass/fail rules. The Dutch PTA's "kolommen met weegfactor per periode feeding the SE-gemiddelde" is exactly this; so is an HE module's "deeltoetsen → eindcijfer" weighting. Consumed by the `grading` spec to compute `FinalGrade`.
- **Course** (extends the existing schema) — collapse the rigid `Course → Module → Lesson` 3-level hierarchy into a **recursive Course** (a Course may contain sub-Courses *or* Lessons directly; a "module" is just a Course used as a container). Add `Course.curriculumPlanId` and `Course.programmeIds`.
- **Cohort** — a group of learners doing a Course/Programme together in a given period, with one or more teachers/instructors. Members are `LearnerProfile`s; backed by an NC group for permissioning.
- **Session** — a scheduled occurrence: cohort + course + start/end datetime + location (room / online URL) + attached `Material`s + linked `Assignment`s. The unit a teacher takes attendance against.
- **Material** — metadata for a file/presentation/reading/video/SCORM-cmi5 package attached to a Course, Lesson, or Session. The bytes live in OpenRegister's native file attachments; this schema carries type, license, LOM-style tags, and ordering.

## User Stories

- As a programme coordinator, I want to define a Programme with its CurriculumPlan once, so that every cohort that runs it inherits the same required-course list and grade-weighting rules.
- As a teacher, I want to attach this week's slides, reading list, and the homework brief to a Session, so learners see everything for that class in one place.
- As an administrator, I want to clone a published Course as a draft for next year without breaking the live one or carrying over enrolments.
- As a learner, I want my cohort's schedule (sessions with times, rooms, and materials) on one page.
- As a Dutch VO exam secretary, I want to express the PTA — kolommen, weegfactoren, periods, SE-formula — as a CurriculumPlan, so grading and the SE-gemiddelde are computed automatically.

## Acceptance Criteria

- GIVEN a Programme with a CurriculumPlan listing 3 required courses, WHEN a Cohort is created for that Programme, THEN the cohort's required-course set equals the plan's and the grade-weighting formula is the plan's.
- GIVEN a teacher opens a Session, WHEN they attach a Material, THEN it appears in every cohort member's view of that session with the declared title, type, and order.
- GIVEN an administrator clicks "Clone for next year" on a published Course, THEN a draft copy is created with a new academic-year tag, the same Lesson tree, and zero enrolments.
- GIVEN a PTA expressed as a CurriculumPlan with a kolom of weegfactor 3, WHEN the `grading` spec computes the period average, THEN that kolom contributes 3× (see `grading`).

## Requirements

### Requirement: Persist school-structure domain objects in OpenRegister

The system MUST persist `Programme`, `CurriculumPlan`, `Cohort`, `Session`, `Material`, and `Room` as
OpenRegister objects with `x-openregister-lifecycle` (draft → published → archived for Programme/Course;
scheduled → in-progress → completed | cancelled for Session, plus the guarded `substitute-teacher` self-loop
transition described below) and `x-openregister-relations` (Cohort↔Programme/Course, Session↔Cohort/Course/
Room, Material↔Course/Lesson/Session). `Room` carries no workflow lifecycle of its own (see "Room is
persisted as a bookable resource").

#### Scenario: School-structure objects persisted in OpenRegister

- **GIVEN** the school-structure domain schemas are registered
- **WHEN** a coordinator creates a Programme, CurriculumPlan, Cohort, Session, Material, or Room
- **THEN** each is stored as an OpenRegister object carrying the declared lifecycle states and relations

### Requirement: Course schema is recursive with curriculumPlanId + programmeIds
The `Course` schema MUST be recursive (`parentCourseId` self-reference) and MUST carry `curriculumPlanId` + `programmeIds`.

#### Scenario: Course nests sub-courses and references plan and programmes
- **GIVEN** the `Course` schema
- **WHEN** a Course is created as a container with sub-Courses and linked to a plan and programmes
- **THEN** it stores the `parentCourseId` self-reference plus `curriculumPlanId` and `programmeIds`

### Requirement: CurriculumPlan carries component list and roll-up formula
The `CurriculumPlan` MUST carry a structured component list `{ componentId, label, weight, period, kind: assignment|assessment|participation }[]` and a roll-up `formula` (named: `weighted-average` | `last-attempt` | `best-of-n` | `all-must-pass`).

#### Scenario: CurriculumPlan declares weighted components and formula
- **GIVEN** a `CurriculumPlan` expressing a PTA
- **WHEN** a kolom is defined with a weegfactor and a named roll-up formula
- **THEN** the plan carries the structured component list (with weight, period, kind) and the named `formula` that the `grading` spec consumes

### Requirement: Materials reference OpenRegister file attachments
Materials MUST reference OpenRegister file attachments; this app MUST NOT store file bytes itself.

#### Scenario: Material points at an OpenRegister file attachment
- **GIVEN** a teacher attaches a file to a Session
- **WHEN** the Material is saved
- **THEN** it references the OpenRegister file attachment and this app stores no file bytes of its own

### Requirement: Frontend is declarative with cohort-timetable exception
Frontend MUST be declarative: `src/manifest.json` pages for Programme/CurriculumPlan/Cohort/Session index+detail; a custom Vue view only for the cohort timetable if a manifest page can't render it. No PHP CRUD controllers.

#### Scenario: Pages are manifest-declared with timetable exception
- **GIVEN** the school-structure frontend is configured
- **WHEN** the app renders Programme, CurriculumPlan, Cohort, and Session screens
- **THEN** index/detail pages come from `src/manifest.json` and the only custom Vue view is the cohort timetable (when a manifest page cannot render it), with no PHP CRUD controllers

### Requirement: Programme declares its required competencies for the skills-gap view

The `Programme` object MUST support a `requiredCompetencyIds` field (array of `format: uuid` `$ref:
Competency`, default `[]`) declaring which competencies a learner must attain to complete this programme —
the "required by Programme" half of the `competency` capability's skills-gap view (the other half,
"required by role," is declared on `Competency.requiredForRoles` in the `competency` capability itself).
The field MUST be additive and MUST NOT be required; it does not change `Programme`'s existing
`courseIds`/`curriculumPlanId`/`credentialTemplateId` relations or its `publish`/`archive`/`unarchive`
lifecycle.

#### Scenario: A programme declares its required competencies

<!-- @e2e exclude Pure OpenRegister schema field; consumed by the competency capability's SkillsGapDashboard.vue, whose own scenario and @e2e reference live in specs/competency/spec.md. -->

- **GIVEN** a `Programme` being authored
- **WHEN** the coordinator sets `requiredCompetencyIds` to the set of competencies the programme's
  qualification requires
- **THEN** the values persist on the `Programme` object

#### Scenario: An existing programme without declared required competencies is unaffected

<!-- @e2e exclude Additive-field default-value handling; no DOM surface. -->

- **GIVEN** a pre-existing `Programme` row with no `requiredCompetencyIds` set
- **WHEN** it is read
- **THEN** `requiredCompetencyIds` resolves to an empty array, and the skills-gap view treats that
  programme as declaring no Programme-required competencies (role-required competencies via
  `Competency.requiredForRoles` are unaffected)

### Requirement: Cohort and Session expose a Nextcloud Talk conversation via linkedTypes

The `Cohort` schema MUST declare `linkedTypes: ["talk"]` and the `Session` schema MUST declare
`linkedTypes: ["talk"]`, consuming OpenRegister's existing Talk integration
(`TalkLinksController` + `TalkLinkService`, `Talk\Provider` id `talk`) rather than Scholiq building its
own Talk client or storing a conversation token on either object — the room↔object link is persisted in
OpenRegister's own link table, keyed by the Cohort's or Session's object uuid. `Course`, `Programme`, and
`CurriculumPlan` MUST NOT declare `linkedTypes` — they remain catalog/definition objects with no comms
leaf. `src/manifest.json` MUST render the resulting `integration`/`talk` widget on the Cohort detail page
(the persistent class space) and the Session detail page (the per-occurrence join-call action), visible to
teachers/coordinators and, per each object's existing RBAC, enrolled learners.

#### Scenario: Coordinator links a Talk conversation to a Cohort as its persistent class space

<!-- @e2e tests/e2e/spec-coverage/talk-classroom-spaces.spec.ts -->

- **GIVEN** an active Cohort with no Talk conversation linked yet
- **WHEN** a coordinator opens the Cohort detail page and creates-and-links a new Talk conversation via the
  talk widget
- **THEN** the conversation is stored as a link against the Cohort's object id
- **AND** the Cohort detail page shows the linked conversation as the cohort's class space

#### Scenario: Teacher links a Session's call to the parent Cohort's existing conversation

<!-- @e2e tests/e2e/spec-coverage/talk-classroom-spaces.spec.ts -->

- **GIVEN** a Session whose Cohort already has a linked Talk conversation, and the Session has none linked
  yet
- **WHEN** the teacher opens the Session detail page and links the Cohort's existing conversation via the
  talk widget's room picker
- **THEN** the Session detail page shows a join-call action for that conversation

#### Scenario: An enrolled learner sees and can use the join-call action on a Session

<!-- @e2e tests/e2e/spec-coverage/talk-classroom-spaces.spec.ts -->

- **GIVEN** a Session with a linked Talk conversation, and a learner enrolled (active) in its Cohort
- **WHEN** the learner opens the Session detail page
- **THEN** the join-call action for the linked conversation is visible and usable to them

#### Scenario: Talk not installed or not enabled degrades gracefully

<!-- @e2e exclude Requires an NC instance with the spreed app disabled; not reproducible in the standard Playwright environment. Covered by PHPUnit against OpenRegister's TalkLinkService::isTalkAvailable() contract and the existing CnTalkCard 'degraded' surface, both pre-existing platform behaviour this change consumes unchanged. -->

- **GIVEN** Nextcloud Talk (`spreed`) is not installed or not enabled for the current user
- **WHEN** a coordinator or teacher opens the Cohort or Session detail page
- **THEN** the talk widget renders a degraded/unavailable state instead of erroring
- **AND** every other widget on the page renders normally

#### Scenario: A Session without a linked conversation shows no dead action

<!-- @e2e tests/e2e/spec-coverage/talk-classroom-spaces.spec.ts -->

- **GIVEN** a Session with no Talk conversation linked
- **WHEN** anyone with access opens the Session detail page
- **THEN** no join-call action is shown

### Requirement: Enrolled learners sync as Talk room participants on Cohort membership changes

When an `Enrolment` with `cohortId` set transitions to `active`, the system MUST add
`Enrolment.learnerId` as a participant of every Talk conversation currently linked to that Cohort, if any.
When such an `Enrolment` transitions to `withdrawn`, the system MUST remove that learner from every Talk
conversation currently linked to that Cohort. The sync MUST fail soft (no-op, logged) — never raise an
error to the caller — when Talk is unavailable or the Cohort has no conversation linked at the time of the
transition; learners whose `Enrolment` was already `active` before a conversation was later linked are NOT
retroactively added (documented limitation — the coordinator adds the initial batch through Talk's own
participant UI after linking).

#### Scenario: Activating an enrolment adds the learner to the cohort's linked conversation

<!-- @e2e exclude Pure backend event-bridge (ObjectTransitionedEvent → Talk ParticipantService call); no scholiq DOM surface for the sync action itself — the resulting membership is a Talk-native surface, not a scholiq one. Verified by PHPUnit CohortTalkMembershipHandlerTest::testActivateAddsParticipant. -->

- **GIVEN** a Cohort with a linked Talk conversation
- **WHEN** a learner's `Enrolment` (`cohortId` = this Cohort) transitions from `pending` to `active`
- **THEN** the learner is added as a participant of the linked conversation

#### Scenario: Withdrawing an enrolment removes the learner from the cohort's linked conversation

<!-- @e2e exclude Pure backend event-bridge; verified by PHPUnit CohortTalkMembershipHandlerTest::testWithdrawRemovesParticipant. -->

- **GIVEN** a learner enrolled and synced as a participant of the Cohort's linked Talk conversation
- **WHEN** their `Enrolment` transitions to `withdrawn`
- **THEN** they are removed as a participant of the conversation

#### Scenario: No conversation linked yet is a no-op, not an error

<!-- @e2e exclude Pure backend fail-soft path; verified by PHPUnit CohortTalkMembershipHandlerTest::testActivateWithNoLinkedRoomIsNoop. -->

- **GIVEN** a Cohort with no Talk conversation linked
- **WHEN** a learner's `Enrolment` (`cohortId` = this Cohort) transitions to `active`
- **THEN** the system logs and continues without error
- **AND** no participant-sync call is attempted

#### Scenario: Talk unavailable is a no-op, not an error

<!-- @e2e exclude Requires Talk disabled at the instance level; verified by PHPUnit CohortTalkMembershipHandlerTest::testActivateWithTalkUnavailableIsNoop against TalkLinkService::isTalkAvailable() returning false. -->

- **GIVEN** Nextcloud Talk (`spreed`) is not installed or not enabled
- **WHEN** a learner's `Enrolment` transitions to `active` or `withdrawn` for a Cohort with a stale linked
  conversation record
- **THEN** the system logs and continues without error
- **AND** no participant-sync call is attempted

### Requirement: CurriculumPlan declares elective-selection validation rules

`CurriculumPlan` MUST carry an additive, nullable `electiveRules` object: `minElectives`/`maxElectives`
(nullable integers), `mandatoryCombinations` (array of course-id sets that must be chosen together),
`mutuallyExclusive` (array of course-id sets that cannot be chosen together), and `capacityByCourseId`
(nullable map of `courseId` → maximum seats; absence means uncapped). Every existing `CurriculumPlan` row
MUST remain valid with `electiveRules` unset.

#### Scenario: A PTA declares a profiel's mandatory vak combination and a capacity limit

<!-- @e2e exclude Pure OpenRegister schema addition; verified by reasoning over the register JSON and by PHPUnit schema-validation tests — no scholiq DOM surface for the field's presence itself. -->

- **GIVEN** a `CurriculumPlan` expressing a VO profiel
- **WHEN** the plan declares `mandatoryCombinations` naming two courses that must be chosen together and a
  `capacityByCourseId` limit for one of its electives
- **THEN** the plan persists `electiveRules` with that shape
- **AND** an existing `CurriculumPlan` row with no `electiveRules` set remains valid

### Requirement: Persist SubjectChoice domain objects in OpenRegister

The system MUST persist `SubjectChoice` as an OpenRegister object with `x-openregister-lifecycle`
(`draft → submitted → validated | needs-revision → approved → locked`; `needs-revision → draft`). Every
UUID foreign key MUST use the property-level relation dialect already in use across the register.

#### Scenario: SubjectChoice persists with its declared lifecycle

<!-- @e2e exclude Pure OpenRegister schema/lifecycle registration; verified by reasoning over the register JSON and by PHPUnit schema-validation tests. -->

- **GIVEN** the `SubjectChoice` schema is registered
- **WHEN** a `SubjectChoice` is created
- **THEN** it is stored as an OpenRegister object carrying its declared lifecycle state

### Requirement: Guardian consent gates a minor's subject-choice submission

`SubjectChoice`'s `submit` transition (`draft → submitted`) MUST be gated by `SubjectChoiceConsentGuard`,
which resolves the caller's Nextcloud user id server-side and passes only when the caller is listed in the
target learner's `LearnerProfile.parentIds`, or the caller **is** the target learner — the identical rule
`ConferenceSignupGuardianGuard` already enforces for conference sign-ups, reapplied here rather than
reimplemented.

#### Scenario: A linked guardian can submit a subject choice for their own child

<!-- @e2e exclude Lifecycle-transition guard is backend logic verified by PHPUnit SubjectChoiceConsentGuardTest::testLinkedGuardianCanSubmit, mirroring ConferenceSignupGuardianGuardTest. -->

- **GIVEN** a guardian whose Nextcloud user id is in `LearnerProfile.parentIds` for learner L
- **WHEN** they submit a `SubjectChoice` naming learner L's selected electives
- **THEN** the `submit` transition succeeds

#### Scenario: An unrelated user cannot submit a subject choice for someone else's child

<!-- @e2e exclude PHPUnit SubjectChoiceConsentGuardTest::testUnrelatedUserCannotSubmit. -->

- **GIVEN** an authenticated user whose Nextcloud user id is NOT in `LearnerProfile.parentIds` for learner
  L and who is not learner L
- **WHEN** they attempt to submit a `SubjectChoice` naming learner L
- **THEN** the `submit` transition is blocked
- **AND** the `SubjectChoice` remains `draft`

### Requirement: A submitted subject choice is validated against the plan's elective rules, not persisted unchecked

On a `SubjectChoice` reaching `submitted`, `SubjectChoiceValidator` MUST check
`selectedElectiveCourseIds` against the referenced `CurriculumPlan.electiveRules`
(`minElectives`/`maxElectives`, `mandatoryCombinations`, `mutuallyExclusive`) and against the current
`capacityByCourseId` occupancy (counting sibling `SubjectChoice` rows in `approved`/`locked` state for the
same `curriculumPlanId`), then write the object to `validated` on success or `needs-revision` with a
populated `validationErrors[]` naming each unmet rule on failure.

#### Scenario: A choice satisfying every rule validates

<!-- @e2e exclude Cross-object validation is backend logic verified by PHPUnit SubjectChoiceValidatorTest::testValidChoiceMovesToValidated. -->

- **GIVEN** a `CurriculumPlan` with `minElectives: 2`, `maxElectives: 2`, and no mandatory/exclusive
  combinations involving the chosen courses
- **AND** a `submitted` `SubjectChoice` selecting exactly those 2 electives, each under its
  `capacityByCourseId` limit
- **WHEN** `SubjectChoiceValidator` runs
- **THEN** the `SubjectChoice` transitions to `validated`

#### Scenario: A choice violating a mandatory combination is sent back for revision

<!-- @e2e exclude PHPUnit SubjectChoiceValidatorTest::testMandatoryCombinationViolationMovesToNeedsRevision. -->

- **GIVEN** a `CurriculumPlan` with a `mandatoryCombinations` entry requiring courses X and Y together
- **AND** a `submitted` `SubjectChoice` selecting X but not Y
- **WHEN** `SubjectChoiceValidator` runs
- **THEN** the `SubjectChoice` transitions to `needs-revision`
- **AND** `validationErrors` names the unmet mandatory combination

#### Scenario: A choice exceeding a course's capacity is sent back for revision

<!-- @e2e exclude PHPUnit SubjectChoiceValidatorTest::testCapacityExceededMovesToNeedsRevision. -->

- **GIVEN** a `CurriculumPlan` course with `capacityByCourseId` limit 1 already filled by another
  `locked` `SubjectChoice`
- **AND** a `submitted` `SubjectChoice` also selecting that course
- **WHEN** `SubjectChoiceValidator` runs
- **THEN** the `SubjectChoice` transitions to `needs-revision`
- **AND** `validationErrors` names the capacity conflict

### Requirement: An approved subject choice feeds Enrolment

When a `SubjectChoice` transitions `approved → locked`, `SubjectChoiceEnrolmentBridge` MUST create or update
an `Enrolment` (`source: "subject-choice"`) for each course in `selectedElectiveCourseIds` that the learner
is not already enrolled in.

#### Scenario: Locking a subject choice enrols the learner in the chosen electives

<!-- @e2e exclude Cross-object write bridge is backend logic verified by PHPUnit SubjectChoiceEnrolmentBridgeTest::testLockCreatesEnrolments. -->

- **GIVEN** an `approved` `SubjectChoice` selecting two elective courses the learner is not yet enrolled in
- **WHEN** it transitions to `locked`
- **THEN** two `Enrolment` objects are created with `source: "subject-choice"`, one per selected course

### Requirement: Frontend is declarative with one named subject-choice-picker exception

The frontend MUST be declarative: `src/manifest.json` index/detail pages for `SubjectChoice`. The only
custom Vue component for subject choice MUST be `SubjectChoicePicker.vue` — an interactive elective picker
showing live rule and capacity feedback from the referenced `CurriculumPlan.electiveRules`, which a generic
manifest form cannot render. No PHP CRUD controllers.

#### Scenario: A learner picks electives with live rule feedback

<!-- @e2e tests/e2e/spec-coverage/admissions-and-subject-choice.spec.ts -->
<!-- Declarative page rendering + the one custom-view exception is the drivable DOM scenario, mirroring BookConferenceSlotsView's e2e coverage pattern; the underlying validation logic has no DOM surface and is covered by the PHPUnit tests referenced on the preceding scenarios. -->

- **GIVEN** a `CurriculumPlan` with declared `electiveRules`
- **WHEN** a learner opens `SubjectChoicePicker.vue` for that plan and selects electives
- **THEN** the picker shows live feedback against `minElectives`/`maxElectives`, mandatory combinations, and
  remaining capacity before submission
- **AND** every other subject-choice screen (list/detail) is a declarative `src/manifest.json` page

### Requirement: Room is persisted as a bookable resource

The system MUST persist `Room` as an OpenRegister object: `name`/`code`, `capacity` (integer), `kind`
(`classroom|lab|gym|auditorium|online|other`), `facilities` (array of strings), optional `buildingCode`/
`floor`, `tenant_id`. `Room` MUST NOT carry an `x-openregister-lifecycle` workflow beyond the standard active
toggle — it is a resource-metadata object, the same shape as `Material`, not a Session-like state machine.

#### Scenario: A coordinator creates a Room with capacity and facilities

- **GIVEN** a coordinator creating a bookable resource
- **WHEN** they set `name`, `capacity`, `kind`, and `facilities`
- **THEN** a `Room` object is persisted with those values and is available for a `Session` to reference

### Requirement: Session references a Room and carries substitution and import metadata

`Session` MUST gain the following additive fields, all nullable/optional so existing rows continue to
validate unchanged: `roomId` (`$ref Room`) — the canonical structured room reference, kept alongside the
existing free-text `location` (which remains the display label for an online-meeting URL or for a Session
with no `Room` row); `externalRef` (string) — an idempotency key used only by timetable import to upsert
without duplicating; `substituteTeacherId` (Nextcloud user id); `changeReasonKind` (enum:
`teacher-absence|room-unavailable|timetable-change|other`); `changeReason` (free text); `affectedLearnerIds`
/ `affectedParentIds` (materialised Nextcloud-user-id arrays, computed and persisted at the `cancel`/
`substitute-teacher` transitions, mirroring `ConferenceRound.invitedLearnerIds`). `Session.x-openregister-
lifecycle.transitions` MUST gain a `substitute-teacher` self-loop (`from: [scheduled, in-progress], to:` the
same state) alongside the existing `cancel` transition; both `cancel` and `substitute-teacher` MUST declare
`requires: SessionChangeGuard`.

#### Scenario: A Session references a real Room instead of only a free-text location

- **GIVEN** a `Room` object with a declared `capacity`
- **WHEN** a coordinator sets a Session's `roomId` to that Room
- **THEN** the Session's structured room reference resolves to the Room's real capacity and facilities
- **AND** the Session's existing `location` field is left as-is (unchanged, still usable for an online URL
  or display label)

#### Scenario: An imported Session carries an externalRef without being user-visible as such

<!-- @e2e exclude Field-shape/idempotency-key invariant only; behaviour is covered by the timetabling capability's import scenarios. -->

- **GIVEN** a `Session` created via a `timetable-import` `DataExchangeJob`
- **WHEN** the Session is persisted
- **THEN** its `externalRef` is set to the source system's occurrence id
- **AND** the field is used only for import matching, never rendered as a primary user-facing value

#### Scenario: An existing Session without any new field set continues to validate

- **GIVEN** a pre-existing `Session` row with `roomId`, `externalRef`, `substituteTeacherId`,
  `changeReasonKind`, `changeReason`, `affectedLearnerIds`, and `affectedParentIds` all unset
- **WHEN** the row is read or re-saved unchanged
- **THEN** it validates without error — none of the new fields are required

### Requirement: Cohort declares a kind distinguishing standing care/plusklas subgroups from teaching cohorts

The `Cohort` schema MUST declare a `kind` property (enum: `teaching`, `care`, `plusklas`; default
`teaching`) so a standing, cross-period care or plusklas subgroup (finding 1.14 — ParnasSys "sublesgroep",
ESIS "instructiegroepen") can be represented without requiring a `GroupPlan`, which `GroupPlanSubgroup`
does (`groupPlanId` is required, so a `GroupPlanSubgroup` cannot outlive its plan).

`kind` MUST be additive: existing `Cohort` rows are valid without it (default `teaching` applies), and no
existing `Cohort` consumer (enrolment, attendance, rollover) is required to branch on it.

#### Scenario: A coordinator creates a standing plusklas group

<!-- @e2e exclude a single additive enum property on an already-manifest-declarative schema (Cohort);
     asserted by the register's JSON Schema shape and existing Cohort CRUD e2e coverage, not a new browser
     scenario -->

- **GIVEN** the `Cohort` schema declares `kind`
- **WHEN** a coordinator creates a `Cohort` with `kind: "plusklas"` and no `programmeId`/`courseId`
- **THEN** the cohort is created and persists across academic periods like any other `Cohort`, unlike a
  `GroupPlanSubgroup`, which requires and is scoped to one `GroupPlan`

#### Scenario: An existing Cohort without a declared kind defaults to teaching

- **GIVEN** a `Cohort` row created before this change, with no `kind` value stored
- **WHEN** the row is read
- **THEN** `kind` resolves to its default, `"teaching"`

### Requirement: School and Location are persisted as OpenRegister records
The system MUST persist `School` (BRIN, name, pedagogical concept) and `Location` (vestigingscode, onderwijslocatiecode, address, `schoolId` reference) as OpenRegister objects, each a plain resource-metadata schema with no lifecycle. The `Location` schema's internal key/slug is `Vestiging` (`location` is already claimed by `shillinq` on the shared OpenRegister; see design.md Decision 4); this requirement uses "Location" throughout for the user-facing concept, matching the page title and menu label. — the same shape as `Room` (see "Room is persisted as a bookable resource"). No bestuur/board entity is introduced this round (decision D2); `School` is the top-level record.

#### Scenario: A school and its locations are recorded
- **GIVEN** the `School` and `Location` schemas are registered
- **WHEN** an administrator creates a `School` with a BRIN and a `Location` referencing it via `schoolId`
- **THEN** both persist as OpenRegister objects and the `Location` resolves back to its `School`

### Requirement: School declares a BRIN and a pedagogical concept
`School.brin` MUST be a pattern-validated DUO BRIN-nummer (4 characters: two digits followed by two alphanumeric characters). `School.pedagogicalConcept` MUST be one of `regular`, `montessori`, `dalton`, `jenaplan`, `freinet`, `vrijeschool`, `other`, defaulting to `regular`.

#### Scenario: A montessori school records its pedagogical concept
- **GIVEN** a `School` being created for a montessori primary school
- **WHEN** `pedagogicalConcept` is set to `montessori`
- **THEN** the value persists and later school-year/reporting changes can read it to select the montessori-shaped reporting profile

#### Scenario: An out-of-pattern BRIN is rejected
- **GIVEN** a `School` being created
- **WHEN** `brin` is submitted as a value that does not match the two-digit-plus-two-alphanumeric pattern
- **THEN** OpenRegister's schema validation rejects the write

### Requirement: Location declares vestigingscode and an independent onderwijslocatiecode
`Location.vestigingscode` MUST be a required string identifying the DUO/RIO vestiging. `Location.onderwijslocatiecode` MUST be an independent, nullable string — a vestiging MAY have more than one onderwijslocatie (`legal-po-2026-09-25.md`: "BRIN, vestigingscode, onderwijslocatie" are three distinct codes, not one).

#### Scenario: A vestiging with a separate onderwijslocatie is recorded
- **GIVEN** a `Location` with `vestigingscode` "02VG00"
- **WHEN** `onderwijslocatiecode` is set to a different RIO onderwijslocatie code for a satellite building
- **THEN** both codes persist independently on the same `Location` object

#### Scenario: A location without a separate onderwijslocatie is unaffected
- **GIVEN** a `Location` with no `onderwijslocatiecode` set
- **WHEN** it is read
- **THEN** `onderwijslocatiecode` resolves to `null` and the location is otherwise complete with just its `vestigingscode`

### Requirement: Cohort names the one Location it runs at
`Cohort.locationId` MUST be an additive, nullable `$ref Location` field. This is the enforcement point for the DUO rule that a groep belongs to exactly one location (`legal-po-2026-09-25.md`: "one groep per location"): a `Cohort` object has at most one `locationId`, never an array.

#### Scenario: A groep is assigned to its location
- **GIVEN** a `Cohort` representing a PO groep
- **WHEN** `locationId` is set to a `Location` object's UUID
- **THEN** the cohort resolves to exactly that one location, never more than one

#### Scenario: A pre-existing Cohort without a location is unaffected
- **GIVEN** a pre-existing `Cohort` row with no `locationId` set
- **WHEN** it is read
- **THEN** `locationId` resolves to `null` and the cohort's existing `programmeId`/`courseId`/`teacherIds`/`learnerIds` fields and lifecycle are unchanged

### Requirement: Frontend is declarative for School and Location
`School` and `Location` MUST render as manifest-declared index+detail page pairs under the existing People domain (`src/manifest.d/people.json`), matching the Enrolment/Credential convention: a data widget, a related widget, and an audit-history sidebar tab. No custom Vue view and no PHP CRUD controller.

#### Scenario: A coordinator opens a School's detail page
- **GIVEN** the School/Location pages are configured
- **WHEN** a coordinator navigates to People → Schools and opens a School
- **THEN** the detail page renders from the manifest (data + related widgets, audit tab), with no bespoke Vue component

### Requirement: Cohort carries free-text notes
`Cohort` MUST declare `notes` (nullable string) additively.

#### Scenario: A coordinator adds a note to a group
- **GIVEN** a `Cohort`
- **WHEN** `notes` is set to a free-text observation
- **THEN** the value persists on the `Cohort` object

#### Scenario: A pre-existing Cohort without notes is unaffected
- **GIVEN** a pre-existing `Cohort` row with no `notes` set
- **WHEN** it is read
- **THEN** `notes` resolves to `null`

### Requirement: CohortDetail surfaces notes and a today-scoped session view
`CohortDetail` MUST render a widget showing `Cohort.notes`, and a widget listing this cohort's `Session`s filtered to the current day using the manifest's `@today` filter-token grammar.

#### Scenario: A coordinator reads and edits the group's notes from the group page
- **GIVEN** `CohortDetail` for a cohort with `notes` set
- **WHEN** the page renders
- **THEN** the notes widget shows the current value

#### Scenario: A coordinator sees only today's sessions for this cohort
- **GIVEN** `CohortDetail` for a cohort with sessions on multiple days
- **WHEN** the page renders
- **THEN** the today's-sessions widget lists only sessions whose `startsAt` falls within the current day

### Requirement: CohortDetail's header already names the cohort
`CohortDetail`'s header MUST show the cohort's own `name`, not the static page-type label. This is satisfied by the current `@conduction/nextcloud-vue` `CnDetailPage` component's `objectDisplayName`/`displayTitle` resolution (which prefers `obj.name` over the `title` prop) consuming `Cohort.name` (already a required property); no manifest change is needed.

#### Scenario: A coordinator opens a cohort and sees its name in the header
- **GIVEN** `CohortDetail` for a cohort named "Groep 5/6"
- **WHEN** the page renders and the object has loaded
- **THEN** the header reads "Groep 5/6", not the literal string "Cohort"

### Requirement: A class x subject teacher join exists distinct from Cohort.teacherIds
The system MUST persist `SubjectTeacherAssignment` (`cohortId`, `courseId`, `teacherId`) as an OpenRegister object, distinct from `Cohort.teacherIds`. `Cohort.teacherIds` names who teaches the cohort generally; `SubjectTeacherAssignment` names who teaches a specific subject (`Course`) within that cohort.

#### Scenario: A VO class has a different teacher per subject
- **GIVEN** a `Cohort` (a VO class) with two `Course`s, wiskunde and Engels
- **WHEN** two `SubjectTeacherAssignment` objects are created, each naming the same `cohortId` but a different `courseId` and `teacherId`
- **THEN** both persist independently, and neither depends on or duplicates `Cohort.teacherIds`

### Requirement: Cohort declares a duo-partner role and working days per teacher
`Cohort` MUST declare `teacherAssignments` additively: an array of `{ teacherId, role, days }`, where `role` is `primary` or `duo-partner` and `days` is an array of weekday values. `Cohort.teacherIds` MUST remain unchanged.

#### Scenario: A PO groep has a main teacher and a duo-partner on named days
- **GIVEN** a `Cohort` (a PO groep)
- **WHEN** `teacherAssignments` is set with one entry `role: primary` covering Monday-Wednesday and one entry `role: duo-partner` covering Thursday-Friday
- **THEN** both entries persist, and `Cohort.teacherIds` (if also set) is unaffected

#### Scenario: A pre-existing Cohort without teacherAssignments is unaffected
- **GIVEN** a pre-existing `Cohort` row with no `teacherAssignments` set
- **WHEN** it is read
- **THEN** `teacherAssignments` resolves to an empty array and `teacherIds` is unchanged

### Requirement: Staff is persisted as an OpenRegister record with roles, qualifications and working days
The system MUST persist `Staff` (`ncUserId`, `roles`, `qualifications`, `workingDays`) as an OpenRegister object, a plain resource-metadata schema with no lifecycle (same shape as `Room`).

#### Scenario: A staff member's roles, qualifications and working days are recorded
- **GIVEN** the `Staff` schema is registered
- **WHEN** a `Staff` object is created with `roles: ["teacher", "mentor"]`, one or more `qualifications`, and `workingDays`
- **THEN** all three persist on the `Staff` object

### Requirement: Frontend is declarative for Staff and SubjectTeacherAssignment
`Staff` and `SubjectTeacherAssignment` MUST render as manifest-declared index+detail page pairs (`Staff` under People in `src/manifest.d/people.json`; `SubjectTeacherAssignment` alongside Cohort in `src/manifest.d/learning.json`), and `CohortDetail` MUST surface an object-list widget of its `SubjectTeacherAssignment`s filtered by `cohortId`. No custom Vue view and no PHP CRUD controller.

#### Scenario: A coordinator sees which teacher covers which subject from the group page
- **GIVEN** `CohortDetail` for a VO class with two `SubjectTeacherAssignment`s
- **WHEN** the page renders
- **THEN** the subject-teacher roster widget lists both assignments, each linking to its own detail page

### Requirement: Staff roles name the counsellor and exam functions a school staffs
`Staff.roles` MUST accept, in addition to `teacher`, `mentor`, `coordinator`, `teaching-assistant`, `support-staff`, `administrator` and `other`, the values `career-counsellor` (decaan, loopbaanbegeleider), `study-adviser` (studieadviseur), `remedial-teacher`, `care-coordinator` (intern begeleider, zorgcoördinator), `exam-secretary` (examensecretaris), `placement-coordinator` (stagecoördinator) and `confidential-counsellor` (vertrouwenspersoon). The seven original values MUST keep their position at the start of the enum, so no stored value changes meaning.

#### Scenario: A school records its exam secretary and its decaan
- **GIVEN** the `Staff` schema is registered
- **WHEN** a `Staff` object is created with `roles: ["teacher", "career-counsellor", "exam-secretary"]`
- **THEN** the object validates and all three tags persist

#### Scenario: An existing Staff row stays valid
- **GIVEN** a `Staff` row stored before this change with `roles: ["teacher", "mentor"]`
- **WHEN** it is read and saved again
- **THEN** it validates unchanged

### Requirement: Every Staff role has a readable, translated label
`Staff.roles.items` MUST declare an `x-enum-labels` map with an English label for every enum value, and every label MUST have a key in `l10n/en.json` and a Dutch value in `l10n/nl.json`.

#### Scenario: The roles picker shows labels, not codes
- **GIVEN** a Dutch-language user opens the `Staff` form
- **WHEN** the roles field renders its options
- **THEN** it shows "Examensecretaris" for `exam-secretary` and "Vertrouwenspersoon" for `confidential-counsellor`

### Requirement: A Staff role tag grants no access
A `Staff.roles` value MUST be descriptive metadata only. No schema `authorization` block and no manifest `visibleIf` MAY name a `Staff.roles` value that is not also a declared security group or a `DashboardRoleService` role, and the `roles` property description MUST state that a tag grants no access.

#### Scenario: Tagging someone confidential counsellor does not open confidential notes
- **GIVEN** a `Staff` object tagged `confidential-counsellor` whose Nextcloud user is in no confidential group
- **WHEN** that user reads a schema whose `authorization.read` is limited to a confidential group
- **THEN** the tag has no effect on the result; only group membership decides

## Standards

Schema.org `EducationalOccupationalProgram`, `Course`, `CourseInstance`, `Syllabus`; NL LOM / VDEX for Material tags; ECTS / Bologna for HE workload; NL VO PTA convention as a `CurriculumPlan` profile; OOAPI 5.0 for HE catalog publication (deferred to a follow-up — out of scope here).

## Data Model

All in OpenRegister. New schemas: `Programme`, `CurriculumPlan`, `Cohort`, `Session`, `Material`. Modified: `Course` (recursive + curriculumPlanId + programmeIds), `Enrolment` (add optional `cohortId`). See `docs/ARCHITECTURE.md`.

## Out of Scope

- Room-booking conflict resolution and timetabling optimisation (a Session just records a location string).
- OOAPI 5.0 catalog publication endpoints (separate follow-up).
- The actual content runtime — cmi5/xAPI/SCORM execution is `course-management`'s job.
- Grading computation — that's the `grading` spec; this spec only *declares* the weighting in the CurriculumPlan.
