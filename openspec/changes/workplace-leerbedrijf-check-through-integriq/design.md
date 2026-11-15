# Design: check the company is an approved training company first

## Context

At development `24b9ae95`:

- `lib/Bpv/ProvidesLeerbedrijfVerification.php`: `verify(string $kvkOrErkenningNumber): array{status, erkenningNumber, expiresAt, raw}`.
- `BpvPlacement` lifecycle: `proposed` → `checkLeerbedrijf` → `sbb-verification-pending` → `confirm` (guard `BpvConfirmationGuard`) → `confirmed` → `active` → `completed`. Fields `trainingCompanyName`, `trainingCompanyKvkNumber`, `trainingCompanyVerification`.
- `lib/Settings/connections.json` row `sbb`: `available: false`.
- learniq already reaches integriq the same way for wallets and exchange jobs (`openconnector_api_token`, `IntegriqExchangeClient`).

## Screen

No board. The canvas (`5NkFW28vZUUij43xzxHg5a`) draws a vo school's teacher; BPV is mbo only and is listed as "ander segment" in `capabilities-learniq.md`. The placement page in the app keeps its existing layout; the check is its existing `checkLeerbedrijf` action.

## Decisions

### D1: The wire protocol stays in integriq

ADR-022 and the bpv spec: learniq does not talk to SBB. The provider sends a KVK number or erkenningsnummer to integriq and maps the answer. If SBB changes its API, only integriq changes.

### D2: Fail closed

No integriq, no token or no SBB source gives `pending` with a reason. `BpvConfirmationGuard` already refuses anything but `verified`, so a missing integration can never let a placement through.

### D3: Expiry is a warning, not a block

A recognition that ends during the placement does not undo the confirmation; SBB can renew it. The placement shows the end date and a warning, so the coordinator checks again in time.

## Open question for Ruben

The gap decision says defer. Build when an mbo school asks for it.
