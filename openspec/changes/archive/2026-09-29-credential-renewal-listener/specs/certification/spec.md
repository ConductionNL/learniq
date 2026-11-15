# certification

## MODIFIED Requirements

### Requirement: Auto-enrol on renewal or content-version change

The system MUST auto-enrol learners in renewal or delta modules when
triggered by expiry or content-version change.

The **expiry** trigger is implemented: `CredentialRenewalListener` reacts to a
`Credential`'s `expire` transition (`issued` → `expired`) by creating a new
`Enrolment` for the same learner/course (`source: credential-renewal`,
`mandatory: true`) and writing its id back onto `Credential.renewalEnrolmentId`.

The **content-version-change** trigger is NOT implemented by this
requirement's current scope — it needs a content-version concept on `Course`
that does not exist today, and a fan-out across every credential-holder
affected by a version bump, not a single-object transition listener. This is
a named, open gap, not a silent omission.

#### Scenario: Auto-enrol on credential expiry

- **GIVEN** a previously certified learner whose `Credential` transitions `issued` → `expired`
- **WHEN** the `expire` transition is applied
- **THEN** a new `Enrolment` is created for the same learner and course, with `source: credential-renewal`
- **AND** the expiring `Credential`'s `renewalEnrolmentId` is set to the new Enrolment's id

#### Scenario: Auto-enrol on renewal or content-version change

<!-- @e2e exclude The content-version-change half is not implemented in this change — Course carries no content-version concept today; tracked as an explicit open gap in openspec/changes/credential-renewal-listener/proposal.md Out of Scope, not silently assumed covered. The expiry half is covered by the scenario above. -->

- **GIVEN** a previously certified learner whose certification expires or whose related course gets a new content version
- **WHEN** the expiry or content-version change is triggered
- **THEN** the learner is auto-enrolled in the corresponding renewal or delta module
