## ADDED Requirements

### Requirement: Each connection on the Integrations page says what it is missing

Every connection learniq reports MUST carry, next to its status, a status message that says in words what the connection needs when it does not work, such as an app that is not installed or a setting that is not set. The Integrations page MUST show that message and the date of the last check, and link to the connection's settings.

#### Scenario: The timetable connection names the missing app

- **GIVEN** integriq is installed and planninq is not
- **WHEN** an admin opens the Integrations page after the daily connection report
- **THEN** the timetable row reads Unavailable with the message that planninq is not installed

#### Scenario: A working connection says what is left to set

- **GIVEN** integriq is installed and offers its LTI launch
- **WHEN** an admin opens the Integrations page after the daily connection report
- **THEN** the LTI row reads Configured with the message that grades come back once the grade subscription is set in the LTI section
