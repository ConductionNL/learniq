# Design: po-schooladvies-flow

## Architecture Overview
```
SchoolAdvies (voorlopig)
      │ coordinator sets doorstroomtoetsResultLevel, definitiefAdviesLevel
      ▼
  vaststellenDefinitief ──requires──▶ SchoolAdviesFinalizeGuard
      │ (blocks unless raised / motivated / pro-vmbo-bb exempt)
      ▼
SchoolAdvies (definitief)
      │ coordinator triggers
      ▼
  verzendenNaarRod ──triggers──▶ SchoolAdviesSendToRodHandler
      │ creates DataExchangeJob(target: bron-rod), stamps dataExchangeJobId back
      ▼
SchoolAdvies (verzonden-naar-rod)
```

Two new PHP classes, one new schema, one manifest index+detail pair. Both classes are narrow,
single-responsibility, and each mirrors an already-shipped precedent in this exact codebase:
`SchoolAdviesFinalizeGuard` mirrors `AdmissionsDecisionGuard`'s ordinal-comparison shape;
`SchoolAdviesSendToRodHandler` mirrors `SupportRequestSubmitHandler`'s auto-queue-a-job shape.

## API Design
Not applicable — no HTTP endpoint added. `SchoolAdvies` CRUD is served by OpenRegister's generic
object API; the `DataExchangeJob` creation is an internal object write triggered by a lifecycle
event, not a new route.

## Database Changes
See migration.md — additive OpenRegister schema only.

## Nextcloud Integration
- Controllers: none added.
- Services: none added.
- Mappers/Entities: none — OpenRegister owns storage.
- Events/Hooks: `SchoolAdviesSendToRodHandler` is a new `IEventListener` for
  `ObjectTransitionedEvent` (schema=school-advies, action=verzendenNaarRod), registered in
  `lib/AppInfo/Application.php` alongside every other listener.

## Security Considerations
`SchoolAdvies` carries statutory PO advies data (schooladvies + doorstroomtoets result), comparable
in sensitivity to `Application`'s own schooladvies fields; it inherits this register's default
authenticated-staff-role posture, matching `Application`'s own (no narrower `x-property-rbac` is
declared on `Application.schoolAdviceLevel` either, so this is not a regression). The `bron-rod`
`DataExchangeJob` it queues reuses the SAME target/delegation path `DataExchangeRunHandler` already
guards for every other `bron-rod` job — no new attack surface.

## NL Design System
`SchoolAdvies`/`SchoolAdviesDetail` are declarative `src/manifest.json` pages using the same generic
widgets every other page in this register already uses — no bespoke Vue component.

## File Structure
```
lib/
  Settings/
    learniq_register.json          (MODIFIED — new SchoolAdvies schema)
    learniq_mock_register.json     (MODIFIED — seed SchoolAdvies objects)
  Lifecycle/
    SchoolAdviesFinalizeGuard.php  (NEW)
  Listener/
    SchoolAdviesSendToRodHandler.php (NEW)
  AppInfo/
    Application.php                (MODIFIED — register the new listener)
src/
  manifest.d/*.json                (MODIFIED — SchoolAdvies index+detail pages)
tests/
  Unit/
    Lifecycle/SchoolAdviesFinalizeGuardTest.php      (NEW)
    Listener/SchoolAdviesSendToRodHandlerTest.php    (NEW)
    Settings/SchoolAdviesRegisterTest.php            (NEW)
```

## Declarative-vs-imperative decision (ADR-031)
| Behaviour | Path chosen | Rationale |
|---|---|---|
| `SchoolAdvies.lifecycle` | Declarative (`x-openregister-lifecycle`) | Plain state machine. |
| `isVoorlopigOverdue`/`isDefinitiefOverdue` | Declarative (`x-openregister-calculations`) | Identical shape to the already-shipped `TlvApplication.daysUntilValidUntil`/`tlvExpiringSoon` — a materialised boolean over `dateDiff`/`now`. |
| Heroverweging upward-only rule | **Imperative** (`SchoolAdviesFinalizeGuard`) — ADR-031 "domain rule requiring cross-field conditional logic" exception, the exact exception `AdmissionsDecisionGuard` already established for the identical rule shape on `Application`. This register's calculation DSL has no boolean short-circuit expressive enough for "block unless A OR B OR C" as a `requires` guard (a `requires` value must resolve to a single guard class, not an inline expression). | Extending the established exception is narrower than inventing a new declarative primitive OpenRegister does not offer. |
| Auto-queuing the `bron-rod` job | **Imperative** (`SchoolAdviesSendToRodHandler`) — ADR-031 "cross-object write bridge" exception, the exact exception `SupportRequestSubmitHandler` already established for the identical "auto-queue a DataExchangeJob on this transition" shape. | Same reasoning as `SupportRequestSubmitHandler`: creating a second object in response to a transition cannot be expressed as schema metadata. |

## Seed Data
### Schema: `school-advies`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | `schooladvies-groep-8-leerling-1` | `schooladvies-groep-8-leerling-2` | `schooladvies-groep-8-leerling-3` |
| learnerId | learner-007 | learner-008 | learner-009 |
| academicYear | 2025-2026 | 2025-2026 | 2025-2026 |
| voorlopigAdviesLevel | vmbo-gt | havo | vmbo-kb |
| voorlopigAdviesDate | 2026-01-20 | 2026-01-22 | 2026-01-18 |
| doorstroomtoetsResultLevel | havo | havo | vmbo-kb |
| definitiefAdviesLevel | havo | havo | vmbo-kb |
| heroverwegingMotivation | (empty) | (empty) | (empty) |
| lifecycle | definitief | verzonden-naar-rod | voorlopig |

**Related items per object:** none.

## Trade-offs
Considered generalising `AdmissionsDecisionGuard` to cover both `Application` and `SchoolAdvies`
rather than writing a second guard. Rejected: the two schemas have different transition names,
different lifecycles, and different required-field shapes (PO has no admissions-round/capacity
dimension); a shared guard would need to branch on schema type internally, which is less readable
than two small, single-schema guards. See proposal.md Risk 1 for the same reasoning applied to the
ordinal-comparison logic specifically.

## Open Questions
None outstanding.
