## ADDED Requirements

### Requirement: A DataExchangeJob target can require a confirmed teldatum pre-flight check before it runs
`DataExchangeJob` SHALL gain five additive properties: `requiresTeldatumCheck` (boolean, default `false`),
`teldatumCheckStatus` (enum `not-required | pending | confirmed`, default `not-required`), `teldatumCheckDate`
(nullable date, the 1 February or 1 October count date), `teldatumCheckedBy`, `teldatumCheckedAt` (both nullable).
`DataExchangeRunGuard::check()` SHALL deny the `run` transition when `requiresTeldatumCheck === true` and
`teldatumCheckStatus !== 'confirmed'`, independently of every other condition on the same guard. Every existing job
defaults to `requiresTeldatumCheck: false`, so no previously-running target is newly blocked.

#### Scenario: A ROD job requiring a teldatum check cannot run before confirmation
- **GIVEN** a `DataExchangeJob` with `target: "bron-rod"`, `requiresTeldatumCheck: true`, `teldatumCheckStatus: "pending"`
- **WHEN** the `run` transition is attempted from `queued`
- **THEN** the transition is refused

#### Scenario: Confirmation unblocks the run transition
- **GIVEN** the same job with `teldatumCheckStatus` updated to `"confirmed"`
- **WHEN** the `run` transition is attempted from `queued`
- **THEN** the transition succeeds (subject to any other applicable gate)

#### Scenario: A job with no teldatum-check requirement is unaffected
- **GIVEN** a `DataExchangeJob` with `requiresTeldatumCheck: false` (the default)
- **WHEN** the `run` transition is attempted from `queued`
- **THEN** the teldatum-check condition never blocks it

<!-- @e2e exclude Pure backend/data-model requirement, per this spec's own "no #### Scenario DOM assertions" convention for guard logic — verified by DataExchangeRunGuardTest and FundingTeldatumRegisterTest (schema shape). -->
