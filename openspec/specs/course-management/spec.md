---
slug: course-management
title: Course Management
status: done
feature_tier: must
depends_on_adrs: [adr-001, adr-002, adr-011]   # TODO until ADRs land
created: 2026-05-11
---

# Course Management

@e2e exclude Most requirements in this spec define OpenRegister schema shapes, OOAPI endpoints, and cmi5/xAPI runtime with no dedicated browser journey. The course-authoring-ux requirements below are the exception — see their own per-scenario `@e2e` tags pointing at `tests/e2e/spec-coverage/course-authoring-ux.spec.ts`.

## Purpose
Course Management ranks #2 of 354 canonical features (153 demand, 43 tenders, 12 competitors). All 13 OSS LMS leaders ship it; the differentiator is a modern Vue/NL-Design surface — insight #16 says "OSS LMS leaders all share dated UX". Without authoring, Scholiq cannot anchor the LVS, eLearning, training, and certification surfaces above it.

## What
Authoring of courses, modules, and lessons; cloning of templates; ordered learning paths; published-vs-draft state; ECTS workload declaration for HE; programme-committee approval workflow for HE catalog changes; Open Onderwijs API publication so external sites and student portals consume one source. Content runtime is cmi5 + xAPI primary, with a SCORM 1.2/2004 shim for legacy packages.

## User Stories
- As an HE administrator, I want the course catalog exposed via Open Onderwijs API so external apps and the institution website pull from one source.
- As a board member, I want catalog changes to go through programme committee approval so curriculum governance is auditable.
- As an administrator, I want each module to declare ECTS workload so totals match the 60-credit-per-year Bologna rule.
- As a student, I want the catalog to tell me up-front whether I meet a course prerequisite so I do not waste time on a denied registration.
- As an instructional designer, I want to clone a published course as a draft so I can prepare next year's edition without breaking the live one.

## Acceptance Criteria
- GIVEN an instructional designer opens a published course, WHEN they click "Clone for next year", THEN a draft copy is created with a new academic year tag and zero enrolments.
- GIVEN a student opens the catalog, WHEN a course has unmet prerequisites, THEN the enrol button is disabled and the failing prerequisite is named in plain text.
- GIVEN a programme committee approves a catalog change, WHEN approval is recorded, THEN the change becomes visible in OOAPI within 5 minutes.
- GIVEN a `Course` transitions to `published`, WHEN the publication contract's field mapping is applied, THEN a `DataExchangeJob` (`target: ooapi-catalog`) carries the OOAPI 5.0 `course` resource fields (ECTS, language, level) to the catalog publication surface hosted by opencatalogi — Scholiq itself serves no `/ooapi/v5/*` endpoint.

## Requirements

### Requirement: Course/Module/Lesson hierarchy in OpenRegister
The system MUST support Course → Module → Lesson hierarchy persisted as OpenRegister objects.

#### Scenario: Persist course hierarchy as OpenRegister objects
- **GIVEN** an instructional designer authoring a course with modules and lessons
- **WHEN** the course, its modules, and their lessons are saved
- **THEN** the system persists the Course → Module → Lesson hierarchy as related OpenRegister objects

### Requirement: Publish course catalog via OOAPI 5.0

