## ADDED Requirements

### Requirement: Certificates from the previous system are uploaded with a dry run first

The system MUST let hr and compliance-officers upload a CSV of existing certificates with the columns learner (id or email), course code or regulation, issued on, valid until, old certificate number and issuing body. It MUST first return a per-row result (ready, or refused with the reason) without writing anything, and MUST write only the ready rows when the user confirms.

#### Scenario: A clean file

- **GIVEN** a CSV with 3 rows whose learners and courses exist
- **WHEN** a compliance officer uploads it
- **THEN** the preview shows 3 rows as ready and nothing is stored
- **AND** after "Overnemen" 3 certificates exist with `source: migrated`

#### Scenario: A row with an unknown learner

- **GIVEN** a CSV row with an email no learner has
- **WHEN** it is uploaded
- **THEN** the preview shows that row as refused with "Geen leerling met dit e-mailadres"
- **AND** the other rows are still written on confirm

#### Scenario: The same file twice

- **GIVEN** a file that was already taken over
- **WHEN** it is uploaded again
- **THEN** every row shows as already present and nothing new is written

### Requirement: A migrated certificate keeps its dates and is not signed as learniq's own

A migrated `Credential` MUST keep `issuedAt` and `expiresAt` as given, MUST carry `legacyNumber`, `legacyIssuer`, `migratedAt` and `migratedBy`, and MUST NOT get a learniq signature or Open Badges payload. Its public verification page MUST say that it was taken over from the previous system, when, and that learniq did not issue it.

#### Scenario: Someone verifies a migrated certificate

- **GIVEN** a migrated certificate with old number VCA-2023-0112
- **WHEN** an employer opens its verification link
- **THEN** the page shows the holder, the course, the validity, the old number and the issuing body
- **AND** it says the certificate was taken over on that date and not issued by learniq

### Requirement: Migrated certificates count like any other

Migrated certificates MUST count toward compliance coverage while valid, and the expiry warnings and the refresher sign-up MUST run on them.

#### Scenario: Coverage on day one

- **GIVEN** a regulation with 10 learners in scope and 8 migrated certificates still valid for it
- **WHEN** the compliance officer opens the coverage page
- **THEN** the regulation shows 80 percent

#### Scenario: A migrated certificate lapses

- **GIVEN** a migrated certificate that expires today, for a course with a refresher
- **WHEN** it expires
- **THEN** the learner is signed up for the refresher
