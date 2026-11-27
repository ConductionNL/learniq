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

### D4: The lookup contract with integriq

Learniq asks in process, through integriq's typed event (ADR-041), as it asks the open material source: `OCA\Integriq\Event\TrainingCompanyLookupRequestedEvent(sourceApp, request)` with request `{source: "sbb", number}` (a KVK number, else an erkenningsnummer already on the placement). Integriq answers `{found, erkenningNumber, endDate}` (endDate `Y-m-d`) or refuses `{code, reason}`; `source-not-available`, `source-disabled`, `unknown-source` and `unknown-operation` mean no SBB source. The class is named by string, so learniq has no class dependency on integriq. The shape is recorded in `tests/Stubs/Integriq/Event/TrainingCompanyLookupRequestedEvent.php` for integriq to implement.

Mapping: not found is `rejected`; found with an end date before today is `expired`; otherwise `verified`. No integriq, no source or no answer is `pending` with a reason; any other refusal is `pending` with "the SBB source did not answer (<code>)".

### D5: The mid-placement warning is worked out when the check runs

The handler stores `endsBeforePlacement` (the end date falls before `periodTo`) with the result, and the page shows the warning from it. A changed `periodTo` updates it on the next check.

## Decision (24 Nov 2026)

The gap decision said defer, waiting for an mbo school to ask. Decided to build under Ruben's standing delegation of product decisions: the learniq side fails closed, so it changes nothing until integriq has an SBB source, and then a school can confirm placements without a custom adapter.
