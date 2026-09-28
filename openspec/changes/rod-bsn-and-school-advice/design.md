# Design: rod-bsn-and-school-advice

## Context

Since #1157 integriq carries learniq's exchanges and asks learniq's gate (`ExchangeGateService`)
whether a job may run and which records leave. `ExchangeDisclosure` holds the field list per
integriq mapping; `DataExchangePayloadBuilder` composes the records. Two gaps:

1. The ROD learner mapping sends `eckId`. DUO's ROD message has no place for an ECK iD: it
   identifies a pupil by the persoonsgebonden nummer, a choice between `burgerservicenummer` and
   `onderwijsnummer` (both AN9, elfproef or its adapted variant).
2. `SchoolAdviesSendToRodHandler` names no mapping, so the gate refuses every school advice job
   `disclosure-undefined`.

LearnerProfile has a legacy `bsnEncrypted` (an app-side ICrypto ciphertext nobody writes). It is
left alone and stays on the NEVER list.

## Source: DUO's ROD specification

DUO, *Programma van Eisen ROD-PO*, versie 1.14.2, dated 15-4-2026, retrieved 2026-09-28 from
https://duo.nl/zakelijk/images/pve-po.pdf (linked from
https://duo.nl/zakelijk/primair-onderwijs/softwareleveranciers/softwareleveranciers-las.jsp).
Section 7.9.1 `AanleverenAdviesVO_Request`, contract `DUO_PO_AdviesVO_V1`; value list 7.9.1.2
`AdviesVO`; controls 7.9.1.1.

| DUO element | Required | Format | Learniq key in the gate record | Learniq source |
|---|---|---|---|---|
| persoonsgebonden nummer: burgerservicenummer or onderwijsnummer | yes | AN9..9 | `persoonsgebondenNummer` + `persoonsgebondenNummerType` (`burgerservicenummer` or `onderwijsnummer`) | LearnerProfile `personalNumber` / `personalNumberType` |
| adviesvolgnummer | yes | AN20, letters and digits, unique per person, instellingscode and school year | `adviesvolgnummer` | first 20 characters of the SchoolAdvies uuid without dashes |
| onderwijsaanbieder | no (checked from adviesjaar 2024) | nnnAnnn | `onderwijsaanbieder` | School `onderwijsaanbiedercode` (new) |
| onderwijslocatie | no (checked from adviesjaar 2024) | nnnXnnn | `onderwijslocatie` | Vestiging `onderwijslocatiecode` |
| vestigingscode | yes | AN6..6 | `vestigingscode` | Vestiging `vestigingscode` |
| adviesjaar | yes | N4, the calendar year the school year ends | `adviesjaar` | end year of SchoolAdvies `academicYear` (`2025-2026` gives `2026`) |
| Advies1 { Advies, Adviesdatum } | at least one advice | AdviesVO value, date | `advies1`, `advies1Datum` | `voorlopigAdviesLevel`, `voorlopigAdviesDate` |
| Advies2 { Advies, Adviesdatum } | no | AdviesVO value, date | `advies2`, `advies2Datum` | `definitiefAdviesLevel`, `definitiefAdviesDate` |

