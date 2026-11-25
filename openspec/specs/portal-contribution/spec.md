---
capability: portal-contribution
status: in-progress
built_by: openspec/changes/portal-contribution
---

# portal-contribution Specification

**Status**: in-progress
**Scope**: scholiq
**Depends on**: `portal-identity`
**OpenSpec changes**:
- [portal-contribution](../../changes/portal-contribution/) _(active)_ — plain ADR-046 provider class for the `student` + `parent` audiences (kind: code, depends_on portal-identity)
- [portal-parent](../../changes/portal-parent/) _(active)_ — re-enables the `parent` audience against portaliq's merged reverse / scope-value `via` join (`match: 'scopeField'`); corrects the via key-set (kind: code, depends_on portal-contribution)
- [assignment-portal-wiring](../../changes/assignment-portal-wiring/) _(active)_: the pupil's hand-in uses portaliq's file field and a scalar `learnerRef` scope; the server stamps learners and tenant (kind: code)

## Purpose

Scholiq contributes a `student` (the learner) and a `parent` (a guardian)
section to portaliq, the shared external portal for people without Nextcloud
accounts (hydra ADR-046 + contract v2). The contribution is one plain,
dependency-free provider class (`OCA\Scholiq\Portal\PortalContributionProvider`,
duck-typed by FQCN — inert without portaliq) that declares field-projected read
collections, whitelisted create-actions and a learner inbox, all scoped by the
UUID domain-object refs from `portal-identity` (never a Nextcloud user id, A4).

## Requirements

Detailed requirements (REQ-PCON-001 … REQ-PCON-005) are defined in the active
change's delta spec —
[`openspec/changes/portal-contribution/specs/portal-contribution/spec.md`](../../changes/portal-contribution/specs/portal-contribution/spec.md)
— and are merged here by `openspec sync` when the change is archived. The
umbrella requirement below anchors the capability until then.

### Requirement: Scholiq ships an ADR-046 portal contribution scoped by domain UUIDs (REQ-PCON-000)

The app MUST serve its portal contribution through one plain, dependency-free
`OCA\Scholiq\Portal\PortalContributionProvider` class (duck-typed by FQCN,
inert without portaliq) that declares the `student` and `parent` audiences and
scopes every read/create by the `portal-identity` UUID domain refs, never a
Nextcloud user id. No other portal contribution logic, UI, or dependency may
ship in Scholiq.

#### Scenario: The contribution is one plain, domain-UUID-scoped class

- GIVEN a Scholiq install with portaliq present
- WHEN portaliq resolves `OCA\Scholiq\Portal\PortalContributionProvider` and calls `getContribution()` for a student or parent subject
- THEN it receives a declarative manifest scoped exclusively by UUID domain refs (`learnerRef` / `learnerRefs` / `submittedByRef` / `guardianRefs`)
- AND the provider imports nothing from portaliq and is inert when portaliq is absent
- @e2e exclude backend-only contract class rendered by portaliq, not by any Scholiq UI — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Provider is a plain, dependency-free class (REQ-PCON-001)

The app MUST ship `OCA\Scholiq\Portal\PortalContributionProvider` as a plain PHP
class: no imports from portaliq, no `implements` clause, no `info.xml`
dependency on portaliq, and no constructor dependencies. Portaliq discovers it
by convention FQCN and duck-types it via `method_exists` (never `instanceof`),
so without portaliq installed the class MUST be inert and MUST NOT change any
app behaviour (ADR-046 amendment A1).

#### Scenario: Provider constructs standalone

- GIVEN a PHP runtime where portaliq is not installed and no portaliq class is autoloadable
- WHEN `new PortalContributionProvider()` is called
- THEN the class instantiates without error
- AND it declares no `implements` clause, no parent, no constructor, and no `use` of any portaliq symbol
- @e2e exclude backend-only contract class with no Scholiq UI surface; the portal renders inside portaliq — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Provider declares both v2 and v1 audience methods (REQ-PCON-002)

The provider MUST implement `getAudiences(): array` returning
`['student','parent']` (contract v2, preferred by the registry) AND
`getAudience(): string` returning `'student'` (v1 fallback), so it works against
both registry generations (A2). `getContribution(array $subject): ?array` MUST
return `null` for any audience other than `student` or `parent` (fail-closed).

#### Scenario: Audience methods agree and unserved audiences get null

- GIVEN a constructed provider
- WHEN `getAudiences()` and `getAudience()` are called
- THEN `getAudiences()` returns exactly `['student','parent']` and `getAudience()` returns `'student'`, which is a member of `getAudiences()`
- AND `getContribution()` returns `null` for a `teacher` subject and for an empty subject
- @e2e exclude backend-only contract methods with no Scholiq UI surface — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Student manifest scopes by learnerRef with inbox and whitelisted creates (REQ-PCON-003)

For a `student` subject `getContribution()` MUST return a manifest labelled
`Scholiq` with six field-projected read collections — `grade-entry`,
`final-grade`, `attendance-record`, `enrolment`, `submission`, `excuse-request`
— each in register `scholiq` with `scopeClaim` `learnerRef`, scoped by
`learnerRef` (or `learnerRefs` for `submission`); a `grade-notification`
collection with `kind: inbox` scoped by `learnerRef`; and two create-actions,
`createSubmission` (fields `assignmentId`, `attachmentRefs`) and
`createExcuseRequest` (fields `dateFrom`, `dateTo`, `reason`, `reasonKind`,
`attachmentRef`, `minTrust` `low`). No grade, status, lifecycle or staff field
may be exposed on read projection or accepted on create.

#### Scenario: Student subject receives the scoped, projected manifest

- GIVEN a subject whose `audience` is `student` and `subjectRef` is the student's LearnerProfile object UUID
- WHEN `getContribution($subject)` is called
- THEN the manifest has label `Scholiq`, six learner-scoped read collections (submission by `learnerRefs`, the rest by `learnerRef`) and a `grade-notification` `kind: inbox` collection
- AND `createSubmission` whitelists exactly `assignmentId`,`attachmentRefs` and `createExcuseRequest` whitelists exactly `dateFrom`,`dateTo`,`reason`,`reasonKind`,`attachmentRef` — neither exposes `value`, `passed`, `lifecycle`, `submittedBy`, `submittedAuthLevel` or `decidedBy`
- @e2e exclude manifest is consumed and rendered by portaliq, not by any Scholiq UI — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Parent manifest resolves child via a reverse scope-value join (REQ-PCON-004)

For a `parent` subject `getContribution()` MUST return a manifest labelled
`Scholiq` whose three read collections (`grade-entry`, `attendance-record`,
`excuse-request`) each carry a one-hop reverse `via` join whose keys are EXACTLY
`{register: scholiq, schema: learner-profile, scopeField: guardianRefs,
targetField: id, match: 'scopeField'}` — the exact contract portaliq's
`PortalObjectReader::isValidVia()` recognises — with the collection's own
`scopeClaim` `guardianRef`, `scopeField` `learnerRef`, and `minTrust`
`substantial`. The `via.scopeField` `guardianRefs` is the `learner-profile`
field matched (array-contains) against the resolved guardian UUID; `via.targetField`
`id` collects each matched child profile's own OpenRegister object UUID (the
top-level identity `ObjectEntity::jsonSerialize()` exposes), which the outer
records match on their own `learnerRef` under `match: 'scopeField'`. The parent
manifest MUST ship `actions: []` (reads only): a guardian create would require a
client-supplied child `learnerRef` cross-reference that portaliq's writer does
not yet verify against the guardian's `guardianRefs` (a write IDOR), so the
create is withheld until that writer-side validation lands. Field projection on
reads MUST be identical to the student surface for the same schema. Invented via
keys (`matchField`/`selectField`) MUST NOT be used — they fail portaliq's
`isValidVia()` closed to zero rows.

#### Scenario: Parent subject receives reverse-joined reads and no create action

- GIVEN a subject whose `audience` is `parent` and `subjectRef` is a guardian domain-object UUID resolved from the `guardianRef` claim
- WHEN `getContribution($subject)` is called
- THEN each of `parentGrades`, `parentAttendance`, `parentExcuseRequests` carries a `via` whose keys are exactly `register, schema, scopeField, targetField, match`, with `schema` `learner-profile`, `scopeField` `guardianRefs`, `targetField` `id` and `match` `scopeField`, and the collection's own `scopeField` is `learnerRef`, `scopeClaim` is `guardianRef`, `minTrust` is `substantial`
- AND the manifest's `actions` is empty (the guardian create is withheld pending portaliq writer cross-ref validation — a write IDOR guard)
- @e2e exclude parent resolution is executed by portaliq's reader, not by any Scholiq UI — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php: testParentManifestShape, testParentCollectionsUseReverseScopeValueVia, testParentShipsNoCreateActionPendingCrossRefValidation)

### Requirement: Scoping uses portal-identity UUID refs and a verified via shape (REQ-PCON-005)

The manifest MUST reference only scope fields, `via` join fields and whitelisted
fields that exist in `lib/Settings/scholiq_register.json` — the UUID domain refs
added by `portal-identity` (`learnerRef`, `learnerRefs`, `submittedByRef`,
`guardianRefs`) and never a Nextcloud user id (A4). A unit register-drift pin
MUST fail if any referenced schema slug or property is renamed or missing. For
the parent `via` join the pin MUST assert `via.scopeField` (`guardianRefs`)
exists on the `via` schema (`learner-profile`), and MUST assert `via.targetField`
is either a real property on that schema OR an OpenRegister object-identity token
(`id`/`uuid`) — so the reverse join can never silently break, and an invented
key can never masquerade as a real one.

#### Scenario: Manifest references only properties present in the register (or identity tokens)

- GIVEN the shipped `scholiq_register.json` and both audience manifests
- WHEN every collection/action schema slug, `scopeField`, whitelisted field and parent `via.scopeField`/`via.targetField` is checked against the register
- THEN each schema slug, `scopeField` and whitelisted field resolves to a real schema property
- AND each parent `via.scopeField` (`guardianRefs`) exists on `learner-profile` and each `via.targetField` (`id`) is a register property or an OR identity token
- AND `grade-entry` defines `learnerRef`, `submission` defines `learnerRefs`, `excuse-request` defines `submittedByRef`, and `learner-profile` defines `guardianRefs`
- @e2e exclude register-vs-manifest consistency is a backend invariant with no UI surface — covered by the register-drift-pin PHPUnit test (tests/Unit/Portal/PortalContributionProviderTest.php: testManifestMatchesRegisterSchemas)

