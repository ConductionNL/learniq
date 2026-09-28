## ADDED Requirements

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
