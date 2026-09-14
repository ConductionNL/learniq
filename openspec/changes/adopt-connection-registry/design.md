# Design: adopt-connection-registry

The contract is hydra `openspec/changes/connection-registry/design.md`. This file records how learniq meets it and where it fits loosely.

## D1. Which connections are declared

Each candidate was checked against learniq and integriq on `development` on 2026-09-14, not against its name.

| Key | Declared as | Why |
|---|---|---|
| `data-exchange` | `available: false`, link `#section-data-exchange` | `DataExchangeRunHandler` posts to `/apps/openconnector/api/sources/%s/run`. Integriq's whole `sources#` surface is `test`, `logs` and the two circuit-breaker routes. |
| `timetable` | `available: false`, link `#section-data-exchange` | `TimetableImportHandler` resolves the app id and still asks for `api/sources/timetable-import/run`. Its jobs are data-exchange jobs, so it links to the same section. |
| `lti` | `available: false` | `LtiToolPlacementController` posts to `api/lti/deployments/{id}/launch`. Integriq publishes `api/lti/{deployment}/launch`, a public browser leg with a different contract. The grade pull route does exist, and it has nothing to collect without a launch. |
| `eudi-wallet` | `requiredConfig: ["openconnector_api_token"]` | Offer and revocation call `api/eudi/credential-offers` and `.../{id}/revoke`, which integriq publishes. Both skip the call without the token. |
| `payment` | `available: false` | `PaymentInitiationClient` posts to `api/payments/initiate`. Integriq publishes `api/payments` for shillinq's Mollie adapter, with another request shape. |
| `sbb` | `available: false` | See below. |
| `proctoring` | `available: false` | `ProvidesProctoring` has no implementation and no caller in the fleet. |
| `plagiarism` | `available: false` | `ProvidesPlagiarismCheck` has no implementation and no caller in the fleet. |

**Why these rows are not available, rather than reported as error.** The four broken calls fail on every instance, and the reason is in the code, not in the environment. A declaration says it without a report or a probe. When an endpoint lands, the constant in learniq changes with it, and `ConnectionsDeclarationTest` goes red on the old path. That is the moment to make the row available.

**Why SBB is `available: false`, not `reportedOnly`.** `BpvLeerbedrijfVerificationHandler` does call a provider, and the provider class is named on each `BpvPlacement`. A report would need a provider to observe. None ships in learniq, integriq or anywhere else in the fleet, so every placement ends on "no provider configured". A `reportedOnly` row would stay on Not checked yet forever. When a provider ships, the row becomes `reportedOnly` and learniq reports the outcome of the last check.

**Why payment is one row.** The provider is a field on each payment. Design D12 says a family gets one row. Today every provider fails the same way, so one row tells the whole truth.

**No source templates.** Integriq seeds no source template for DUO/BRON, OSO, Zermelo, Untis, Xedule, LTI, a payment provider or SBB. No row names one.

## D2. What learniq reports

Only the wallet row can take a report. Rule 2 of D4 ignores a report on an unavailable row.

`WalletOfferDelegationService` records one observation per offer through `ConnectionReportService::observe()`:

- no token: `unconfigured`, "No integriq API token is set, so the last wallet offer was not sent.";
- a call that throws: `error`, with the reason cut at 300 characters;
- an answer without JSON, or without a usable offer: `error`, naming that;
- an offer with a usable reference: `configured`, "The last wallet offer reached integriq."

The same status and reason again change nothing, so a batch of offers costs one config read each and no write. The value is a lazy config key, never loaded per request.

`reportObservations()` sends the stored observation as `ConnectionStatusReportedEvent('learniq', 'eudi-wallet', status, message)`. The message ends with "Unchanged since {time} UTC.", so a resend claims no fresh call.

**Why record, then send later.** The offer runs inside an OpenRegister lifecycle guard. Integriq's listener writes an OpenRegister object. Nesting that write inside another object's transition is a risk this change does not need to take.

**Why no refresh event.** A refresh is for a save that changes a `requiredConfig` or `adapter.configKey` value (D6). Learniq's settings save writes `register` only, and no screen writes `openconnector_api_token`. It is set with `occ`, and integriq's hourly job resolves every row (D7). A refresh that can never fire would be dead code.

## D3. When it reports

- `ConnectionReportJob`, a `TimedJob` once a day. It sends again each day, so a report integriq refused before its first sync lands on the next run.
- `SettingsController::update()`, after the save, so an admin sees the latest outcome without waiting a day.

The event is named by string constant and built only when the class exists (ADR-041). Without integriq nothing is sent or logged, and the observation is kept. A listener that throws is caught and logged, and never reaches the save.

## D4. The page

- `src/manifest.d/connection-registry.json`: an `index` page `ConnectionRegistry` at `/settings/integrations`, `requiresApp` integriq, `showAdd: false`.
- Its menu entry `ConnectionRegistryMenu` sits in the settings gear with `query: {app: learniq}` and `visibleIf` on `appInstalled: integriq` and the admin role. Learniq gates every menu entry on `user.primaryRole` (`tests/validate-menu-role-gates.js`), so the entry does the same.
- The page carries no `permission` key, like every other learniq page. The `app_connection` schema is admin-only on its own (hydra D3).
- Columns follow dossiq: connection, status, status message, last checked, settings.
- `src/utils/connectionRegistry.js` holds `connectionStatus`, `connectionSettingsLabel` and `openIntegriqConnections`.

**Formatters.** The pinned `@conduction/nextcloud-vue` 2.37.0 ships no `connectionStatus` built-in (nextcloud-vue#1163 and #1165 merged after it), so learniq carries a local copy with all six labels, `limited` included.

**Handler.** `CnIndexPage` resolves a header action's handler name against `customComponents` only. `App.vue` passes the one handler through that prop.

## Risks

- **Most rows read Not available.** That is the truth on `development` today, and the reason this page is worth having.
- **The token key still says openconnector.** Integriq's register slug is `integriq`, and the key names the old app. The value is a bearer credential and does not depend on the slug, so nothing breaks. Renaming the key needs a repair step that copies stored values.