### Requirement: The parent audience exposes per-child and per-guardian-group directory data (REQ-PCON-006)
`PortalContributionProvider::parentContribution()` SHALL declare a `parentChildren` collection matching `learner-profile`
rows directly (no `via`) by `guardianRefs` (array) containing the caller's `guardianRef` — the same array-containment
match `studentActivityCollections()`'s `Submission.learnerRefs` already uses. Its `fields` SHALL include `givenName`,
`familyName`, `guardianRefs` (the full co-guardian group for that child), `beeldmateriaalConsent`, and
`beeldmateriaalConsentReviewDueAt`. The four existing parent read collections (`parentGrades`, `parentAttendance`,
`parentExcuseRequests`, `parentReportCards`) SHALL each additionally declare `groupByField: 'learnerRef'`.

#### Scenario: A guardian with two children sees a directory naming both
- **GIVEN** a guardian who is a `guardianRef` on two `LearnerProfile` rows
- **WHEN** their `parentChildren` collection is read
- **THEN** it lists both children, each with the guardian's own co-guardian group and current beeldmateriaal consent
  state

#### Scenario: A portal can group existing collections per child
- **GIVEN** the `parentGrades` collection
- **WHEN** a portal reads its manifest declaration
- **THEN** it finds `groupByField: 'learnerRef'`, letting it render results grouped by child without a schema change

<!-- @e2e exclude Declarative manifest shape verified by PortalContributionProviderTest (audiences, manifest shape, exact via/groupByField key-set); the provider is pure data with no I/O per its own class docblock — no PHP execution path beyond array construction to test end-to-end. -->

### Requirement: The parent audience can report a child's absence, validated against the caller's own children (REQ-PCON-007)
`PortalContributionProvider::parentContribution()` SHALL declare `actions: [createExcuseRequest]`: `scopeField:
submittedByRef`, `scopeClaim: guardianRef`, `via` identical to the read collections' reverse-join descriptor,
`minTrust: substantial`, and `fields` including `learnerRef` (the child the excuse concerns) alongside `dateFrom`,
`dateTo`, `reason`, `reasonKind`, `attachmentRef`. This relies on portaliq's writer (`portaliq#607`, merged
2026-09-18) validating that a client-supplied cross-reference declared via `via` resolves inside the subject's own
scope — the guard `portal-parent`'s own deferral comment named as this action's blocker.

#### Scenario: A guardian reports an absence for their own child
- **GIVEN** a guardian who is a `guardianRef` on a child's `LearnerProfile`
- **WHEN** they call `createExcuseRequest` with that child's `learnerRef` in the create body
- **THEN** the write succeeds, with `submittedByRef` server-stamped to the guardian's own UUID

#### Scenario: A guardian cannot report an absence for a child that is not theirs
- **GIVEN** a guardian who is not a `guardianRef` on some other child's `LearnerProfile`
- **WHEN** they call `createExcuseRequest` with that other child's `learnerRef`
- **THEN** the write is refused, because the supplied `learnerRef` does not resolve inside the guardian's own
  `via`-derived scope

#### Scenario: The create action never stamps the guardian's own UUID into the child-identifying field
- **GIVEN** `createExcuseRequest`'s declared shape
- **WHEN** it is inspected
- **THEN** `scopeField` is `submittedByRef`, never `learnerRef` — stamping `learnerRef` from `guardianRef` would write
  the guardian's own UUID into the field that identifies the child, silently corrupting every subsequent read

<!-- @e2e exclude Declarative manifest shape + drift-pin verified by PortalContributionProviderTest; the actual cross-reference validation runs in portaliq's writer (portaliq#607), out of this repo's test surface. -->

### Requirement: A pupil takes a timed test through the portal (REQ-PCON-008)

The `student` manifest MUST declare a `studentTests` collection with `kind: timedTask` on the
`assessment-result` schema, scoped by the scalar `learnerRef` with `scopeClaim: learnerRef`,
exposing only `assessmentId`, `assessmentTitle`, `lifecycle`, `attemptNumber`, `startedAt` and
`submittedAt` (never responses or scores), and a `timedTask` block naming five endpoint actions:
`listTests`, `startTest`, `saveTestAnswer`, `submitTest` and `readTestResult`. Each action MUST be a
`POST` to an instance-local `/apps/learniq/api/portal/assessments...` endpoint, whitelist only the
fields its step sends, declare `subjectField: learnerRef` and `scopeClaim: learnerRef`, and require
`minTrust: low`.

#### Scenario: The student manifest carries the timed task

