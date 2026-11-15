## ADDED Requirements

### Requirement: A placement has a phase that follows its facts, and says when it is late

A `bpv-placement` MUST carry a phase derived from its company, POK signatures, period, assessments and hour approvals (searching, agreement, running, assessment, rounding-off, done), recomputed whenever one of those changes, and a late line in words when the phase's due date has passed. A manual move on the board MUST be logged with who and when and MUST last until the next fact change.

#### Scenario: The POK is signed
- **GIVEN** Milan's placement is in agreement and Bakker Techniek BV signs the POK as the last party
- **WHEN** the signature is saved
- **THEN** the placement moves to running without anyone dragging it
- @e2e exclude spec-only proposal; derivation asserted in unit tests

#### Scenario: Late in words
- **GIVEN** a placement still searching two weeks after the BPV period began
- **WHEN** the board shows its card
- **THEN** the card reads "2 weken te laat"
- @e2e exclude as above

### Requirement: A school coach plans visits, and the company agrees the date

learniq MUST keep a `bpv-visit` per planned company visit with its kind, the proposed or planned moment and its state, and MUST let the coach propose a date that the praktijkopleider accepts or answers from her portal. A placement's visit report MUST link the visit it reports.

#### Scenario: Datum voorstellen
- **GIVEN** Jesse Kok's mid assessment visit has no date
- **WHEN** his coach proposes Tuesday 13 October 10.00
- **THEN** the visit reads proposed, and the praktijkopleider of Van Dijk Machinebouw sees the proposal on her portal
- @e2e exclude cross-audience flow; spec-only proposal

### Requirement: The BPV-begeleider's Today gathers her students

The BPV-begeleider view of Today MUST show her First today (a student without a company in a running period, a visit due this week without a date, hours waiting more than seven days), her visits of the week as day columns, hours waiting per company oldest first with a reminder action, and her students per phase with a link to the board. It MUST count only placements where she is the school coach.

#### Scenario: Daan has no placement
- **GIVEN** Daan Smits's BPV period started and he has no company
- **WHEN** Ruud Hermans opens Today as BPV-begeleider
- **THEN** the card reads "Daan Smits (MT2A) heeft nog geen BPV-plek"
- @e2e exclude staff screen; spec-only proposal
