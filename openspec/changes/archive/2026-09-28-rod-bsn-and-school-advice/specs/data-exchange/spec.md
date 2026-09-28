# Delta: data-exchange (rod-bsn-and-school-advice)

Decision D31 replaces the project rule "never expose BSN" for exactly one destination: DUO's ROD.
Everywhere else the rule holds.

## ADDED Requirements

### Requirement: A learner's personal number is encrypted and readable only by administration and compliance
LearnerProfile MUST hold the persoonsgebonden nummer in `personalNumber`, flagged
`x-openregister-encrypted: true`, with its kind in `personalNumberType` (`bsn` or
`onderwijsnummer`). Both properties MUST carry a property authorization whose `read` and `update`
name only `administration-managers` and `compliance-officers`, and `personalNumber` MUST ask for a
reveal audit (`audit: true`). Neither property MAY be a filter, facet or search field.

#### Scenario: a teacher reads a learner profile
- GIVEN a learner profile with a `personalNumber`
- WHEN a user in `instructors` reads it
- THEN the response has no `personalNumber` and no `personalNumberType`

#### Scenario: the register declares the protection
- GIVEN the learniq register
- WHEN LearnerProfile is loaded
- THEN `personalNumber` is flagged encrypted, and both properties authorize only `administration-managers` and `compliance-officers`

### Requirement: The ROD learner record carries the personal number where DUO expects a BSN
A `bron-rod` job with mapping `learniq-bron-rod-export-learner` MUST hand each record
`persoonsgebondenNummer` (nine digits) and `persoonsgebondenNummerType` (`burgerservicenummer` or
`onderwijsnummer`) next to `eckId`, `givenName`, `familyName`, `birthDate` and `schoolId`. A number
that is not nine digits or fails its check (elfproef for a BSN, the adapted elfproef for an
onderwijsnummer) MUST count as missing, and the gate MUST refuse the job `statutory-incomplete`
naming the field and the record reference, never the value.

#### Scenario: a ROD export sends the BSN and keeps the ECK iD
- GIVEN a `bron-rod` job with mapping `learniq-bron-rod-export-learner` over a complete profile whose `personalNumber` is a valid BSN
- WHEN integriq asks the gate
- THEN the record holds `persoonsgebondenNummer` with that BSN, `persoonsgebondenNummerType` `burgerservicenummer`, and the `eckId`

#### Scenario: a profile without a valid number
- GIVEN a `bron-rod` learner job whose profile has no `personalNumber`
- WHEN integriq asks the gate
- THEN the gate refuses with `statutory-incomplete`, naming `persoonsgebondenNummer` and the record, and the reason holds no number

### Requirement: The personal number leaves learniq only in a ROD message and is never logged
No mapping other than the two ROD mappings MAY carry `personalNumber`, `personalNumberType`,
`persoonsgebondenNummer` or `persoonsgebondenNummerType`; a pass-through export MUST drop
`personalNumber` and `personalNumberType` as it drops `bsnEncrypted`. No log line, exception message
or refusal reason written by the gate, the payload builder or the resolver MAY contain the number.

#### Scenario: an HR pass-through export
- GIVEN an `hr` export (no mapping) over a profile with a `personalNumber`
- WHEN the gate composes the records
- THEN no record holds `personalNumber`, `personalNumberType` or the number anywhere in its data

#### Scenario: an OSO export
- GIVEN an `oso` job with mapping `learniq-oso-export-dossier`
- WHEN the gate composes the records
- THEN no record holds the number

#### Scenario: nothing is logged
- GIVEN a ROD job whose composition succeeds, and one whose profile read fails
- WHEN the gate runs both
- THEN no captured log message or context value contains the number

### Requirement: A school advice goes to ROD with DUO's AanleverenAdviesVO field set
The school advice ROD handler MUST name mapping `learniq-bron-rod-export-schooladvies`, and the gate
MUST compose, per SchoolAdvies, exactly: `persoonsgebondenNummer`, `persoonsgebondenNummerType`,
`adviesvolgnummer`, `onderwijsaanbieder`, `onderwijslocatie`, `vestigingscode`, `adviesjaar`,
`advies1`, `advies1Datum`, `advies2`, `advies2Datum` (DUO PvE ROD-PO 1.14.2, 7.9.1). Levels MUST be
DUO's AdviesVO values (`pro` to `PRAKTIJKONDERWIJS`, `vmbo-bb` to `VMBO_BB`, `vmbo-kb` to `VMBO_KB`,
`vmbo-gt` to `VMBO_GL/TL`, `havo` to `HAVO`, `vwo` to `VWO`). Nothing else from the advice or the
pupil dossier MAY leave, in particular not the heroverweging motivation or the doorstroomtoets
result. The job MUST no longer be refused `disclosure-undefined`; a record missing
`persoonsgebondenNummer`, `adviesvolgnummer`, `onderwijsaanbieder`, `onderwijslocatie`,
`vestigingscode`, `adviesjaar`, `advies1` or `advies1Datum` MUST refuse it `statutory-incomplete`
(DUO checks the onderwijsaanbieder and onderwijslocatie from adviesjaar 2024, controls 030 and 031).
A value that does not fit DUO's format counts as missing.

#### Scenario: a definitief advice is sent
- GIVEN a SchoolAdvies with voorlopig `vmbo-kb` on 2026-01-20, definitief `vmbo-gt` on 2026-03-20, academic year `2025-2026`, a vestiging with code `02VG00`, and a learner with a valid BSN
- WHEN integriq asks the gate for its `bron-rod` job
- THEN the gate allows one record with `advies1` `VMBO_KB`, `advies1Datum` `2026-01-20`, `advies2` `VMBO_GL/TL`, `advies2Datum` `2026-03-20`, `adviesjaar` `2026`, `vestigingscode` `02VG00` and the BSN, and no other key

#### Scenario: the advice has no vestiging and the tenant has several
- GIVEN a SchoolAdvies without `vestigingId` in a tenant with two vestigingen
- WHEN integriq asks the gate
- THEN the gate refuses with `statutory-incomplete` naming `vestigingscode`

#### Scenario: the handler names the mapping
- GIVEN a SchoolAdvies moving to `verzonden-naar-rod`
- WHEN the handler asks integriq for the job
- THEN it names mapping `learniq-bron-rod-export-schooladvies`
