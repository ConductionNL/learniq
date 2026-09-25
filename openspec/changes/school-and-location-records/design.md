# Design: school-and-location-records

## Context
Learniq's register has 118 schemas and no `School`/`Location` at all — `LearnerProfile.schoolId` is a free string (finding 1.1). `Cohort` has `programmeId`/`courseId`/`teacherIds`/`learnerIds` but nothing naming a physical location. Legal recon (`../recon/legal-po-2026-09-25.md`) is explicit that DUO/RIO track three distinct codes (BRIN for the school/bestuur-registered instelling, vestigingscode for the vestiging, onderwijslocatiecode for the physical teaching location) and that a groep MUST belong to exactly one location. ParnasSys and ESIS both model this as a three-level bestuur/school/vestiging tree (`../parnassys/round1/documented-column.md` row 1.1); decision D2 explicitly stops learniq at two levels (school + location) with no bestuur switcher this round.

## Goals / Non-Goals
- **Goal**: make School and Location real, referenceable OpenRegister objects.
- **Goal**: give `Cohort` a way to declare its one location, closing the "one groep per location" statutory gap at the data-model level.
- **Non-goal**: bestuur/multi-school switching (D2, deferred).
- **Non-goal**: DUO/RIO live BRIN validation (integriq's adapter territory, D3 — this change only pattern-validates the BRIN shape client/schema-side).
- **Non-goal**: migrating `LearnerProfile.schoolId` — left untouched (see proposal Out of Scope).

## Decisions

### Decision 1: Plain resource-metadata schema, no lifecycle
`School` and `Location` follow `Room`'s precedent exactly: `x-openregister.active/hardDelete/searchable`, no `x-openregister-lifecycle`. A school or a vestiging is reference data an administrator maintains directly; it does not move through draft/published/archived states the way a `Cohort` or `Programme` does. Alternative considered: giving `School` a draft/active/closed lifecycle to mirror board mergers/closures (row P-new-14) — rejected as premature: P-new-14 is explicitly deferred by D2, and a lifecycle with no transition ever fired is dead scaffolding (the same class of defect D01/D04/D05 exist to fix elsewhere in this register).

### Decision 2: `Cohort.locationId` is a single nullable ref, not an array
DUO's rule is one groep per location — modelling it as `locationIds: []` would silently permit violating that rule. A single nullable `$ref` makes "at most one" the shape itself, not a validation rule someone has to remember to write. Existing Cohorts get `null` (additive) until a coordinator backfills.

### Decision 3: `onderwijslocatiecode` is independent of `vestigingscode`, not derived
`legal-po-2026-09-25.md` names BRIN, vestigingscode and onderwijslocatie as three separate codes. A vestiging can have more than one onderwijslocatie (e.g. a satellite building). Modelling `onderwijslocatiecode` as a second field on the same `Location` object (rather than a child schema) matches this round's proportionality: no finding asks for multiple onderwijslocaties per vestiging to be independently manageable objects, only for the code to be recordable. If a future change needs one-to-many onderwijslocaties per vestiging, this field promotes to its own schema then.

## Declarative-vs-imperative decision (ADR-031)
No lifecycle, aggregation, calculation, notification, or dashboard widget behaviour is introduced by this change — `School`/`Location` are flat reference-data schemas and `Cohort.locationId` is a plain additive relation. Everything here is JSON-only in `lib/Settings/learniq_register.json` plus manifest pages in `src/manifest.d/people.json`. No new `lib/Service/*.php` class.

## Seed Data (ADR-001)
Two `School` seeds and three `Location` seeds, general (non-identifying) organisation shapes:
- `School` seed 1: `brin: "02VG"`, `name: "OBS De Wilgenboom"`, `pedagogicalConcept: "regular"`.
- `School` seed 2: `brin: "14LM"`, `name: "Montessorischool De Ontdekking"`, `pedagogicalConcept: "montessori"`.
- `Location` seed 1: `schoolId` → seed 1, `vestigingscode: "02VG00"`, `onderwijslocatiecode: null`, `name: "Hoofdlocatie"`, `city: "Utrecht"`.
- `Location` seed 2: `schoolId` → seed 1, `vestigingscode: "02VG01"`, `onderwijslocatiecode: "02VG01-A"`, `name: "Dependance Noorderpark"`, `city: "Utrecht"` — exercises the independent-onderwijslocatiecode scenario.
- `Location` seed 3: `schoolId` → seed 2, `vestigingscode: "14LM00"`, `onderwijslocatiecode: null`, `name: "Hoofdlocatie"`, `city: "Amersfoort"`.
One existing `Cohort` seed gets `locationId` backfilled to `Location` seed 1, exercising the one-groep-per-location scenario without touching any other Cohort seed field.

## Risks / Trade-offs
[Risk] A school with genuinely multiple boards/instellingen under one BRIN cannot be expressed this round → Mitigation: out of scope per D2; tracked as P-new-14, unblocked once this School record exists to extend.
[Risk] Manual BRIN entry could contain typos the pattern doesn't catch (a valid-shaped but wrong BRIN) → Mitigation: pattern-shape validation only, per Non-Goals; live DUO/RIO validation is integriq's adapter territory (D3), not this change.

## Migration Plan
Declarative only — no Nextcloud PHP migration class. On next register import, OpenRegister creates the `School` and `Location` schemas and applies the additive `Cohort.locationId` property; existing `Cohort` rows read `locationId: null` until backfilled. Rollback: revert the two JSON files.

## Open Questions
None.
