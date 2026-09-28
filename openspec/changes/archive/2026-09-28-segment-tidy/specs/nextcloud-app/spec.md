# Nextcloud App Specification

## ADDED Requirements

### Requirement: The company segment also hides study advice and subject choices
Once an admin chose Company as the kind of organisation, the app MUST hide the study progress (BSA) risk dashboard, trajectories, warnings and decisions (menu entries, Progress landing cards and the study progress risk Reports card) and the subject choice and elective picker menu entries (D34). Each such surface MUST carry `{"workspace.chosenSegment": {"notIn": ["corporate"]}}` next to its existing `workspace.segment` gate, so an install that never chose keeps them and higher education keeps BSA.

#### Scenario: A company that chose Company
- **GIVEN** the chosen segment is `corporate`
- **WHEN** the menu, the Progress landing and the Reports page are built
- **THEN** no BSA entry, BSA card, study progress risk card or subject choice entry renders

#### Scenario: An install that never chose
- **GIVEN** no segment was chosen
- **WHEN** the menu is built
- **THEN** the BSA and subject choice entries render as before

#### Scenario: A university
- **GIVEN** the chosen segment is `he`
- **WHEN** the menu is built
- **THEN** the BSA and subject choice entries render
