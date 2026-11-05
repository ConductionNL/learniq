## ADDED Requirements

### Requirement: A detail page follows its board

A detail page that declares `config.board` MUST render one tab strip holding the page's own cards, with the record history as the last tab, and the record history as the last card of the side column. It MUST NOT show a Follow control, per-card Actions menus, the object sidebar, or the generic data and related cards; the record's fields MUST be shown in the cards the board draws, without inline editors.

#### Scenario: A concern report as the counsellor sees it
- **GIVEN** a concern report about bullying from Fenna de Jong who wants to talk
- **WHEN** the counsellor opens the report
- **THEN** the header shows the pills Ontvangen and Wil praten, the tabs read Overzicht and Historie, the overview shows Waar gaat het over, Wanneer gebeurde het and Wat is er gebeurd, and the side column shows Contact met de melder and Historie
- **AND** there is no Volgen pill and no "bewerken" button beside a field
- @e2e exclude manifest config; covered by tests/unit-js/boardDetail.test.mjs, remeasured against the board

#### Scenario: A page without the board key keeps its own layout
- **GIVEN** a detail page without `config.board`
- **WHEN** the app boots
- **THEN** the page's widgets, layout and sidebar are used as declared
- @e2e exclude boot-time manifest transform; covered by tests/unit-js/boardDetail.test.mjs

### Requirement: A decision page says what to do next

The BSA decision and the attendance flag MUST show a "Wat nu?" card for the stages that need an action, listing the steps with the ones already done marked, and a line about what follows.

#### Scenario: A concept BSA decision
- **GIVEN** a BSA decision in concept whose student has been warned and heard
- **WHEN** the examination board secretary opens it
- **THEN** "Wat nu?" lists Waarschuwing gegeven and Student gehoord as done and Motivering geschreven as open, with the line that the decision goes to the examination board
- @e2e exclude manifest config; covered by tests/unit-js/boardDetail.test.mjs
