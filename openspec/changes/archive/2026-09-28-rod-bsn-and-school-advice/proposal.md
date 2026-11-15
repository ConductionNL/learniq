---
kind: code
depends_on: []
---

# Proposal: rod-bsn-and-school-advice

## Summary

DUO's Register Onderwijsdeelnemers (ROD) identifies a pupil by the persoonsgebonden nummer: a BSN,
or an onderwijsnummer when the pupil has no verifiable BSN. Learniq sends the ECK iD there today,
which DUO does not accept. This change gives LearnerProfile a `personalNumber` field (BSN or
onderwijsnummer), encrypted at rest by OpenRegister and readable only by administration managers
and compliance officers, and puts it in the ROD payload the exchange gate composes, and nowhere
else. It also gives the school advice its ROD field set (DUO's AanleverenAdviesVO message: advice
levels, dates, the reconsidered advice, the school), so the gate stops refusing a school advice job
`disclosure-undefined`.

## Motivation

Decisions D31 and D32 (2026-09-28, Ruben): ROD sends the BSN or onderwijsnummer, stored as an
encrypted, access-restricted field, sent only in ROD messages and never logged or exported
elsewhere, with the ECK iD kept for publisher chains; the school advice carries DUO's required set
only, nothing from the pupil dossier. Tracker `TRACKER-R2.md` "ROUND 3 FINAL" lists both as open
follow-ups ("ROD sends ECK iD where DUO expects BSN", "ROD for school advice has no field list").
Since #1157 the gate listener composes the records integriq sends, so the fix lives in the gate.

## Affected Projects

- [ ] Project: learniq — LearnerProfile, School and SchoolAdvies schemas; exchange disclosure,
  payload builder, gate and the school advice ROD handler
- [ ] Project: integriq — its ROD adapter maps the new keys (integriq change `rod-adapter-bsn`, a
  separate PR); not changed here

## Scope

### In Scope

- LearnerProfile `personalNumber` (x-openregister-encrypted, property authorization read and update
  for `administration-managers` and `compliance-officers`, reveal audit on) and `personalNumberType`
  (`bsn` or `onderwijsnummer`).
- School `onderwijsaanbiedercode`; SchoolAdvies `vestigingId`.
- The `learniq-bron-rod-export-learner` records carry `persoonsgebondenNummer` and
  `persoonsgebondenNummerType` next to the ECK iD.
- A new mapping `learniq-bron-rod-export-schooladvies` with DUO's AanleverenAdviesVO field set, named
  by the school advice ROD handler.
- The personal number never appears in a log line, in any other mapping, in a pass-through export,
  or in the SWV, OSO and leerplicht files.

### Out of Scope

- Integriq's adapter and mapping rows (integriq PR).
- Sending the voorlopig advice on its own before the definitief advice exists (the lifecycle only
  sends from `definitief`); see Open Questions.
- The legacy `bsnEncrypted` field stays as it is (still never leaves).
- A settings screen for the new fields; they are edited in the learner and school forms the
  manifest already renders from the schema.

## Approach

Declarative where OpenRegister carries it (encryption, property authorization, reveal audit), and
imperative only inside the existing gate composition: a small `RodPersonalNumberResolver` reads the
profile as a system read (`_rbac: false`, tenant forced), validates the number (9 digits, elfproef
or the onderwijsnummer variant) and returns it only to the payload builder for the two ROD
mappings. Details in design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/learniq_register.json` (info.version and three schema versions)
- `lib/Service/ExchangeDisclosure.php`, `lib/Service/DataExchangePayloadBuilder.php`,
  `lib/Service/ExchangeGateService.php`, new `lib/Service/RodPersonalNumberResolver.php`
- `lib/Listener/SchoolAdviesSendToRodHandler.php`

## Cross-Project Dependencies

The integriq PR for `rod-adapter-bsn` reads the keys named in design.md. Either PR can land first:
integriq ignores keys it does not map, and learniq's new mapping slug only runs once integriq has a
row for it (until then integriq ends the job as it does today).

## Risks

### Risk 1: a BSN leaks through a log line or another export
**Severity**: High
**Mitigation**: one reader, no logging of values anywhere on its path, `personalNumber` and
`personalNumberType` on the NEVER list, and tests that capture every logger call and every other
mapping's output and assert the number is absent.

### Risk 2: the gate reads the profile without a user, so property authorization would strip the field
**Severity**: Medium
**Mitigation**: the resolver reads with `_rbac: false` (OpenRegister's trusted internal read, which
still decrypts), with the tenant forced as the builder already does.

### Risk 3: DUO rejects a record for a missing school code
**Severity**: Low
**Mitigation**: the gate's completeness check refuses the job `statutory-incomplete` naming the
field, before anything leaves.

## Rollback Strategy

Revert the PR. The encrypted values stay in the stored objects as envelopes; with the flag gone
OpenRegister returns the envelope string, so a rollback should also drop the field from the form.

## Open Questions

- DUO expects the voorlopig advice within 14 days of giving it (before the definitief exists).
  Learniq sends only from `definitief`. A follow-up can add a send from `voorlopig`; the field set
  here already allows `advies2` to be null.
