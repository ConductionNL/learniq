---
kind: config
depends_on: []
---

## Why

`findings.md` rows `13.3` (UWLR/ECK iD, MUST), `13.4` (Edu-V/Basispoort, MUST) and `5.5` (method
integration/SSO to publisher content, MUST) are three of the round's cleanest total-absence gaps:
grepping the whole tree for UWLR returns zero hits beyond `LearnerProfile.eckId` itself, and zero hits for
Basispoort or Edu-V at all. `M3-integrations.md` row I4 (UWLR/Edu-V data services) and I6 (Basispoort) both
record `learniq today` as `none`/`zero hits` against ten-plus competitor cells naming these connections
directly (`parnassys`: "UWLR link type reserved for education suppliers"; `po-las` (esis): "UWLR
Leerlinggegevens coupling type... Edu-V 5 components qualified"; `vo-las` (somtoday): "UWLR 2.2 + ECK-iD").

D3 (`decisions.md`): learniq declares the job type and payload mapping; integriq owns the adapter
(`integriq-adapter-uwlr-eduv`, tracked separately, also gated on Edu-V keurmerk certification per
`M3-integrations.md`'s governance note — not a blocker for this change, which ships no wire code).

This change is pure `DataMappingProfile`/`DataExchangeJob.target` declaration — no new schema, no lifecycle
gate (unlike `lvs-import-contract`/`oso-inbound-contract`, the inbound direction here is a generic
data-service pull, not a discretionary transfer or an unreviewed create). `change-plan.md` labels this row
`config` and, unlike the two before it in this lane, that label holds here: no PHP file changes at all.

Three named connections, one change, per D3's own framing (`M3-integrations.md` I4 treats UWLR and Edu-V as
one row: "UWLR / Edu-V data services... pupil, group, teacher data to publishers and test suppliers; results
back"):

- **UWLR**: pupil/group/teacher export carrying ECK iD, plus the generic "results back" data-service
  direction. The LVS-specific normed-toets result shape already has its own schema and job type
  (`lvs-import-contract`'s `lvs-results`/`LvsResult`) — this change's `uwlr` (direction: import) seed
  deliberately reuses `LvsResult` as the landing shape for UWLR's generic results-back capability rather
  than inventing a second, competing results schema (both rows cite the SAME UWLR transport:
  `M3-integrations.md` I4 and I8). `DataMappingProfile.sourceSchema` is a free-form string, never a
  `$ref`-enforced pointer, so this seed is valid regardless of whether `lvs-import-contract` has merged yet
  — it is simply inert until `LvsResult` exists.
- **Edu-V**: the qualified-data-service afsprakenstelsel some suppliers use instead of/alongside UWLR
  (`M3-integrations.md` I4: "qualified per data service, per product" — Onderwijsdeelnemers,
  Onderwijsmedewerkers, Onderwijsgroepen are separate qualified components, not one blob).
- **Basispoort / Entree content SSO**: `M3-integrations.md` I6 draws the PO/VO line explicitly — Basispoort
  is PO-only (`parnassys#13.4,5.5`: "sends pupil/group/ECK-iD/staff/BRIN"); VO uses Edu-V/UWLR directly for
  the data plane and Entree for the content-access SSO hand-off instead. Both seeds are `direction: sync`
  (a combined SSO-plus-export hand-off, not a one-way export) and are distinct from
  `entree-surfconext-sso-contract`'s own concern: THAT change is federated login into learniq itself; these
  seeds are learniq handing a pupil off to a THIRD-PARTY method/publisher site, matching D3's
  job-type-owns-mapping pattern for every other external connection.

## What Changes

- Extend `DataExchangeJob.target`'s description to document four new named connections: `uwlr`, `edu-v`,
  `basispoort`, `entree-content` (`surfconext` already exists as a distinct target for the SEPARATE login
  concern — unchanged here).
- Extend `DataMappingProfile.target`'s description likewise.
- Add nine `DataMappingProfile` seeds (field maps only, no schema changes):
  1. `UWLR pupil export` (`uwlr`, export, `learner-profile` → `UWLR:Leerling`, carries `eckId`)
  2. `UWLR group export` (`uwlr`, export, `cohort` → `UWLR:Groep`)
  3. `UWLR teacher export` (`uwlr`, export, `learner-profile` → `UWLR:Onderwijsmedewerker`, carries `eckId`
     — teaching staff are `LearnerProfile` rows with `roles: ['instructor']`, this register has no separate
     Staff schema)
  4. `UWLR results import (generic data services)` (`uwlr`, import, `lvs-result` → `UWLR:Onderwijsresultaten`
     — reuses `LvsResult`, see Why)
  5. `Edu-V Onderwijsdeelnemers data service export` (`edu-v`, export, `learner-profile` →
     `EduV:Onderwijsdeelnemers`, carries `eckId`)
  6. `Edu-V Onderwijsgroepen data service export` (`edu-v`, export, `cohort` → `EduV:Onderwijsgroepen`)
  7. `Edu-V Onderwijsmedewerkers data service export` (`edu-v`, export, `learner-profile` →
     `EduV:Onderwijsmedewerkers`, carries `eckId`)
  8. `Basispoort SSO and pupil/group/staff export (PO)` (`basispoort`, sync, `learner-profile` →
     `Basispoort:Leerling`, carries `eckId`)
  9. `Entree content SSO hand-off (VO)` (`entree-content`, sync, `learner-profile` →
     `Entree:MethodeToegang`, carries `eckId`)

## Impact

- Affected specs: `data-exchange` (ADDED: UWLR/Edu-V/Basispoort/Entree-content job-type and payload-mapping
  requirements).
- Affected code: `lib/Settings/learniq_register.json` only (target descriptions + nine seeds). No PHP files.
- `DataExchangePayloadBuilder`'s `MANDATORY_PROFILE_TARGETS` fail-closed allowlist is NOT extended in this
  change (that is a PHP edit, out of scope for a `config`-kind change per ADR-032) — tracked as a follow-up
  so an unmapped `uwlr`/`edu-v`/`basispoort` job does not silently pass through PII-stripped rather than
  hard-failing, matching the C3 posture `bron-rod`/`oso`/`edukoppeling`/`swv` already have.
- No integriq adapter code (`integriq-adapter-uwlr-eduv`, separate, also gated on Edu-V keurmerk
  certification per M3c — not a blocker here).
- No change to `entree-surfconext-sso-contract`'s scope — that change is federated LOGIN, this change is
  content-access hand-off to a third party.