The system MUST NOT serve OOAPI 5.0 endpoints itself. Instead it MUST define the OOAPI 5.0 catalog
**publication contract**: (a) which objects are eligible for publication — `Course` and `Programme` with
`lifecycle: published`, with `Cohort` representing a specific "run" of a course or programme; (b) the
**field mapping** from Scholiq's objects to OOAPI 5.0 resources — `Course → course`, `Programme → program`,
`Cohort → offering` — keyed to RIO `opleidingseenheid` / `aangeboden opleiding` identifiers where the
institution has recorded them, and omitted otherwise; and (c) the **publication lifecycle** — a `publish`
transition on `Course` or `Programme` MUST queue a `DataExchangeJob` (`direction: sync`,
`target: ooapi-catalog`, per the `data-exchange` spec's delegation mechanism) so the catalog reflects the
change, and an `archive` transition MUST queue the matching unpublish/removal sync. The public `/ooapi/v5/*`
HTTP surface and the OOAPI 5.0 wire protocol are served by **opencatalogi**; the field-mapping adapter is
hosted in **openconnector**. Scholiq implements neither.

#### Scenario: Publishing a course queues a catalog-sync job, not a scholiq-served endpoint

- **GIVEN** a `Course` with `lifecycle: draft` and its required OOAPI mapping fields populated (`code`,
  `name`, `level`, `language`)
- **WHEN** an instructional designer transitions it to `published`
- **THEN** the system queues a `DataExchangeJob` with `direction: sync` and `target: ooapi-catalog` carrying
  the OOAPI 5.0 `course` resource field mapping
- **AND** Scholiq itself exposes no `/ooapi/v5/*` route — the catalog request is served by opencatalogi

#### Scenario: Unpublishing removes the catalog entry

- **GIVEN** a `Course` or `Programme` with `lifecycle: published`
- **WHEN** it is archived
- **THEN** the system queues a corresponding unpublish `DataExchangeJob` (`target: ooapi-catalog`) so the
  opencatalogi-hosted OOAPI 5.0 catalog removes or deprecates the entry

#### Scenario: Field mapping covers course, program, and offering resources keyed to RIO where available

- **GIVEN** a `Programme` that aggregates `Course`s and a `Cohort` representing one specific run of a course
- **WHEN** the publication contract's field mapping is applied
- **THEN** the `Course` maps to the OOAPI `course` resource, the `Programme` maps to the OOAPI `program`
  resource, and the `Cohort` maps to the OOAPI `offering` resource
- **AND** each mapped resource carries its RIO `opleidingseenheid` / `aangeboden opleiding` identifier when
  the source object has one, and omits the RIO identifier field otherwise

### Requirement: Run cmi5 + xAPI natively with SCORM shim

The system MUST run cmi5 + xAPI content natively via a real LRS ingest endpoint
(`POST /api/lrs/statements`, `GET /api/lrs/statements`) that authenticates the caller (a signed cmi5 launch
JWT minted by `Cmi5LaunchTokenService`, or a Nextcloud session that passes the CSRF check), stamps
`verified_actor_id` server-side from the authenticated identity, NEVER from the posted statement's `actor.*`
fields, and persists the statement as an `XapiStatement` OpenRegister object. The `xapi-statement` schema MUST
NOT let a learner create statements through the generic object API; learners read only their own statements.
Launching a cmi5 AU MUST mint a signed RS256 launch token once an administrator has provisioned the key-pair,
hand the token to the AU only through a single-use fetch URL (never in the launch URL), and the launch endpoint
MUST return HTTP 503 with a human-readable body while `Cmi5LaunchTokenService::isEnabled()` is false. A
statement whose actor cannot be authenticated MUST NOT be stored. The SCORM 1.2 shim posts its statements to the
same endpoint; the SCORM 2004 shim and a cmi5 package importer remain follow-ups.

#### Scenario: Run cmi5/xAPI content with SCORM fallback
- **GIVEN** a lesson backed by a content package
- **WHEN** a learner launches the lesson
- **THEN** the system runs cmi5 + xAPI content natively
- **AND** it runs SCORM 1.2 packages through the compatibility shim, which posts to the same LRS endpoint (SCORM 2004 is a follow-up)

<!-- @e2e exclude Carried over from the canonical requirement; the launch paths are covered by tests/e2e/spec-coverage/progress-tracking.spec.ts and tests/Unit/Controller/Cmi5LaunchControllerTest.php. -->

#### Scenario: A SCORM 1.2 package's completion status produces a recognised xAPI statement

<!-- @e2e exclude The SCORM 1.2 API shim's completion-to-xAPI mapping is covered by tests/unit-js/scorm12Runtime.test.mjs; the POST target by LessonPlayer.vue postXapiStatement and tests/Unit/Controller/LrsControllerTest.php. -->

- **GIVEN** a `Lesson` with `contentType: "scorm12"` and a learner has launched it
- **WHEN** the package calls `LMSSetValue('cmi.core.lesson_status', 'completed')` (or `'passed'`)
- **THEN** an xAPI statement is built with `verb.id` equal to `http://adlnet.gov/expapi/verbs/completed` or
  `.../passed`, the same IRIs `XapiCompletionHandler` already recognises
- **AND** the statement is posted to `POST /api/lrs/statements`, which stamps `verified_actor_id` from the session

#### Scenario: A cmi5 lesson gracefully degrades until the sibling ingest change ships

- **GIVEN** a `Lesson` with `contentType: "cmi5"` and no cmi5 launch signing key is provisioned
  (`Cmi5LaunchTokenService::isEnabled()` is false, so the launch endpoint answers 503)
- **WHEN** a learner opens the lesson
- **THEN** `LessonPlayer.vue` shows a clear "cmi5 playback is not yet available for this lesson" empty state
- **AND** no unhandled error or infinite loading spinner is shown

<!-- @e2e exclude Depends on instance key state, which a shared instance cannot toggle per test; the 503 half is pinned by tests/Unit/Controller/Cmi5LaunchControllerTest.php, the empty state is the `!cmi5.available` branch of src/views/LessonPlayer.vue. -->

#### Scenario: A learner's completed AU produces a queryable xAPI statement

- **GIVEN** a Lesson with `contentType: cmi5` and a learner with a valid, unexpired launch JWT
- **WHEN** the AU posts a `completed` statement to `POST /api/lrs/statements`
- **THEN** the statement is persisted as an `XapiStatement` with `verified_actor_id` set to the
  authenticated learner's identity
- **AND** the statement is queryable via `GET /api/lrs/statements`, scoped to that learner for a non-admin caller

<!-- @e2e exclude Server-to-server AU call with a launch token, no UI; pinned by tests/Unit/Controller/LrsControllerTest.php. -->

#### Scenario: A forged actor claim in the statement body is ignored

- **GIVEN** a learner is authenticated with their own valid launch JWT
- **WHEN** they post a statement whose `actor.account.name` claims a different learner's identifier
- **THEN** the persisted `XapiStatement.verified_actor_id` is the authenticated caller's own identity
- **AND** it is NOT the identifier claimed in `actor.account.name`

<!-- @e2e exclude Trust boundary of the ingest endpoint, no UI; pinned by tests/Unit/Controller/LrsControllerTest.php. -->

#### Scenario: Launch is unavailable before the signing key is provisioned

- **GIVEN** `Cmi5LaunchTokenService::isEnabled()` returns false (no RS256 key-pair provisioned yet)
- **WHEN** a learner attempts to launch a `cmi5` Lesson
- **THEN** the launch endpoint returns HTTP 503 with a human-readable error body
- **AND** no launch token is issued

<!-- @e2e exclude Depends on instance key state; pinned by tests/Unit/Controller/Cmi5LaunchControllerTest.php, and the lesson player empty state by tests/e2e/spec-coverage/progress-tracking.spec.ts. -->

#### Scenario: The launch token never travels in the launch URL

- **GIVEN** a provisioned key and a learner who may read the lesson
- **WHEN** the learner launches the cmi5 lesson
- **THEN** the answer carries `endpoint`, `fetchUrl`, `actor`, `activityId` and `registration`, and no token
- **AND** the fetch URL hands out the token once, and a second call is refused

<!-- @e2e exclude Token hand-off, no UI; pinned by tests/Unit/Controller/Cmi5LaunchControllerTest.php. -->

#### Scenario: SCORM 2004 is not claimed done

- **GIVEN** a Lesson with `contentType: scorm2004`
- **WHEN** the wedge plan is read
- **THEN** `openspec/WEDGE-PLAN.md` names the SCORM 2004 shim as a follow-up rather than a blanket "built" status

<!-- @e2e exclude Documentation state, no UI; checked by reading openspec/WEDGE-PLAN.md. -->

### Requirement: Place an LTI 1.3 tool inside a lesson via a dedicated placement object

The system MUST support placing an external LTI 1.3 tool inside a Course or Lesson as an
`LtiToolPlacement` OpenRegister object: `lessonId` (reference to the placing `Lesson`, nullable
when the placement is course-level), `courseId` (reference to the placing `Course`, nullable
when the placement is lesson-level), `openconnectorDeploymentId` (the UUID of the corresponding
`lti_deployment` registration in openconnector's register), `launchMode`
(`resource-link | deep-linking`), and, when AGS grade passback is desired,
`curriculumPlanId` / `gradeEntryComponentId` / `gradeScaleId` naming which grading component the
tool's scores feed. A `Lesson` with `contentType: lti` MUST set `contentRef` to the UUID of its
`LtiToolPlacement`, not a raw URL — a static link cannot carry a signed OIDC launch.

@e2e exclude Pure backend/data-model requirement — no dedicated browser journey; covered by PHPUnit schema tests

#### Scenario: An LtiToolPlacement names its openconnector registration

- **GIVEN** an instructional designer places an external LTI tool inside a Lesson
- **WHEN** the `LtiToolPlacement` is saved with `lessonId` and `openconnectorDeploymentId` set
- **THEN** the Lesson's `contentType` is `lti` and its `contentRef` equals the
  `LtiToolPlacement`'s UUID

#### Scenario: A placement configured for grade passback names its curriculum mapping

- **GIVEN** an `LtiToolPlacement` intended to feed AGS scores into the gradebook
- **WHEN** it is saved with `curriculumPlanId`, `gradeEntryComponentId`, and `gradeScaleId` set
- **THEN** those three fields are persisted on the placement, not inferred from any LTI protocol
  metadata

### Requirement: LessonPlayer delegates the OIDC launch to the openconnector adapter

When a learner opens a Lesson with `contentType: lti`, `LessonPlayer.vue` MUST resolve
`contentRef` to its `LtiToolPlacement` and call a scholiq backend endpoint
(`LtiToolPlacementController::launch`) that delegates to openconnector's Platform-role
launch-initiation service (openconnector REQ-LTI-006), passing only
`LtiToolPlacement.openconnectorDeploymentId`. Scholiq MUST NOT construct, sign, or verify any LTI
`id_token`, JWT, or JWK itself — it forwards the placement reference and renders back whatever
launch response (auto-submitting form or URL) openconnector returns, treating it as opaque. The
outbound call MUST reuse the existing scholiq→openconnector authenticated-REST pattern
(`IClientService` + `IURLGenerator::getAbsoluteURL()` + an `IAppConfig` bearer token under the
same `scholiq.openconnector_api_token` key `DataExchangeRunHandler::callOpenConnector()` already
uses) rather than introducing a second cross-app authentication mechanism.

@e2e exclude Launch delegation is a thin outbound proxy with no LTI protocol logic in scholiq; contract covered by PHPUnit against a mocked openconnector response

#### Scenario: Opening an LTI lesson delegates the launch and renders the response opaquely

- **GIVEN** a Lesson with `contentType: lti` whose `contentRef` names a valid `LtiToolPlacement`
- **WHEN** a learner opens the lesson in `LessonPlayer`
- **THEN** the backend calls openconnector's launch-initiation endpoint with the placement's
  `openconnectorDeploymentId`
- **AND** the response (auto-submitting form or URL) is rendered without scholiq inspecting any
  LTI claim it carries

#### Scenario: The outbound call reuses the existing cross-app auth pattern, not a new one

- **GIVEN** the `scholiq.openconnector_api_token` app-config value is set
- **WHEN** `LtiToolPlacementController::launch()` calls openconnector
- **THEN** the request carries the same bearer-token header shape
  `DataExchangeRunHandler::callOpenConnector()` already sends
- **AND** no second, LTI-specific cross-app credential is introduced

### Requirement: Course declares an ECTS credit value

The `Course` object MUST support an `ectsCredits` field (nullable number, `minimum: 0`) declaring the
Bologna-style credit value the course/module contributes toward a learner's cumulative EC total. The field
MUST be additive — existing `Course` rows leave it `null` — and MUST NOT be required, since `po`/`vo`/
`corporate` courses (which do not participate in ECTS-bearing programmes) never need to set it. Any
consumer summing a learner's earned credits MUST treat a `null` `ectsCredits` as `0`, not as an error.

#### Scenario: A course declares its ECTS value

<!-- @e2e exclude Pure OpenRegister schema field; no scholiq DOM surface. Consumed by the study-progress capability's BsaProgressEvaluator, itself covered by PHPUnit as referenced in that spec. -->

- **GIVEN** an HBO/WO course being authored
- **WHEN** the instructional designer sets `ectsCredits` to a positive number
- **THEN** the value persists on the `Course` object
- **AND** it is available to any downstream credit-summing calculation (e.g. the `study-progress`
  capability's `BsaProgressEvaluator`)

#### Scenario: An existing course without a declared credit value defaults to zero for summation

<!-- @e2e exclude Null-handling verified by the study-progress capability's BsaProgressEvaluatorTest; no scholiq DOM surface here. -->

- **GIVEN** a pre-existing `Course` row with `ectsCredits` unset (`null`)
- **WHEN** a downstream calculation sums a learner's earned credits across their passed courses
- **THEN** that course contributes `0` EC to the total
- **AND** the calculation does not error

### Requirement: A Lesson's body is authored as an ordered list of typed content blocks

The system SHALL support authoring a `Lesson`'s body as `blocks`, an ordered array of typed content blocks,
each `{blockId, type, order}` plus exactly one payload matching `type`: `richText` (inline markdown text),
`media` (a pointer to an existing `Material` UUID — covers image, video, file attachment, and cmi5/SCORM
package reference blocks via `Material.kind`), `quiz` (a pointer to an existing `Assessment` UUID),
`assignment` (a pointer to an existing `Assignment` UUID), or `ltiTool` (a pointer to an existing
`LtiToolPlacement` UUID). `Lesson.contentType: text` denotes a native, block-composed lesson; `contentRef`
remains required for every other `contentType` value (`video`, `scorm12`, `scorm2004`, `cmi5`, `lti`,
`quiz`) exactly as before, but is NOT required when `contentType: text` and `blocks` is populated — no
existing packaged-content lesson's validation changes.

#### Scenario: An instructional designer composes a lesson from mixed blocks

- **GIVEN** a `Lesson` with `contentType: text`
- **WHEN** the instructional designer adds a `richText` block, a `media` block pointing at an existing
  `Material`, and a `quiz` block pointing at an existing `Assessment`, in that order
- **THEN** `Lesson.blocks` persists all three blocks with their `order` and type-specific payload
- **AND** `contentRef` is not required for this `Lesson`

<!-- @e2e tests/e2e/spec-coverage/course-authoring-ux.spec.ts -->

#### Scenario: A media block references an existing Material rather than duplicating file metadata

- **GIVEN** a `Material` already exists (`kind: video`, `fileRef` set) attached to the same `Lesson`
- **WHEN** the instructional designer adds a `media` block and selects that `Material`
- **THEN** the block persists only the `Material`'s UUID — no `fileRef`, `kind`, or file bytes are
  duplicated onto the block

<!-- @e2e tests/e2e/spec-coverage/course-authoring-ux.spec.ts -->

#### Scenario: Packaged-content lessons are unaffected

- **GIVEN** an existing `Lesson` with `contentType: cmi5` and `contentRef` set to a launch URL
- **WHEN** the `Lesson` is saved without any change
- **THEN** `contentRef` is still required and validation is unchanged — the conditional relaxation applies
  only to `contentType: text`

<!-- @e2e exclude Schema-level regression on an unrelated contentType; covered by PHPUnit schema validation tests (CourseAuthoringRegisterTest), no new browser journey -->

### Requirement: Lessons within a Course and blocks within a Lesson are reorderable by drag-and-drop and by keyboard

The system SHALL provide drag-and-drop reordering of `Lesson`s within a `Course` (writing `Lesson.order`)
and of blocks within a `Lesson` (writing each block's `order`), AND SHALL provide an equivalent
keyboard-operable reordering control (move up / move down) for both, so that reordering is never
drag-only. This satisfies WCAG 2.1 AA success criterion 2.1.1 (Keyboard) — a legal duty for an app serving
publicly funded Dutch schools (po/vo/mbo/hbo) under the Tijdelijk besluit digitale toegankelijkheid
overheid / EN 301 549, and a commitment `nextcloud-app`'s own spec already declares (WCAG 2.1 AA).

#### Scenario: A teacher reorders lessons within a course by drag-and-drop

- **GIVEN** a `Course` with three `Lesson`s in order 1, 2, 3
- **WHEN** the teacher drags the third lesson to the first position in `CourseBuilder`
- **THEN** the three `Lesson`s persist with `order` 1, 2, 3 reflecting the new sequence

<!-- @e2e tests/e2e/spec-coverage/course-authoring-ux.spec.ts -->

#### Scenario: A teacher reorders lessons within a course using only the keyboard

- **GIVEN** a `Course` with three `Lesson`s in order 1, 2, 3
- **WHEN** the teacher tabs to the third lesson's "Move up" control and activates it twice, using no
  pointer device
- **THEN** the same `order` mutation as the drag-and-drop scenario is persisted
- **AND** the move is announced to assistive technology (e.g. "Lesson moved to position 1 of 3")

<!-- @e2e tests/e2e/spec-coverage/course-authoring-ux.spec.ts -->

#### Scenario: A teacher reorders blocks within a lesson using only the keyboard

- **GIVEN** a `Lesson` with a `richText` block followed by a `quiz` block
- **WHEN** the teacher moves the `quiz` block up using its keyboard-operable control
- **THEN** `Lesson.blocks` persists the `quiz` block's `order` ahead of the `richText` block's `order`

<!-- @e2e tests/e2e/spec-coverage/course-authoring-ux.spec.ts -->

### Requirement: A Course declares its display order among sibling modules

The system SHALL support an `order` field (nullable integer) on `Course`, used to sequence sibling child
`Course`s ("modules") sharing the same `parentCourseId`. The field MUST be additive — existing `Course` rows
leave it `null` — and any UI listing sibling modules MUST treat `null` as sorting after every module with an
explicit `order` value (append-to-end), never as an error or as position zero.

#### Scenario: A designer sets module order in the course builder

- **GIVEN** a `Course` with two child modules, both `order: null`
- **WHEN** the designer arranges them in `CourseBuilder` and saves
- **THEN** both modules persist explicit, distinct `order` values reflecting the arrangement

<!-- @e2e tests/e2e/spec-coverage/course-authoring-ux.spec.ts -->

#### Scenario: A pre-existing module without an order value sorts last, not first

<!-- @e2e exclude Null-default sort behaviour is a pure list-rendering rule with no distinct browser journey beyond the drag-and-drop scenario already covered; verified by a component unit test on CourseBuilder's sort comparator (tests/unit-js/courseOrder.test.mjs) -->

- **GIVEN** two sibling modules, one with `order: 1` and one with `order: null`
- **WHEN** `CourseBuilder` renders the module list
- **THEN** the `order: 1` module is listed first and the `order: null` module is listed after it

### Requirement: A Course structure can be saved as a reusable template and instantiated

The system SHALL support a `CourseTemplate` object capturing a Course→Module→Lesson skeleton (module and
lesson names, order, `contentType`, and lightweight block placeholders — not live content references or
learner data) plus an optional `CurriculumPlan` skeleton (`kind`, `formula`, `components`, `periods`,
`passRules`, in the same shape `CurriculumPlan` itself already uses), captured either from an existing
`Course` ("Save as template") or authored from scratch, and instantiated into a new, independent `Course`
tree (and, when the skeleton is present, a new `CurriculumPlan`) that shares no object references with the
source.

#### Scenario: An instructional designer saves a published course as a template

- **GIVEN** a published `Course` with two modules and several lessons
- **WHEN** the designer chooses "Save as template" in `CourseBuilder`
- **THEN** a `CourseTemplate` is created capturing the module/lesson names, order, and content types
- **AND** the source `Course` and its `Lesson`s are unchanged

<!-- @e2e tests/e2e/spec-coverage/course-authoring-ux.spec.ts -->

#### Scenario: Instantiating a template creates a fresh, independent course tree

- **GIVEN** a `CourseTemplate` with two modules and three lessons across them
- **WHEN** the designer instantiates it as a new course
- **THEN** a new `Course` in `lifecycle: draft` is created, with new child `Course`s and `Lesson`s matching
  the template's structure, each with a freshly generated UUID
- **AND** the new `Course` has zero enrolments — fulfilling the "Clone for next year" acceptance criterion
  this spec declared above

<!-- @e2e tests/e2e/spec-coverage/course-authoring-ux.spec.ts -->

### Requirement: LessonPlayer renders a Lesson's authored blocks

When `Lesson.contentType` is `text`, `LessonPlayer.vue` SHALL render `lesson.blocks` in `order`, dispatching
each block to a renderer by `type`: `richText` renders its markdown as sanitised HTML, `media` resolves and
renders the referenced `Material` (image/video/file/link per `Material.kind`), `quiz` embeds the referenced
`Assessment`'s take-flow, `assignment` renders a summary card linking to the referenced `Assignment`, and
`ltiTool` triggers the same opaque launch-delegation flow the existing `contentType: lti` branch already
uses, scoped to that one block rather than the whole lesson.

#### Scenario: A learner opens a native lesson and sees its composed blocks in order

- **GIVEN** a `Lesson` with `contentType: text` and three blocks (`richText`, `media`, `quiz`) in that order
- **WHEN** a learner opens the lesson in `LessonPlayer`
- **THEN** the three blocks render in their persisted order, each via its type-specific renderer

<!-- @e2e tests/e2e/spec-coverage/course-authoring-ux.spec.ts -->

### Requirement: Course and Lesson declare which competencies they teach

The `Course` object MUST support a `competencyIds` field (array of `format: uuid` `$ref: Competency`,
default `[]`) declaring which competencies (from the `competency` capability's taxonomy) this course
teaches, and the `Lesson` object MUST support the same field at lesson granularity. Both fields MUST be
additive — existing `Course`/`Lesson` rows leave `competencyIds` as an empty array — and MUST NOT be
required. `Lesson.learningObjectives` (the existing free-text `string[]`) MUST remain unchanged and
continue to accept free text; its description gains a note pointing authors at `competencyIds` for
anything that needs to roll up into a learner's `CompetencyAttainment` or be queried structurally, since
`learningObjectives` itself is not linked to the taxonomy and never rolls up.

#### Scenario: A course declares the competencies it teaches

<!-- @e2e exclude Pure OpenRegister schema field; no scholiq DOM surface beyond the declarative manifest pages the course-management spec already covers. Consumed by the competency capability's alignment view. -->

- **GIVEN** a `Course` being authored
- **WHEN** the instructional designer sets `competencyIds` to one or more `Competency` UUIDs
- **THEN** the values persist on the `Course` object
- **AND** they are queryable by the `competency` capability's alignment and skills-gap views

#### Scenario: An existing course or lesson without declared competencies is unaffected

<!-- @e2e exclude Additive-field default-value handling; no DOM surface. -->

- **GIVEN** a pre-existing `Course` or `Lesson` row with no `competencyIds` set
- **WHEN** it is read
- **THEN** `competencyIds` resolves to an empty array and the row behaves exactly as it did before this
  change

### Requirement: Course declares prerequisite courses via a relation, not a separate `Prerequisite` entity

The `Course` object MUST support a `prerequisiteCourseIds` field: an array of `$ref Course` UUIDs
(additive, default `[]`), naming the courses a learner MUST hold a `completed` `Enrolment` for before they
may enrol in this course. This corrects the Data Model claim elsewhere in this spec that a separate
`Prerequisite` OpenRegister entity exists — no such schema exists, was ever built, or is being introduced by
this requirement; the relation is a plain array-of-`$ref` field on `Course`, structurally identical to the
existing `CurriculumPlan.requiredCourseIds`/`electiveCourseIds` and `Course.programmeIds` fields. The field
MUST NOT be required — existing `Course` rows leave it `[]`/absent, and courses with no prerequisites are
unaffected. Enforcement (blocking enrolment when a prerequisite is unmet) is specified in the `enrolment`
capability's "Validate prerequisites before persistence" requirement, not here — this requirement covers
only the relation's existence and shape.

#### Scenario: A course declares one or more prerequisite courses

<!-- @e2e exclude Pure OpenRegister schema field; no scholiq DOM surface for declaring the relation itself. Consumed by the enrolment capability's EnrolmentPrerequisiteListener, covered by PHPUnit as referenced in that spec. -->

- **GIVEN** an instructional designer authoring a course
- **WHEN** they set `prerequisiteCourseIds` to one or more existing `Course` UUIDs
- **THEN** the value persists on the `Course` object
- **AND** it is available to the `enrolment` capability's prerequisite check

#### Scenario: A course with no declared prerequisites is unaffected

<!-- @e2e exclude Pure OpenRegister schema field; null-handling verified by PHPUnit against EnrolmentPrerequisiteListener. -->

- **GIVEN** a pre-existing `Course` row with `prerequisiteCourseIds` unset (`[]`/absent)
- **WHEN** a learner attempts to enrol
- **THEN** no prerequisite check blocks the enrolment

### Requirement: Lesson declares per-learner release conditions

The `Lesson` object MUST support a `releaseConditions` field: an array of condition objects, each with a
`kind` (`lesson-completed` | `assessment-min-score`), and — depending on `kind` — a `lessonId` (`$ref
Lesson`), an `assessmentId` (`$ref Assessment`, cross-referencing the `assessment` capability's schema),
and/or a `minScore` (number). The field MUST be additive (default `[]`) and AND-combined: a `Lesson` is
available to a learner only when every listed condition is satisfied for that learner. A `lesson-completed`
condition MUST be satisfied by the existence of an `XapiStatement` for the referenced `Lesson` whose
`verified_actor_id` matches the learner and whose `verb` indicates completion or passing. An
`assessment-min-score` condition MUST be satisfied per the `assessment` capability's equivalent requirement
on `Assessment.releaseConditions`. Evaluation MUST happen per-learner at request time via the shared
`LessonReleaseEvaluator` service — it MUST NOT be materialised as a schema-level calculation, because
availability differs per learner while the `Lesson` row is shared across every learner enrolled in the
course. A `Lesson` with an empty/absent `releaseConditions` array is available to every enrolled learner as
soon as it is published, matching today's behaviour. When a learner opens a `Lesson` in `LessonPlayer.vue`
regardless of its `contentType` (`text`, `video`, `scorm12`, `scorm2004`, `cmi5`, `lti`, `quiz`), the system
MUST evaluate `releaseConditions` before rendering the lesson's content and render a locked state naming
the unmet condition when unavailable.

#### Scenario: A lesson is unavailable until its prerequisite lesson is completed

- **GIVEN** a `Lesson` B with `releaseConditions: [{kind: "lesson-completed", lessonId: <Lesson A's id>}]`
- **AND** a learner enrolled in the course who has not completed Lesson A
- **WHEN** the learner opens Lesson B in `LessonPlayer`
- **THEN** the system renders a locked state naming Lesson A as the unmet condition instead of the lesson
  content

<!-- @e2e tests/e2e/spec-coverage/adaptive-release.spec.ts#lesson-locked-until-prerequisite-lesson-completed -->

#### Scenario: A lesson unlocks once its prerequisite lesson is completed

- **GIVEN** the same `Lesson` B from the scenario above
- **AND** the learner now holds a completion `XapiStatement` for Lesson A
- **WHEN** the learner opens Lesson B in `LessonPlayer`
- **THEN** the lesson content renders normally

<!-- @e2e tests/e2e/spec-coverage/adaptive-release.spec.ts#lesson-unlocks-once-prerequisite-lesson-completed -->

### Requirement: Lesson supports drip release relative to each learner's own enrolment date

The `Lesson` object MUST support an `availableAfterDays` field: a nullable, non-negative integer declaring
the number of days after the learner's OWN `Enrolment.created` timestamp (for the lesson's course) before
the lesson becomes available to that learner. `availableAfterDays` MUST NOT be materialised as a
schema-level calculated field — the resolved per-learner instant (`enrolment.created + N days`) differs per
learner sharing the same `Lesson` row, so only the static duration is stored on the schema; the per-learner
resolution happens in `LessonReleaseEvaluator` at request time, reading the requesting learner's own
`Enrolment`. When set, this gate applies in addition to any `releaseConditions` — a `Lesson` is available
to a learner only once both are satisfied.

#### Scenario: A lesson is locked until N days after the learner's own enrolment date

- **GIVEN** a `Lesson` with `availableAfterDays: 7`
- **AND** a learner whose `Enrolment` for the course was created 3 days ago
- **WHEN** the learner opens the lesson in `LessonPlayer`
- **THEN** the system renders a locked state showing the date it becomes available (4 days from now)

<!-- @e2e tests/e2e/spec-coverage/adaptive-release.spec.ts#lesson-locked-until-drip-delay-elapses -->

#### Scenario: Two learners with different enrolment dates see different unlock dates for the same lesson

<!-- @e2e exclude Per-learner date arithmetic verified by PHPUnit against LessonReleaseEvaluator; the single-learner locked-state rendering path is already covered by the scenario above. -->

- **GIVEN** a `Lesson` with `availableAfterDays: 7`
- **AND** learner A enrolled 10 days ago and learner B enrolled 1 day ago
- **WHEN** `LessonReleaseEvaluator` evaluates availability for each
- **THEN** the lesson is available to learner A and unavailable to learner B, from the same `Lesson` row

### Requirement: Import a Common Cartridge or Moodle course package into the Course/Lesson/Material hierarchy

The system MUST support importing an IMS Common Cartridge 1.3 package or a Moodle backup (`.mbz`) archive via
a `CoursePackageImportService` (ADR-031 "External-format import" exception, mirroring `QtiImportService`).
The importer MUST walk the package's manifest (`imsmanifest.xml` for Common Cartridge; `moodle_backup.xml`
for Moodle) and materialise its organization tree as `Course`/`Lesson` objects (a folder-level organization
node becomes a child `Course` via `parentCourseId`, a leaf item becomes a `Lesson`), its web content and
weblink resources as `Material` objects, and MUST delegate any embedded QTI or Common-Cartridge-format
assessment items to the existing item-import machinery (`QtiImportService::importFromDirectory()`) rather
than re-implementing item parsing. `LtiToolPlacement` objects MUST be created for embedded LTI resources,
reusing the placement shape the "Place an LTI 1.3 tool inside a lesson" requirement already defines. The
importer MUST NOT implement any wire protocol — parsing an uploaded archive is a one-shot file transform, not
a conversation with a live external system (see `design.md` "Routing: scholiq, not openconnector").

@e2e exclude Package parsing and object-graph creation is backend logic verified by PHPUnit against fixture
archives; no scholiq DOM surface for the parse itself (the resulting report and course ARE drivable — see the
"names every resource's outcome" and frontend requirements below).

#### Scenario: A Common Cartridge package materialises its course structure

- **GIVEN** a valid IMS Common Cartridge 1.3 archive with an organization tree, web content resources, and
  embedded QTI assessment items
- **WHEN** an authorised user imports the package
- **THEN** the system creates a `Course` (and child `Course`s for nested organization folders), `Lesson`
  objects in manifest order, `Material` objects for the web content resources, and `Item`/`ItemBank` objects
  for the embedded QTI content via the existing item-import machinery

#### Scenario: A Moodle backup materialises the same structural shapes

- **GIVEN** a valid Moodle `.mbz` archive with sections, modules, and a quiz module using single-answer
  questions
- **WHEN** an authorised user imports the package
- **THEN** the system creates the equivalent `Course`/`Lesson`/`Material`/`Item` objects from the Moodle
  section/module structure and the supported quiz-question subset

#### Scenario: An LTI resource becomes a placement, not an inline link

- **GIVEN** a Common Cartridge package containing a `basiclti` resource
- **WHEN** the package is imported
- **THEN** an `LtiToolPlacement` object is created and the corresponding `Lesson.contentType` is set to `lti`
  with `contentRef` naming the placement, matching the shape a manually-placed LTI tool would have

### Requirement: Every course-package import produces a CoursePackageImportReport naming every resource's outcome

The system MUST persist a `CoursePackageImportReport` OpenRegister object for every import attempt, carrying
`sourceFormat`, `sourceFilename`, `courseId` (nullable until a `Course` exists), `lifecycle`
(`running → succeeded | partial | failed`), summary counts (`resourcesTotal`, `resourcesImported`,
`resourcesDegraded`, `resourcesDropped`), and an `entries` array with one row per source-package resource:
`resourceIdentifier`, `resourceType`, `title`, `outcome` (`imported` | `degraded` | `dropped`), `targetType`,
`targetId`, and a human-readable `reason`. The system MUST NOT omit a resource from `entries` for any reason
— a resource type the importer does not support MUST still produce a `dropped` entry naming why, never a
silent absence. `lifecycle` MUST resolve to `succeeded` only when zero entries are `degraded` or `dropped`;
any package with at least one non-`imported` entry MUST resolve to `partial`, which is a normal, non-error
terminal state.

#### Scenario: A package with unsupported content still reports every resource

<!-- @e2e exclude Report-content correctness (one entry per source resource, zero omissions) is a data
     invariant verified by PHPUnit against fixture archives with a known resource count; the rendered report
     IS drivable — see the frontend requirement below for the DOM-facing scenario. -->

- **GIVEN** a Common Cartridge package containing a discussion-topic resource (no scholiq schema represents
  discussions)
- **WHEN** the package is imported
- **THEN** the `CoursePackageImportReport` contains an entry for that resource with `outcome: dropped` and a
  `reason` naming why, and the report's `lifecycle` resolves to `partial`, not `succeeded`

#### Scenario: A fully-supported package resolves to succeeded

- **GIVEN** a Common Cartridge package containing only resource types this importer fully supports
- **WHEN** the package is imported
- **THEN** every entry has `outcome: imported`, `resourcesDegraded` and `resourcesDropped` are both `0`, and
  the report's `lifecycle` resolves to `succeeded`

#### Scenario: A corrupt or unrecognised archive fails loudly, not silently

<!-- @e2e exclude Error-path verified by PHPUnit against a deliberately corrupt fixture archive; no DOM
     surface for the parse failure itself beyond the report the frontend requirement below covers. -->

- **GIVEN** an archive that is not a valid ZIP/gzipped-tar or has no recognisable manifest
- **WHEN** an import is attempted
- **THEN** the `CoursePackageImportReport` resolves to `lifecycle: failed` with a non-empty `errorMessage`,
  `courseId` remains `null`, and no partial `Course`/`Lesson`/`Material` objects are left behind

### Requirement: Export a full course as Common Cartridge and scholiq-native JSON with resolved file attachments

The system MUST support exporting a `Course` (and its `Lesson`/`Material`/`Item`/`Rubric`/`LtiToolPlacement`
descendants) as (a) an IMS Common Cartridge 1.3 package for interoperability with other LMS platforms and (b)
a scholiq-native JSON tree for lossless round-trip back into Scholiq, via a `CoursePackageExportController`
mirroring the existing `AuditPackExportController`'s in-memory-ZIP streaming pattern. `Material.fileRef`
bytes MUST be resolved through OpenRegister's native file-attachment API and included in the exported
package — the export MUST NOT reference file paths the recipient cannot resolve. Embedded assessment items
MUST be exported in QTI 3.0 form via the `assessment` capability's item-export capability, not re-serialised
by the course exporter. Export MUST respect the exporting user's own read authorization: a field an
OpenRegister `x-property-rbac` rule would hide from that user in the UI MUST NOT appear in the export.

#### Scenario: Exporting a course produces a portable Common Cartridge package

- **GIVEN** a `Course` with `Lesson`s, `Material`s (including file-backed materials), and an `Assessment` with
  `Item`s
- **WHEN** an authorised user requests a Common Cartridge export
- **THEN** the system streams a ZIP containing an `imsmanifest.xml` organization tree, the resolved material
  file bytes, and the assessment items in QTI 3.0 form

#### Scenario: Exporting a course produces a lossless scholiq-native JSON tree

- **GIVEN** the same `Course` as above
- **WHEN** an authorised user requests a scholiq-native export
- **THEN** the system streams a JSON document that, when re-imported into a Scholiq tenant, reproduces the
  same `Course`/`Lesson`/`Material`/`Item`/`Rubric` object graph

#### Scenario: Export never leaks a field the exporting user cannot already see

<!-- @e2e exclude RBAC-boundary verification is backend authorization logic tested by PHPUnit against a
     user without a privileged role, mirroring FinalGrade's existing x-property-rbac PHPUnit coverage; no
     DOM surface distinguishes "field present" from "field correctly redacted" without inspecting the raw
     response body, which PHPUnit does directly. -->

- **GIVEN** a `Course` containing a `GradeEntry`-adjacent field an unprivileged exporting user is not
  authorized to read
- **WHEN** that user requests an export
- **THEN** the exported package does not contain the restricted field, matching what OpenRegister's own
  object-read API would have returned to that user

### Requirement: Course-package frontend is declarative with one named custom view for the import report

The frontend MUST be declarative: `src/manifest.json` index/detail pages for `CoursePackageImportReport`. The
only custom Vue component MUST be `CoursePackageImportView` — an upload surface that submits the package and
then renders the resulting `CoursePackageImportReport`'s `entries` table (filterable by `outcome`) so an
instructional designer sees every imported, degraded, and dropped resource in one place. No PHP CRUD
controller — `CoursePackageImportController`/`CoursePackageExportController` are thin per ADR-022, delegating
all parsing/generation to `CoursePackageImportService`/`CoursePackageExportService`.

<!-- @e2e exclude tests/e2e/spec-coverage/course-package-import-export.spec.ts was never created during this change's apply pass (tasks.md task 7.2 was left unchecked with no other file covering this flow) — verify-archive correction 2026-07-16: the file did not exist in the working tree, so the original @e2e reference was a false coverage claim. The upload/report/filter flow is exercised by CoursePackageImportServiceTest.php at the PHPUnit level (fixture-archive imports asserting entries/outcome counts); live-browser e2e coverage for CoursePackageImportView.vue remains outstanding follow-up work. -->

#### Scenario: An instructional designer uploads a package and sees the report

- **GIVEN** `CoursePackageImportView`
- **WHEN** an instructional designer uploads a course package
- **THEN** the resulting `CoursePackageImportReport` renders with its `entries` table, and the designer can
  filter it to `degraded`/`dropped` rows to see exactly what needs manual attention

### Requirement: A course leaves the school only through the sharing gate
The system MUST offer a share export, separate from the regular export, that refuses with the full list of reasons unless all of these hold: the course has a `license` that is an open Creative Commons licence or CC0; the course has an `author`; no lesson has its own non-open `license`; no material has a non-empty `license` outside the open set; the exporting user confirmed `noPupilData`; the exporting user confirmed `rightsCleared`. A lesson without its own licence MUST be judged by the course licence.

#### Scenario: A course with no licence is refused
- **GIVEN** a course with an author and no `license`
- **WHEN** a teacher requests a share export with both confirmations
- **THEN** the response is 422 and `blockers` holds `licence-missing`

#### Scenario: All rights reserved is refused
- **GIVEN** a course with `license: "all-rights-reserved"`
- **WHEN** a share export is requested
- **THEN** `blockers` holds `licence-not-open`

#### Scenario: A publisher-licensed material blocks the course
- **GIVEN** an openly licensed course with one material whose `license` is `© Uitgeverij Voorbeeld`
- **WHEN** a share export is requested
- **THEN** `blockers` holds `material-licence-not-open` naming that material

#### Scenario: Missing confirmations are named
- **GIVEN** an openly licensed course with an author
- **WHEN** a share export is requested without `noPupilData` and without `rightsCleared`
- **THEN** `blockers` holds `pupil-data-not-confirmed` and `rights-not-confirmed`, and no consent record is written

### Requirement: A share package carries no school-bound or personal fields
The share package MUST be learniq JSON without `@self`, `tenant_id`, material `fileRef`, `sessionId`, `cohortId`, `curriculumPlanId`, `curriculumPlanComponentId`, `programmeIds`, `gradeEntryComponentId`, `gradeScaleId` or an assessment's `accessCode`, and with an empty LTI placement list. It MUST add a `sharing` block with the licence, author, subject, education levels, language, goals covered and the share date, and MUST NOT name the Nextcloud user who confirmed.

#### Scenario: An assessment access code does not travel
- **GIVEN** an assessment with `accessCode: "KLAS3B"` and `cohortId` set
- **WHEN** its course is share-exported
- **THEN** the package's assessment has neither `accessCode` nor `cohortId`

### Requirement: Every share export leaves a consent record
A successful share export MUST write a `CourseShareConsent` with the course, the course name, purpose `download`, the confirming user, the time, both confirmations and the licence. If the record cannot be written, the export MUST fail. `CourseShareConsent` MUST be readable by `instructors`, `team-leads`, `coordinators`, `administration-managers`, `compliance-officers` and the confirming user, creatable by the four course-authoring groups, and updatable by `administration-managers` only.

#### Scenario: A school leader sees who shared what
- **GIVEN** teacher `docent-07` share-exported course "Nederlands havo 4"
- **WHEN** a user in `administration-managers` lists course share consents
- **THEN** a record shows `docent-07`, the time, both confirmations and `CC-BY-SA-4.0`

### Requirement: The export page offers sharing with the confirmations
The course package export page MUST offer a "Share outside the school" switch that shows the two confirmations, sends the share request, and lists the refusal reasons in the user's language.

#### Scenario: A teacher sees why sharing is refused
- **GIVEN** the switch is on and the course has no author
- **WHEN** the teacher submits
- **THEN** the page lists "Name the author on the course, so others can credit them."

### Requirement: The Store page lists shared courses through the store plane
Learniq MUST declare a `StoreDescriptor` for schema `shared-course-package` (default register `learniq`) and MUST search and resolve shared courses only through OpenRegister's `GenericStoreService`. `GET /api/store/items` MUST answer `{outcome, cards, kinds, builtIn}`; each card MUST carry `slug`, `title`, `description`, `subject`, `level`, `goals`, `language`, `license`, `author`, `version`, `typeName` and `kind`, and nothing else. With no registry configured it MUST answer `not_configured` without a network call.

#### Scenario: No registry configured
- **GIVEN** `registry_url` is empty for learniq
- **WHEN** a signed-in user opens the Store page
- **THEN** the response outcome is `not_configured`, the card list is empty, and no request left the server

#### Scenario: A teacher finds a shared course
- **GIVEN** a configured registry holding a shared package "Betoog schrijven, havo 4" licensed CC BY-SA 4.0
- **WHEN** the teacher searches "betoog"
- **THEN** a card shows the title, `Nederlandse taal · havo · CC-BY-SA-4.0` under it, and the author

#### Scenario: An anonymous request is refused
- **GIVEN** no session
- **WHEN** `GET /api/store/items` is called
- **THEN** the response is 401

### Requirement: Installing a shared course creates an independent copy that keeps the credit
`POST /api/store/items/{slug}/install` MUST require the `course-package.import` action, MUST refuse a malformed slug with 400 and an unresolvable one with 404, and MUST import the resolved package through `CoursePackageImportService` as learniq JSON. The import MUST create new objects and MUST carry the course `license`, `author`, `subject`, `educationalLevels`, `language`, `level` and `description` onto the new course when their values are valid. The response MUST list each imported resource with status `installed`, `degraded` or `refused`.

#### Scenario: A shared course is installed as a copy
- **GIVEN** a resolvable shared package whose course is licensed CC BY-SA 4.0 by "Sectie Nederlands, OSG De Vaart"
- **WHEN** an administrator installs it
- **THEN** a new draft course exists with that licence and author, a new course code, and its own lessons

#### Scenario: A package without a package body is refused
- **GIVEN** a registry object with no `package`
- **WHEN** it is installed
- **THEN** the response reports failure and nothing is written

### Requirement: Publishing sends a gated package to the registry
`POST /api/store/publish` MUST require the `course-package.share` action and MUST run the sharing gate with the two confirmations; a refusal MUST be 422 with `blockers`. On a pass it MUST record a `CourseShareConsent` with purpose `store`, then POST the registry object to `<registry_url>/index.php/apps/openregister/api/objects/<register>/shared-course-package` with the registry token as a Bearer header only, after `SecurityService::assertSafeFetchUrl()`, with redirects refused and 10 second timeouts. A package over 20 MB MUST be refused without a request.

#### Scenario: Publishing without a registry
- **GIVEN** the gate passes and `registry_url` is empty
- **WHEN** the teacher publishes
- **THEN** the outcome is `not_configured` and no request is made

#### Scenario: A private registry address is refused
- **GIVEN** `registry_url` is `http://192.168.1.10`
- **WHEN** the teacher publishes
- **THEN** the outcome is `store_unreachable` and no request is made

#### Scenario: A published course is findable
- **GIVEN** the gate passes and the registry accepts the POST
- **WHEN** the teacher publishes "Betoog schrijven, havo 4"
- **THEN** the response carries outcome `ok` and a slug starting with `course-package-`

### Requirement: Any learniq instance can act as the registry
The register MUST declare `SharedCoursePackage` (slug `shared-course-package`) holding the card fields as strings, `levels` and `goalsCovered` as arrays, `kind` `course-package`, `sharedAt` and the `package` object. None of the fields the publisher cannot fill (such as `tenant_id`) MAY be required. Read MUST be `authenticated`; create MUST be `instructors`, `team-leads`, `coordinators` and `administration-managers`; update MUST be `administration-managers`.

#### Scenario: A school board runs the registry
- **GIVEN** a learniq instance whose admin created a service account in `instructors` and handed its token to member schools
- **WHEN** a member school publishes
- **THEN** the package is stored as a `SharedCoursePackage` on the board's instance and every member school's store lists it

### Requirement: Courses and lessons carry sharing metadata aligned to NL-LOM
`Course` and `Lesson` MUST declare four optional properties: `license` (one of `CC0-1.0`, `CC-BY-4.0`, `CC-BY-SA-4.0`, `CC-BY-NC-4.0`, `CC-BY-NC-SA-4.0`, `CC-BY-ND-4.0`, `CC-BY-NC-ND-4.0`, `all-rights-reserved`), `author` (the name to credit), `subject` (the subject name as the Onderwijsbegrippenkader (OBK) gives it) and `educationalLevels` (an array of `po`, `so`, `vmbo`, `havo`, `vwo`, `mbo-1`, `mbo-2`, `mbo-3`, `mbo-4`, `hbo`, `wo`, `adult-education`, `professional-training`). None of them MAY be required, and `license` MUST have no default.

#### Scenario: A teacher marks a course as openly licensed
- **GIVEN** a `Course` in draft
- **WHEN** the teacher sets `license: "CC-BY-SA-4.0"`, `author: "Sectie wiskunde, OSG De Vaart"`, `subject: "Rekenen/wiskunde"` and `educationalLevels: ["havo", "vwo"]`
- **THEN** the course validates and all four values persist

#### Scenario: An existing course without metadata stays valid
- **GIVEN** a `Course` stored before this change
- **WHEN** it is read and saved again
- **THEN** it validates with the four properties absent

#### Scenario: A lesson without a licence falls back to its course
- **GIVEN** a `Lesson` with no `license` in a `Course` with `license: "CC-BY-4.0"`
- **WHEN** a consumer resolves the lesson's licence
- **THEN** it uses `CC-BY-4.0`, as the lesson `license` description states

### Requirement: Licence and level values have translated labels
`license` and `educationalLevels.items` MUST declare `x-enum-labels` for every value, and every label MUST have an English key and a Dutch value in the catalogue.

#### Scenario: A Dutch teacher picks a licence
- **GIVEN** a Dutch-language user opens the course form
- **WHEN** the licence field renders
- **THEN** `all-rights-reserved` shows as "Alle rechten voorbehouden"

### Requirement: The lesson composer offers four AI assist actions through hermiq, only when hermiq can answer

<!-- @e2e exclude The actions need hermiq PR 962 merged and a DPO-acknowledged feature on a live instance, neither exists yet. The decision logic (visibility, payloads, outcome classes) is covered by tests/unit-js/lessonAssist.test.mjs. -->

`LessonComposer` MUST offer four assist actions: draft an outline from one or more goals, suggest questions
for the lesson, rewrite one rich text block at a lower reading level, and suggest which goals the lesson
covers. Each action MUST call hermiq's `lesson-authoring` delegate
(`POST /apps/hermiq/api/lesson-authoring/{outline|questions|simplify|goal-suggestions}`) and MUST NOT call
any model or vendor directly. The actions MUST NOT render when the hermiq app is not enabled for the user,
read from `window.OC.appswebroots.hermiq` exactly as `LearniqSettings.vue` reads it, so learniq keeps no hard
dependency on hermiq. When hermiq answers `available: false` with `reason: feature-not-enabled`, or the route
answers 404, the actions MUST collapse to a note saying AI help is switched off and MUST stay hidden for the
rest of the browser session. A `provider-error`, a 429 or any other failure MUST show a plain message and
MUST leave the lesson and the actions unchanged.

#### Scenario: A teacher without hermiq sees no assist actions

- **GIVEN** a Nextcloud instance where the hermiq app is not enabled
- **WHEN** a teacher opens `LessonComposer` for a lesson
- **THEN** no assist panel and no "rewrite simpler" block action render
- **AND** no request is sent to `/apps/hermiq/`

#### Scenario: Hermiq answers that the feature is switched off

- **GIVEN** hermiq is enabled but its `lesson-authoring` AI feature is `disabled`
- **WHEN** the teacher runs "Suggest questions"
- **THEN** hermiq answers `{available: false, reason: "feature-not-enabled"}`
- **AND** the panel collapses to a note that AI help is switched off
- **AND** no block is added to the lesson

#### Scenario: The model gives no usable answer

- **GIVEN** hermiq's feature is enabled and the model call fails
- **WHEN** the teacher runs "Draft an outline"
- **THEN** hermiq answers `{available: false, reason: "provider-error"}`
- **AND** the panel shows that the AI model gave no usable answer
- **AND** the actions stay available for another try

### Requirement: Assist requests carry lesson content and goal titles only

<!-- @e2e exclude Request shape is a pure function of the lesson state; asserted by tests/unit-js/lessonAssist.test.mjs ("request bodies carry only the contract fields", "goal ids never leave learniq"). -->

Every assist request body MUST be built from an allowlist per action and MUST carry only `lessonText`,
`goalTitles`, `language`, `questionCount` and `readingLevel` as hermiq's contract defines them for that
action. It MUST NOT carry a pupil name, number, grade, note, any other pupil data, or any object id. Goal ids
MUST be mapped to their titles before the call, and the `index` values hermiq returns MUST be mapped back to
goal ids after it; an index outside the list sent MUST be ignored. `lessonText` MUST be the rich text of the
lesson's blocks that are not pending drafts, cut to 20,000 characters; `goalTitles` MUST hold at most 100
titles of at most 300 characters each. Next to the actions the panel MUST say that the text goes to an AI
model and must not contain pupil data, and before the first call in a browser the teacher MUST confirm a
notice that says the same.

#### Scenario: Goal suggestions map back to goal ids

- **GIVEN** a lesson whose course links three goals, sent in the order A, B, C
- **WHEN** hermiq answers `suggestedGoals: [{index: 0}, {index: 2}, {index: 7}]`
- **THEN** learniq offers goals A and C as suggestions
- **AND** index 7 is ignored
- **AND** the request body held the three titles and no goal id

#### Scenario: The first assist call asks for confirmation

- **GIVEN** a teacher who has never confirmed the AI notice in this browser
- **WHEN** they run any assist action
- **THEN** a dialog says the lesson text goes to an AI model and must not contain pupil data
- **AND** the request is sent only after they choose to continue
- **AND** a later action in the same browser runs without the dialog

### Requirement: Every assist result is a draft the teacher keeps or discards

<!-- @e2e exclude Needs a live hermiq answer; the draft insertion and the save guard are covered by tests/unit-js/lessonAssist.test.mjs ("draft blocks are marked and never serialised with the marker", "pending drafts block save"). -->

An outline, a question list or a rewrite MUST be inserted as a `richText` block marked as an AI draft: an
outline or question list at the end of the lesson, a rewrite directly after the block it came from. A draft
block MUST show that it is an AI draft, MUST name the model service hermiq reports in `provider`, and MUST
offer "Keep" and "Discard". Keeping MUST turn it into an ordinary `richText` block; discarding MUST remove it.
The lesson MUST NOT be saved while any draft is pending, and the draft marker MUST never be written to
`Lesson.blocks`. A goal suggestion MUST be added to `Lesson.competencyIds` only when the teacher adds it, and
the addition MUST be saved with the next "Save lesson". No assist result MUST change `Lesson.lifecycle`.

#### Scenario: A teacher keeps an AI outline

- **GIVEN** hermiq returns an outline for the goal "De leerling kan breuken vergelijken en ordenen"
- **WHEN** the draft block appears at the end of the lesson labelled as an AI draft with its model service
- **AND** the teacher edits it and chooses "Keep"
- **THEN** the block becomes an ordinary rich text block
- **AND** "Save lesson" writes it to `Lesson.blocks` without any draft marker

#### Scenario: A pending draft blocks the save

- **GIVEN** a lesson with one AI draft block not yet kept or discarded
- **WHEN** the teacher chooses "Save lesson"
- **THEN** nothing is sent to OpenRegister
- **AND** the composer asks the teacher to keep or discard the AI drafts first

#### Scenario: A teacher adds a suggested goal

- **GIVEN** goal suggestions list a goal the lesson does not yet link
- **WHEN** the teacher adds it and saves the lesson
- **THEN** `Lesson.competencyIds` holds that goal's id
- **AND** `Lesson.lifecycle` is unchanged

### Requirement: Any teacher installs a shared course as a copy
Installing from the course store MUST require the ADR-023 action `course-store.install`, seeded `["admin", "instructors", "team-leads"]`, and MUST NOT require `course-package.import`, which stays the right to import Canvas and Moodle packages. The seed MUST name only groups that may create the objects an install writes (course, lesson, material). FEATURES tier: should (sharing, D27).

#### Scenario: A teacher installs a shared course
- **GIVEN** a user in the `instructors` group only, and a configured registry holding "Betoog schrijven, havo 4"
- **WHEN** the user installs it from the Store
- **THEN** the install passes the action check and the course is imported as the user's own copy

#### Scenario: The package import stays with administrators
- **GIVEN** the same teacher
- **WHEN** the teacher uploads a Moodle backup on the import page
- **THEN** the request is refused, because `course-package.import` still names only `admin`

### Requirement: Publishing to the store defaults to team leads
The ADR-023 action `course-package.share` MUST be seeded `["admin", "team-leads"]`, and the store's publish groups MUST be read from that row (store-publish-through-plane).

#### Scenario: A team lead publishes
- **GIVEN** a user in `team-leads` and a course that passes the sharing gate
- **WHEN** the user publishes it to the store
- **THEN** the matrix and the plane both admit the user

#### Scenario: A teacher cannot publish
- **GIVEN** a user in `instructors` only
- **WHEN** the user calls `POST /api/store/publish`
- **THEN** the response is 403 and nothing leaves the school

### Requirement: Existing installs get the new store defaults once
An upgrade MUST apply the store defaults to an existing matrix exactly once: it MUST add `course-store.install` with its seed groups when the matrix has no such row, and MUST replace `course-package.share` with its seed groups only when that row is exactly `["admin"]`. It MUST record that it ran and MUST NOT change either row on a later upgrade. A fresh install MUST get the defaults from the seed.

#### Scenario: An untouched instance upgrades
- **GIVEN** a matrix where `course-package.share` is `["admin"]` and `course-store.install` is absent
- **WHEN** the upgrade runs
- **THEN** `course-store.install` is `["admin", "instructors", "team-leads"]` and `course-package.share` is `["admin", "team-leads"]`

#### Scenario: An administrator's choice survives
- **GIVEN** the step ran once, and the administrator then set `course-package.share` back to `["admin"]`
- **WHEN** a later upgrade runs
- **THEN** `course-package.share` stays `["admin"]`

#### Scenario: A customised row is left alone
- **GIVEN** a matrix where `course-package.share` is `["admin", "coordinators"]`
- **WHEN** the upgrade runs for the first time
- **THEN** `course-package.share` stays `["admin", "coordinators"]`

### Requirement: The Store page shows each user the actions they may take
The app MUST provide initial state `storeAccess` as `{install, publish}` for the signed-in user: `install` is whether the matrix admits `course-store.install`; `publish` is whether the matrix admits `course-package.share`, the installed OpenRegister can publish, and the store plane admits the user. The app MUST pass `canInstall` and `canPublish` from that state to every `type: "store"` page, and the Store page MUST name `publishRoute: "CoursePackageExport"`. The export screen MUST show its publish button only when `storeAccess.publish` is true, and the export menu entry MUST admit `team-lead`. With a `CnStorePage` that predates these props, the page MUST keep showing Install to administrators only.

#### Scenario: A teacher opens the Store
- **GIVEN** a user in `instructors` only
- **WHEN** the user opens the Store page
- **THEN** `storeAccess` is `{install: true, publish: false}` and the page config carries `canInstall: true` and `canPublish: false`

#### Scenario: A team lead opens the export screen
- **GIVEN** a user in `team-leads` whose primary role is `team-lead`
- **WHEN** the user opens the menu
- **THEN** "Export course package" is listed, and the export screen shows "Publish to the course store"

### Requirement: An administrator connects the course registry in the admin settings
The Learniq admin settings page MUST offer a "Course store" section with the registry address, the register and the token. `GET /api/admin/store-registry` and `PUT /api/admin/store-registry` MUST be admin-only. The GET MUST return the address, the register and whether a token is set, and MUST NOT return the token. The PUT MUST accept an empty address (which disconnects the store) or an absolute `http` or `https` URL without user credentials, a register that is empty or a lowercase slug, and a token that is kept when omitted, replaced when given, and removed when `clearToken` is true. The token MUST be stored as a sensitive app config value under `registry_token`; the address and register under `registry_url` and `registry_register`, the keys the store plane reads.

#### Scenario: An administrator connects a registry
- **GIVEN** an administrator on the Learniq admin settings page
- **WHEN** they enter `https://store.example.nl`, register `learniq` and token `YOUR_TOKEN_HERE`, and save
- **THEN** the three keys are stored, the token as sensitive, and the section shows "A token is set" without the value

#### Scenario: A malformed address is refused
- **GIVEN** an administrator
- **WHEN** they save the address `ftp://store.example.nl` or `https://user:CHANGE_ME@store.example.nl`
- **THEN** the response is 400 and nothing is stored

#### Scenario: A non-administrator cannot read the connection
- **GIVEN** a teacher
- **WHEN** they call `GET /api/admin/store-registry`
- **THEN** Nextcloud refuses the request before the controller runs

### Requirement: A course store publish travels through the store plane's write path
Learniq MUST publish a shared course only through OpenRegister's `GenericStoreService::publish()`, with the `CourseStoreDescriptor` descriptor and the registry object `CourseStoreRegistryObject` builds. Learniq MUST NOT build a registry URL, read the registry token, or open an HTTP client for a publish. The descriptor MUST name the object properties that may leave the server (`publishFields`) and the groups that may send them (`publishGroups`), and `publishGroups` MUST be the groups learniq's own permission matrix holds for the action `course-package.share`. This supersedes the transport sentence of "Publishing sends a gated package to the registry" (lesson-sharing-via-store-plane): the sharing gate and the `CourseShareConsent` record with purpose `store` still run first, but the SSRF guard, the redirect refusal, the timeouts, the Bearer token and the 20 MiB cap are the plane's. FEATURES tier: should (sharing, D22).

#### Scenario: A passing course is published through the plane
- **GIVEN** a configured registry, a user the matrix and the plane both admit, and a course that passes the sharing gate
- **WHEN** the user publishes "Betoog schrijven, havo 4"
- **THEN** learniq calls `GenericStoreService::publish()` once with the course store descriptor and a payload whose `slug` starts with `course-package-`
- **AND** the response carries outcome `ok` and that slug

#### Scenario: Only listed properties may travel
- **GIVEN** the registry object for a shared course
- **WHEN** the descriptor is built
- **THEN** every property of that object is in `publishFields`, and `publishFields` names nothing the object does not carry

#### Scenario: The matrix is the source of the publish groups
- **GIVEN** the matrix holds `course-package.share: ["admin", "team-leads"]`
- **WHEN** the descriptor is built on an OpenRegister that can publish
- **THEN** its `publishGroups` are `["admin", "team-leads"]`

### Requirement: The plane decides who may publish before a package is built
`POST /api/store/publish` MUST keep `requireAction('course-package.share')` and MUST then ask OpenRegister's `StoreActionAuthorizer::canPublish()` with the course store descriptor. When the plane refuses, learniq MUST answer 403 with outcome `forbidden` and MUST NOT run the sharing gate, record consent or send anything. Every other plane outcome MUST map to a status: `ok` and `not_configured` 200, `too_large` 413, `rate_limited` 429, `store_unreachable`, `store_rejected` and `store_invalid_response` 502, `not_publishable` 500 (a learniq defect: the descriptor did not opt in).

#### Scenario: The plane refuses a user the matrix admitted
- **GIVEN** the matrix entry for `course-package.share` is an empty list, so the plane names nobody
- **WHEN** an administrator publishes
- **THEN** the response is 403 with outcome `forbidden`, and no gate, consent or request ran

#### Scenario: A rate-limited registry says wait
- **GIVEN** the registry answers the publish with 429
- **WHEN** the user publishes
- **THEN** the response is 429 with outcome `rate_limited`, and the publish screen says to try again in a few minutes

### Requirement: Publishing degrades cleanly on an OpenRegister without the write path
Learniq MUST probe the installed OpenRegister before it uses the publish path: `StoreDescriptor` must declare `publishFields`, `GenericStoreService` must have `publish()`, and `StoreActionAuthorizer` must have `canPublish()`. When any is missing, search and install MUST keep working, the descriptor MUST be built without the publish arguments, and `POST /api/store/publish` MUST answer 501 with outcome `publish_not_supported` without running the gate or sending a request.

#### Scenario: An older OpenRegister
- **GIVEN** an OpenRegister whose `StoreDescriptor` has no `publishFields`
- **WHEN** a user searches the store
- **THEN** the search answers as before
- **AND** WHEN the user publishes, the response is 501 with outcome `publish_not_supported` and no request left the server

### Requirement: Teacher notes live in a store only staff can read
Learniq MUST keep every teacher note as a `LessonTeacherNote` object (`schema:Comment`), never inside a `Lesson`. Its `authorization` MUST grant read, create, update and delete only to staff groups (`instructors`, `team-leads`, `coordinators`, `hr`, `compliance-officers`, `administration-managers` for read; the groups that may write lessons for create, update and delete) and MUST NOT contain `authenticated`, `learners` or `guardians`, with or without a match. The schema MUST NOT be searchable. FEATURES tier: must (privacy of pupil-related staff notes).

#### Scenario: A learner asks for the notes of a lesson
- **GIVEN** a learner in the `learners` group, and a lesson with the note "Sem heeft hier extra uitleg nodig"
- **WHEN** the learner lists `lesson-teacher-note` objects filtered on that lesson
- **THEN** OpenRegister's authorization gives the learner no read rule to match, and nothing is returned

#### Scenario: A guardian asks for a note by id
- **GIVEN** a guardian in the `guardians` group who knows a note's id
- **WHEN** the guardian requests it
- **THEN** no read rule admits the guardian

#### Scenario: A teacher reads the notes of a lesson
- **GIVEN** a teacher in `instructors`
- **WHEN** the composer lists the lesson's notes
- **THEN** the notes are returned

### Requirement: A lesson a learner can read cannot hold a teacher note
`Lesson.blocks[].type` MUST NOT accept `teacherNote`, so OpenRegister refuses any lesson write that carries a note, whoever sends it. This replaces the office-file-lesson-onboarding requirement that `Lesson.blocks[].type` accepts `teacherNote`; the composer label and the player filter stay.

#### Scenario: A stale client saves a note inside a lesson
- **GIVEN** a lesson body with a `teacherNote` block
- **WHEN** it is saved to the objects API
- **THEN** schema validation refuses it and the stored lesson is unchanged

### Requirement: The composer shows notes inline and saves them to the staff store
`LessonComposer` MUST load the lesson's `LessonTeacherNote` objects and show each at its place among the blocks: after the block named by `afterBlockId`, or first when that is empty or no longer exists, ordered by `position`. On save it MUST write the lesson's blocks without any note, then create each new note, update each changed note, and delete each note the teacher removed, matched by `blockId`. A failed note write MUST report that the lesson was saved but a note was not.

#### Scenario: A teacher adds a note after the second block
- **GIVEN** a lesson with blocks A and B
- **WHEN** the teacher adds a note after B and saves
- **THEN** the lesson's `blocks` are A and B only, and one `LessonTeacherNote` exists with `afterBlockId` B

#### Scenario: A teacher deletes a note
- **GIVEN** a lesson with one stored note
- **WHEN** the teacher removes it in the composer and saves
- **THEN** that `LessonTeacherNote` is deleted

### Requirement: Imported slide notes land in the staff store
When the onboarding importer turns a presentation into a lesson draft, the lesson MUST receive only learner-facing blocks, and every slide's speaker note MUST be written as a `LessonTeacherNote` on that lesson, following the block of its slide.

#### Scenario: A presentation with speaker notes
- **GIVEN** a confirmed presentation whose slide 3 has the note "Vraag naar de rol van licht"
- **WHEN** it is imported
- **THEN** the lesson's blocks carry no note, and one `LessonTeacherNote` with that text follows slide 3's block

### Requirement: An upgrade moves the notes lessons already hold
An upgrade MUST move every `teacherNote` block of every lesson into a `LessonTeacherNote` with the block's `blockId`, `text`, the preceding block as `afterBlockId` and its order among the notes as `position`, and MUST then save the lesson without it. A note whose `lessonId` and `blockId` already exist MUST NOT be created again. A lesson whose note could not be created MUST keep its note block. The app `<version>` MUST move so `occ upgrade` runs the step.

#### Scenario: An imported lesson from before this change
- **GIVEN** a lesson with blocks A, a note N, and B
- **WHEN** the upgrade runs
- **THEN** the lesson's blocks are A and B, and a `LessonTeacherNote` with `blockId` N and `afterBlockId` A exists

#### Scenario: The upgrade runs again after a partial failure
- **GIVEN** the note for N was created but the lesson save failed
- **WHEN** the upgrade runs again
- **THEN** no second note is created and the lesson is saved without N

### Requirement: A Word file is read by OpenRegister's document reader when there is one

When OpenRegister provides `DocumentExtractor`, a confirmed Word file MUST be read through it into the lesson structure: a section per heading, paragraph, list and table blocks as paragraph text, pictures with their bytes within the existing limits, and a note when the read was cut off. When OpenRegister has no `DocumentExtractor`, or it fails or reads nothing, learniq's own Word reader MUST be used as before.

#### Scenario: OpenRegister's reader is used when present
@e2e exclude Server-side read with no UI step of its own; pinned by tests/Unit/Service/LessonOnboarding/DocumentLessonReaderTest.php::testTheExtractorPrefersOpenRegistersReader.
- **GIVEN** an OpenRegister with `DocumentExtractor`
- **WHEN** a teacher confirms a Word file
- **THEN** the lesson draft comes from `DocumentExtractor`'s sections
- **AND** learniq's own Word reader does not run

#### Scenario: Learniq's own reader is the fallback
@e2e exclude Server-side read with no UI step of its own; pinned by tests/Unit/Service/LessonOnboarding/DocumentLessonReaderTest.php::testTheExtractorFallsBackToDocxLessonReader.
- **GIVEN** an OpenRegister without `DocumentExtractor`
- **WHEN** a teacher confirms a Word file
- **THEN** `DocxLessonReader` reads it as before

### Requirement: A teacher chooses one lesson onboarding folder in their own files

<!-- @e2e exclude The folder picker is Nextcloud's own file picker; the setting's validation is server-side and covered by LessonOnboardingControllerTest (testSetFolderStoresTheIdOfAFolderInTheUsersFiles, testSetFolderRefusesAFile, testSetFolderWithAnEmptyPathClearsIt) and OnboardingFolderSettingTest. -->

A teacher MUST be able to choose one folder in their own Nextcloud files as their lesson onboarding folder,
through `PUT /apps/learniq/api/lesson-onboarding/folder` with a `path` relative to their files. The system
MUST resolve the path in the calling user's own file tree, MUST refuse a path that is not a folder, and MUST
store the folder's file id as a per-user setting, so a rename keeps the choice. An empty `path` MUST clear the
setting. `GET` on the same route MUST return the stored folder's id and current path, or nulls when none is
set or the folder no longer exists.

#### Scenario: A teacher picks a folder

- **GIVEN** a teacher with a folder `Lessen inbox` in their files
- **WHEN** they choose it as their onboarding folder
- **THEN** the setting holds that folder's file id
- **AND** `GET /api/lesson-onboarding/folder` answers its id and the path `/Lessen inbox`

#### Scenario: A file is not a folder

- **GIVEN** a teacher who passes the path of a `.docx` file
- **WHEN** the request is handled
- **THEN** it is refused with 400 and the setting is unchanged

### Requirement: A new Word or PowerPoint file in the folder is detected and the teacher is notified, and nothing is read

<!-- @e2e exclude A file event cannot be raised from a browser test without driving the shared instance; the listener is covered by LessonOnboardingFileListenerTest (testADocxInTheFolderIsRecordedForItsOwner, testAFileOutsideTheFolderIsIgnored, testAnotherTypeIsIgnoredBeforeAnyLookup, testAFileRecordedBeforeIsNotRecordedTwice, testAFailureNeverReachesTheUpload) and the notification shape by LessonOnboardingRegisterTest. -->

The system MUST listen for `OCP\Files\Events\Node\NodeCreatedEvent`, and for `NodeRenamedEvent` so a file moved
into the folder counts too (its target node). For a file whose name ends in `.docx` or
`.pptx` (case-insensitive) and whose parent folder is the onboarding folder of the file's owner, it MUST
create one `LessonOnboardingFile` row in state `detected` carrying `teacherId` (the owner), `fileId`,
`fileName`, `filePath`, `mimeType`, `format` (`docx` or `pptx`) and `detectedAt`. It MUST NOT open or read
the file's content. It MUST NOT create a second row for a file id it already recorded for that teacher. Every
other node MUST be ignored before any setting or object lookup, and no failure in the listener MUST reach the
file operation that raised the event. The `LessonOnboardingFile` schema MUST declare a notification on
creation to the `teacherId` user with an action that opens the import page (`course-packages/import`).

#### Scenario: A teacher drops a Word file in the folder

- **GIVEN** a teacher whose onboarding folder is `Lessen inbox`
- **WHEN** `Breuken.docx` is created in that folder
- **THEN** one `LessonOnboardingFile` row exists with `teacherId` the teacher, `format: docx` and `lifecycle: detected`
- **AND** the teacher gets a Nextcloud notification that links to the review page
- **AND** the file content has not been read

#### Scenario: A file elsewhere or of another type is ignored

- **GIVEN** the same teacher
- **WHEN** `Breuken.docx` is created in another folder, or `foto.png` in the onboarding folder
- **THEN** no row is created

### Requirement: Nothing is extracted until the teacher confirms one file on the review page

<!-- @e2e exclude Needs a detected row and a live file on the shared instance; the confirmation guard is covered by LessonOnboardingControllerTest (testImportRefusesARowOfAnotherTeacher, testImportRefusesARowThatIsNotDetected) and the page's request shapes by tests/unit-js/lessonOnboarding.test.mjs. -->

The review section on the "Import course package" page (`/course-packages/import`) MUST list the calling teacher's `LessonOnboardingFile` rows in state
`detected`, each with a course picker, an "Import as lesson draft" action and a "Dismiss" action. Next to the
import action the page MUST say that a lesson is visible to everyone in the school and that a file with pupil
names, marks or notes about a pupil does not belong in a lesson. Extraction MUST happen only through
`POST /apps/learniq/api/lesson-onboarding/files/{id}/import` with a `courseId`, and that endpoint MUST refuse
a row that belongs to another teacher (404) or that is not `detected` (409). Dismissing MUST move the row to
`dismissed` without reading the file.

#### Scenario: A teacher dismisses a file

- **GIVEN** a detected row for `Rooster.docx`
- **WHEN** the teacher chooses "Dismiss"
- **THEN** the row moves to `dismissed`
- **AND** no lesson is created and the file is not read

#### Scenario: Another teacher's row cannot be imported

- **GIVEN** a detected row whose `teacherId` is another user
- **WHEN** a teacher posts an import for it
- **THEN** the answer is 404 and no lesson is created

### Requirement: A confirmed Word file becomes one lesson draft

<!-- @e2e exclude Covered by DocxLessonReaderTest (headings split sections, lists and tables become text, images are returned with their bytes, the core title names the lesson), LessonDraftBuilderTest and LessonOnboardingImporterTest (testADocxBecomesOneDraftLessonWithMaterials, testTheLessonIsNeverPublished). -->

On confirmation of a `docx` row the system MUST read the document's structure and create one `Lesson` in the
chosen course with `contentType: text`, the next free `order` in that course, and no lifecycle other than the
initial `draft`. Each heading (a paragraph styled `Heading1` to `Heading6` or `Title`, or with an outline
level) MUST start a section; the section MUST become one `richText` block whose markdown starts with the
heading, followed by its paragraphs, list items as `- ` lines and table rows as `cell | cell` lines. Text
before the first heading MUST become an untitled first section. Each embedded image MUST be written to the
teacher's files, recorded as a `Material` with its `fileRef` and the lesson id, and placed as a `media` block
after its section's text. The original document MUST become a `Material` with `kind: document`, its own
`fileRef` and the lesson id. The lesson name MUST be the document's core title, else its first `Title`
paragraph, else the file name without extension. When the structure yields no text, the system MUST fall back
to OpenRegister's `WordExtractor` flat text, one section, when that class is available. The row MUST move to
`imported` with `lessonId` and `courseId`.

#### Scenario: A lesson plan with two headings and an image

- **GIVEN** `Breuken.docx` with the headings "Start" and "Instructie", three paragraphs and one image under "Instructie"
- **WHEN** the teacher imports it into the course "Rekenen groep 6"
- **THEN** one draft lesson "Breuken" exists with a `richText` block starting "## Start", a `richText` block starting "## Instructie" and a `media` block after it
- **AND** two `Material` rows exist for the image and for `Breuken.docx`, both linked to the lesson
- **AND** the row is `imported` with the lesson's id

### Requirement: A confirmed PowerPoint file becomes one lesson draft, or waits when the reader is missing

<!-- @e2e exclude Covered by PresentationLessonReaderTest (testMissingExtractorIsReportedAsUnavailable, testSlidesMapToSections, testHiddenSlidesAreLeftOut), LessonDraftBuilderTest (notes become a teacherNote block) and LessonOnboardingImporterTest (testAPptxWithoutTheReaderLeavesTheRowDetected). -->

On confirmation of a `pptx` row the system MUST call OpenRegister's
`OCA\OpenRegister\Service\TextExtraction\PresentationExtractor::extract()` only when that class exists. Each
visible slide MUST become a section: one `richText` block starting with the slide title as a markdown heading
(or "Slide N" when it has none), followed by its body paragraphs; non-empty speaker notes MUST become a
`teacherNote` block directly after it. Hidden slides MUST be left out and counted in the row's `importNote`,
and a truncated deck MUST say so there too. The lesson MUST be created as for a Word file (course, order,
`contentType: text`, `draft`), named after the first slide title or the file name, and the deck MUST become a
`Material` with `kind: slides` and its `fileRef`. When the class is missing, the import MUST answer 503 with
`reason: reader-unavailable`, MUST create nothing, and MUST leave the row `detected`.

#### Scenario: A deck with speaker notes

- **GIVEN** `Fotosynthese.pptx` with three slides, the second hidden, the third with speaker notes
- **WHEN** the teacher imports it
- **THEN** the draft lesson has a block for slide 1, a block for slide 3 and a `teacherNote` block with slide 3's notes
- **AND** the row's `importNote` says one hidden slide was left out

#### Scenario: OpenRegister has no presentation reader yet

- **GIVEN** an OpenRegister without `PresentationExtractor`
- **WHEN** the teacher imports a `pptx` row
- **THEN** the answer is 503 with `reason: reader-unavailable`
- **AND** no lesson exists and the row stays `detected`

### Requirement: A teacher note block is shown to staff in the composer and never rendered by the lesson player

<!-- @e2e exclude Covered by tests/unit-js/lessonOnboarding.test.mjs ("teacherNote keeps its text when serialised", "the player's block list leaves teacher notes out") and LessonOnboardingRegisterTest (teacherNote is a block type). -->

`Lesson.blocks[].type` MUST accept `teacherNote`, whose payload is `text`. `LessonComposer` MUST render a
`teacherNote` block with a label saying learners do not see it in the lesson player, MUST let a teacher add
one, and MUST keep its `text` on save. `LessonPlayer` MUST NOT render a `teacherNote` block.

#### Scenario: Notes stay out of the player

- **GIVEN** a lesson with a `richText` block and a `teacherNote` block
- **WHEN** a learner opens it in the lesson player
- **THEN** only the `richText` block renders

## Standards
SCORM, xAPI, cmi5, LTI 1.3, Common Cartridge, NL LOM, VDEX, OAI-PMH, OOAPI 5.0, Schema.org `Course` / `CourseInstance`, ECTS, Bologna. LTI 1.3 / LTI Advantage (Assignment & Grade Services, Deep Linking 2.0) protocol implementation lives entirely in openconnector's `lti-13-platform` adapter; Scholiq covers only the consuming-app placement and launch-delegation contract. WCAG 2.1 AA (reorder keyboard-operability, course-authoring-ux).

## Data Model
See `docs/ARCHITECTURE.md`. Uses entities: `Course`, `Module`, `Lesson`, `LearningPath`, `Prerequisite`, `CatalogChangeRequest`, `LtiToolPlacement`, `CourseTemplate`. All persisted via OpenRegister; no Scholiq tables. `CourseTemplate` (course-authoring-ux) captures a reusable Course→Module→Lesson (+ optional CurriculumPlan) skeleton, instantiated via frontend orchestration against OpenRegister's object-create endpoint — no new PHP controller.

## Out of Scope
- Authoring tool for SCORM packages themselves (use external authoring; we run, not author).
- Real-time collaborative lesson editing (V2).
- Marketplace / paid course storefront (separate spec if pursued).
