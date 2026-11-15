# Design: example-set-regulation-rows

## Architecture Overview

```
learniq_register.json  Regulation.properties.slug.pattern = ^[A-Z0-9_-]+$     (own slug)
                       components.objects: regulation AVG                      (register seed)
        │
        ▼
ExampleSetDescriptorContractTest
  ownsSlug(schema)?  yes → slug = the object's own code: pattern + unique, no "<id>-" prefix,
                           and not a code the register already seeds
                     no  → slug = <id>-<schema>-<NNN> (unchanged)
        │
scripts/example-sets/{corporate,training}.py  ── append "regulation" to SCHEMAS ──▶ profiles/{corporate,training}.json
```

## Decisions

### Decision 1: the rule keys on a declared pattern
`Regulation.slug` declares `pattern: ^[A-Z0-9_-]+$`; the three other schemas with a `slug` property (`AiFeature`, `ReportCardTemplate`, `SharedCoursePackage`) declare none, so a plain string, including the envelope form, is valid for them. D29 names "schemas with their own slug pattern", so the test keys on `properties.slug.pattern`, not on the property's existence. The pattern itself is enforced by the existing value check, which already reported `corporate-regulation-001` against it; the test only stops demanding the `<id>-` prefix for those buckets and keeps the uniqueness check.

### Decision 2: no duplicate of a register seed row
OpenRegister's seed import (`ImportHandler::importSeedDataObjects`) looks an object up by `uuid` when one is given and only falls back to the slug without one. The register ships `AVG` without a uuid, the sets ship fixed uuids, so a set's `AVG` would become a second row with the same code, the duplicate the e2e seeder was fixed for (`fix/seed-duplicate-avg-regulation`). The contract test reads the register's seed rows and reports a set row that reuses one of their codes. Both sets therefore reference the register's `AVG` and ship no row for it.

### Decision 3: uuids stay where they are
Each generator numbers a schema by its position in `SCHEMAS` (`ee<SS><position>-...`). Inserting `regulation` near the courses would renumber every later schema and change thousands of uuids, and a re-load on an install that already has the set would then add a second copy of it. `regulation` is appended last: the new rows get the next free number and nothing else moves. Regulations are referenced by code, not uuid, so their place in the file does not matter to the importer. Removal purges in reverse file order, which removes them first, harmlessly.

### Decision 4: audiences follow what the generators do
The Compliance overview counts an obligation per learner the audience covers (`RegulationAudienceResolver::covers()`), and only for `published`, `active` rows.

| Company (`ee05`) | audience | renewal | matches the generator |
|---|---|---|---|
| GEDRAGSCODE | all-employees | 12 months, annual | everyone does the code of conduct e-learning |
| INFORMATIEBEVEILIGING | all-employees | 12 months, annual | everyone does the phishing e-learning |
| VCA | department `Operatie` | 120 months | `OPERATIONS` (planning, installatie, magazijn, werkplaats) |
| NEN3140 | department `Operatie/Installatie en service`, `Operatie/Werkplaats` | 36 months | `TECHNICIANS` |
| HEFTRUCK | department `Operatie/Magazijn en logistiek` | 60 months | the warehouse |
| FGASSEN | role-specific, no roles | none | designated heat pump technicians (20 of 72 installers) |
| BHV | role-specific, no roles | 12 months, annual | the BHV team, sampled across departments |
| NIS2 | board: `manager`, `compliance-officer` | 12 months | the nine board members with an external NIS2 record |

The three department scopes are exactly the ones `CorporateExampleSetTest::testEveryoneACertificationAppliesToHoldsItOrIsBooked` hardcoded; that test now reads them from the rows, so the rows and the data cannot drift apart. BHV and F-gassen fall on designated people; LearnerProfile has no role for that, so their audience is empty and `applicabilityCriteria` says who is meant. A per-person obligation on everyone would show BHV at 17 percent coverage, which is not what the law asks.

| Training institute (`ee06`) | audience | renewal |
|---|---|---|
| VCA | role-specific, no roles | 120 months |
| ARBOWET-BHV | role-specific, no roles | 12 months, annual |
| ARBOWET-PREVENTIE | role-specific, no roles | none |
| ARBOBESLUIT-HEFTRUCK | role-specific, no roles | 60 months |
| NIS2 | role-specific, no roles | 12 months, annual |

The institute trains people for their employers and obliges none of them itself, so no participant carries an obligation; the rows name the certificate and its validity, which the courses already use.

### Decision 5: the test that owns the contract changes with it
The contract says "a change to the descriptor rules is a change to this contract and to `ExampleSetDescriptorContractTest` in the same PR". Both change here; `testEveryKindOfDefectIsReported` gains four cases (a valid own-slug row, the envelope form, a duplicate code, a register-seeded code).

## Declarative-vs-imperative decision (ADR-031)
Data only. The Regulation rows feed the existing declarative roll-up and the existing assignment action; no behaviour is added.

## Security Considerations
Fictional data only; no personal data in a Regulation row. The rows use the example tenant, like every other object in the sets.

## Seed Data
The Regulation rows above are the seed data of this change, in the two example sets, not in the register (the register keeps its single AVG row). Names and descriptions are Dutch, as the rest of those sets:
- VCA: "VCA, veiligheid, gezondheid en milieu", for everyone who works on location or in the workshop.
- NEN3140: "NEN 3140, veilig werken aan elektrische installaties", voldoend onderricht personen.
- HEFTRUCK: "Heftruckcertificaat", for the warehouse.
- FGASSEN: "F-gassen, EU-verordening 2024/573", for technicians who open refrigerant circuits.
- BHV: "Bedrijfshulpverlening (Arbowet artikel 15)", for the designated BHV team.
- GEDRAGSCODE: "Gedragscode Esdoorn Techniek", internal.
- INFORMATIEBEVEILIGING: "Informatiebeveiligingsbeleid", internal.
- NIS2: "NIS2, cyberbeveiliging voor bestuurders", for the board.
- Training: VCA, ARBOWET-BHV, ARBOWET-PREVENTIE (preventiemedewerker, Arbowet artikel 13), ARBOBESLUIT-HEFTRUCK (Arbobesluit 7.32), NIS2.

## Risks / Trade-offs
- [Both sets loaded on one instance] → VCA and NIS2 exist twice. Each set must stand alone; the wizard loads one.
- [The register's AVG is a draft] → AVG obligations do not count in the Compliance overview until that row is published (open question, outside this lane's files).

## Migration Plan
None. A re-load of a set on an install that already has it adds only the new Regulation rows.

## Open Questions
See the proposal.
