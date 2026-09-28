---
kind: code
---

# Proposal: po-schooladvies-flow

## Summary
Adds a `SchoolAdvies` schema and index+detail for the PO (primary school) schooladvies flow:
voorlopig advies, the doorstroomtoets result, a heroverweging (reconsideration) that may only raise
the advies (never lower it) unless motivated, definitief advies, and a `send-to-rod` transition
that queues the existing `bron-rod` `DataExchangeJob` target. A new `SchoolAdviesFinalizeGuard`
enforces the upward-only rule, mirroring the existing `AdmissionsDecisionGuard`'s VO-side pattern
exactly. A new `SchoolAdviesSendToRodHandler` listener queues the ROD job, mirroring the existing
`SupportRequestSubmitHandler`'s auto-queue pattern exactly.

## Kind correction (read before reviewing)
The brief labelled this change `config, M`. Building it surfaced a `code` requirement the label
didn't anticipate: enforcing "heroverweging may only raise the advies, never lower it, unless
motivated" needs the SAME ordinal-comparison guard logic `AdmissionsDecisionGuard` already
implements for the VO side (`openspec/specs/enrolment/spec.md`, "A VO schooladvies must be adjusted
upward when the doorstroomtoets scores higher, unless motivated") — this is genuine PHP, not a
schema declaration (OpenRegister's calculation DSL has no cross-field conditional-block primitive
expressive enough for "block this transition unless A OR B OR C", the same limitation
`AdmissionsDecisionGuard`'s own precedent already established). Declaring `kind: code` here rather
than splitting into a `config`-then-`code` chain (ADR-032's own escape valve) because the schema
addition and its one guard/one listener are tightly coupled and inseparable: the schema has no
useful existence without the rule that governs its central transition. Same correction pattern as
this round's `role-dashboards` change.

## Motivation
Round-1 competitor research (`compare/change-plan.md`, "Privacy, payments, funding" table) carries
this change against findings `7.9` and `6.7`:

- **7.9** Schooladvies flow (`compare/findings.md`): ParnasSys and ESIS both document "voorlopig
  between 10 and 31 January, definitief 'uiterlijk 24 maart'" (ParnasSys) / "voorlopig 10-31 Jan,
  definitief after doorstroomtoets ... automatically ... aangeleverd bij DUO" (ESIS). Statutory
  dates confirmed in this round's legal research (`recon/legal-po-2026-09-25.md`): "groep 8 advies
  flow with dates 31 Jan / 7 Apr", "heroverweging upward only". learniq's nearest schema
  (`Application.schoolAdviceLevel`) is VO-intake-scoped (the RECEIVING school's admissions side,
  already-shipped `AdmissionsDecisionGuard`); no PO (the SENDING school's) schooladvies flow exists
  at all.
- **6.7** Doorstroomtoets result feeding the advies flow (`compare/findings.md`): cito-lib,
  ParnasSys, ESIS. learniq's `Application.progressionTestLevel` is the same VO-intake-scoped field;
  no PO-side doorstroomtoets capture exists.

## Affected Projects
- [x] Project: `learniq` — new `SchoolAdvies` schema, `SchoolAdviesFinalizeGuard`,
  `SchoolAdviesSendToRodHandler`, manifest index+detail.

## Scope

### In Scope
- New `SchoolAdvies` schema: `learnerId`, `academicYear`, `voorlopigAdviesLevel` (required, the
  same 6-value ordinal `Application.schoolAdviceLevel` already uses:
  `pro`/`vmbo-bb`/`vmbo-kb`/`vmbo-gt`/`havo`/`vwo`), `voorlopigAdviesDate`,
  `voorlopigDeadline`/`definitiefDeadline` (nullable, coordinator-set, mirroring
  `LearningPlan.sixWeekDeadline`'s coordinator-set precedent from this same round's
  `care-and-support-index` change — no calendar-date-from-academicYear derivation, since this
  register's calculation DSL has no date-construction-from-string-parts primitive), materialised
  `isVoorlopigOverdue`/`isDefinitiefOverdue` calculations mirroring `TlvApplication`'s own idiom,
  nullable `doorstroomtoetsResultLevel`/`doorstroomtoetsResultDate`, nullable
  `definitiefAdviesLevel`/`definitiefAdviesDate`, nullable `heroverwegingMotivation`, nullable
  `dataExchangeJobId` ($ref `DataExchangeJob`, stamped on send).
- Lifecycle `voorlopig → definitief → verzonden-naar-rod`: `vaststellenDefinitief` (voorlopig →
  definitief) requires `SchoolAdviesFinalizeGuard`, which blocks the transition when
  `doorstroomtoetsResultLevel` outranks `definitiefAdviesLevel` on the shared ordinal, UNLESS
  `heroverwegingMotivation` is non-empty or both levels are `pro`/`vmbo-bb` — the exact rule and
  exemption `AdmissionsDecisionGuard` already enforces on the VO side, applied to this schema's own
  fields. `verzendenNaarRod` (definitief → verzonden-naar-rod) triggers
  `SchoolAdviesSendToRodHandler`, which auto-queues a `bron-rod` `DataExchangeJob`
  (`scope.schema: school-advies`), mirroring `SupportRequestSubmitHandler`'s auto-queue pattern
  exactly, and stamps the new job's UUID back onto `dataExchangeJobId`.
- Manifest index+detail pages for `SchoolAdvies`, declarative, no PHP controller.
- PHPUnit tests for `SchoolAdviesFinalizeGuard` (block/allow/exemption scenarios, mirroring
  `AdmissionsDecisionGuardTest`'s own structure) and `SchoolAdviesSendToRodHandler` (job payload
  shape, mirroring `SupportRequestSubmitHandlerTest`'s structure).

### Out of Scope
- **Modifying `Application`/`AdmissionsDecisionGuard`**: the VO-intake side is unchanged; this
  change adds a parallel, PO-scoped schema and guard rather than generalising the existing VO one,
  since the two flows differ in more than field names (PO has no admissions-round/capacity
  dimension at all).
- **The ROD wire adapter itself**: `bron-rod` is an existing `DataExchangeJob` target already
  delegated to integriq (per D3's job-type-owns-mapping pattern, `integriq-adapter-rod`, a separate
  sibling repo change); this change only queues the job, exactly as `SupportRequestSubmitHandler`
  only queues the `swv` job today.
- **A pre-flight teldatum count check** (`P-new-12`) — carried by a different, separate change
  (`funding-and-teldatum-checks`) in this round's plan, not this one.

## Approach
One new schema, one lifecycle guard (mirrors `AdmissionsDecisionGuard`'s ordinal-comparison logic),
one listener (mirrors `SupportRequestSubmitHandler`'s auto-queue-a-job shape), one manifest
index+detail pair. Both PHP classes are the ADR-031 "cross-object write bridge" / "domain rule
requiring cross-field conditional logic" exceptions this register's own precedents already
establish — no new architectural pattern.

## New Dependencies
None.

## Impact
- `lib/Settings/learniq_register.json`: new `SchoolAdvies` schema.
- `lib/Lifecycle/SchoolAdviesFinalizeGuard.php` (new).
- `lib/Listener/SchoolAdviesSendToRodHandler.php` (new).
- `lib/AppInfo/Application.php`: register the new listener (mirrors every other listener's
  registration).
- `src/manifest.d/*.json`: `SchoolAdvies` index+detail pages.
- `tests/Unit/Lifecycle/SchoolAdviesFinalizeGuardTest.php`,
  `tests/Unit/Listener/SchoolAdviesSendToRodHandlerTest.php`,
  `tests/Unit/Settings/SchoolAdviesRegisterTest.php` (all new).

## Cross-Project Dependencies
None — the ROD wire adapter is a separate, already-planned sibling change in a different repo
(integriq); this change's own scope ends at queuing the job, matching the existing `swv`/`oso`
precedents' own scope boundary.

## Risks

### Risk 1: A second ordinal-comparison guard duplicates AdmissionsDecisionGuard's logic
**Severity:** Low — **Mitigation:** the two guards govern different schemas
(`Application` vs `SchoolAdvies`) with different lifecycles and different transition names; a
shared trait/helper for the ordinal-comparison logic itself (not the guard) is a reasonable future
refactor once a third consumer appears, but duplicating ~15 lines of comparison logic now is
cheaper and clearer than a premature shared abstraction for two call sites.

## Rollback Strategy
The schema, guard, and listener are additive; reverting removes them with no impact on
`Application`/`AdmissionsDecisionGuard` or any other existing schema.

## Open Questions
None — corpus evidence (`recon/legal-po-2026-09-25.md`, ParnasSys/ESIS documentation) and this
register's own existing VO-side precedent are specific enough to proceed; scope boundaries above
record judgment calls made under headless operation.
