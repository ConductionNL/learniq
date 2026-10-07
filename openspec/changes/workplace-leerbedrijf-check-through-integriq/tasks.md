# Tasks: check the company is an approved training company first

## 0. Before building

- [ ] 0.1 Confirm with Ruben that the row moves from defer to build. Confirm integriq has, or will build, an SBB source and the lookup route; record its name here.

## 1. Provider

- [ ] 1.1 Add `lib/Bpv/IntegriqLeerbedrijfVerification.php` implementing `ProvidesLeerbedrijfVerification`: call integriq's SBB lookup with the existing integriq token; map the answer to `verified`, `rejected`, `expired`, `pending`. Verify: PHPUnit for each status, no token, integriq absent, HTTP error.
- [ ] 1.2 Make `BpvLeerbedrijfVerificationHandler` resolve it when the provider is empty or `integriq`. Verify: a wiring test from the real `checkLeerbedrijf` transition.

## 2. Register and settings

- [ ] 2.1 Make sure `trainingCompanyVerification` has `erkenningNumber`, `expiresAt`, `checkedAt`, `reason`; validate the handler's written payload against the real schema fragment (Opis). Verify: `npm run check:register`, PHPUnit.
- [ ] 2.2 `lib/Settings/connections.json` row `sbb`: `reportedOnly`, reported by the daily status job and on saving the admin settings, as `timetable` does. Verify: `npm run check:json-strict`.

## 3. Screen

- [ ] 3.1 Placement page: label the action "Leerbedrijf controleren", show status, erkenningsnummer and end date, and the expiry warning. Verify: `npm run check:manifest`, `npm run check:l10n`.

## 4. Close out

- [ ] 4.1 Live check against integriq's SBB source in mock mode: verified, rejected, pending.
- [ ] 4.2 Set row `wpl-check-the-company-is-approved` to built and archive this change.
