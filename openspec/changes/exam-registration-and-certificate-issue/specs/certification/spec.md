## ADDED Requirements

### Requirement: An exam registration list closes at its moment and names who is incomplete

For a course day whose exam is taken by an external examining body, learniq MUST keep a registration list with the body, the moment it closes, and per participant whether the fields the body requires are complete. An incomplete participant's employer MUST receive a task to complete the missing field. The list MUST close at its moment, and only a closed list MUST be exported. The birth date MUST appear only in the export.

#### Scenario: Youssef's birth date
- **GIVEN** the exam of Thursday 8 October closes Wednesday 12.00 and Youssef El Amrani has no birth date
- **WHEN** the administration opens Today on Monday
- **THEN** the card reads "10 van de 11 deelnemers zijn compleet" and names Youssef, and Linda Jansen has the task "Geboortedatum invullen"
- @e2e exclude staff screen; spec-only proposal

### Requirement: The certificates of a finished course day are issued in one action

For a course day in rounding off, a member of the administration MUST be able to issue the certificate to every participant with a passing result in one action, recording who issued and when per certificate; participants without a passing result MUST be named and skipped.

#### Scenario: Thursday's F-gassen day
- **GIVEN** 11 participants, 10 passed and 1 without a result
- **WHEN** Rob Maas issues the certificates of that day
- **THEN** 10 certificates are issued and the participant without a result is named
- @e2e exclude as above
