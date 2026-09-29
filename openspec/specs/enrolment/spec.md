---
slug: enrolment
title: Enrolment
status: done
feature_tier: must
depends_on_adrs: [adr-001, adr-003, adr-006]   # TODO until ADRs land
created: 2026-05-11
---

# Enrolment

@e2e exclude Pure backend/data-model spec. All requirements define OpenRegister schema shapes, prerequisite validation, and Studielink/Edukoppeling integration — no `#### Scenario:` headings exist in this spec.

## Purpose
Enrolment is the gateway from identity to learning record. For HE, Studielink integration is mandatory (insight #4); for corporate L&D, bulk-enrol of cohorts is the #1 line-manager workflow (5 high-priority stories). Without enrolment, every downstream capability — assessment, certification, compliance audit — has no subject.

## What
Manual and bulk enrolment of learners into courses, modules, and learning paths; cohort and group management (NL `klas`, HE `tutor group`, corporate `team`); eligibility/prerequisite checks; auto-enrol on hire (HR template) and on Studielink intake (HE); auto-enrol into certification renewal modules when expiry is detected; unenrolment with reason capture; immediate LMS account provisioning on enrolment.

## User Stories
- As an HE administrator, I want incoming Studielink enrolments to appear automatically in Scholiq so I do not rekey applicant data.
- As a student, I want an institutional account and LMS access immediately after enrolment so I can start orientation activities.
- As HR, I want to apply a 30-60-90 onboarding template when I create a new-hire account so modules are scheduled across days 1, 30, 60, and 90.
- As a line manager, I want to bulk-assign a course to my direct reports so all selected learners are enrolled with the same deadline and I see a team progress bar.
- As a compliance officer, I want to bulk-enrol every active employee in the annual refresher with deadlines T-30, T-7, T-1 days so coverage is automatic.

## Acceptance Criteria
- GIVEN a Studielink enrolment is received via the Edukoppeling adapter, WHEN it parses successfully, THEN a Learner + Enrolment object is created and an LMS account is provisioned within 60 seconds.
- GIVEN a line manager opens the team view, WHEN they multi-select reports and pick a course, THEN every selected learner is enrolled with a single shared deadline and notification.
- GIVEN HR creates a new hire, WHEN they pick the role, THEN the matching 30-60-90 template auto-applies and milestones populate Days 1/30/60/90.
- GIVEN a course has unmet prerequisites, WHEN a learner attempts enrolment, THEN the system blocks the enrolment and explains which prerequisite failed.

## Requirements

### Requirement: Bulk enrolment via cohort, role, department or CSV
The system MUST support bulk enrolment via cohort, role, department, or CSV upload.

#### Scenario: Bulk-enrol a selected group of learners
- **GIVEN** a manager with a cohort, role, department, or CSV of learners and a target course
- **WHEN** they trigger a bulk enrolment
- **THEN** the system enrols every selected learner into the course with a single shared deadline

### Requirement: Validate prerequisites before persistence

The system MUST validate prerequisites before enrolment is persisted. This MUST be implemented as an
OpenRegister `ObjectCreatingEvent` listener on the `enrolment` schema (`EnrolmentPrerequisiteListener`) —
NOT as an `x-openregister-lifecycle` transition `requires` guard — because a `requires` guard only resolves
on a transition between two already-persisted states, and `Enrolment` has no transition into its `pending`
initial state for a guard to attach to. For each UUID in the target `Course`'s `prerequisiteCourseIds`, the
listener MUST check whether the enrolling learner already holds an `Enrolment` with `lifecycle: completed`
for that prerequisite course. If any required prerequisite is unmet, the listener MUST call the event's
error-setting and propagation-stopping methods so OpenRegister aborts the create with a validation error
naming the specific failing prerequisite by course name — not a generic rejection. When `Course` has no
`prerequisiteCourseIds` (empty or absent), enrolment MUST proceed unaffected. A lookup failure caused by an
infrastructure error (not an unmet prerequisite) MUST NOT block the enrolment — the listener fails open on
infrastructure faults and fails closed only on an actually-unmet, successfully-checked prerequisite.

#### Scenario: Block enrolment when prerequisites are unmet

<!-- @e2e exclude Pure backend/data-model requirement — no scholiq DOM surface to drive an ObjectCreatingEvent rejection directly; covered by PHPUnit against EnrolmentPrerequisiteListener. -->

- **GIVEN** a course with prerequisites the learner has not met
- **WHEN** the learner attempts to enrol
- **THEN** the system blocks the enrolment before persistence and names the failing prerequisite

#### Scenario: Enrolment proceeds unaffected when a course has no prerequisites

<!-- @e2e exclude Pure backend/data-model requirement; covered by PHPUnit against EnrolmentPrerequisiteListener. -->

- **GIVEN** a course with an empty or absent `prerequisiteCourseIds`
- **WHEN** a learner attempts to enrol
- **THEN** the enrolment is created without any prerequisite check blocking it

#### Scenario: Enrolment succeeds once the prerequisite course is completed

<!-- @e2e exclude Pure backend/data-model requirement; covered by PHPUnit against EnrolmentPrerequisiteListener. -->

- **GIVEN** a course requiring a prerequisite course the learner holds a `completed` `Enrolment` for
- **WHEN** the learner attempts to enrol
- **THEN** the enrolment is created

#### Scenario: An infrastructure error during the prerequisite lookup does not block enrolment

<!-- @e2e exclude Pure backend/data-model requirement; covered by PHPUnit against EnrolmentPrerequisiteListener, simulating an ObjectService failure. -->

- **GIVEN** the prerequisite course lookup fails due to an infrastructure error, not an unmet prerequisite
- **WHEN** a learner attempts to enrol
- **THEN** the system allows the enrolment and logs the failure, rather than blocking on an unverifiable
  check

### Requirement: Provision LMS account within 60 seconds via Studielink
The system MUST provision an LMS account within 60 seconds of an HE enrolment via Studielink.

#### Scenario: Provision an account on Studielink intake
- **GIVEN** a Studielink enrolment received via the Edukoppeling adapter
- **WHEN** it parses successfully
- **THEN** the system creates the Learner and Enrolment objects and provisions an LMS account within 60 seconds

### Requirement: Enrolment carries a declared lesson-progress roll-up

The system MUST expose a declared, learner-scoped lesson-progress roll-up on `Enrolment` — `completedLesson
Count` and `totalPublishedLessonCount` as `x-openregister-aggregate-refs` (cross-schema counts against
`lesson-completion` and `lesson` respectively, scoped by `learnerId`/`courseId`), and `progressPercent` as a
plain, PHP-computed field written by `OCA\Scholiq\Progress\EnrolmentProgressEvaluator` via
`OCA\Scholiq\Listener\EnrolmentProgressRollupHandler` — reusing the same `x-openregister-aggregations` +
`engine`-keyed-PHP shape `FinalGrade.value` already uses, because no declarative division operator exists in
the calculation DSL. The roll-up MUST be recomputed whenever a `LessonCompletion` for the learner+course is
created or updated — not via a `TimedJob` (ADR-022).

#### Scenario: Progress percentage recomputes when a lesson is completed
<!-- @e2e exclude Calculation + trigger behaviour is backend/PHP logic verified by PHPUnit (EnrolmentProgressEvaluatorTest, EnrolmentProgressRollupHandlerTest); no DOM surface for a declared roll-up recomputing server-side. -->

- **GIVEN** an active `Enrolment` for a learner in a `Course` with 10 published `Lesson`s and 3 existing
  `LessonCompletion` rows for that learner
- **WHEN** a 4th `LessonCompletion` is created for that learner and course
- **THEN** `Enrolment.completedLessonCount` reads `4` and `totalPublishedLessonCount` reads `10`
- **AND** `Enrolment.progressPercent` recomputes to `40`

#### Scenario: Progress percentage is null-safe before any lesson completes
<!-- @e2e exclude Backend edge-case, covered by EnrolmentProgressEvaluatorTest (zero completions, zero published lessons). -->

- **GIVEN** a newly `active` `Enrolment` with zero `LessonCompletion` rows
- **WHEN** `progressPercent` is read
- **THEN** it reads `0`, not an error, even when `totalPublishedLessonCount` is also `0`

#### Scenario: Progress percentage is visible on the learner's My-learning dashboard
- **GIVEN** a learner with an active `Enrolment` whose `progressPercent` is `40`
- **WHEN** they open their My-learning dashboard
- **THEN** the enrolment's progress is shown as a percentage, sourced from `Enrolment.progressPercent`
<!-- @e2e exclude Requires a seeded learner session with existing LessonCompletion data on the single-admin scholiq e2e harness, which cannot provision a second per-test learner identity; covered by the manual-completion scenario in progress-tracking.spec.ts instead, which exercises the same progressPercent field end-to-end from a single admin session. -->

### Requirement: Persist AdmissionsRound and Application domain objects in OpenRegister

The system MUST persist `AdmissionsRound` and `Application` as OpenRegister objects. `AdmissionsRound` MUST
carry `x-openregister-lifecycle` (`draft → open → closed → archived`). `Application` MUST carry
`x-openregister-lifecycle` (`draft → submitted → intake-scheduled → intake-completed → placed | waitlisted
| rejected`; `waitlisted → placed`; `placed → converted`; any of `submitted`/`intake-scheduled`/
`intake-completed`/`waitlisted`/`placed` → `withdrawn`). Every UUID foreign key MUST use the
property-level relation dialect already in use across the register (`format: uuid` + `$ref:
<SchemaTitle>` on the property itself).

#### Scenario: AdmissionsRound and Application persist with their declared lifecycles

<!-- @e2e exclude Pure OpenRegister schema/lifecycle registration; verified by reasoning over the register JSON and by PHPUnit schema-validation tests — no scholiq DOM surface to drive registration itself. -->

- **GIVEN** the `AdmissionsRound` and `Application` schemas are registered
- **WHEN** an `AdmissionsRound` and an `Application` are each created
- **THEN** each is stored as an OpenRegister object carrying its declared lifecycle state

### Requirement: Application captures guardian identity via the reused ADR-046 dual-identity pattern

`Application` MUST carry `guardianId` (nullable Nextcloud user id) and `guardianRef` (nullable UUID domain
ref, ADR-046 A4) as a pair, copying the shape `ConferenceSignup.guardianId`/`guardianRef` already uses, plus
free-text `guardianGivenName`/`guardianFamilyName`/`guardianEmail`/`guardianPhone` and a
`submittedAuthLevel` eIDAS-assurance enum (reusing `ExcuseRequest.submittedAuthLevel`'s shape) for the
common case where no NC account or `LearnerProfile` exists yet at intake time. The system MUST NOT
introduce a second parent/guardian-account mechanism — no new authentication, invitation, or account-linking
class is added by this capability.

#### Scenario: An application is recorded for a family with no existing Scholiq identity

<!-- @e2e exclude Schema-shape verification (field presence, nullability); no distinct DOM behaviour beyond the standard manifest form covered by the frontend requirement's scenario below. -->

- **GIVEN** an intake coordinator records an `Application` for a family with no Nextcloud account and no
  `LearnerProfile`
- **WHEN** the application is saved
- **THEN** `guardianId` and `guardianRef` are both null
- **AND** the guardian's name, email, and phone are captured in the free-text fields
- **AND** no account, invitation, or credential is created as a side effect

### Requirement: Required intake documents reference OpenRegister file attachments

`Application.requiredDocuments` MUST be an array of `{kind, materialId}` entries, where `kind` is one of
`schooladvies`, `doorstroomtoets-result`, `id-document`, `prior-report`, `medical-statement`, `other`, and
`materialId` is a UUID `$ref` to a `Material` object — reusing the `school-structure` rule that materials
reference OpenRegister file attachments and this app stores no file bytes of its own.

#### Scenario: A PO schooladvies document is attached to a VO application

<!-- @e2e exclude Declarative $ref relation, no new file-storage code path; covered by the Material schema's own existing PHPUnit/register-validation coverage. -->

- **GIVEN** a VO `Application` requiring a schooladvies document
- **WHEN** the coordinator attaches the document
- **THEN** `requiredDocuments` carries an entry with `kind: "schooladvies"` referencing a `Material` object
- **AND** no file bytes are stored on the `Application` object itself

### Requirement: An MBO applicant who applies by the deadline and completes the mandatory intake has a right to admission

For an `AdmissionsRound` with `kind: "mbo-toelatingsrecht"`, the `AdmissionsDecisionGuard` MUST block an
`Application`'s transition to `rejected` when `submittedAt` is on or before `AdmissionsRound
.applicationDeadline`, the applicant reached `intake-completed`, and `studiekeuzeadviesGiven` is true —
unless `decisionReason` names a specific unmet prerequisite or additional requirement. `Application` MUST
NOT be transitionable to `intake-completed` while `AdmissionsRound.mandatoryIntake` is true and the intake
conversation has not been recorded.

#### Scenario: A timely, intake-complete MBO application cannot be rejected without a named reason

<!-- @e2e exclude Lifecycle-transition guard is backend logic verified by PHPUnit AdmissionsDecisionGuardTest::testToelatingsrechtBlocksRejectionWithoutNamedReason; no scholiq DOM surface for the guard itself. -->

- **GIVEN** an `Application` under an `mbo-toelatingsrecht` round, submitted before `applicationDeadline`,
  with `intake-completed` reached and `studiekeuzeadviesGiven` true
- **WHEN** a coordinator attempts to transition it to `rejected` with an empty `decisionReason`
- **THEN** the transition is refused

#### Scenario: A named prerequisite failure still allows rejection

<!-- @e2e exclude PHPUnit AdmissionsDecisionGuardTest::testToelatingsrechtAllowsRejectionWithNamedReason; backend guard behaviour. -->

- **GIVEN** the same `Application` as above
- **WHEN** a coordinator transitions it to `rejected` with `decisionReason: "prerequisite diploma not
  held"`
- **THEN** the transition succeeds

### Requirement: A VO schooladvies must be adjusted upward when the doorstroomtoets scores higher, unless motivated

The `AdmissionsDecisionGuard` MUST block any decision transition for an `AdmissionsRound` with
`kind: "vo-schooladvies-doorstroomtoets"` when `Application.doorstroomtoetsLevel` outranks
`Application.schooladviesLevel` on the shared ordinal
(`pro < vmbo-bb < vmbo-kb < vmbo-gt < havo < vwo`), unless `schooladviesAdjustedLevel` equals
`doorstroomtoetsLevel`, or `adjustmentMotivation` is
non-empty, or both levels are `pro`/`vmbo-bb`.

#### Scenario: A higher doorstroomtoets score without an adjustment or motivation blocks the decision

<!-- @e2e exclude Lifecycle-transition guard is backend logic verified by PHPUnit AdmissionsDecisionGuardTest::testSchooladviesAdjustmentRequiredBlocksDecision. -->

- **GIVEN** an `Application` with `schooladviesLevel: "vmbo-gt"` and `doorstroomtoetsLevel: "havo"`, and
  `schooladviesAdjustedLevel` still `"vmbo-gt"` with an empty `adjustmentMotivation`
- **WHEN** a coordinator attempts to move the application to a decision
- **THEN** the transition is refused

#### Scenario: The pro/vmbo-bb exemption allows the decision without adjustment

<!-- @e2e exclude PHPUnit AdmissionsDecisionGuardTest::testProVmboBbExemptionAllowsDecision. -->

- **GIVEN** an `Application` with `schooladviesLevel: "pro"` and `doorstroomtoetsLevel: "vmbo-bb"`
- **WHEN** a coordinator moves the application to a decision without raising `schooladviesAdjustedLevel`
- **THEN** the transition succeeds

### Requirement: Placement capacity is enforced and a waitlisted Application is auto-promoted when a seat frees up

When `AdmissionsRound.capacity` is set, `AdmissionsDecisionGuard` MUST block an `Application`'s transition
to `placed` once the count of that round's `placed`/`converted` `Application`s reaches `capacity` — the
transition MUST target `waitlisted` instead. When a `placed` `Application` transitions to `withdrawn` or
`rejected`, `AdmissionsWaitlistPromoter` MUST promote the oldest-`submittedAt` `waitlisted` `Application`
for the same `admissionsRoundId` to `placed`, re-running `AdmissionsDecisionGuard`.

#### Scenario: A full round routes a new placement to the waitlist

<!-- @e2e exclude Cross-object count in a lifecycle guard is backend logic verified by PHPUnit AdmissionsDecisionGuardTest::testCapacityReachedBlocksPlacement. -->

- **GIVEN** an `AdmissionsRound` with `capacity: 2` and two `Application`s already `placed`
- **WHEN** a coordinator attempts to transition a third `Application` to `placed`
- **THEN** the transition is refused and the coordinator must target `waitlisted` instead

#### Scenario: A withdrawal promotes the oldest waitlisted applicant

<!-- @e2e exclude Event-driven promotion is backend logic verified by PHPUnit AdmissionsWaitlistPromoterTest::testOldestWaitlistedApplicationPromotedOnWithdrawal. -->

- **GIVEN** an `AdmissionsRound` at capacity with two `waitlisted` `Application`s, `A` (older `submittedAt`)
  and `B`
- **WHEN** a `placed` `Application` for the same round transitions to `withdrawn`
- **THEN** `A` transitions to `placed`
- **AND** `B` remains `waitlisted`

### Requirement: An accepted Application converts into a LearnerProfile and Enrolments

When an `Application` transitions to `placed`, `ApplicationConversionHandler` MUST create a `LearnerProfile`
(`guardianRefs` stamped from `Application.guardianRef` when set), create one `Enrolment`
(`source: "admission"`) per course in the chosen `Programme.courseIds`, stamp `Application
.convertedLearnerProfileId` and `convertedEnrolmentIds`, and transition the `Application` to `converted`.
This handler MUST NOT provision a Nextcloud user account or LMS access as a side effect.

#### Scenario: Placement creates a LearnerProfile and Enrolments

<!-- @e2e exclude Cross-object write bridge is backend logic verified by PHPUnit ApplicationConversionHandlerTest::testPlacementCreatesLearnerProfileAndEnrolments. -->

- **GIVEN** an `Application` for a `Programme` with three courses, transitioning to `placed`
- **WHEN** `ApplicationConversionHandler` runs
- **THEN** a `LearnerProfile` is created with `guardianRefs` containing the application's `guardianRef`
- **AND** three `Enrolment` objects are created with `source: "admission"`
- **AND** the `Application` transitions to `converted` with both reference fields stamped

### Requirement: Enrolment records its origin including admission and subject-choice sources

The `Enrolment.source` enum MUST gain two additive values: `"admission"` (created by
`ApplicationConversionHandler` on placement) and `"subject-choice"` (created by a `SubjectChoice`'s
approval, per the `school-structure` capability). Existing enum values and existing `Enrolment` rows are
unaffected.

#### Scenario: An admission-created Enrolment carries the admission source

<!-- @e2e exclude Additive enum value; covered by ApplicationConversionHandlerTest and the register JSON-schema validation gate, no distinct DOM surface. -->

- **GIVEN** the `Enrolment.source` enum
- **WHEN** an `Enrolment` is created by `ApplicationConversionHandler`
- **THEN** its `source` is `"admission"`

### Requirement: Frontend is declarative with one named admissions-review exception

The frontend MUST be declarative: `src/manifest.json` index/detail pages for `AdmissionsRound` and
`Application`. The only custom Vue component for admissions MUST be `AdmissionsReviewBoard.vue` — a
coordinator's queue of applications needing intake scheduling or a decision, cross-referencing each
`Application` against its round's deadline, kind, and remaining capacity. No PHP CRUD controllers.

#### Scenario: A coordinator reviews pending applications on the review board

<!-- @e2e tests/e2e/spec-coverage/admissions-and-subject-choice.spec.ts -->
<!-- Declarative page rendering + the one custom-view exception is the drivable DOM scenario, mirroring BsaRiskDashboard's e2e coverage pattern; the underlying guard/handler logic has no DOM surface and is covered by the PHPUnit tests referenced on the preceding scenarios. -->

- **GIVEN** one or more `Application`s in `intake-completed` state for the coordinator's scope
- **WHEN** the coordinator opens the admissions review board
- **THEN** the pending applications are listed with their round's deadline, kind, and remaining capacity
- **AND** the coordinator can navigate from a listed application to record its decision

### Requirement: Enrolment carries inschrijving date, volgnummer and its own vestiging
`Enrolment` MUST declare `inschrijvingDate` (nullable date), `volgnummer` (nullable integer) and `locationId` (nullable `$ref Vestiging`) additively. `Enrolment.locationId` is the inschrijving's own vestiging and is independent of any `Cohort.locationId` the pupil is later grouped into (school-and-location-records).

#### Scenario: An inschrijving records its date, volgnummer and vestiging
- **GIVEN** an `Enrolment` representing a school inschrijving
- **WHEN** `inschrijvingDate`, `volgnummer` and `locationId` are set
- **THEN** all three persist on the `Enrolment` object, independent of the `Cohort` it may later reference

#### Scenario: A pre-existing Enrolment without these fields is unaffected
- **GIVEN** a pre-existing `Enrolment` row with none of the three fields set
- **WHEN** it is read
- **THEN** each resolves to `null` and the existing `learnerId`/`courseId`/`source`/lifecycle fields are unchanged

### Requirement: Enrolment carries a destination school on withdrawal
`Enrolment` MUST declare `destinationSchoolId` (nullable `$ref School`) additively, captured alongside the existing `withdraw` transition and free-text `reason` field.

#### Scenario: A leaver's destination school is recorded on withdrawal
- **GIVEN** an active `Enrolment` for a groep-8 leaver
- **WHEN** the `withdraw` transition fires with `reason` set and `destinationSchoolId` set to the receiving school
- **THEN** both persist on the withdrawn `Enrolment` object

### Requirement: Enrolment carries leerjaar per pupil, independent of the cohort name
`Enrolment` MUST declare `leerjaar` (nullable integer, 1 to 8) additively. A combination group (e.g. `Groep 5/6`) is one `Cohort` whose member `Enrolment`s carry different `leerjaar` values; `leerjaar` MUST NOT be parsed from the `Cohort.name` string.

#### Scenario: A combination group carries two leerjaar values across its enrolments
- **GIVEN** a `Cohort` named "Groep 5/6" with two `Enrolment`s referencing it via `cohortId`
- **WHEN** one `Enrolment.leerjaar` is set to 5 and the other to 6
- **THEN** both values persist independently on their own `Enrolment` objects, and neither is derived from the `Cohort`'s `name`

### Requirement: CohortDetail's roster surfaces leerjaar
The `CohortDetail` page's enrolment roster widget MUST include a `leerjaar` column.

#### Scenario: A coordinator sees each pupil's leerjaar on the group roster
- **GIVEN** `CohortDetail` for a combination group
- **WHEN** the roster widget renders
- **THEN** each row shows that enrolment's `leerjaar` value alongside the existing learner/course columns

### Requirement: LearnerProfile records the NOAT/CUMI/NNCA funding-weight classification
`LearnerProfile` SHALL gain `fundingWeightCode` (nullable enum `noat | cumi | nnca`, default `null`) — the
culturele-achtergrond classification that feeds the ROD/bekostiging funding weging (P-new-13). This is additive:
no existing `LearnerProfile` object is affected, and the field is independent of any other property.

#### Scenario: A school records a learner's funding-weight classification
- **GIVEN** a `LearnerProfile` with `fundingWeightCode: null`
- **WHEN** staff set it to `"cumi"`
- **THEN** the property persists and feeds the same ROD/bekostiging chain P-new-12's teldatum check protects

<!-- @e2e exclude Schema-shape requirement, verified by FundingTeldatumRegisterTest; no bespoke controller — reads/writes go through OpenRegister's generic object endpoint per ADR-022. -->

### Requirement: Persist SchoolAdvies domain objects in OpenRegister

The system MUST persist `SchoolAdvies` as an OpenRegister object with `x-openregister-lifecycle`
(`voorlopig → definitief → verzonden-naar-rod`) and materialised `isVoorlopigOverdue`/
`isDefinitiefOverdue` calculations mirroring `TlvApplication`'s `daysUntilValidUntil`/
`tlvExpiringSoon` idiom exactly (a `dateDiff`/`now` expression, not a PHP TimedJob).

#### Scenario: A SchoolAdvies persists with its declared lifecycle and calculations

<!-- @e2e exclude Pure OpenRegister schema/lifecycle registration; no scholiq DOM surface for registration itself, covered by PHPUnit SchoolAdviesRegisterTest mirroring the established `*RegisterTest` convention. -->

- **GIVEN** the `enrolment` schemas are registered
- **WHEN** a `SchoolAdvies` is created with `voorlopigAdviesLevel` set
- **THEN** it persists as an OpenRegister object in `lifecycle: voorlopig`, carrying the declared
  `isVoorlopigOverdue`/`isDefinitiefOverdue` calculations

### Requirement: A PO schooladvies may only be raised on heroverweging, never lowered, unless motivated

`SchoolAdviesFinalizeGuard` MUST block the `vaststellenDefinitief` transition (`voorlopig →
definitief`) when `doorstroomtoetsResultLevel` outranks `definitiefAdviesLevel` on the shared
ordinal (`pro < vmbo-bb < vmbo-kb < vmbo-gt < havo < vwo` — the same ordinal
`AdmissionsDecisionGuard` already uses for the VO intake side), UNLESS `heroverwegingMotivation` is
non-empty, or both levels are `pro`/`vmbo-bb` — the exact rule and exemption
`openspec/specs/enrolment/spec.md`'s existing "A VO schooladvies must be adjusted upward..."
requirement already establishes for `Application`, applied here to `SchoolAdvies`'s own fields.

#### Scenario: A higher doorstroomtoets result without a raised definitief or a motivation blocks finalisation

<!-- @e2e exclude Lifecycle-transition guard is backend logic verified by PHPUnit SchoolAdviesFinalizeGuardTest, mirroring AdmissionsDecisionGuardTest's own structure. -->

- **GIVEN** a `SchoolAdvies` with `voorlopigAdviesLevel: "vmbo-gt"`,
  `doorstroomtoetsResultLevel: "havo"`, `definitiefAdviesLevel` still `"vmbo-gt"`, and an empty
  `heroverwegingMotivation`
- **WHEN** a coordinator attempts `vaststellenDefinitief`
- **THEN** the transition is refused

#### Scenario: Raising definitiefAdviesLevel to match the doorstroomtoets result allows finalisation

<!-- @e2e exclude PHPUnit SchoolAdviesFinalizeGuardTest. -->

- **GIVEN** the same `SchoolAdvies`, with `definitiefAdviesLevel` raised to `"havo"`
- **WHEN** a coordinator attempts `vaststellenDefinitief`
- **THEN** the transition succeeds

#### Scenario: A motivation allows finalisation without raising the level

<!-- @e2e exclude PHPUnit SchoolAdviesFinalizeGuardTest. -->

- **GIVEN** a `SchoolAdvies` with `voorlopigAdviesLevel: "vmbo-gt"`,
  `doorstroomtoetsResultLevel: "havo"`, `definitiefAdviesLevel` still `"vmbo-gt"`, and a non-empty
  `heroverwegingMotivation`
- **WHEN** a coordinator attempts `vaststellenDefinitief`
- **THEN** the transition succeeds

#### Scenario: The pro/vmbo-bb exemption allows finalisation without a raise or motivation

<!-- @e2e exclude PHPUnit SchoolAdviesFinalizeGuardTest, mirroring AdmissionsDecisionGuardTest::testProVmboBbExemptionAllowsDecision. -->

- **GIVEN** a `SchoolAdvies` with `voorlopigAdviesLevel: "pro"` and
  `doorstroomtoetsResultLevel: "vmbo-bb"`
- **WHEN** a coordinator attempts `vaststellenDefinitief` without raising `definitiefAdviesLevel`
- **THEN** the transition succeeds

#### Scenario: A doorstroomtoets result that does not outrank the definitief advies never blocks finalisation

<!-- @e2e exclude PHPUnit SchoolAdviesFinalizeGuardTest. -->

- **GIVEN** a `SchoolAdvies` with `doorstroomtoetsResultLevel` equal to or lower than
  `definitiefAdviesLevel` on the ordinal
- **WHEN** a coordinator attempts `vaststellenDefinitief`
- **THEN** the transition succeeds regardless of `heroverwegingMotivation`

### Requirement: Sending a definitief schooladvies to ROD auto-queues the existing bron-rod DataExchangeJob

`SchoolAdviesSendToRodHandler` MUST, on the `verzendenNaarRod` transition (`definitief →
verzonden-naar-rod`), create a `DataExchangeJob` (`direction: export`, `target: bron-rod`,
`scope.schema: school-advies`, `scope.filters: {learnerId, schoolAdviesId}`), mirroring
`SupportRequestSubmitHandler`'s own auto-queue-a-job pattern exactly, and stamp the new job's UUID
back onto `SchoolAdvies.dataExchangeJobId`.

#### Scenario: Sending a definitief advies creates and links a bron-rod DataExchangeJob

<!-- @e2e exclude Cross-object write bridge is backend logic verified by PHPUnit SchoolAdviesSendToRodHandlerTest, mirroring SupportRequestSubmitHandlerTest's own structure; no scholiq DOM surface for the job-creation side effect itself. -->

- **GIVEN** a `SchoolAdvies` in `definitief`
- **WHEN** a coordinator triggers `verzendenNaarRod`
- **THEN** a `DataExchangeJob` is created with `target: bron-rod` and `scope.schema: school-advies`
- **AND** `SchoolAdvies.dataExchangeJobId` is stamped with the new job's UUID

### Requirement: Frontend is declarative with manifest index+detail pages

`src/manifest.json` (or its `src/manifest.d/*.json` fragment, per this repo's ADR-037 modular
pipeline) MUST declare `SchoolAdvies`/`SchoolAdviesDetail` index+detail pages, following the same
`<Schema>s`/`<Schema>Detail` convention every other schema in this register already uses. There
MUST be no PHP CRUD controller.

#### Scenario: Pages are manifest-declared

<!-- @e2e tests/e2e/spec-coverage/enrolment.spec.ts -->

- **GIVEN** the manifest is built
- **WHEN** `SchoolAdvies`/`SchoolAdviesDetail` are inspected
- **THEN** both exist as declarative index/detail pages, and no PHP controller serves them

### Requirement: A school offers optional lessons with a window and a capacity

A user in `instructors`, `team-leads` or `compliance-officers` MUST be able to create an optional lesson offer with its lessons, a capacity per lesson, the eligible cohorts and a sign-up window, either fixed dates or relative to each lesson (opening a number of days before it and closing a number of hours before it).

#### Scenario: A coordinator offers weekly extra maths

- **GIVEN** a coordinator on the optional lessons page
- **WHEN** they create "Keuzewerktijd wiskunde" for the havo 4 and 5 groups, four Thursday lessons, 24 places each, opening 7 days and closing 12 hours before each lesson, and open it
- **THEN** each lesson shows 24 free places and the date its sign-up opens

### Requirement: A learner signs up for an optional lesson inside the window

An eligible learner MUST be able to sign up for a lesson of an open offer while its window is open and a place is free, and MUST be able to withdraw inside the window. The sign-up MUST always be made in the caller's own name.

#### Scenario: A learner signs up for Thursday

- **GIVEN** learner j.bakker in havo 4 and the first Thursday lesson with 13 free places, its window open
- **WHEN** j.bakker opens "Optional lessons" and chooses "Sign up" on that lesson
- **THEN** the lesson shows j.bakker as signed up and 12 free places

#### Scenario: The window has closed

- **GIVEN** the first Thursday lesson starts in 10 hours and its window closes 12 hours before
- **WHEN** j.bakker opens "Optional lessons"
- **THEN** the lesson shows that sign-up has closed and offers no button

### Requirement: A coordinator places learners who missed the window

After the window closes, a coordinator MUST see per lesson the eligible learners who did not sign up and MUST be able to place them on the lesson while a place is free. A placed sign-up MUST be recorded as placed and by whom.

#### Scenario: A coordinator places a learner after the deadline

- **GIVEN** the window of the first Thursday lesson has closed with 12 free places, and learner t.smit did not sign up
- **WHEN** the coordinator opens the lesson, finds t.smit under "Not signed up" and chooses "Place"
- **THEN** t.smit is on the lesson as placed by the coordinator

### Requirement: Every sign-up obeys the same rules whoever writes it

The rules on eligibility, the window, the capacity and one sign-up per learner per lesson MUST be enforced when a sign-up is created or changed through any path: the learner's screen, a coordinator's screen or the object API. Only staff placing a learner may write outside the window; nobody may exceed the capacity.

#### Scenario: A full lesson refuses a coordinator too

<!-- @e2e exclude Creating-event listener; covered by ElectiveSignUpRulesTest::testCapacityHoldsForStaff. -->

- **GIVEN** a lesson with all 24 places taken
- **WHEN** a coordinator places one more learner
- **THEN** the write is refused with the reason that the lesson is full

### Requirement: Another system signs learners up through the API

An account in the group `elective-integrations` MUST be able to create and withdraw sign-ups for any eligible learner through OpenRegister's object API on `elective-sign-up`, and every such write MUST be recorded as made via integration and checked by the same rules.

#### Scenario: A student portal signs a learner up

<!-- @e2e exclude API path with no learniq screen; covered by the integration test of task 5. -->

- **GIVEN** a school's own student app with an account in `elective-integrations`
- **WHEN** it posts a sign-up for learner j.bakker on a lesson with a free place inside the window
- **THEN** the sign-up exists with `madeVia: integration`
- **AND** the same post for a full lesson is refused

### Requirement: A course or programme says whether learners may sign up

`Course` and `Programme` MUST declare `selfEnrolment` with the values `closed`, `open` and `on-request`, default `closed`. Only a `published` course or programme whose `selfEnrolment` is not `closed` MUST appear in the learner catalogue. Every course and programme stored before this change MUST read as `closed`.

#### Scenario: A training coordinator opens a course for sign-up

- **GIVEN** the published course "Excel voor gevorderden"
- **WHEN** a training coordinator sets sign-up to open on the course form and saves
- **THEN** the course appears in the learner catalogue

### Requirement: A learner signs up from the catalogue

A signed-in learner MUST be able to browse and search the catalogue and sign up for a course that is open or on request. Signing up MUST create one `Enrolment` for the caller only, with `source: self`: active at once for an open course, pending for a course on request. A prerequisite that is not met MUST refuse the sign-up with the message of the prerequisite check. A closed or unpublished course MUST be refused.

#### Scenario: A learner signs up for an open course

- **GIVEN** learner p.ganpat and the open course "Excel voor gevorderden"
- **WHEN** p.ganpat opens the catalogue, searches "Excel", opens the course and chooses "Sign up"
- **THEN** the course shows "You are signed up"
- **AND** p.ganpat has an active enrolment with source self

#### Scenario: A prerequisite blocks a sign-up

<!-- @e2e exclude Veto from EnrolmentPrerequisiteListener through the service; covered by CatalogueSignUpServiceTest::testPrerequisiteVetoIsReturned. -->

- **GIVEN** a course that requires "Excel basis", which the learner has not completed
- **WHEN** the learner posts to `POST /api/catalogue/courses/{id}/sign-up`
- **THEN** the answer names "Excel basis" as the missing prerequisite and no enrolment is created

### Requirement: A learner signs up for a whole programme

Signing up for an open or on-request programme MUST create one enrolment per course of the programme that the learner is not already enrolled in, each with `programmeId` set, without bypassing the prerequisite check.

#### Scenario: A learner signs up for a track

- **GIVEN** the open programme "Basis projectmanagement" with three courses
- **WHEN** a learner chooses "Sign up" on the programme in the catalogue
- **THEN** the learner has three enrolments, one per course, each naming the programme

### Requirement: A request waits for a teacher or manager

A sign-up for a course on request MUST stay pending until a user in `instructors`, `hr` or `team-leads`, or the learner's manager, approves it with `activate` or declines it with a reason. The learner MUST be told either way.

#### Scenario: A manager approves a request

- **GIVEN** a pending sign-up by p.ganpat for "Leidinggeven aan hybride teams", with p.ganpat's manager as `managerId`
- **WHEN** the manager opens "Sign-up requests" and chooses "Approve"
- **THEN** the enrolment is active and p.ganpat gets a notification that the sign-up was approved

### Requirement: A learner withdraws their own sign-up

A learner MUST be able to withdraw an enrolment they signed up for themselves while it is pending or active with no progress. An enrolment made by staff, or one with progress, MUST NOT be withdrawn by the learner.

#### Scenario: A learner changes their mind

- **GIVEN** p.ganpat's active self sign-up for "Excel voor gevorderden" with no progress
- **WHEN** p.ganpat chooses "Withdraw" on the course in the catalogue
- **THEN** the enrolment is withdrawn and the course shows "Sign up" again

### Requirement: Provider courses show their provider

A course written by an outside provider's catalogue connector MUST show the provider's name on its catalogue card and MUST be filterable by provider. Staff MUST be able to list imported courses that are not yet published, to choose which ones the school offers.

#### Scenario: An administrator publishes a provider course

- **GIVEN** twenty draft courses written by the Go1 connector with `author` "Go1"
- **WHEN** an administrator opens the course list with the filter "Imported, not yet published", opens one and publishes it with sign-up open
- **THEN** that course appears in the learner catalogue with "Go1" as its provider
- **AND** the other nineteen do not appear

### Requirement: The learner is told in words that fit a chosen course

When a self sign-up becomes active, the learner MUST get a notification that says they are signed up for the course. The notification for a mandatory enrolment MUST NOT be sent for a self sign-up.

#### Scenario: No mandatory-course message for a chosen course

<!-- @e2e exclude Notification condition in the register dialect; covered by CatalogueSignUpRegisterTest and gate 18. -->

- **GIVEN** a learner signs up for an open course
- **WHEN** the enrolment is activated
- **THEN** the learner gets "You are signed up for Excel voor gevorderden"
- **AND** not "You have been enrolled in a mandatory course"

### Requirement: A teacher sets up work groups with a maximum size

A user in `instructors`, `team-leads` or `compliance-officers` MUST be able to create work groups for a cohort, grouped in a named set, each with a name and a maximum number of members, and MUST be able to open them for self sign-up until a date, move a member, and close sign-up. Learners MUST NOT be able to create or edit a `WorkGroup` through the object API.

#### Scenario: A teacher makes five groups of four

- **GIVEN** a teacher on the cohort page of "MV2A marketing en communicatie"
- **WHEN** the teacher opens "Work groups", adds a set "Project campagne periode 2" with five groups of four, open for sign-up until 14 November, and saves
- **THEN** the tab shows five groups, each with four free places and the sign-up date

### Requirement: A learner joins a work group with a free place

A learner of the cohort MUST be able to see the cohort's open work groups with their free places and join a group that has a free place while sign-up is open. A group at its maximum MUST refuse a join with a reason, also when two learners try for the last place at the same moment. After the sign-up date a learner MUST NOT be able to join, move or leave.

#### Scenario: A learner joins a group

- **GIVEN** learner s.dekker in "MV2A marketing en communicatie" and "Groep 4" with two of four places taken
- **WHEN** s.dekker opens "My work groups" and chooses "Join" on "Groep 4"
- **THEN** s.dekker is listed as a member of "Groep 4"
- **AND** "Groep 4" shows one free place

#### Scenario: A full group takes nobody more

<!-- @e2e exclude Race and cap rule in the service; covered by WorkGroupMembershipServiceTest::testLastPlaceGoesToOneLearnerOnly. -->

- **GIVEN** "Groep 1" with four of four places taken
- **WHEN** a learner of the cohort posts to `POST /api/work-groups/{id}/join`
- **THEN** the answer says the group is full and the group still has four members

### Requirement: A learner is in one work group per set

A learner MUST be a member of at most one work group per set of a cohort. Joining another group of the same set while sign-up is open MUST move the learner in one step.

#### Scenario: A learner switches groups

- **GIVEN** s.dekker is in "Groep 4" and "Groep 5" has a free place
- **WHEN** s.dekker chooses "Move here" on "Groep 5"
- **THEN** s.dekker is a member of "Groep 5" only

### Requirement: A group hand-in names the whole work group

When an assignment has `groupSubmission` and names a work group set, the hand-in screen MUST list every member of the learner's work group in that set as the submission's learners.

#### Scenario: One member hands in for the group

- **GIVEN** the assignment "Campagneplan" set to group hand-in for the set "Project campagne periode 2", and s.dekker in "Groep 5" with three others
- **WHEN** s.dekker hands in the plan on the hand-in screen
- **THEN** the submission lists all four members of "Groep 5"
- **AND** each of them sees the hand-in on their own assignment page

## Standards
Studielink, Edukoppeling, OOAPI 5.0, IMS LIS (legacy), Schema.org `EducationEvent`, eduPersonAffiliation propagation.

## Data Model
See `docs/ARCHITECTURE.md`. Uses: `Learner`, `Enrolment`, `Cohort`, `OnboardingTemplate`, `EnrolmentRule`. All in OpenRegister.

## Out of Scope
- Payment processing for paid enrolments (separate spec; routes to billing system).
- Waitlist auto-promotion (V1 enhancement).
- Cross-institution credit transfer (handled by oso-transfer / EDCI).
