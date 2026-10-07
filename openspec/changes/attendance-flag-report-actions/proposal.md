---
kind: code
---

# Take up an absence flag and report it to the leerplicht office from its page

## Why

The backend for a leerplicht report is finished. A new attendance flag asks integriq for an exchange job (`AttendanceFlagCreationHandler::requestExchangeJob`), integriq routes it to the Verzuimloket adapter, and `AttendanceFlagReportGuard` allows `report` once that job succeeded. The job waits for a person: `ExchangeGateService` refuses while the flag is still `open`. And nobody can move the flag, because `AttendanceFlagDetail` (`src/manifest.d/people.json`) is `readOnly` and declares no `lifecycleActions`. The four transitions (`startHandling`, `report`, `resolve`, `recordMunicipalityFeedback`) exist only in the register. Today the report reaches the authority through the OpenRegister lifecycle API and nothing else.

The delivered halves are the archived `2026-09-28-data-exchange-to-integriq` (learniq) and `integriq/2026-09-29-learniq-exchange-jobs-native`. This change adds the missing part: the actions on the page.

### Matrix rows (`openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `att-report-absence-to-authority` | Report persistent absence onward to the authority. | `specified`: backend path built, no page starts it |

## What changes

- `AttendanceFlagDetail` shows the flag's transitions in its header: "Take up", "Mark as reported", "Close" and "Record the municipality's answer". The flag's data stays read only; only the lifecycle moves.
- The header says why "Mark as reported" is refused while the exchange job has not succeeded, using the guard's own reason.
- The flag page shows the exchange job's status next to the flag, so the person who took it up sees when the report went out.
- "Record the municipality's answer" asks for the MAS route and a note, the two fields a coordinator types; `receivedAt` and `recordedBy` stay server stamps.
- The flags list gets a status column and filter, so open flags stand out.

## Capabilities

### Modified capabilities

- `attendance`: ADDED requirements for handling a flag from its page.

## Impact

- **Register**: `recordMunicipalityFeedback.inputs` declares `municipalityFeedback.masRoute` and `municipalityFeedback.note` instead of the whole object (see design D3). No schema change.
- **Frontend**: `src/manifest.d/people.json` (AttendanceFlagDetail, AttendanceFlags), `l10n/nl.json` and `l10n/en.json`.
- **Backend**: none. The guards and handlers exist.
- **Cross-repo**: if `CnTransitionInputDialog` cannot draw a dotted object input, nextcloud-vue needs that first (listed in the PR).
