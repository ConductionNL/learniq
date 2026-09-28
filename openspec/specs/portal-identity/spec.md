---
capability: portal-identity
status: in-progress
built_by: openspec/changes/portal-identity
---

# portal-identity Specification

**Status**: in-progress
**Scope**: scholiq
**OpenSpec changes**:
- [portal-identity](../../changes/portal-identity/) _(active)_ — additive UUID domain-object scope refs (`learnerRef` / `learnerRefs` / `submittedByRef` / `guardianRefs`) on a first slice of eight schemas (kind: config)

## Purpose

Scholiq's record schemas carry UUID **domain-object** scope references
(`learnerRef` / `learnerRefs` / `submittedByRef` / `guardianRefs`) alongside
their existing Nextcloud-uid properties, so the ADR-046 external portal can
scope portal subjects to their own records without ever touching a Nextcloud
user id (amendment A4). The references are additive, optional, and fail-closed.
This capability is the head of the portal chain — `portal-contribution` depends
on it.

## Requirements

Detailed requirements (REQ-PID-001 … REQ-PID-003) are defined in the active
change's delta spec —
[`openspec/changes/portal-identity/specs/portal-identity/spec.md`](../../changes/portal-identity/specs/portal-identity/spec.md)
— and are merged here by `openspec sync` when the change is archived. The
umbrella requirement below anchors the capability until then.

### Requirement: Scholiq exposes ADR-046 domain-UUID portal scope refs (REQ-PID-000)

The first portal slice MUST scope every portal-exposed record by a UUID
domain-object reference, never a Nextcloud user id (ADR-046 A4). Each schema in
the slice (`GradeEntry`, `FinalGrade`, `AttendanceRecord`, `Enrolment`,
`Submission`, `ExcuseRequest`, `LearnerProfile`, `GradeNotification`) carries a
new `*Ref` UUID property alongside — never replacing — its existing
Nextcloud-uid property, additive and optional so existing objects stay valid
and unset refs are fail-closed (invisible to the portal).

#### Scenario: Every slice schema carries an additive UUID scope ref

- GIVEN the shipped `scholiq_register.json`
- WHEN the register configuration is parsed
- THEN each schema in the first portal slice defines a `*Ref` property with `format` `uuid` (on the item for arrays)
- AND its original Nextcloud-uid property is still present and no new ref is `required`
- @e2e exclude declarative register configuration with no Scholiq UI surface — covered by the JSON gate (`python3 json.load`) and the provider register-drift-pin PHPUnit test (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Learner-scoped record schemas expose a UUID domain ref (REQ-PID-001)

The learner-scoped record schemas MUST each expose a UUID domain-object scope
reference alongside their existing Nextcloud-uid property (ADR-046 A4).
Specifically, `GradeEntry`, `FinalGrade`, `AttendanceRecord` and `Enrolment` in
`lib/Settings/scholiq_register.json` each define a `learnerRef` property
(`type: string`, `format: uuid`, title "Learner Ref") whose value is the UUID
of the learner's `LearnerProfile` object — the portal-subject scope key,
distinct from the Nextcloud-uid `learnerId`. `Submission` defines a
`learnerRefs` array (items `format: uuid`, title "Learner Refs") alongside its
Nextcloud-uid `learnerIds`. The refs are additive: the Nextcloud-uid properties
stay unchanged and no new ref appears in a `required` list, so every existing
object stays valid with the ref absent.

#### Scenario: Learner-scoped schemas carry the UUID scope ref

- GIVEN the shipped `scholiq_register.json`
- WHEN the register configuration is parsed
- THEN `GradeEntry`, `FinalGrade`, `AttendanceRecord` and `Enrolment` each define `learnerRef` with `type` `string` and `format` `uuid`
- AND `Submission` defines `learnerRefs` as an array whose items have `format` `uuid`
- AND each schema still defines its original `learnerId` / `learnerIds` property and lists neither new ref as required
- @e2e exclude declarative register configuration with no Scholiq UI surface — covered by the JSON gate (`python3 json.load`) and the provider register-drift-pin PHPUnit test (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Parent linkage and submitter use UUID domain refs (REQ-PID-002)

`LearnerProfile` MUST define a `guardianRefs` array (items `format: uuid`,
title "Guardian Refs") alongside the unchanged Nextcloud-uid `parentIds`, so
the portal can resolve a parent subject to that parent's learner(s) via a
one-hop join. `ExcuseRequest` MUST define both `learnerRef` (uuid) and
`submittedByRef` (uuid) alongside the unchanged `learnerId` and `submittedBy`,
so a parent-audience create can be scope-stamped by the guardian domain UUID
without touching a Nextcloud user id. Neither new ref may be `required`.

#### Scenario: Guardian and submitter refs are present and additive

- GIVEN the shipped `scholiq_register.json`
- WHEN the register configuration is parsed
- THEN `LearnerProfile` defines `guardianRefs` (array of uuid items) and still defines `parentIds`
- AND `ExcuseRequest` defines `learnerRef` (uuid) and `submittedByRef` (uuid) and still defines `learnerId` and `submittedBy`
- AND none of `guardianRefs`, `learnerRef`, `submittedByRef` is listed as required
- @e2e exclude declarative register configuration with no Scholiq UI surface — covered by the JSON gate and the provider register-drift-pin PHPUnit test

### Requirement: Versions bump for the version-gated import (REQ-PID-003)

Because OpenRegister's import is version-gated, the register `info.version` MUST
be bumped from `0.2.0` to `0.3.0` and every touched schema version
(`GradeEntry`, `FinalGrade`, `AttendanceRecord`, `Enrolment`, `Submission`,
`ExcuseRequest`, `LearnerProfile`, `GradeNotification`) MUST be bumped from
`0.1.0` to `0.2.0` in the same change. `GradeNotification` MUST also define
`learnerRef` (uuid, title "Learner Ref") alongside its `learnerId` and
`recipient`, so the portal can scope a learner inbox. The register MUST remain
valid JSON.

#### Scenario: Register and schema versions are bumped and the file is valid

- GIVEN the shipped `scholiq_register.json`
- WHEN the register configuration is parsed
- THEN `info.version` is `0.3.0` and each of the eight touched schemas has version `0.2.0`
- AND `GradeNotification` defines `learnerRef` with `format` `uuid`
- AND the file loads without error via `python3 -c "import json; json.load(...)"`
- @e2e exclude declarative register configuration with no Scholiq UI surface — covered by the JSON gate (`python3 json.load`) run in CI
