---
kind: config
depends_on: []
---

## Why

`findings.md` rows `11.7` (rostering import, SHOULD) and `13.15` (migration import, SHOULD). Verified at
HEAD: `11.7` is `partial` — `lib/Timetabling/TimetableImportHandler.php` and the `timetable-import` target
already cover Zermelo/Untis/Xedule, but TimeEdit (named alongside them by `mbo-he-sis` competitor evidence:
`eduarte`/`osiris`/`progress` all connect to "cambo, verzuimloket en ROD MBO"-style rostering suites
including TimeEdit) has no preset at all. `13.15` is a total absence — zero hits for ParnasSys, ESIS,
Magister or SOMtoday anywhere in the tree, against four competitor systems each documenting a one-way
migration-import koppeling (`social-schools`: "ParnasSys koppeling... and ESIS koppeling; EDEXML import on
migration"; `kwieb`: "ParnasSys and ESIS daily sync"; `hoy`: "Officieel partner van Somtoday en Magister").

D3 (`decisions.md`): learniq declares the job type and payload mapping; integriq owns the adapter
(`integriq-adapter-rostering-imports`, tracked separately). This is the smallest change in the lane (`S` per
`change-plan.md`) and, unlike the four before it, needs no new schema and no PHP file at all — field maps
only, exactly matching the `config` label `change-plan.md` gives it.

Two families, five seeds:

- **Rostering import (TimeEdit)**: the fourth named rostering system in `M3-integrations.md`'s "rostering
  imports" family (Zermelo/Untis/Xedule already seeded; TimeEdit was the one missing). Reuses the existing
  `timetable-import` target and `session` sourceSchema — this is a preset addition to an existing contract,
  not a new one.
- **Migration import (ParnasSys/ESIS/Magister/SOMtoday)**: a NEW target, `migration-import`, since none of
  the four systems' one-time SIS-to-SIS migration transfer shares the `timetable-import` target's shape (a
  migration import carries the full `LearnerProfile` record, not a Session/rooster row). One seed per source
  system, `direction: import`, `sourceSchema: learner-profile`, mirroring the `bron-rod` export seed's field
  set in reverse (the migration import populates the same identity fields the BRON export sends out).

## What Changes

- Extend `DataExchangeJob.target`/`DataMappingProfile.target` descriptions to name `migration-import`.
- Add a `DataMappingProfile` seed: `TimeEdit timetable import` (`timetable-import`, import, `session` →
  `TimeEdit:Activity`), matching the Zermelo/Untis/Xedule seeds' field shape exactly.
- Add four `DataMappingProfile` seeds, one per migration source (`migration-import`, import,
  `learner-profile` → `<System>:Leerling`): ParnasSys, ESIS, Magister, SOMtoday — each mapping `eckId`,
  `givenName`, `familyName`, `birthDate`, `schoolId`.

## Impact

- Affected specs: `data-exchange` (ADDED: TimeEdit rostering-import preset requirement, migration-import job
  type requirement).
- Affected code: `lib/Settings/learniq_register.json` only (target descriptions + five seeds). No PHP files.
- No integriq adapter code (`integriq-adapter-rostering-imports`, separate — the same adapter change also
  covers the migration-import target per `change-plan.md`'s own naming).
