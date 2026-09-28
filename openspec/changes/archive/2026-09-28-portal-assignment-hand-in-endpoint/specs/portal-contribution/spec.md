# portal-contribution Specification

## ADDED Requirements

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
