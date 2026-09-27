# portal-contribution Specification

## ADDED Requirements

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
