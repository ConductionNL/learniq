## ADDED Requirements

### Requirement: A user on the Dutch locale reads the screens and notifications in Dutch

Every string learniq shows on a screen MUST have a Dutch value in `l10n/nl.json`, every schema shown in a form MUST carry a Dutch name, and every notification MUST carry an `nl` subject. A user whose Nextcloud language is Dutch MUST see the screens and receive the notifications in Dutch.

#### Scenario: A Dutch teacher opens the app

- **GIVEN** a teacher whose Nextcloud language is Dutch
- **WHEN** they open the learning plans list and a plan's page
- **THEN** the menu, the page titles, the column labels and the buttons read in Dutch

#### Scenario: A Dutch parent gets a notification

- **GIVEN** a parent whose Nextcloud language is Dutch, and a lesson of their child is cancelled
- **WHEN** the notification is delivered
- **THEN** its subject is the `nl` subject of the rule
