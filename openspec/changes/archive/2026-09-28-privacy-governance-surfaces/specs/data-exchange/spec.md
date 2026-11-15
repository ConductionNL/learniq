## ADDED Requirements

### Requirement: A DataExchangeJob target can require standing partner approval before it runs
`DataExchangeJob` SHALL gain four additive properties: `requiresPartnerApproval` (boolean, default `false`),
`partnerApprovalStatus` (enum `not-required | pending | approved | rejected`, default `not-required`),
`partnerApprovedBy`, `partnerApprovedAt`, and `dataSharedFields` (array of strings naming which fields this
target pulls). `DataExchangeRunGuard::check()` SHALL deny the `run` transition (`queued → running`) when
`requiresPartnerApproval === true` and `partnerApprovalStatus !== 'approved'`, independently of the existing
OSO/SWV parent-review gate — a job may be subject to either gate, both, or neither. Every existing job defaults
to `requiresPartnerApproval: false`, so no previously-running target is newly blocked by this change.

#### Scenario: A job for a partner-gated target cannot run before approval
- **GIVEN** a `DataExchangeJob` with `target: "uwlr"`, `requiresPartnerApproval: true`, `partnerApprovalStatus: "pending"`
- **WHEN** the `run` transition is attempted from `queued`
- **THEN** the transition is refused

#### Scenario: Approval unblocks the run transition
- **GIVEN** the same job with `partnerApprovalStatus` updated to `"approved"`
- **WHEN** the `run` transition is attempted from `queued`
- **THEN** the transition succeeds (subject to any other applicable gate, e.g. OSO/SWV)

#### Scenario: A job with no partner-approval requirement is unaffected
- **GIVEN** a `DataExchangeJob` with `requiresPartnerApproval: false` (the default)
- **WHEN** the `run` transition is attempted from `queued`
- **THEN** the partner-approval condition never blocks it

<!-- @e2e exclude Pure backend/data-model requirement, per this spec's own "no #### Scenario DOM assertions" convention for guard logic — verified by DataExchangeRunGuardTest and PrivacyGovernanceRegisterTest (schema shape). -->
