# Design: take up an absence flag and report it from its page

## Context

At development `de2d7388`:

- `AttendanceFlag` lifecycle (`lib/Settings/learniq_register.json`): `startHandling` open to in-handling; `report` in-handling to reported, guarded by `AttendanceFlagReportGuard` (allows when no `dataExchangeJobId` is linked, or when integriq's job reads `exchangeStatus: succeeded`); `resolve` from open, in-handling or reported to resolved; `recordMunicipalityFeedback`, a self-loop on reported, guarded by `MunicipalityFeedbackGuard`, stamped by `MunicipalityFeedbackStampListener`, with `inputs: [{field: municipalityFeedback}]`.
- `lib/Service/ExchangeGateService.php:179` holds the exchange job while the flag is `open`; taking the flag up releases it.
- `AttendanceFlagDetail` (`src/manifest.d/people.json:1610`): `readOnly: true`, widgets `flag-data`, `flag-recs`, `flag-related`. The related widget already resolves the data-exchange job.
- Authorization on `attendance-flag`: read and update for `instructors` and `compliance-officers`.
- nextcloud-vue `CnDetailPage` takes `config.lifecycleActions: {field}` and fetches the allowed transitions from OpenRegister's `/available-actions`; `CnLifecycleActions` opens `CnTransitionInputDialog` for a transition that declares `inputs`.

## Screen

The canvas (`5NkFW28vZUUij43xzxHg5a`) draws the entry only: board `LqAanwezigheidLijst` has the "Afwezigheidsmeldingen" link next to "Register van vandaag". No board draws the flag detail. This change follows the detail-page pattern the other learniq detail pages use (`ReportCardDetail`, `CredentialDetail`): the transitions sit in the header's Actions menu, the data below them. Labels:

| action | English | Dutch |
|---|---|---|
| startHandling | Take up | Oppakken |
| report | Mark as reported | Gemeld bij leerplicht |
| resolve | Close | Afsluiten |
| recordMunicipalityFeedback | Record the municipality's answer | Reactie gemeente vastleggen |

## Decisions

### D1: Actions, not edits

`readOnly` stays true. A flag is evidence; its window, metric and breaching records are computed. Only the lifecycle moves, and every move is in the audit trail.

### D2: The server decides which actions show

`lifecycleActions: {field: "lifecycle"}` without an explicit list. OpenRegister answers which transitions the caller may run from the current state, so a mentor never sees an action the register would refuse.

### D3: The municipality's answer asks for two fields

The dialog asks for `masRoute` (text) and `note` (long text). `receivedAt` is stamped when empty and `recordedBy` always, so asking for them invites a wrong value. If the dialog cannot address a property inside an object, nextcloud-vue adds that (CROSS item in the PR); until then the builder declares the inputs and leaves the task open.

### D4: Say why the report waits

When `report` is refused the header shows the guard's reason ("the leerplicht exchange has not succeeded yet"). The job's status sits in the Related panel with its `exchangeStatus`, so the reader can see what it waits on.

## Risks

- A school without integriq: no job is linked and `report` is allowed at once. That is the manual route the guard already supports.
