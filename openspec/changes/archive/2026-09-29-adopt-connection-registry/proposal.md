---
kind: code
---

# Proposal: adopt-connection-registry

## Summary

Admins get one page in learniq that lists its outside connections and says whether each one really works. The rows come from integriq's connection registry (hydra `openspec/changes/connection-registry`, hydra#667, amended in hydra#673).

## Motivation

Learniq reaches eight outside systems or services, and no screen says which of them work:

- **Data exchange** and **timetable import** run jobs through integriq. Both call `api/sources/[target]/run`, which integriq does not publish.
- **LTI tools** launch through `api/lti/deployments/[id]/launch`, which integriq does not publish either.
- **Payments** start through `api/payments/initiate`, which integriq does not publish.
- **EUDI wallet** offers and revocations reach integriq's real endpoints, once an API token is set.
- **SBB leerbedrijf check**, **proctoring** and **plagiarism check** are provider interfaces with no provider.

The code comments already say most of this. An admin never reads them. A failed data-exchange run looks like a misconfigured source, not like a call to an endpoint that does not exist.

## Affected projects

- [x] `learniq`: a connection declaration, an Integrations page under the settings gear, a recorded wallet outcome with a daily report, a section anchor on the admin page, tests.

Integriq owns the rows, the statuses and the Connections overview. Nothing in integriq changes here.

## Scope

### In scope

1. `lib/Settings/connections.json` with eight connections.
2. An `index` page at `/settings/integrations` over `integriq/app_connection`, reached from the settings gear, admins only.
3. A header action Add integration that opens `/apps/integriq/connections?app=learniq&link=1`.
4. `ConnectionReportService`: the wallet offer records what it met, and a daily `ConnectionReportJob` and every settings save send it to integriq.
5. `id="section-data-exchange"` on the admin page.

### Out of scope

- Building the missing integriq endpoints, or pointing learniq at other ones. Each is its own change in integriq and learniq together.
- Renaming the `openconnector_api_token` config key. Stored values would be orphaned without a repair step.
- Proctoring, plagiarism and SBB providers.

## Depends on

- hydra `connection-registry`, design D2, D4, D6, D8, D9 and D12.
- integriq on `development` with the connection registry (integriq#2010).

## Rollback

Revert the change. Learniq writes no rows of its own. Integriq keeps its rows until its next sync finds no declaration and deletes the rows without a linked source. The stored wallet observation is one lazy config key and does nothing without this code.
