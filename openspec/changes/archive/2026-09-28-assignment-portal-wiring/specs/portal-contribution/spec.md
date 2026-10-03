# portal-contribution Specification

## ADDED Requirements

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