Advies2 is "het definitieve schooladvies": after a heroverweging it is the reconsidered advice
(D32's "reconsidered advice"); DUO checks it is not lower than Advies1 (control 102). The
heroverweging motivation and the doorstroomtoets result stay in the dossier (D32: nothing from the
pupil dossier).

Level mapping to the AdviesVO value list: `pro` `PRAKTIJKONDERWIJS`, `vmbo-bb` `VMBO_BB`, `vmbo-kb`
`VMBO_KB`, `vmbo-gt` `VMBO_GL/TL`, `havo` `HAVO`, `vwo` `VWO`. An unknown level gives null, which the
completeness check refuses.

## Decisions

### D1: the field is `personalNumber`, flagged encrypted, with a property authorization
`x-openregister-encrypted: true` (OpenRegister `openspec/specs/field-level-encryption`, on
origin/development) stores an `openregister:enc:v1:` envelope and decrypts only after the property
authorization strip, so a reader outside `administration-managers` and `compliance-officers` gets
no key at all, never ciphertext. `audit: true` on the property authorization records who saw it
(OpenRegister `RevealCollector`). `personalNumberType` gets the same authorization but no encryption
(it is an enum, and knowing a pupil has no BSN is sensitive too). No JSON Schema `pattern` on the
encrypted value: a merge of stored data would validate the envelope; the resolver checks the number.
OpenRegister's `admin` group bypasses property authorization by platform design; that is the
instance administrator, and the reveal audit still records it.
Alternative: reuse `bsnEncrypted`. Rejected: it expects an app-side ciphertext no code writes, and
cannot hold an onderwijsnummer honestly.

### D2: one reader, a system read, used only for the two ROD mappings
The gate runs from integriq's event without a user, so an RBAC read would strip the property. The
new `RodPersonalNumberResolver` reads the profile with `_rbac: false, _multitenancy: false` and the
tenant forced (the builder's existing rule), validates the number and returns the pair. The
builder calls it only when the target is `bron-rod` and the mapping is one of the two ROD
mappings. The resolver logs only a profile id on a failed read, never a value, and catches read
errors itself so no exception message can carry object data.

### D3: required fields per mapping
`ExchangeDisclosure::requiredFor()` takes the mapping slug; a mapping-level list wins over the
target list. ROD learner: `eckId`, `birthDate`, `schoolId`, `persoonsgebondenNummer`,
`persoonsgebondenNummerType`. ROD school advice: `persoonsgebondenNummer`,
`persoonsgebondenNummerType`, `adviesvolgnummer`, `onderwijsaanbieder`, `onderwijslocatie`,
`vestigingscode`, `adviesjaar`, `advies1`, `advies1Datum`. DUO marks onderwijsaanbieder and
onderwijslocatie optional in the table but rejects a message without them from adviesjaar 2024
(controls 030, 031), so learniq refuses first. A value outside DUO's format (nnnAnnn, nnnXnnn, six
characters for the vestigingscode) counts as missing.

### D4: the school advice names its vestiging
SchoolAdvies gets `vestigingId` (a Vestiging). Without it, a tenant with exactly one Vestiging uses
that one; otherwise `vestigingscode` stays null and the job is refused `statutory-incomplete`,
naming the field. School gets `onderwijsaanbiedercode` (RIO, nnnAnnn).

## Declarative-vs-imperative decision

| Behaviour | Path | Why |
|---|---|---|
| Encryption at rest | declarative, `x-openregister-encrypted` | OpenRegister owns it |
| Read restriction and reveal audit | declarative, property `authorization` with `audit` | OpenRegister owns it |
| Composing the ROD records | imperative, inside the existing gate | ADR-031 exception: external integration payload, the gate already composes |

## Risks / Trade-offs

- [An admin read shows the number] → platform rule; the reveal audit records it.
- [A PUT by someone who may update the profile but not read the number] → OpenRegister restores an
  omitted write-only value, but a property-authorized one follows normal PUT semantics; the
  `update` authorization names the same two groups so only they may write it, and forms send the
  whole object as read. Named here, not solved: it is a platform concern.
- [An OpenRegister object export by an administration manager includes the number] → that export
  runs under the reader's own authorization, which is the access D31 grants; learniq's own exports
  (integriq mappings, pass-through) never carry it.

## Migration Plan

No data migration: the field is new. Rollback: revert; stored envelopes stay unreadable until the
flag returns, which is safe.

## Seed Data

No new schema. The mock register's learner profiles get no `personalNumber` (a realistic number in
seed data would be a real person's BSN or a valid-looking fake; neither belongs in a repo). Tests
use DUO-style test numbers built to pass the checks (`111222333` for a BSN).

## Open Questions

- Sending the voorlopig advice on its own within DUO's 14-day window (the lifecycle only sends from
  `definitief`).