<!-- @e2e exclude The manifest is data served to portaliq; the rendered test screen lives in portaliq (#749 tests/timed-task.spec.mjs). Covered by PHPUnit PortalContributionProviderTest::testStudentTestsIsATimedTask. -->

- **GIVEN** a student subject
- **WHEN** portaliq asks learniq for its contribution
- **THEN** `studentTests.timedTask` names the five actions, each an instance-local POST endpoint that stamps `learnerRef`
- **AND** `studentTests.fields` holds no response or score field

### Requirement: A pupil hands in work through the portal with a real file (REQ-PCON-007)

The `student` manifest's `createSubmission` action MUST declare portaliq's file field on
`attachmentRefs` (contract of ConductionNL/portaliq#745): `fieldConfigs.attachmentRefs` with `type:
file`, `multiple: true`, an `accept` list of at most 20 extensions and `maxSizeMb` between 1 and 50.
The action MUST keep `fields` to `assignmentId` and `attachmentRefs`, MUST declare `minTrust: low`,
and MUST scope by the scalar `learnerRef` with `scopeClaim: learnerRef`. The `studentSubmissions`
collection MUST scope by the same scalar `learnerRef` and expose `learnerRef` instead of
`learnerRefs`, because portaliq's direct scope compares one value and never matches an array.

#### Scenario: The hand-in action carries a file field

<!-- @e2e exclude The manifest is data served to portaliq; the rendered picker lives in portaliq (#745 tests/schema-form-file-field.spec.mjs). Covered by PHPUnit PortalContributionProviderTest::testSubmissionHandInDeclaresAFileField. -->

- **GIVEN** a student subject
- **WHEN** portaliq asks learniq for its contribution
- **THEN** `createSubmission.fieldConfigs.attachmentRefs.type` is `file`, `multiple` is true and `maxSizeMb` is 20
- **AND** `attachmentRefs` is in the action's `fields`

#### Scenario: Submissions are scoped by one learnerRef

<!-- @e2e exclude PHPUnit PortalContributionProviderTest::testStudentManifestShape. -->

- **GIVEN** a student subject
- **WHEN** portaliq reads the `studentSubmissions` collection or runs `createSubmission`
- **THEN** both scope by `learnerRef`, a property the `submission` schema declares

### Requirement: A pupil hands in a draft submission from the portal (REQ-PCON-009)

The `student` manifest MUST declare an endpoint-forward action `handIn`: a `POST` to the
instance-local `/apps/learniq/api/portal/submissions/hand-in`, `minTrust: low`, `fields:
[submissionId]`, `subjectField: learnerRef` and `scopeClaim: learnerRef`, `rowField: submissionId`
and `rowWhen: {field: lifecycle, in: [draft]}`. The `studentSubmissions` collection MUST name it in
`rowActions`, so portaliq offers it on the pupil's draft rows only and stamps the id of the row it
read under the pupil's scope.

#### Scenario: The student manifest offers the hand-in on draft submissions

<!-- @e2e exclude The manifest is data served to portaliq; the button is portaliq's (#805 tests/row-action.spec.mjs). Covered by PHPUnit PortalContributionProviderTest::testStudentSubmissionsOffersTheHandInOnDrafts. -->

- **GIVEN** a student subject
- **WHEN** portaliq asks learniq for its contribution
- **THEN** `studentSubmissions.rowActions` names `handIn`, and `handIn` is an instance-local POST that stamps `learnerRef`, carries `rowField: submissionId` and is offered only when `lifecycle` is `draft`

### Requirement: A guardian picks the child from their own children
Every `parent` create action that names a child MUST declare `learnerRef` as a required cross reference over `learner-profile` scoped by `guardianRefs` and the `guardianRef` claim, and MUST offer the child as a choice among the guardian's own children. Every parent collection MUST declare readable `columns`.

#### Scenario: A guardian reports an absence by picking the child
- **GIVEN** a guardian with one child
- **WHEN** she opens "Report a child's absence"
- **THEN** the child field lists her child by name
- **AND** a request naming another child is refused by portaliq before it is stored
- @e2e tests/e2e/po-parent-flows.spec.ts

### Requirement: The parent contribution names the guardian's news audience
The `parent` contribution MUST declare `guardianAudience` with `children: parentChildren`, `schoolField: schoolId` and `groups: {collection: parentGroupMemberships, field: cohortId}`, and every named collection MUST exist in the contribution.

#### Scenario: A school-wide news item reaches the guardian
- **GIVEN** a news item targeted at Voorbeeldschool De Wilgenboom
- **WHEN** guardian Fatima Hulstkamp opens the news page in the portal
- **THEN** the item is listed
- @e2e tests/e2e/po-parent-flows.spec.ts

### Requirement: The parent audience reads the grades on the child's published report cards
The parent manifest MUST declare a `parentReportCardGrades` collection over `report-card` that reads through the same reverse scope-value `via` join as every parent read collection (REQ-PCON-004/005), scoped on `learnerRef`, at `minTrust: substantial`. It MUST carry the server-side filter `lifecycle: published-to-parents`, so a report card in `draft`, `rapportvergadering-review` or `finalised` is never read for a guardian. It MUST project only `learnerRef`, `periodName` and `gradeLines`, and show `periodName` and `gradeLines` as its columns, never the nested `subjectGrades` with its uuids. No parent collection MUST read pupil tracking results (`lvs-result`). Adding it MUST leave every other collection of the parent manifest, and the manifests of every other audience, unchanged.

#### Scenario: A primary school guardian sees her child's report card grades
@e2e tests/e2e/po-parent-flows.spec.ts "e. the guardian reads the grades on her child's published report cards, never a draft"
- **GIVEN** Fatima Hulstkamp is the guardian of Vera, whose two report cards are published to parents
- **WHEN** she opens "My child's report card grades" in the portal
- **THEN** she sees one row per report card with the period ("Rapport 1") and the grades ("Rekenen: 7,9; Taal: 8,3; …")
- **AND** no row of another child

#### Scenario: A draft report card never reaches the guardian
@e2e tests/e2e/po-parent-flows.spec.ts "e. the guardian reads the grades on her child's published report cards, never a draft"
- **GIVEN** the group teacher starts a new report card for Vera, which stays a draft
- **WHEN** the guardian reads "My child's report card grades"
- **THEN** the draft is not among the rows

#### Scenario: The other audiences are untouched
@e2e exclude Manifest shape; pinned by tests/Unit/Portal/PortalContributionProviderTest.php testParentReadsTheGradesOnPublishedReportCards and testParentManifestShape, and by a byte-for-byte JSON comparison of every audience's manifest before and after (tasks.md 1.6).
- **WHEN** the student, praktijkopleider or external-assessor manifest is built
- **THEN** it is identical to the manifest before this change

### Requirement: A guardian reads statuses and kinds of absence in the portal's language
The parent manifest MUST declare `valueLabels` on the status columns a guardian reads: `parentExcuseRequests.lifecycle`, `parentAttendance.status`, `parentConferenceSignups.lifecycle` and `parentConferenceSlots.lifecycle`. It MUST declare `valueLabels` on the `reasonKind` field config of `createExcuseRequest`. The keys of each map MUST equal the enum of the schema property it labels. The provider MUST translate every label through learniq's catalogue in the request's language, and MUST NOT change a key. Every label MUST have a Dutch entry in `l10n/nl.json`.

#### Scenario: A guardian on a Dutch portal reads an approved absence report as "Goedgekeurd"
@e2e exclude Manifest content; the rendering is portaliq's (`contribution-value-labels`). Pinned by tests/Unit/Portal/PortalLabelTranslatorTest.php testStatusesAndAbsenceKindsArriveInDutch against the real nl.json; the live check on the primary-school instance is in the PR.
- **GIVEN** Fatima Hulstkamp's report for Vera has `lifecycle: approved`
- **WHEN** she opens "Afwezigheidsmeldingen van mijn kind" on a Dutch portal
- **THEN** the status reads "Goedgekeurd"

#### Scenario: The absence form offers its kinds in Dutch
@e2e exclude Manifest content; the select is portaliq's. Pinned by tests/Unit/Portal/PortalLabelTranslatorTest.php testStatusesAndAbsenceKindsArriveInDutch.
- **WHEN** a guardian opens the absence form on a Dutch portal
- **THEN** "Waarom?" offers "Ziek", "Dokter of tandarts" and the other reasons in Dutch
- **AND** the report stores `illness` when she picks "Ziekte"

#### Scenario: A renamed enum value fails the build
@e2e exclude Unit invariant; pinned by tests/Unit/Portal/PortalLabelTranslatorTest.php testEveryValueLabelMatchesTheSchemaEnum.
- **GIVEN** a schema enum value is renamed
- **WHEN** the unit tests run
- **THEN** the test names the property whose labels no longer match

### Requirement: The parent audience books and cancels a free conference time
The `parent` contribution MUST list the free times of direct rounds for the guardian's children (`parentConferenceFreeSlots`, `conference-slot` in `free`, scoped by `eligibleLearnerRefs` through the child join, REQ-PCON-004/005), before `parentConferenceSlots`, because portaliq fills the time picker from the first collection over `conference-slot`. It MUST offer `bookConferenceSlot` (create `conference-signup` with `learnerRef`, `slotId` and `notes`, the child checked against the guardian's own children) and `cancelConferenceTime` (update `conference-slot`, scoped by `guardianRef`, the server setting `lifecycle` to `cancelled`) as a row action on the conference times. `parentConferenceSlots` MUST keep its field names and add `conferenceRoundId`, `teacherName`, `slotLabel` and `declineNote`.

#### Scenario: The guardian books a time and sees the teacher's answer
- **GIVEN** guardian Fatima Hulstkamp signed in to the portal and a direct round with free times for Vera
- **WHEN** she books one and the teacher acknowledges it
- **THEN** her conference times show the time as acknowledged
- @e2e tests/e2e/po-parent-flows.spec.ts

#### Scenario: Free times name no child
- **GIVEN** the free times collection
- **WHEN** a guardian reads it
- **THEN** no row carries a child reference
- @e2e exclude covered by PHPUnit `ParentConferenceDirectBookingTest::testFreeTimesAreTheChildrensOwnAndFeedThePicker`

### Requirement: A guardian opens one child and sees everything about them

The parent audience MUST declare "My children" as the record page of `parentChildren`. With one child open the page MUST show, for that child only: the attendance figures, the report cards in `published-to-parents`, the grades on those report cards, the published homework of the child's group with whether the child handed it in, the attendance list, the coming calendar items and the school news. Every collection MUST read through the reverse join on the guardian's own children. Every other listable parent collection MUST keep its own page: its create action, its table and its detail.

#### Scenario: Fatima opens Vera
- GIVEN guardian Fatima Hulstkamp with child Vera in Groep 7
- WHEN she opens "Mijn kinderen" on the Wilgenboom site
- THEN she sees Vera's name, the figure cards, Vera's report cards, homework, attendance, calendar and news
- @e2e tests/e2e/po-parent-flows.spec.ts

#### Scenario: Another child's record is refused
- GIVEN a record link to a pupil who is not one of Fatima's children
- WHEN she follows it
- THEN nothing of that pupil opens, because the server never returns that row
- @e2e exclude the refusal is portaliq's (`tests/record-page.spec.mjs`, "another child's record does not open"); the server scope is pinned by `PortalContributionProviderTest::testParentCollectionsUseReverseScopeValueVia`

### Requirement: A guardian reads her child's attendance figures

The record page MUST show three figure cards from the child's `attendance-summary` row with the latest `schoolYear`: absent days with the days with and without permission, late arrivals with the minutes in total, and unexcused absent days, highlighted. The school year the cards read MUST show beside them.

#### Scenario: Vera's figures
- GIVEN Vera's summary for 2025-2026
- WHEN Fatima opens Vera
- THEN she reads the absent days, with and without permission, the late arrivals and the unexcused days, marked when above zero
- @e2e tests/e2e/po-parent-flows.spec.ts

### Requirement: A guardian reads the homework of their child's group

Every assignment MUST carry `learnerRefs`, the LearnerProfile uuids of the pupils enrolled in its group, written by the server on every create and update and never by a client. The guardian MUST read published assignments by that list through the reverse join, and the list MUST NOT be projected to the portal. Each homework row MUST show "Handed in", "Handed in late", "Marked" or "Open" from the child's own submission.

#### Scenario: Homework of Groep 7
- GIVEN a published assignment for Groep 7 that Vera handed in, and one she did not
- WHEN Fatima opens Vera
- THEN the first reads "Ingeleverd" and the second "Open"
- @e2e tests/e2e/po-parent-flows.spec.ts

### Requirement: A guardian sees a calendar of what is coming

The school MUST be able to keep `school-event` records (title, start, end, kind, the whole school or some groups, the school) on a "School calendar" page for coordinators, the office and the director. A report period MAY name its school. The guardian MUST see, on her child's page and on a calendar page for all her children: the school's events for the whole school or the child's group, the holidays and study days of the school's report periods, and the child's planned conversation times; the calendar page also shows the last day to book a conversation. School events and report periods MUST be read through the reverse join on the child's school.

#### Scenario: The sports day and the autumn holiday
- GIVEN a school event for the whole school and a report period of Vera's school holding the autumn holiday
- WHEN Fatima opens the calendar
- THEN she sees both, each with its date and kind
- @e2e tests/e2e/po-parent-flows.spec.ts

#### Scenario: A trip for another group stays off Vera's page
- GIVEN a school event for Groep 4 only
- WHEN Fatima opens Vera (Groep 7)
- THEN the event is not on Vera's page
- @e2e exclude the narrowing is portaliq's (`tests/record-page.spec.mjs`, "group-bound rows"); learniq's declaration is pinned by `ParentRecordPageTest::testTheCalendarJoinsTheChildsSchool`

### Requirement: A guardian reads a teacher by name

Every parent column that holds a staff Nextcloud user id MUST declare `render: user` and MUST name a projected field, so portaliq answers the teacher's display name and the user id never leaves the server. A parent collection MUST NOT project a staff user id field without such a column.

#### Scenario: Fatima reads her conversation time with the teacher's name
- GIVEN a conversation time for Vera with po-leerkracht-09
- WHEN Fatima opens her conversation times
- THEN the "Met" column reads the teacher's display name, not "po-leerkracht-09"
- @e2e exclude the swap is portaliq's (`ContributionControllerUserNamesTest`); learniq's declaration is pinned by `ParentTeacherNamesTest`, and the live check is in the PR

### Requirement: The parent section is called School

The parent contribution MUST carry the label "Van school" (the menu group the board draws for messages, documents and news), not the app's name.

#### Scenario: The site heads the parent sections with School
- GIVEN the Wilgenboom site
- WHEN Fatima opens her overview
- THEN the group heading reads "Van school"
- @e2e exclude pinned by `ParentTeacherNamesTest::testTheParentSectionIsCalledSchool`

### Requirement: The figure cards count in singular and plural
Each attendance figure card on the parent record page MUST declare its `unit` as `{one, other}`, and the late-minutes detail MUST declare its `label` the same way. The provider MUST translate both forms through learniq's catalogue in the request's language and MUST NOT translate any other key of such a map. Both forms MUST have a Dutch entry in `l10n/nl.json`.

#### Scenario: One day of absence reads singular
@e2e exclude Manifest content; the card is portaliq's (`kpi-unit-singular-and-plural`). Pinned by tests/Unit/Portal/PortalLabelTranslatorTest.php testTheFigureCardsArriveInDutchSingularAndPlural against the real nl.json; the live check on the primary-school instance is in the PR.
- **GIVEN** Vera was absent one day this school year
- **WHEN** Fatima Hulstkamp opens Vera on a Dutch portal
- **THEN** the "Afwezig" card reads "1 dag"

#### Scenario: Any other figure reads plural
@e2e exclude Manifest content; the choice of form is portaliq's. Pinned by tests/Unit/Portal/ParentRecordPageTest.php testTheFigureCardsCountInSingularAndPlural.
- **GIVEN** a child was absent 0 or 5 days
- **WHEN** the guardian opens the child
- **THEN** the card reads "dagen"

### Requirement: The guardian reads the name of the child's group
The parent audience's group memberships collection MUST show the name of each of the child's groups, read from the enrolment's own readable copy (`cohortName`), never the group's uuid. The guardian MUST NOT gain a read of any object beyond their own children's enrolments for it, and the news audience MUST keep matching on `cohortId`.

#### Scenario: Fatima reads Vera's group
@e2e exclude Portal contribution content, rendered by portaliq. Pinned by tests/Unit/Portal/PortalContributionProviderTest.php; the live check on the primary-school instance is in the PR.
- **GIVEN** Vera Hulstkamp is enrolled in Groep 7
- **WHEN** her guardian Fatima opens the parent portal
- **THEN** the group column reads "Groep 7"
- **AND** Fatima reads no cohort object

### Requirement: A guardian reads the newest absence first
The `parentExcuseRequests` collection MUST declare `defaultSort: { field: dateFrom, direction: desc }`, and `dateFrom` MUST be one of the fields it projects.

#### Scenario: Four absences
@e2e exclude Contribution content. Pinned by PortalContributionProviderTest; the order on screen is portaliq's, pinned there by tests/mijn-lists.spec.mjs. The live check is in the PR.
- **GIVEN** Vera has absences from 1 October, 5 October, 2 October and 25 September
- **WHEN** her guardian opens the absences
- **THEN** they read 5 October, 2 October, 1 October, 25 September

### Requirement: Each portal declares the chrome its board shows

Each example portal declaration MUST declare `accountLabel`, `footer.contact`, `authentication.signInPage`, and `public` as the first sign-in mode. The academy's MUST declare `residentMenu.cardLabel`. Every "Direct regelen" tile MUST use one of portaliq's line-icon names, and every public news item of the po portal MUST name its audience in `audienceLabel`. An existing portal MUST get these keys only where its own value is empty.

#### Scenario: Mijn Wilgenboom has its header button and contact column
- **GIVEN** a fresh instance
- **WHEN** the operator loads the po set
- **THEN** portal `wilgenboom` holds `accountLabel` "Mijn Wilgenboom" and a footer contact column starting with "Wilgenlaan 12, Zuiddrecht"
- @e2e tests/e2e/portal-design/wilgenboom.spec.ts

### Requirement: The guardian menu lists each child with its group and teacher

`LearnerProfile.groupLabel` MUST hold the group of the pupil's newest active enrolment and, when that group's first teacher has a display name of their own, " · " and that name. It MUST be written whenever a learner profile is saved, and again after an enrolment of that pupil is created or changes group, state or group name. The write MUST happen after the request, and MUST NOT happen when the line did not move. The guardian's child page MUST declare `group` and `records.subtitleFields: [groupLabel]`, and the children cards and the switcher MUST show the line.

#### Scenario: Vera reads as "Groep 7 · Meester Daan"
- **GIVEN** Vera's active enrolment is in Groep 7, whose first teacher is named "Meester Daan"
- **WHEN** her profile is saved, or her enrolment changes group
- **THEN** her profile's `groupLabel` reads "Groep 7 · Meester Daan"
- @e2e exclude server stamp, covered by PHPUnit `LearnerGroupLabelTest`; the menu itself by `tests/e2e/po-parent-flows.spec.ts` (h)

### Requirement: A new grade reaches the guardian's inbox when it becomes visible

A grade notice MUST carry the child's `learnerRef` and the subject's `courseName`, and MUST NOT carry the grade. The guardian's `parentGradeInbox` and the pupil's `studentInbox` MUST declare `visibleFromField: visibleFrom`, so a notice whose moment lies ahead is not shown.

#### Scenario: A held-back grade waits
- **GIVEN** a grade published with `visibleFrom` tomorrow
- **WHEN** the guardian opens her messages today
- **THEN** the notice is not there; tomorrow it reads the subject's name
- @e2e exclude the hiding is portaliq's (#1198); the notice's fields are covered by PHPUnit `GradeRollupHandlerTest`

### Requirement: The absence form says in one sentence what the guardian reports

The guardian's `createExcuseRequest` MUST declare a `summary` naming only its own fields, with a phrase for every kind of absence, and a `confirmation` with a title.

#### Scenario: Sami is ill today
- **GIVEN** the guardian picks Sami, today and "Ziekte"
- **WHEN** she looks above the send button
- **THEN** she reads "U meldt" and "Sami is de hele dag ziek"
- @e2e tests/e2e/po-parent-flows.spec.ts

### Requirement: The latest report reads as one bar per subject

Publishing a report card to parents MUST replace the pupil's `report-subject-grade` rows with one row per named subject of that card, in its order, carrying the caption and the teacher's words. The report card MUST NOT be written. The child's page MUST draw them with `display: bars`.

#### Scenario: Vera's June report
- **GIVEN** Vera's report 2 is published with Rekenen 7,9 to Wereldoriëntatie 8,2
- **WHEN** the guardian opens Vera's page
- **THEN** she sees six bars in that order and the teacher's words
- @e2e exclude row writer, covered by PHPUnit `ReportSubjectGradeRowsTest`; the bars render through portaliq's display

### Requirement: A guardian lands on an overview of one child at a time

The parent audience MUST declare an overview page `parentOverview`, labelled "Overzicht", with `home: true`, so it opens on `/mijn`. The page MUST declare `records: { collection: parentChildren }`, one child at a time, so portaliq draws a child switcher. Below the open tasks (see the next requirement) the page MUST show, in this order: four quick actions, the coming week, the attendance figures of the latest school year, the latest absence report, the three newest grades and the two newest inbox messages, each for the chosen child (`recordField: learnerRef`). Every block MUST read a collection that goes through the reverse join on the guardian's own children. Design of record: `Main.dc.html`.

#### Scenario: The overview opens on the first child
- GIVEN a guardian with two children, Vera and Sami, at De Wilgenboom
- WHEN she signs in on the site
- THEN she lands on "Overzicht" with Vera chosen and a switch to Sami
- AND every block below the tasks is about Vera
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: Switching child changes every block
- GIVEN the guardian on "Overzicht" with Vera chosen
- WHEN she switches to Sami
- THEN the week, the figures, the latest report, the grades and the messages are Sami's
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: A guardian with one child sees no switcher
- GIVEN a guardian with one child
- WHEN she opens "Overzicht"
- THEN the page shows that child and no switch
- @e2e exclude the switcher is portaliq's component (site-mijn-omgeving-components); learniq declares the same page for one or many children

### Requirement: The overview puts open tasks first

The overview MUST be a home page (`home: true`) and MUST declare a `tasks` block over `parentConferenceRounds` with `dueField: bookingClosesAt`, so each conference round in `booking-open` that invites one of her children shows as a task in portaliq's "Dit moet u nog doen". A task MUST name what to do ("Kies een tijd voor het oudergesprek") and the last day to book. A task MUST link to the booking page. With no open round, no task block MUST show, not an empty one. The task MUST NOT be narrowed per child by projecting `invitedLearnerRefs`: that list names other pupils.

#### Scenario: An open conference round is a task
- GIVEN a conference round in `booking-open` that invites Vera and closes on 9 October
- WHEN the guardian opens `/mijn`
- THEN "Dit moet u nog doen" lists a task to pick a time, with the last day 9 October
- AND the task opens the booking page
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: No open round, no task block
- GIVEN no conference round in `booking-open` for any of her children
- WHEN the guardian opens `/mijn`
- THEN no task block shows
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

### Requirement: The child's page offers two quick actions

The child's page (wilgenboom/Detail) MUST declare two `cta` blocks, as the board draws: "{title} ziek of afwezig melden" on `createExcuseRequest` with `withRecord: true`, and "Bericht aan {teacher}" on the message form with the child's teacher chosen. The overview (wilgenboom/MijnOverzicht) carries no quick actions; grades and conferences are reached from the menu.

#### Scenario: Report Vera sick from the overview
- GIVEN the guardian on Vera's page (wilgenboom/Detail)
- WHEN she taps "Vera ziek of afwezig melden"
- THEN the absence form opens with Vera already chosen
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: Grades for the chosen child
- GIVEN the guardian on Sami's page
- WHEN she taps "Bericht aan meester Daan"
- THEN the message form opens with the teacher already chosen
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

### Requirement: NEW: The child switcher shows the child's group

`Enrolment` MUST carry `cohortName`, a readable copy of its cohort's name, written by the server on create and update and when the cohort is renamed, never by a client. `parentGroupMemberships` MUST project it. The overview's `records` MUST declare `subtitleLookup: { collection: parentGroupMemberships, matchField: learnerRef, valueField: cohortName }`. This is new work: the group name is two hops from the child today.

#### Scenario: Vera, Groep 6
- GIVEN Vera enrolled in the cohort "Groep 6"
- WHEN the guardian opens "Overzicht"
- THEN the switcher reads "Vera" with "Groep 6" under it
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

### Requirement: The guardian menu follows the board: Mijn Wilgenboom, Regelen and Van school

The parent menu MUST have the three groups the board wilgenboom/MijnMenu draws: "Mijn Wilgenboom" (overview, agenda and the pages per child), "Regelen" (absence, conference times) and "Van school" (messages, documents, news). The child is chosen with the child switcher on the page, not with a menu group per child. "Overzicht" and "Agenda" MUST declare `group: Mijn Wilgenboom`. "Afwezigheid", "Cijfers en rapporten" and "Oudergesprekken" MUST each be a record page on `parentChildren` with `perRecord: parentChildren`, so the menu lists them once per child under that child's name. The pages `ParentPortalCollections::pages()` builds today MUST keep their ids and routes and MUST declare `menu: false`. Design of record: the side navigation of `Main.dc.html`.

#### Scenario: The menu names each child
- GIVEN a guardian with Vera and Sami
- WHEN she opens any page under "Mijn Wilgenboom"
- THEN the menu shows the groups "Mijn Wilgenboom", "Regelen" and "Van school", and no group per child
- AND the child switcher on the page chooses between Vera and Sami
- AND no entry reads "My child's grades" or "Your conference times"
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: An old route still opens
- GIVEN a bookmark to `/mijn/learniq/parentExcuseRequests`
- WHEN the guardian follows it
- THEN the page opens as before, with no menu entry of its own
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

### Requirement: The absence page shows the form and only the latest reports

The parent audience MUST declare a record page on `parentChildren` with `perRecord: parentChildren`, "Afwezigheid", that shows the absence form for that child, then the three latest reports of that child by `dateFrom`, then a link to all reports. Each report MUST show the kind, the first day, the reason and the status in Dutch. The form MUST keep every rule of `createExcuseRequest`: the child from the guardian's own children only, a first and last day, a reason and a kind. Design of record: `LearniqAbsence.dc.html`.

#### Scenario: Only the latest three
- GIVEN Vera has seven earlier absence reports
- WHEN the guardian opens "Afwezigheid" for Vera
- THEN she sees the form, then three reports, then "Bekijk alle meldingen"
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: A sick report in under a minute
- GIVEN the guardian on "Afwezigheid" for Vera on a phone
- WHEN she picks the "Ziek" card, "Vandaag" as first and last day, and sends
- THEN the report is saved for Vera with kind `illness`
- AND the form is replaced by the confirmation "De juf of meester heeft uw melding ontvangen."
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: A missing answer is named at the top
- GIVEN the guardian leaves the last day empty
- WHEN she sends the form
- THEN the form lists the missing answer at the top and keeps her other answers
- @e2e exclude the error summary is portaliq's component (site-multi-step-forms); learniq's required fields are pinned by PortalContributionProviderTest

### Requirement: NEW: A grade names its subject and its weight

`GradeEntry` MUST carry `courseName`, a readable copy of its course's name, written by the server on every create and update and never by a client. The parent grade collection MUST project `courseName`, `methodName`, `methodBlock` and `weight`. A grade line on the portal MUST read the subject, then the test, then the date and the value. A weight above one MUST show as "telt N keer mee". This is new work: no readable subject exists on a grade today.

#### Scenario: A grade reads as a subject and a test
- GIVEN a published grade 8,0 for Vera, course "Rekenen", method "toets breuken", weight 1
- WHEN the guardian opens "Overzicht" for Vera
- THEN the newest grades list reads "Breuken" under "Rekenen" with 8,0 and its date
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: A client cannot set the course name
- GIVEN a grade create that sends `courseName` "Gym"
- WHEN the server saves it
- THEN `courseName` holds the name of the grade's own course
- @e2e exclude server stamp; covered by a unit test of the stamp written with the build

### Requirement: NEW: School notices about a child reach the guardian's inbox

The parent audience MUST declare an inbox collection (`kind: inbox`) over `report-card-parent-notification` and over `grade-notification`, through the reverse join on the guardian's own children, showing only rows whose `visibleFrom` has passed. A message MUST name the child and what happened, in Dutch ("Het rapport van Vera staat klaar"). It MUST NOT carry a grade value. This is new work: the parent contribution declares no inbox today.

#### Scenario: A published report card is a message
- GIVEN Vera's report card moves to `published-to-parents`
- WHEN the guardian opens "Berichten"
- THEN she reads "Het rapport van Vera staat klaar"
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: Another family's notice never shows
- GIVEN a grade notification for a pupil who is not her child
- WHEN the guardian opens "Berichten"
- THEN that notice is not there
- @e2e exclude server scope; pinned by PortalContributionProviderTest on the reverse join of every parent collection

### Requirement: NEW: The example primary-school portal has a signed-out home

When `ExamplePortalProvisioner` creates the `wilgenboom` portal it MUST also write its signed-out home: a hero with "Alles over school op één plek" and the sign-in panel, three cards ("Ziek of afwezig melden", "Oudergesprek boeken", "Cijfers en rapporten") and the school news. It MUST NOT write or change pages of a portal it did not create in the same run. Design of record: `LearniqHome.dc.html`.

#### Scenario: A fresh example set gets the home
- GIVEN no `wilgenboom` portal exists
- WHEN the po example set is loaded
- THEN the portal's home shows the hero, the three cards and the news
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: An edited home is left alone
- GIVEN a `wilgenboom` portal whose home an editor changed
- WHEN the po example set is loaded again
- THEN the home keeps the editor's content
- @e2e exclude provisioner behaviour; covered by ExamplePortalProvisionerTest written with the build

### Requirement: Every guardian label on the site reads in Dutch

Every label this change adds (page, menu group, block, card, task, quick action, message, success text) MUST have a Dutch entry and MUST pass through `PortalLabelTranslator`. A Dutch site MUST show no English string from learniq.

#### Scenario: The overview reads Dutch
- GIVEN the Wilgenboom site in Dutch
- WHEN the guardian opens "Overzicht"
- THEN every heading, button and menu entry from learniq is Dutch
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

### Requirement: The overview follows the designed board

The guardian's overview MUST open, in this order, with: a `greeting` block whose action is `createExcuseRequest` labelled "Afwezig melden"; the open tasks as a `tasks` block with `display: highlight` labelled "Wat u nog moet doen"; the guardian's children as a `collection` block over `parentChildren` with `display: cards`, labelled "Mijn kinderen"; the newest school news labelled "Nieuw van school"; and this month's calendar with `display: tiles`, labelled "Deze maand". The blocks of the chosen child (figures, absence reports, grades, messages) MUST follow. The absence page MUST show the child's reports with `display: rows` (date tile from `dateFrom`, the reason kind, the reason in quotes, the status and who decided), labelled "Uw meldingen". Design of record: school-design `wilgenboom/preview/MijnOverzicht.png` and `MijnLijst.png`. The greeting, highlight, cards, rows and tiles are lane L2's block contract; until portaliq knows them it drops the key and the page still renders.

#### Scenario: The overview opens with the greeting and the task
- GIVEN Fatima Hulstkamp signs in on Monday 5 October 2026
- WHEN she opens `/mijn`
- THEN she reads "Goedemorgen, Fatima", the button "Afwezig melden", then "Wat u nog moet doen" with the conference round of groep 4, then "Mijn kinderen" with Vera and Sami
- @e2e tests/e2e/portal-design/wilgenboom.spec.ts

### Requirement: The guardian's pages declare what the wilgenboom boards draw

Learniq MUST declare, through portaliq's options (portaliq change guardian-pages-follow-the-wilgenboom-boards and guardian-message-to-and-whole-day): the Mijn Wilgenboom menu layout in `lib/Settings/portals/po.json` (`residentMenu.groups`: Mijn Wilgenboom, Mijn kinderen with `learniq:parentChildren`, Regelen, Van school, Uw gegevens; wilgenboom/MijnMenu); on the child's page the buttons "{givenName} ziek of afwezig melden" and "Bericht aan {teacherName}" with `labelWhenEmpty` "Bericht sturen" and `messageTo: teacherRef` (wilgenboom/Detail); `statusNoteAtField: decidedAt` on the absence rows (wilgenboom/MijnLijst); and the absence form's child as cards (`widget: choices`) with `descriptionField: groupLabel` (wilgenboom/MobielDetail). `LearnerProfile.teacherName` and `teacherRef` MUST be readable copies of the group's first teacher, written with `groupLabel`, and empty when that teacher has no display name of their own.

#### Scenario: Bericht aan meester Daan
<!-- @e2e exclude Portal manifest shape and readable copies; covered by tests/Unit/Portal/ParentRecordPageTest.php (testMyChildrenIsTheRecordPageOfEachChild) and tests/Unit/Service/LearnerGroupLabelTest.php (testTheTeacherIsNamedWithTheUserTheMessageGoesTo). -->
- **GIVEN** Vera in Groep 7, whose first teacher is Meester Daan
- **WHEN** her father opens Vera's page in Mijn Wilgenboom
- **THEN** the page opens with "Vera ziek of afwezig melden" and "Bericht aan Meester Daan", and the second opens the message form with Meester Daan chosen

#### Scenario: The menu reads as the board draws it
<!-- @e2e exclude Portal declaration; covered by tests/Unit/Portal/GuardianWilgenboomBoardsTest.php. -->
- **GIVEN** the po example set loaded on a new portal
- **WHEN** a guardian opens her own area
- **THEN** the menu reads Mijn Wilgenboom, Mijn kinderen with a row per child, Regelen, Van school and Uw gegevens

### Requirement: The placement page reads in words and shows the hours

Every field the student's placement collection projects, except her own reference, MUST carry a label. The page MUST group the agreed terms under "Afspraken". The placement's state MUST read in words for every state of the schema, as the current step under "Waar sta je?", and MUST NOT be a separate status field. The placement page MUST show the hours bar under the steps, with the agreed hours as its total. A step MUST NOT carry a date beside a line that names the same day.

#### Scenario: Milan opens his placement
- **GIVEN** Milan's placement at Bakker Techniek BV
- **WHEN** he opens it from "BPV en uren"
- **THEN** "Afspraken" reads "Periode", "Werkdagen", "Adres" and "Opleiding", the hours bar under "Waar sta je?" reads "96 van 480 uur goedgekeurd", "Tussenbeoordeling" is the current step, and "27 augustus" appears once
- @e2e exclude covered by PHPUnit `PortalContributionProviderTest::testThePlacementReadsInWordsAndShowsTheHours` and `BpvPlacementStepsTest`; the page by `tests/e2e/portal-design/esdoornveen.spec.ts`

### Requirement: A booking lists its participants, and the certificates group per certificate

An opened booking MUST list its participants as rows, each with the participant's name, the certificate line and a pill for the details. The employer's certificates MUST group per certificate.

#### Scenario: Linda opens the F-gassen booking
- **GIVEN** Linda Jansen and the F-gassen booking for Tom, Youssef and Sanne
- **WHEN** she opens it
- **THEN** Youssef's row reads "Geboortedatum ontbreekt", each row names its certificate line, and "Geboortedatum invullen" is offered
- @e2e exclude covered by PHPUnit `EmployerSitePagesTest::testABookingListsItsParticipantsAndCertificatesGroup`; the page by `tests/e2e/portal-design/warmtepompacademie.spec.ts`

### Requirement: A course row names its weekdays, its kind and links to the course list

Each course in a portal's public index MUST carry meta that starts with the weekdays of its next run, then its number of days, then its kind from its tags (certificate, basic or advanced). When the portal's declaration names a `publicIndex.courseHref`, the row MUST link there.

#### Scenario: The academy's F-gassen course
- **GIVEN** the training set with "F-gassen: herhaling en examen" on Thursday 8 October
- **WHEN** a visitor opens the academy's home
- **THEN** its row reads "Donderdag · 1 dag · certificaat" and links to /cursusaanbod
- @e2e exclude covered by PHPUnit `PortalPublicIndexTest::testTheAcademyShowsItsCoursesWithTheirNextDates`

### Requirement: A booking an employer makes belongs to the company's organisation

When an employer books places through the portal, learniq SHALL create the booking acting for the OpenRegister organisation of the company row, when the company has one. Otherwise the booking SHALL be created as before. An organisation that does not exist SHALL create no booking.

#### Scenario: The company has an organisation
- **GIVEN** a company row whose `@self.organisation` is the training provider's organisation
- **WHEN** its employer books one place on an open edition
- **THEN** the booking is created acting for that organisation
- @e2e exclude Context wiring; pinned by EmployerBookingOrganisationTest

#### Scenario: The company has no organisation
- **GIVEN** a company row without `@self.organisation`
- **WHEN** its employer books one place
- **THEN** the booking is created as before
- @e2e exclude Fallback branch; pinned by EmployerBookingOrganisationTest

### Requirement: The guardian overview and absence page are about both children

The guardian's overview MUST NOT switch between children. Its task card MUST leave out a conference round where one of her children already has a time. The absence page MUST show the form and the reports of all her children, newest first, and MUST NOT be per child.

#### Scenario: Fatima's overview with Vera booked
- **GIVEN** Fatima, whose daughter Vera has a conversation time and whose son Sami has none
- **WHEN** she opens Mijn Wilgenboom
- **THEN** no switcher shows, both children's cards show, and only Sami's round asks her to pick a time
- @e2e exclude declaration covered by PHPUnit `GuardianSitePagesTest::testTheGuardianOverviewIsHomeAndSwitchesChildren`; the rendered page by `tests/e2e/portal-design/wilgenboom.spec.ts`

### Requirement: The participant reads his next course day once

The participant's overview MUST show his next course day once, as the highlight. The list under it MUST be headed "After that" and MUST skip that day.

#### Scenario: Tom's overview
- **GIVEN** Tom, with course days on 8 October and 3 November
- **WHEN** he opens Mijn academie
- **THEN** the highlight shows 8 October, and "Daarna" lists 3 November
- @e2e exclude covered by PHPUnit `ParticipantSitePagesTest::testTheNextCourseDayShowsOnce`

### Requirement: A guardian asks leave for her own child and sees the decision

The parent audience MUST declare an action `requestLeave` that creates a `leave-request` for one of the guardian's own children, with the server stamping `learnerRef` and `requestedByRef` from her session and never from the form, and a collection `parentLeaveRequests` of her own requests with the days, the state in words and the decision note.

#### Scenario: Fatima asks leave for Vera
- **GIVEN** Fatima is signed in with DigiD
- **WHEN** she asks leave for Vera for a family occasion on Friday 6 November
- **THEN** one request exists for Vera, naming Fatima, and her list shows it as "Ingediend" with the date the school will answer by
- @e2e tests/e2e/portal-design/wilgenboom.spec.ts

#### Scenario: Not her child
- **GIVEN** a request that names a classmate of Vera
- **WHEN** Fatima submits it
- **THEN** it is refused and nothing is stored
- @e2e exclude refusal asserted from the caller in the action's unit tests

### Requirement: A school asks a guardian for permission, and the answer is recorded

A school MUST be able to ask the guardians of a cohort or of named learners for permission, with an explanation, the date it is about and a deadline to answer by. A guardian MUST be able to answer yes or no for her own children and for nobody else's, and the answer MUST record who answered, when, and at which assurance level.

#### Scenario: A guardian answers for her own child
- GIVEN the school asked permission for the trip of Vera's group, to answer by 16 October
- WHEN Fatima answers yes for Vera
- THEN one response is stored for Vera, naming Fatima, the moment and the assurance of her session
- @e2e tests/e2e/po-parent-flows.spec.ts

#### Scenario: Another child is not hers to answer for
- GIVEN the same request covers a classmate
- WHEN Fatima answers for that classmate
- THEN the answer is refused and nothing is stored
- @e2e tests/e2e/po-parent-flows.spec.ts

#### Scenario: The request is on her list of things to do
- GIVEN the request is open and she has not answered
- WHEN she opens her overview
- THEN it is among the things she still has to do, with its deadline
- @e2e tests/e2e/po-parent-flows.spec.ts

### Requirement: Silence is not permission

A request MUST state whether an unanswered request counts as a refusal, and MUST default to counting it as one. A closed request MUST NOT accept an answer.

#### Scenario: Nobody answered
- GIVEN the deadline has passed and a child's guardians answered nothing
- WHEN the teacher reads the outcome
- THEN that child reads as not answered, and counts as refused
- @e2e exclude asserted from the caller in the service's unit tests

#### Scenario: An answer after the deadline
- GIVEN the request is closed
- WHEN a guardian answers
- THEN it is refused, and the refusal says the request is closed
- @e2e exclude as above

### Requirement: A teacher sees who has not answered

A teacher MUST be able to read, per request, which children's guardians answered yes, which answered no, and which did not answer at all.

#### Scenario: The evening before the trip
- GIVEN eighteen of twenty-eight guardians have answered
- WHEN the teacher opens the request
- THEN she reads the three groups, and the ten who have not answered are named
- @e2e exclude a staff screen; asserted in the view's own tests

### Requirement: A pupil reads her school's open support lessons and signs herself up

The student audience MUST declare `studentElectiveOffers` over `elective-offer`, narrowed to open offers of the pupil's own school and year, projecting title, next date and time, place, the teacher's name and a line with the places left, and an action `signUpForElective` that creates an `elective-sign-up` for the signed-in learner only. The action MUST refuse a full offer, an offer outside its window and a second sign-up for the same offer, with the reason in words.

#### Scenario: Noor signs up for Tuesday
- **GIVEN** the support lesson wiskunde A has 6 places left for Tuesday 6 October
- **WHEN** Noor signs up from her wiskunde page
- **THEN** her sign-up exists, the line reads "Nog 5 plekken", and her mentor's page shows the support lesson from 6 October
- @e2e tests/e2e/portal-design/vaartveld.spec.ts

#### Scenario: Full
- **GIVEN** no places are left
- **WHEN** a pupil signs up
- **THEN** the sign-up is refused with "Deze steunles is vol"
- @e2e exclude refusal asserted in the action's unit tests

### Requirement: A pupil may book her own mentor talk when the round allows it

When a conference round sets `pupilMayBook`, the student audience MUST read the free times of that round for her own group's mentor and MUST be able to book one for herself; the booking MUST follow the same atomic claim and lifecycle as a guardian's booking, and the pupil's guardians MUST see the booked time on their portal and receive the booking notice.

#### Scenario: Noor picks Tuesday 16.30
- **GIVEN** mevrouw Kramer's round for H4b allows pupils to book
- **WHEN** Noor books Tuesday 13 October 16.30
- **THEN** the time is hers, her father Erik receives a notice, and his portal shows the talk
- @e2e tests/e2e/portal-design/vaartveld.spec.ts

### Requirement: An employer sends a colleague in place of a participant, before the deadline

The employer audience MUST offer `substituteParticipant` as "Collega in zijn plaats" or "Collega in haar plaats" on a participant row of her own organisation's booking, replacing that participant by another person of her organisation while keeping the booking's place, until the course day's substitution deadline. The replaced participant's enrolment MUST be withdrawn with the reason in words and the new participant's enrolment created; after the deadline the action MUST be refused in words.

#### Scenario: Sanne goes instead of Tom
- **GIVEN** Linda's booking I-2026-0412 for Thursday 8 October, deadline Wednesday 12.00
- **WHEN** she replaces Tom by Bram de Wit on Monday
- **THEN** the booking still holds three places, Tom's enrolment reads "vervangen door collega" and Sanne is enrolled
- @e2e tests/e2e/portal-design/warmtepompacademie.spec.ts

#### Scenario: Too late
- **GIVEN** it is Wednesday 12.30
- **WHEN** Linda tries to replace a participant
- **THEN** it is refused with "Vervangen kan tot woensdag 12:00 uur. Bel de planning."
- @e2e exclude refusal asserted in the action's unit tests

### Requirement: A certificate names its holder, its course and its renewal

A credential MUST carry `learnerName`, `courseName`, `organisationRef` (from the holder's learner profile), `validUntilLabel` ("Geldig tot" and the date of `expiresAt`) and `renewalLine` ("Herhaling op" and the first course day of the renewal enrolment while that enrolment is pending or active) as readable copies written on every save. It MUST carry `weeksUntilExpiry` and `expiryLabel` as OpenRegister calculations: "Verlopen" when `expiryStatus` is expired; "Verloopt deze week", "Verloopt over 1 week" or "Verloopt over N weken" while it is expiring or expiring-soon; "Geldig" otherwise.

#### Scenario: Tom's certificate on Monday 5 October 2026
- **GIVEN** Tom's F-gassen categorie 1 expires on 30 November 2026 and his renewal on 8 October is booked
- **WHEN** the certificate is read
- **THEN** it reads "Geldig tot 30 november 2026", "Herhaling op 8 oktober" and "Verloopt over 8 weken"
- @e2e exclude copies and calculation, covered by PHPUnit `CertificateCopiesTest`; the overview by `tests/e2e/portal-design/warmtepompacademie.spec.ts`

### Requirement: An employer sees her people's certificates, the first to expire first

The employer audience MUST declare `employerCertificates` over `credential`, scoped by `organisationRef`, filtered to issued certificates (`kind: certificate`), sorted by `expiresAt` ascending, without the signature or the signed payload. Her overview MUST show them as dated rows with the expiry status as a pill and `expiryLabel` beside it, and a page MUST list them as a table with the verification link.

#### Scenario: Linda sees the F-gassen certificates first
- **GIVEN** the training set
- **WHEN** Linda opens her overview
- **THEN** the three F-gassen categorie 1 certificates come before Sanne's BRL 6000-21, each with "Verloopt over 8 weken"
- @e2e tests/e2e/portal-design/warmtepompacademie.spec.ts

### Requirement: A participant lands on his next course day

Learniq MUST serve a `participant` audience whose collections are all scoped by the `learnerRef` claim: his enrolments on `learnerRef`, his certificates on `learnerId`. It MUST offer no write. His home page MUST open with the greeting, then his first coming course day as a highlight with the course, the days, the time and the place, then his issued certificates with the expiry, then his later course days. Each participant enrolment of a company booking MUST carry the booking's `firstDay`, `dayLabel`, `timeLabel`, `placeLabel`, `trainerName` and `upcoming`, written by the server.

#### Scenario: Tom on Monday 5 October 2026
- **GIVEN** the training set and Tom's participant account
- **WHEN** he opens Mijn academie on his phone
- **THEN** he reads F-gassen: herhaling en examen, donderdag 8 oktober, 08.30 tot 16.30 uur at the Praktijkhal, then his F-gassen categorie 1 with "Verloopt over 8 weken", then the course of 3, 4 en 10 november
- @e2e tests/e2e/portal-design/warmtepompacademie.spec.ts

### Requirement: An employer reads only her own company's people and bookings

Learniq MUST serve an `employer` audience. Every employer collection MUST be scoped by `scopeField: organisationRef` with `scopeClaim: organisationRef`, except the editions she may book, which MUST be scoped by the cohort's `locationId` and the claim `editionLocationRef`. No employer collection MUST use a `via` join, and no employer collection MUST project a birth date. An enrolment MUST carry `organisationRef`, `learnerName` and `courseName` as readable copies taken from its learner profile and course on every save.

#### Scenario: Linda sees Jansen's bookings and nobody else's
- **GIVEN** Linda's portal account holds `organisationRef` of Jansen Installatietechniek BV
- **WHEN** she opens Mijn academie
- **THEN** she reads the bookings and participants of Jansen only
- @e2e tests/e2e/portal-design/warmtepompacademie.spec.ts

### Requirement: An employer lands on what waits for her

The employer's home page MUST open with the greeting and the button to book places, then the participants whose details are missing as a highlighted task with the sentence, the reason and the time it is due, then the coming bookings as dated rows with course, participants, status and note.

#### Scenario: The overview asks for Youssef's birth date
- **GIVEN** the training set is loaded
- **WHEN** Linda opens her overview on Monday 5 October 2026
- **THEN** she reads "Vul de geboortedatum van Youssef El Amrani in" and the three coming bookings, I-2026-0412 with "Geboortedatum van 1 deelnemer ontbreekt"
- @e2e tests/e2e/portal-design/warmtepompacademie.spec.ts

### Requirement: A booking tells the employer what still waits for her

A booking's readable copies (course, day line, time, place, trainer, participants), its `lifecycle`, `employerStatus`, `statusNote` and `detailsDueAt`, and each participant enrolment's `detailsStatus`, `openTask`, `openTaskNote`, `openTaskDueAt` and `certificateLine` MUST be written by the server from the rows the booking is made of. `employerStatus` MUST be `waiting-for-you` while a place has no participant or a participant lacks a birth date the course needs (a course tagged `examen` or `certificaat`). `detailsDueAt` MUST be 12.00 on the working day before the first course day. The booking MUST be re-derived after every employer write, and after staff change a participant's enrolment state, birth date or name, without slowing the save (deferred). A booking page MUST show five steps: booked, confirmed, details complete, course day, result and certificate.

#### Scenario: A confirmed exam booking waits for one birth date
- **GIVEN** booking I-2026-0412 has Tom, Youssef and Sanne, all enrolments active, and Youssef has no birth date
- **WHEN** the booking is derived
- **THEN** it reads "Wacht op u", "Geboortedatum van 1 deelnemer ontbreekt", due Wednesday 7 October 12.00, and its steps stand at "Gegevens compleet"
- @e2e exclude server derivation, covered by PHPUnit `EmployerBookingFactsTest`; the page by `tests/e2e/portal-design/warmtepompacademie.spec.ts`

#### Scenario: The planner confirms an enrolment in Nextcloud
- **GIVEN** a received booking
- **WHEN** the planner activates one of its enrolments
- **THEN** after the request the booking reads "Bevestigd"
- @e2e exclude deferred listener, covered by PHPUnit `EmployerBookingCascadeTest`

### Requirement: An employer books places and names her participants

The employer MUST be able to book 1 to 12 places on a planned edition of the location her account names, and then name one of her company's employees per place. Both MUST go through learniq's endpoint with the company stamped from the claim. A person of another company, a person already on the booking, a place too many, an edition of another location or one that is no longer planned MUST be refused and write nothing.

#### Scenario: Linda books two places and names Tom
- **GIVEN** a planned F-gassen edition at the Praktijkhal
- **WHEN** Linda books two places and puts Tom on the booking
- **THEN** the booking reads "Vul de naam van 1 deelnemer in"
- @e2e exclude endpoint rules, covered by PHPUnit `PortalEmployerBookingsTest`

### Requirement: An employer supplies a missing birth date and never reads it

The employer MUST be able to store a birth date for one of her company's participants whose profile has none. The value MUST be a real date between 100 and 14 years ago. A date the institute already holds MUST NOT be overwritten, and the answer MUST NOT carry the date back. After the write every booking of that participant MUST be re-derived.

#### Scenario: Linda fills in Youssef's birth date
- **GIVEN** Youssef's profile has no birth date
- **WHEN** Linda sends 14 March 1994 for him
- **THEN** his profile holds 1994-03-14 and booking I-2026-0412 no longer waits for her
- @e2e exclude endpoint rules, covered by PHPUnit `PortalEmployerBookingsTest`

### Requirement: The guardian reads that the school saw her report

The parent audience's absence collection MUST project a line naming the teacher and the time the report was seen while it is `submitted`, and MUST offer the action `reportRecovered` on a report of her own child without a last day.

#### Scenario: Seen at 8.12
- **GIVEN** juf Esra saw Sami's report at 8.12
- **WHEN** Fatima opens her absence page
- **THEN** the report reads "Gezien door juf Esra om 8.12 uur"
- @e2e tests/e2e/portal-design/wilgenboom.spec.ts

### Requirement: A pupil of eighteen reports for herself

The student audience's absence action MUST accept lesson hours and an open last day for a learner of eighteen or older, and MUST offer `reportRecovered` on her own open report.

#### Scenario: Eighteen and ill
- **GIVEN** a vwo 6 pupil of eighteen, signed in with her school account
- **WHEN** she reports herself ill without a last day
- **THEN** the report names her as the one who reported, and she can end it herself
- @e2e exclude spec-only proposal; asserted in the action's unit tests

### Requirement: A pupil lands on an overview of today

The student audience MUST declare pages. The first MUST be `studentOverview`, "Overzicht", with `home: true`, showing in this order: work to hand in as a `tasks` block with `dueField: dueAt` that leaves out handed-in work (`excludeWhen` on her own submission), today's timetable (`range: day`), four quick actions (hand in, grades, tests, report absent), the three newest grades and the two newest inbox messages. Every block MUST read a collection scoped to the pupil's own `learnerRef`. Design of record: `LearniqPupil.dc.html`.

#### Scenario: Noa opens the portal between classes
- GIVEN pupil Noa with one assignment due today at 23.59 and one due in 7 days
- WHEN she signs in on her phone
- THEN "Huiswerk en toetsen" on `/mijn` shows both, today's first, each with its date and time
- AND below it today's lessons and her three newest grades
- @e2e exclude planned: written with the build in tests/e2e/vo-pupil-flows.spec.ts (specs-only change)

### Requirement: The pupil menu is short

The student pages MUST appear in the menu as the board groups them: Overzicht under Mijn Zuiddrecht; Rooster, Huiswerk en toetsen, Cijfers and Afwezigheid under School; Mentorgesprek, Vakken en profiel and Documenten under Regelen; Mijn gegevens under Account. Berichten is portaliq's own inbox entry. The default page of each student collection MUST keep its route and MUST declare `menu: false`.

#### Scenario: No collection names in the menu
- GIVEN Noa signed in
- WHEN she opens the menu
- THEN she sees the nine entries and Berichten, and no "My submissions" or "My enrolments"
- @e2e exclude planned: written with the build in tests/e2e/vo-pupil-flows.spec.ts (specs-only change)

### Requirement: NEW: A pupil sees her own timetable

The student audience MUST declare `studentSessions`, the `session` rows of the cohorts the pupil is actively enrolled in, joined through her own enrolments. It MUST project only `cohortId`, `courseId`, `title`, `startsAt`, `endsAt`, `location`, `changeReasonKind`, `changeReason` and `lifecycle`; never `substituteTeacherId`, `affectedLearnerIds`, `affectedParentIds` or `externalRef`. A cancelled session MUST show as cancelled ("Vervalt"), not disappear. A changed session MUST show the word of its `changeReasonKind` ("Ander lokaal", "Andere docent", "Gewijzigd") and the school's `changeReason` as its note. A session of a cohort the pupil is not enrolled in MUST NOT show. The join MUST declare `via.when: { field: lifecycle, in: [active] }`, so a withdrawn or failed enrolment grants no session. The overview shows today's timetable as a `calendar` block with `display: timetable` and `range: day` (portaliq `calendar-timetable-display`), followed by a "Hele week" link to the "Rooster" page, which shows the week with day tiles (`range: week`).

#### Scenario: Today's lessons with a cancellation
- GIVEN Noa's group has wiskunde at 8.30, engels at 10.15 and a cancelled geschiedenis at 12.30 today
- WHEN she opens "Overzicht"
- THEN "Je rooster vandaag" lists the three, the last struck through and marked "Vervalt"
- @e2e exclude planned: the live proof rerun on the fresh instance after the portaliq timetable display lands; the join and the seed are pinned by StudentTimetableTest

#### Scenario: Noor's Monday on the board
- GIVEN the vo example set, loaded, and pupil Noor Bakker in H4b on Monday 5 October 2026
- WHEN she opens "Overzicht"
- THEN "Je rooster vandaag" shows her seven lessons, economie in lokaal 0.21 with "Ander lokaal", and lichamelijke opvoeding struck through with "Vervalt"
- @e2e exclude planned: the live proof rerun on the fresh instance; StudentTimetableTest walks the join over the real seed

#### Scenario: Another group's lessons stay hidden
- GIVEN a session for a cohort Noa is not enrolled in
- WHEN she opens "Rooster"
- THEN that session is not there
- @e2e exclude server scope; pinned by a PortalContributionProviderTest on the enrolment join written with the build

#### Scenario: A withdrawn enrolment stops the timetable
- GIVEN Noa's enrolment in a cohort is `withdrawn`
- WHEN she opens "Rooster"
- THEN no session of that cohort shows
- @e2e exclude enforced by portaliq's `via.when` (REQ-SMO-023); covered by a portaliq reader test and a PortalContributionProviderTest on the declared `when`

### Requirement: NEW: A pupil sees the work she has to hand in

The student audience MUST declare `studentHomework`, the published assignments whose `learnerRefs` holds the pupil, with `title`, `dueAt` and a status from her own submission: "Open", "Ingeleverd", "Te laat ingeleverd" or "Nagekeken". `learnerRefs` MUST NOT be projected. Open work MUST link to the hand-in. This is new work: the pupil reads only her own submissions today.

#### Scenario: An open assignment links to the hand-in
- GIVEN a published assignment "Verslag biologie: de cel" for Noa's group, not handed in
- WHEN she taps it on "Overzicht"
- THEN the hand-in form opens for that assignment
- @e2e exclude planned: written with the build in tests/e2e/vo-pupil-flows.spec.ts (specs-only change)

#### Scenario: Handed-in work drops from the task list
- GIVEN Noa handed in "Verslag biologie"
- WHEN she opens `/mijn`
- THEN it no longer shows under "Inleveren" on the overview
- AND the "Inleveren" page lists it as "Ingeleverd"
- @e2e exclude planned: written with the build in tests/e2e/vo-pupil-flows.spec.ts (specs-only change)

### Requirement: A pupil's grade shows its subject and weight

`studentGrades` MUST project `courseName`, `methodName`, `methodBlock` and `weight`. A grade line MUST read the subject and test, the value, and "telt N keer mee" when the weight is not one.

#### Scenario: A double-weight grade
- GIVEN a published grade 7,2 for wiskunde, hoofdstuk 2, weight 2
- WHEN Noa opens "Overzicht"
- THEN she reads "Wiskunde" with "Hoofdstuk 2", 7,2 and "Telt mee" with the weight 2 in that column
- @e2e exclude planned: written with the build in tests/e2e/vo-pupil-flows.spec.ts (specs-only change)

### Requirement: Every pupil label on the site reads in Dutch

The student manifest MUST pass through `PortalLabelTranslator`. Every student label MUST have a Dutch entry in the "je" form. A Dutch site MUST show no English string from learniq to a pupil.

#### Scenario: The pupil site reads Dutch
- GIVEN the Esdoornveen site in Dutch
- WHEN Noa opens any of her pages
- THEN every heading, button and menu entry from learniq is Dutch
- @e2e exclude planned: written with the build in tests/e2e/vo-pupil-flows.spec.ts (specs-only change)

### Requirement: The pupil overview follows the designed board

The pupil's overview MUST open, in this order, with: a `greeting` block (today's date and "Goedemorgen, {first name}"); her homework and tests as a `tasks` block over `studentHomework` with `display: highlight`, labelled "Huiswerk en toetsen"; her three newest grades, labelled "Laatste cijfers"; and her absence this school year as a `kpi` block over a new collection `studentAttendanceSummary` (her own `attendance-summary` rows, scoped on `learnerRef`): days absent, times late and days without a report. The quick actions and the messages MUST follow. Today's timetable MUST sit between the greeting and the homework, read from `studentSessions` (T1, T5b), never from authored items. Design of record: school-design `vaartveld/preview/MijnOverzicht.png`.

#### Scenario: Noor opens her overview
- GIVEN Noor Bakker of H4b signs in with her school account
- WHEN she opens `/mijn`
- THEN she reads "Goedemorgen, Noor", then "Huiswerk en toetsen" with her leesverslag, then "Laatste cijfers", then her absence this school year: 1 day absent, 2 times late
- @e2e tests/e2e/portal-design/vaartveld.spec.ts

### Requirement: An external assessor lands on what is shared with him and until when

The `external-assessor` audience MUST declare pages under `group: Mijn omgeving`, with `menu: false` on the default collection pages. The first MUST be `eaOverview`, "Gedeeld met u", with `home: true`, showing one row per share with the candidate's name, the portfolio title, the end date or "Gedeeld zonder einddatum", and how many parts are shared. An expired share MUST stay listed as "Verlopen", with nothing to open. The audience MUST stay read-only: no action is added. Design of record: esdoornveen/Beoordelaar.

#### Scenario: Henk sees what is shared and until when
- GIVEN assessor Henk Vos with a share ending 31 January 2027
- WHEN he signs in on the site for assessors
- THEN "Gedeeld met u" shows one row per share with the candidate's name
- AND that row reads "Gedeeld tot 31 januari 2027"
- @e2e exclude planned: written with the build in tests/e2e/mbo-assessor-flows.spec.ts (specs-only change)

#### Scenario: A share without an end date
- GIVEN a share to Henk with no `expiresAt`
- WHEN he opens "Gedeeld met u"
- THEN the row reads "Gedeeld zonder einddatum" and "Blijft open"
- @e2e exclude planned: written with the build in tests/e2e/mbo-assessor-flows.spec.ts (specs-only change)

#### Scenario: A revoked share disappears
- GIVEN a student revokes one of Henk's shares
- WHEN he opens "Gedeeld met u"
- THEN that candidate is no longer listed
- @e2e exclude server filter; pinned by PortalContributionProviderTest on `filter: lifecycle active`

### Requirement: NEW: A share names its candidate and portfolio

`portfolio-share` MUST carry `portfolioTitle` and `learnerName`, readable copies stamped by the server when the share is created and never written by a client. `eaSharedPortfolios` MUST project both. This is new work: a share holds only pointers today.

#### Scenario: The candidate's name on the share
- GIVEN a share of Milan de Groot's portfolio "Mechatronica" to Henk
- WHEN Henk opens "Gedeeld met u"
- THEN the row reads "Milan de Groot, portfolio Mechatronica"
- @e2e exclude planned: written with the build in tests/e2e/mbo-assessor-flows.spec.ts (specs-only change)

#### Scenario: A client cannot rename the candidate
- GIVEN a share create that sends `learnerName` "Iemand anders"
- WHEN the server saves it
- THEN `learnerName` holds the name of the portfolio's own learner
- @e2e exclude server stamp; covered by a unit test of the stamp written with the build

### Requirement: NEW: An external assessor opens the shared entries, and only those

The `external-assessor` audience MUST declare `eaSharedPortfolioEntries`, the `portfolio-entry` rows granted by his active, unexpired shares, projecting `portfolioId`, `title`, `evidenceKind`, `attachmentRef` and `reflectionText`. An entry outside a share's selection MUST NOT show. A revoked or expired share MUST grant no entry. The join MUST declare `via.when: { field: lifecycle, in: [active] }` and `via.validUntilField: expiresAt`. The collection MUST NOT ship before portaliq honours both (REQ-SMO-023). This is new work: the shared content is a flagged follow-up today.

#### Scenario: Only the selected entries
- GIVEN a share of two of Milan's five portfolio entries to Henk
- WHEN Henk opens the share
- THEN he sees those two and not the other three
- AND the page reads "Milan deelde 2 van zijn 5 onderdelen met u. De andere 3 ziet u niet."
- @e2e exclude planned: written with the build in tests/e2e/mbo-assessor-flows.spec.ts (specs-only change)

#### Scenario: An expired share opens nothing
- GIVEN a share to Henk whose `expiresAt` has passed
- WHEN Henk opens a link to one of its entries
- THEN no entry opens, and the page reads "Deze deling is verlopen. Er is niets meer te zien."
- @e2e exclude enforced by portaliq's `via.validUntilField` (REQ-SMO-023); covered by a portaliq reader test and a PortalContributionProviderTest on the declared join

### Requirement: Every assessor label on the site reads in Dutch

The `external-assessor` manifest MUST pass through `PortalLabelTranslator`. Every label MUST have a Dutch entry in the "u" form.

#### Scenario: The assessor site reads Dutch
- GIVEN the Esdoorn Techniek College site in Dutch
- WHEN Henk opens "Gedeeld met u"
- THEN every heading and menu entry from learniq is Dutch
- @e2e exclude planned: written with the build in tests/e2e/mbo-assessor-flows.spec.ts (specs-only change)

### Requirement: The student pages use the words of the boards

The student contribution MUST declare readable columns for `studentGrades` (subject, date, grade), each a projected field. The student menu MUST hold the BPV hours page as "BPV and hours". A week of hours in state `rejected` MUST read "Sent back". Dutch readers MUST see "BPV en uren" and "Teruggestuurd".

#### Scenario: The grades table has readable headers
- **GIVEN** a student signed in to her portal
- **WHEN** she opens her grades
- **THEN** each row reads the subject, the date and the grade without column headers, as the vaartveld boards draw, and no field key is shown
- @e2e exclude declaration only, covered by PHPUnit `PortalContributionProviderTest::testStudentGradesHaveReadableColumns`

#### Scenario: A sent-back week says so
- **GIVEN** Milan's week 39 that Petra Bakker sent back
- **WHEN** Milan opens "BPV en uren" from his menu
- **THEN** the week's status reads "Teruggestuurd"
- @e2e exclude declaration only, covered by PHPUnit `PortalContributionProviderTest::testHoursPageIsInTheMenuAndASentBackWeekSaysSo` and `GuardianSitePagesTest::testThePupilOverviewAndShortMenu`

### Requirement: A guardian is offered a cancel only on a time that can still be cancelled

`cancelConferenceTime` MUST declare `rowWhen: {field: lifecycle, in: [booked, acknowledged]}`, the states in which ConferenceSlotBookingSync lets a parent cancel. Every row action learniq contributes MUST declare a `rowWhen` on a field its collection projects, with values the schema's lifecycle allows. The condition is presentation only: ConferenceSlotBookingSync keeps refusing a cancel the time does not allow, including one after the booking window closed, which the condition cannot express.

#### Scenario: Fatima sees the cancel on her booked time only
- GIVEN Fatima has a booked time, a completed time and a cancelled time for Vera
- WHEN she opens "Uw gesprekstijden"
- THEN only the booked time offers "Deze tijd afzeggen"
- @e2e exclude the hiding is portaliq's (`tests/row-action.spec.mjs`, update-row-action-condition); learniq's declaration is pinned by `PortalRowActionConditionsTest`, and the live check is in the PR
