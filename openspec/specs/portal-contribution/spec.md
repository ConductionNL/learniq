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
