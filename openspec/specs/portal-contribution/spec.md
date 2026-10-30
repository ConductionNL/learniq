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
- **THEN** "Soort afwezigheid" offers "Ziekte", "Medische afspraak" and the other kinds in Dutch
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

The parent contribution MUST carry the label "School" (Dutch "School"), not the app's name.

#### Scenario: The site heads the parent sections with School
- GIVEN the Wilgenboom site
- WHEN Fatima opens her overview
- THEN the group heading reads "School"
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
- **THEN** she reads "U meldt" and "Sami is vandaag ziek."
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

### Requirement: The overview offers four quick actions for the chosen child

The overview MUST declare four `cta` blocks, as in `Main.dc.html`: "{title} ziek of afwezig melden" on `createExcuseRequest` with `withRecord: true`; "Oudergesprek boeken" on the page `parentConferences` with `withRecord: true`; "Cijfers en rapport bekijken" on the page `parentChildren` with `withRecord: true`; "Bericht sturen aan de juf" on portaliq's conversations `route`. `createExcuseRequest` MUST declare `recordField: learnerRef`, so the tile presets the chosen child. The child cross-check of the action MUST stay as it is.

#### Scenario: Report Vera sick from the overview
- GIVEN the guardian on "Overzicht" with Vera chosen
- WHEN she taps "Vera ziek of afwezig melden"
- THEN the absence form opens with Vera already chosen
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: Grades for the chosen child
- GIVEN the guardian on "Overzicht" with Sami chosen
- WHEN she taps "Cijfers en rapport bekijken"
- THEN Sami's record page opens
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

### Requirement: NEW: The child switcher shows the child's group

`Enrolment` MUST carry `cohortName`, a readable copy of its cohort's name, written by the server on create and update and when the cohort is renamed, never by a client. `parentGroupMemberships` MUST project it. The overview's `records` MUST declare `subtitleLookup: { collection: parentGroupMemberships, matchField: learnerRef, valueField: cohortName }`. This is new work: the group name is two hops from the child today.

#### Scenario: Vera, Groep 6
- GIVEN Vera enrolled in the cohort "Groep 6"
- WHEN the guardian opens "Overzicht"
- THEN the switcher reads "Vera" with "Groep 6" under it
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

### Requirement: The guardian menu is grouped per child

"Overzicht" and "Agenda" MUST declare `group: Mijn omgeving`. "Afwezigheid", "Cijfers en rapporten" and "Oudergesprekken" MUST each be a record page on `parentChildren` with `perRecord: parentChildren`, so the menu lists them once per child under that child's name. The pages `ParentPortalCollections::pages()` builds today MUST keep their ids and routes and MUST declare `menu: false`. Design of record: the side navigation of `Main.dc.html`.

#### Scenario: The menu names each child
- GIVEN a guardian with Vera and Sami
- WHEN she opens any page under "Mijn omgeving"
- THEN the menu shows a group "Vera" and a group "Sami", each with three entries
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
- THEN the newest grades list reads "Rekenen, toets breuken" with 8,0 and its date
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
