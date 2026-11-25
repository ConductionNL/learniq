## ADDED Requirements

### Requirement: The po set holds what the guardian boards show

The po example set MUST hold a gym lesson for groep 7 on the story's day at 13.15 uur, in the gym, on the gym course, so a child card and the child page can say "Gym om 13.15 uur". The photographer's event MUST read "In de ochtend, voor het uitje.", as the Detail board.

#### Scenario: Vera's group has gym on the story's day
- **GIVEN** the po set is loaded in the week of its story
- **WHEN** the guardian's child page reads groep 7's sessions of today
- **THEN** one of them is "Gym" from 13.15 to 14.00 uur in the Gymzaal
- @e2e exclude seed data, covered by PHPUnit `WilgenboomStoryDataTest`; the rendered line waits on the card declaration (lane FIX-L)
