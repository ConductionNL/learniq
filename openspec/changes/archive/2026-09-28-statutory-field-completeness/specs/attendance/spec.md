## ADDED Requirements

### Requirement: AttendanceFlag classifies its statutory flagKind

`AttendanceFlag` MUST carry a `flagKind` enum property (`signal-verzuim`, `langdurig-relatief-verzuim`,
`thuiszitter`; default `signal-verzuim`) classifying which statutory concern the flag represents (finding
4.9). SWV notification reuses the existing `dataExchangeJobId` field — no new field is needed for that half
of the finding.

#### Scenario: A langdurig-relatief-verzuim flag is classified distinctly from a routine signal

- **GIVEN** an `AttendanceThreshold` crossing that represents a langdurig relatief verzuim case
- **WHEN** the resulting `AttendanceFlag` is created with `flagKind: "langdurig-relatief-verzuim"`
- **THEN** it is distinguishable from a routine `signal-verzuim` flag by that field alone

#### Scenario: An existing AttendanceFlag without a declared flagKind defaults to signal-verzuim

- **GIVEN** an `AttendanceFlag` row created before this change, with no `flagKind` value stored
- **WHEN** the row is read
- **THEN** `flagKind` resolves to its default, `"signal-verzuim"`
