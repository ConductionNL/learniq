## ADDED Requirements

### Requirement: Items in an item bank are reused by more than one test

An `ItemBank` MUST hold `Item`s that any number of `Assessment`s reference, through `itemRefs` or through a random draw from the bank. Changing which tests use an item MUST NOT copy the item.

#### Scenario: Two tests use the same item

- **GIVEN** an item bank "Breuken" with a published item "1/2 + 1/4"
- **WHEN** a teacher adds that item to the tests "Toets blok 3" and "Herkansing blok 3"
- **THEN** both tests reference the same item and the bank still holds one copy

### Requirement: A QTI package is imported into an item bank

`POST /api/assessment/qti-import` MUST accept a QTI 2.1 package and a target item bank, and MUST create one `Item` in that bank per `assessmentItem` in the package, with its title, body, correct response and maximum score.

#### Scenario: A package with three items

- **GIVEN** a QTI 2.1 package with three choice items
- **WHEN** a teacher imports it into the item bank "Breuken"
- **THEN** the bank holds three new items with the titles from the package

### Requirement: A test is assembled from items with a time limit and a scoring scheme

An `Assessment` MUST take its items as a fixed list or a random draw from a bank. It MUST be able to carry `timeLimitMinutes`, `maxAttempts` and a `scoringScheme` of `points`, `passMark` or `irt`, with a `passMark` where that scheme needs one. Publishing MUST pass `AssessmentPublishGuard`.

#### Scenario: A teacher builds a timed test with a pass mark

- **GIVEN** an item bank with ten published items
- **WHEN** a teacher creates a test with five of them, a time limit of 45 minutes, scoring scheme `passMark` and pass mark 5.5, and publishes it
- **THEN** the test is `published` with those five items, the time limit and the pass mark
