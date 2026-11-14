## ADDED Requirements

### Requirement: The pupil list shows what the school kind acts on, and its saved views count

The staff list of pupils of a group MUST offer the columns its school kind declares (po: today, absence this year, late, conference time, care; vo: signal, attendance this year, average, mentor talk; mbo: company, approved BPV hours, attendance, placement state), each in words, and saved views that show how many pupils they hold. Pupils with an open signal MUST sort first in the vo mentor view.

#### Scenario: Groep 7 on Monday morning
- **GIVEN** Lina has a dentist appointment until 11.30 and Hamza was reported ill
- **WHEN** Daan opens the list of groep 7
- **THEN** Lina's Vandaag reads "Tandarts tot 11.30", Hamza's "Ziek gemeld", and the view "Afwezig vandaag" reads 2
- @e2e exclude staff screen; spec-only proposal

### Requirement: The pupil's page opens with a header, one next step and the people around the pupil

The pupil's page MUST open with the pupil's name, a state pill, group, age and number (and the profile or qualification with crebo where there is one), then a "Wat nu?" card with the first applicable next step (a booked conference within 14 days, a placement assessment or visit, an open signal) with at most three preparatory lines and one action. The side column MUST name the mentor, the care coordinator (or that no plan runs) and, for a student in a placement, the praktijkopleider and the BPV-begeleider.

#### Scenario: Vera's page
- **GIVEN** Fatima booked 18.00 on 29 October and Daan confirmed it
- **WHEN** Daan opens Vera's page
- **THEN** "Wat nu?" reads "Oudergesprek op donderdag 29 oktober, 18.00 uur" with "De tijd is gekozen door Fatima Hulstkamp en door u bevestigd"
- @e2e exclude staff screen; spec-only proposal

### Requirement: The conversation with the guardians is on the pupil's page

The pupil's page MUST show the newest messages between staff and this pupil's guardians, read through portaliq's staff message endpoints for conversations about this pupil, and MUST let a member of staff who is a participant reply from the page. Staff who are no participant MUST NOT read them.

#### Scenario: A question about topography
- **GIVEN** Fatima asked on 27 September whether Vera must know the topography by heart
- **WHEN** Daan opens Vera's page
- **THEN** he reads the question and his answer of 28 September, and can send a new message to Vera's parents
- @e2e exclude cross-app read; spec-only proposal
