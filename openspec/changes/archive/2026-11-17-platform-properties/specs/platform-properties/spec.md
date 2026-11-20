# platform-properties Specification (delta)

## Purpose

What learniq is as a product, independent of any page: where it runs, under which licence its source is available, and that it works without AI. Covers rows `gov-self-host-everything`, `gov-source-without-a-contract` and `gov-works-without-any-ai`.

## ADDED Requirements

### Requirement: The app runs on the school's own Nextcloud (REQ-PLAT-001)

learniq SHALL install and run as a Nextcloud app on a server the school or company operates, in a location of its choosing. Its required apps SHALL be OpenRegister and OpenConnector, both open source Nextcloud apps installed on the same server. learniq SHALL NOT need a service operated by Conduction or another vendor to start, store data or serve its pages. Links to outside systems (Studielink, DUO and ROD exchanges, the EUDI wallet, hermiq) SHALL be optional and configured by the operator.

#### Scenario: An installation with no route to vendor services

- **GIVEN** a Nextcloud server with OpenRegister and OpenConnector and no route to any Conduction service
- **WHEN** an administrator installs and enables learniq
- **THEN** the app enables, its pages load, and courses, enrolments and grades can be created and read

#### Scenario: An outside exchange is not configured

- **GIVEN** no DUO or Studielink connection is configured
- **WHEN** a coordinator enrols a learner by hand
- **THEN** the enrolment is saved, and nothing waits on the missing exchange

### Requirement: The source code is open under the EUPL-1.2 (REQ-PLAT-002)

The repository SHALL be public and SHALL carry the EUPL-1.2 licence text in `LICENSE`. `appinfo/info.xml` SHALL declare `<licence>EUPL-1.2</licence>` and `composer.json` SHALL declare `EUPL-1.2`. Every PHP file under `lib/` SHALL carry an SPDX licence identifier. Getting, reading, changing or running the source SHALL NOT need a contract, an account or a licence key.

#### Scenario: The licence is declared consistently

- **GIVEN** the repository at any release tag
- **WHEN** `LICENSE`, `appinfo/info.xml` and `composer.json` are read
- **THEN** all three name the EUPL-1.2

#### Scenario: A PHP file without a licence header

- **GIVEN** a new file under `lib/` without an SPDX identifier
- **WHEN** the quality gates run
- **THEN** the SPDX gate fails on that file

### Requirement: The whole product runs with no AI at all (REQ-PLAT-003)

Every page, lifecycle transition and background job of learniq SHALL work when no AI provider is configured and hermiq is not installed. An AI-assisted option SHALL be offered only when hermiq is installed and enabled. When it is absent, a feature that has a manual path SHALL keep that path, and the AI processing disclosure SHALL say that no AI processing takes place instead of failing.

#### Scenario: A school without hermiq

- **GIVEN** an instance where hermiq is not installed
- **WHEN** a teacher builds, publishes and marks an assessment with manual proctoring
- **THEN** every step succeeds and no screen asks for an AI provider

#### Scenario: The disclosure without AI

- **GIVEN** an instance where hermiq is not installed
- **WHEN** the privacy officer opens the AI processing disclosure
- **THEN** it shows the school's locality policy and an empty AI feature list, not an error
