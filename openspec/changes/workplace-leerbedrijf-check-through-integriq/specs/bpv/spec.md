## MODIFIED Requirements

### Requirement: Leerbedrijf verification is a pluggable provider
The SBB erkend-leerbedrijf check MUST be a declared `leerbedrijfVerification.provider` config on `BpvPlacement` resolving to `ProvidesLeerbedrijfVerification`. learniq MUST ship exactly one provider, `IntegriqLeerbedrijfVerification`, which delegates the lookup to integriq and implements no SBB wire protocol itself. Another provider MAY be configured in its place. Without integriq the app MUST still work: the check ends `pending` and the placement cannot confirm, not break.

#### Scenario: Verification resolves to a pluggable provider without a bundled adapter
- **GIVEN** a `BpvPlacement` with a `leerbedrijfVerification.provider` config naming a custom adapter
- **WHEN** the config resolves the provider through `ProvidesLeerbedrijfVerification`
- **THEN** the configured adapter returns the verification result and no SBB wire protocol is implemented inside learniq itself

#### Scenario: The integriq provider is the default
- **GIVEN** a `BpvPlacement` with no provider configured
- **WHEN** the `checkLeerbedrijf` action runs
- **THEN** `IntegriqLeerbedrijfVerification` handles the check

## ADDED Requirements

### Requirement: The check stores SBB's answer on the placement

When `checkLeerbedrijf` runs, the system MUST look the company up by `trainingCompanyKvkNumber` (or an erkenningsnummer when one is entered) and store the status, the erkenningsnummer, the recognition's end date and the time of the check in `trainingCompanyVerification`. A recognised company MUST give `verified`; a company SBB does not recognise MUST give `rejected`; a recognition that has ended MUST give `expired`.

#### Scenario: A recognised company

- **GIVEN** a placement at a company with KVK number 12345678 that SBB recognises until 2027-08-31
- **WHEN** the coordinator runs "Leerbedrijf controleren"
- **THEN** the placement stores status `verified`, the erkenningsnummer and end date 2027-08-31
- **AND** the coordinator can confirm the placement

#### Scenario: A company SBB does not recognise

- **GIVEN** a placement at a company SBB does not list
- **WHEN** the check runs
- **THEN** the placement stores status `rejected` and cannot be confirmed

### Requirement: A missing integration fails closed

When integriq is not installed, has no token configured or has no SBB source, the check MUST store status `pending` with a reason that names what is missing, and the placement MUST NOT confirm.

#### Scenario: integriq has no SBB source

- **GIVEN** integriq is installed without an SBB source
- **WHEN** the check runs
- **THEN** the placement stores status `pending` with the reason "integriq has no SBB source configured"
- **AND** the confirm transition is refused

### Requirement: A recognition that ends during the placement is flagged

When the stored recognition end date falls before `periodTo`, the placement MUST show a warning with the end date.

#### Scenario: Recognition ends mid-placement

- **GIVEN** a verified placement running until 2027-06-30 at a company recognised until 2027-01-31
- **WHEN** the coordinator opens the placement
- **THEN** the page warns that the recognition ends on 31 January 2027
