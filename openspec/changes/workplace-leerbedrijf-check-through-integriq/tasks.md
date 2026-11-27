# Tasks: check the company is an approved training company first

## 0. Before building

- [x] 0.1 Confirm with Ruben that the row moves from defer to build. Confirm integriq has, or will build, an SBB source and the lookup route; record its name here.
  - Decided 24 Nov 2026 under Ruben's standing delegation of product decisions (22 Nov): build. The defer was about demand, not risk, and the learniq side fails closed, so building it changes nothing for a school without integriq's SBB source. The lookup is integriq's typed event `OCA\Integriq\Event\TrainingCompanyLookupRequestedEvent` (design D4); integriq has no SBB source yet, so the check ends `pending` with "integriq has no SBB source configured" until it does (integriq row `con-sbb`).

## 1. Provider

- [x] 1.1 Add `lib/Bpv/IntegriqLeerbedrijfVerification.php` implementing `ProvidesLeerbedrijfVerification`: call integriq's SBB lookup with the existing integriq token; map the answer to `verified`, `rejected`, `expired`, `pending`. Verify: PHPUnit for each status, no token, integriq absent, HTTP error.
  - `lib/Bpv/IntegriqLeerbedrijfVerification.php`; `tests/Unit/Bpv/IntegriqLeerbedrijfVerificationTest.php` (verified, rejected, expired, no integriq, no source, no answer, source down). The token is not needed: integriq is asked in process through its typed event (ADR-041), as the open material source is.
- [x] 1.2 Make `BpvLeerbedrijfVerificationHandler` resolve it when the provider is empty or `integriq`. Verify: a wiring test from the real `checkLeerbedrijf` transition.
  - `BpvLeerbedrijfVerificationHandler::resolveProvider()`; `BpvLeerbedrijfVerificationHandlerTest::testWithoutANamedProviderTheIntegriqProviderChecksAndFailsClosed` runs the handler with the real provider and the real `BpvConfirmationGuard`.

## 2. Register and settings

- [x] 2.1 Make sure `trainingCompanyVerification` has `erkenningNumber`, `expiresAt`, `checkedAt`, `reason`; validate the handler's written payload against the real schema fragment (Opis). Verify: `npm run check:register`, PHPUnit.
  - `trainingCompanyVerification` gains `checkedAt`, `reason` and `endsBeforePlacement` (BpvPlacement 0.8.0, register 0.68.0); `erkenningNumber` and `expiresAt` were there. The handler writes them (`testConfiguredProviderWritesVerifiedResultBack`, `testARecognitionEndingMidPlacementIsFlagged`).
- [x] 2.2 `lib/Settings/connections.json` row `sbb`: `reportedOnly`, reported by the daily status job and on saving the admin settings, as `timetable` does. Verify: `npm run check:json-strict`.
  - Row `sbb` is `reportedOnly`; `ConnectionReportService::SBB_KEY` is reported, and every check records what it met (`configured`, `unconfigured`, `error`, `unavailable`), sent by the daily job and on a settings save as for the other reported rows.

## 3. Screen

- [x] 3.1 Placement page: label the action "Leerbedrijf controleren", show status, erkenningsnummer and end date, and the expiry warning. Verify: `npm run check:manifest`, `npm run check:l10n`.
  - `BpvPlacementDetail` card: status (with "Recognition ended"), why not approved, recognition number, recognised until, and the warning when `endsBeforePlacement`; the schema transition `checkLeerbedrijf` carries the label "Check training company" (nl "Leerbedrijf controleren").

## 4. Close out

- [ ] 4.1 Live check against integriq's SBB source in mock mode: verified, rejected, pending.
- [ ] 4.2 Set row `wpl-check-the-company-is-approved` to built and archive this change.
