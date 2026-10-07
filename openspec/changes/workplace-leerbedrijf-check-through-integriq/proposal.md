---
kind: code
---

# Check the company is an approved training company first

## Why

An mbo pupil in the beroepspraktijkvorming (BPV, the work placement) may only train at a company that SBB recognises as a leerbedrijf. learniq already refuses to confirm a placement until that check says `verified` (`BpvConfirmationGuard`, bpv spec). It has nothing that runs the check. `lib/Bpv/ProvidesLeerbedrijfVerification.php` is an interface with no implementation, and the connections page says so: "No verification provider ships in learniq or integriq." So today a coordinator cannot confirm any placement through the app.

The bpv spec told learniq to ship no provider and left the SBB adapter to openconnector, now integriq. This change keeps the SBB wire protocol in integriq and ships the one thin provider learniq needs to ask integriq.

One row, one change.

### Matrix rows (`learniq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `wpl-check-the-company-is-approved` | Check the company is an approved training company first. | `no`: an interface without a provider; no placement can confirm |

### Decision on record

`openspec/parity/gap-decisions.json` records `defer` for this row (integriq's lane, 2026-09-27: no competitor rates yes, no demand). The spec round of 2026-10-07 writes a spec for every unbuilt row without one, so this change exists to make the row buildable. Building it stays Ruben's call.

## What changes

- learniq ships `IntegriqLeerbedrijfVerification`, a provider that asks integriq to look a company up by KVK number or SBB erkenningsnummer and maps the answer to `verified`, `rejected`, `expired` or `pending`.
- The `checkLeerbedrijf` action on the placement page runs it and stores the result, the erkenningsnummer and its end date on the placement.
- A verified result that expires before the placement ends shows a warning on the placement.
- The connections page row `sbb` reports available once integriq answers that its SBB source is configured.

## Capabilities

### Modified capabilities

- `bpv`: MODIFIED "Leerbedrijf verification is a pluggable provider" (learniq now ships one provider that delegates to integriq); ADDED requirements for the check and its result.

## Impact

- **Backend**: `lib/Bpv/IntegriqLeerbedrijfVerification.php`; `BpvLeerbedrijfVerificationHandler` resolves it when `trainingCompanyVerification.provider` is `integriq` or empty.
- **Register**: `trainingCompanyVerification` gains `erkenningNumber`, `expiresAt` and `checkedAt` if they are not already there.
- **Settings**: `lib/Settings/connections.json` row `sbb` becomes `reportedOnly`, like `timetable` and `lti`.
- **Depends on**: an SBB source in integriq (integriq row `con-sbb`). Without it the check ends `pending` with a reason, and the placement still cannot confirm, as today.
