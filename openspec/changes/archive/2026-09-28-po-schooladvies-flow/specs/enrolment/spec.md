# enrolment Specification

## ADDED Requirements

### Requirement: Persist SchoolAdvies domain objects in OpenRegister

The system MUST persist `SchoolAdvies` as an OpenRegister object with `x-openregister-lifecycle`
(`voorlopig → definitief → verzonden-naar-rod`) and materialised `isVoorlopigOverdue`/
`isDefinitiefOverdue` calculations mirroring `TlvApplication`'s `daysUntilValidUntil`/
`tlvExpiringSoon` idiom exactly (a `dateDiff`/`now` expression, not a PHP TimedJob).

#### Scenario: A SchoolAdvies persists with its declared lifecycle and calculations

<!-- @e2e exclude Pure OpenRegister schema/lifecycle registration; no scholiq DOM surface for registration itself, covered by PHPUnit SchoolAdviesRegisterTest mirroring the established `*RegisterTest` convention. -->

- **GIVEN** the `enrolment` schemas are registered
- **WHEN** a `SchoolAdvies` is created with `voorlopigAdviesLevel` set
- **THEN** it persists as an OpenRegister object in `lifecycle: voorlopig`, carrying the declared
  `isVoorlopigOverdue`/`isDefinitiefOverdue` calculations

### Requirement: A PO schooladvies may only be raised on heroverweging, never lowered, unless motivated

`SchoolAdviesFinalizeGuard` MUST block the `vaststellenDefinitief` transition (`voorlopig →
definitief`) when `doorstroomtoetsResultLevel` outranks `definitiefAdviesLevel` on the shared
ordinal (`pro < vmbo-bb < vmbo-kb < vmbo-gt < havo < vwo` — the same ordinal
`AdmissionsDecisionGuard` already uses for the VO intake side), UNLESS `heroverwegingMotivation` is
non-empty, or both levels are `pro`/`vmbo-bb` — the exact rule and exemption
`openspec/specs/enrolment/spec.md`'s existing "A VO schooladvies must be adjusted upward..."
requirement already establishes for `Application`, applied here to `SchoolAdvies`'s own fields.

#### Scenario: A higher doorstroomtoets result without a raised definitief or a motivation blocks finalisation

<!-- @e2e exclude Lifecycle-transition guard is backend logic verified by PHPUnit SchoolAdviesFinalizeGuardTest, mirroring AdmissionsDecisionGuardTest's own structure. -->

- **GIVEN** a `SchoolAdvies` with `voorlopigAdviesLevel: "vmbo-gt"`,
  `doorstroomtoetsResultLevel: "havo"`, `definitiefAdviesLevel` still `"vmbo-gt"`, and an empty
  `heroverwegingMotivation`
- **WHEN** a coordinator attempts `vaststellenDefinitief`
- **THEN** the transition is refused

#### Scenario: Raising definitiefAdviesLevel to match the doorstroomtoets result allows finalisation

<!-- @e2e exclude PHPUnit SchoolAdviesFinalizeGuardTest. -->

- **GIVEN** the same `SchoolAdvies`, with `definitiefAdviesLevel` raised to `"havo"`
- **WHEN** a coordinator attempts `vaststellenDefinitief`
- **THEN** the transition succeeds

#### Scenario: A motivation allows finalisation without raising the level

<!-- @e2e exclude PHPUnit SchoolAdviesFinalizeGuardTest. -->

- **GIVEN** a `SchoolAdvies` with `voorlopigAdviesLevel: "vmbo-gt"`,
  `doorstroomtoetsResultLevel: "havo"`, `definitiefAdviesLevel` still `"vmbo-gt"`, and a non-empty
  `heroverwegingMotivation`
- **WHEN** a coordinator attempts `vaststellenDefinitief`
- **THEN** the transition succeeds

#### Scenario: The pro/vmbo-bb exemption allows finalisation without a raise or motivation

<!-- @e2e exclude PHPUnit SchoolAdviesFinalizeGuardTest, mirroring AdmissionsDecisionGuardTest::testProVmboBbExemptionAllowsDecision. -->

- **GIVEN** a `SchoolAdvies` with `voorlopigAdviesLevel: "pro"` and
  `doorstroomtoetsResultLevel: "vmbo-bb"`
- **WHEN** a coordinator attempts `vaststellenDefinitief` without raising `definitiefAdviesLevel`
- **THEN** the transition succeeds

#### Scenario: A doorstroomtoets result that does not outrank the definitief advies never blocks finalisation

<!-- @e2e exclude PHPUnit SchoolAdviesFinalizeGuardTest. -->

- **GIVEN** a `SchoolAdvies` with `doorstroomtoetsResultLevel` equal to or lower than
  `definitiefAdviesLevel` on the ordinal
- **WHEN** a coordinator attempts `vaststellenDefinitief`
- **THEN** the transition succeeds regardless of `heroverwegingMotivation`

### Requirement: Sending a definitief schooladvies to ROD auto-queues the existing bron-rod DataExchangeJob

`SchoolAdviesSendToRodHandler` MUST, on the `verzendenNaarRod` transition (`definitief →
verzonden-naar-rod`), create a `DataExchangeJob` (`direction: export`, `target: bron-rod`,
`scope.schema: school-advies`, `scope.filters: {learnerId, schoolAdviesId}`), mirroring
`SupportRequestSubmitHandler`'s own auto-queue-a-job pattern exactly, and stamp the new job's UUID
back onto `SchoolAdvies.dataExchangeJobId`.

#### Scenario: Sending a definitief advies creates and links a bron-rod DataExchangeJob

<!-- @e2e exclude Cross-object write bridge is backend logic verified by PHPUnit SchoolAdviesSendToRodHandlerTest, mirroring SupportRequestSubmitHandlerTest's own structure; no scholiq DOM surface for the job-creation side effect itself. -->

- **GIVEN** a `SchoolAdvies` in `definitief`
- **WHEN** a coordinator triggers `verzendenNaarRod`
- **THEN** a `DataExchangeJob` is created with `target: bron-rod` and `scope.schema: school-advies`
- **AND** `SchoolAdvies.dataExchangeJobId` is stamped with the new job's UUID

### Requirement: Frontend is declarative with manifest index+detail pages

`src/manifest.json` (or its `src/manifest.d/*.json` fragment, per this repo's ADR-037 modular
pipeline) MUST declare `SchoolAdvies`/`SchoolAdviesDetail` index+detail pages, following the same
`<Schema>s`/`<Schema>Detail` convention every other schema in this register already uses. There
MUST be no PHP CRUD controller.

#### Scenario: Pages are manifest-declared

<!-- @e2e tests/e2e/spec-coverage/enrolment.spec.ts -->

- **GIVEN** the manifest is built
- **WHEN** `SchoolAdvies`/`SchoolAdviesDetail` are inspected
- **THEN** both exist as declarative index/detail pages, and no PHP controller serves them
