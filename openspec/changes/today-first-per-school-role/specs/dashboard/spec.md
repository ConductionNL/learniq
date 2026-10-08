## ADDED Requirements

### Requirement: Today shows one First today card per role, from the role's ordered rules

For the group teacher, subject teacher, mentor, intern begeleider and head of school, Today MUST show at most one First today card: the first rule of that role's ordered list (design.md) that has at least one row, as one sentence, one line of context and at most two actions. When no rule has a row, no card MUST show.

#### Scenario: Meester Daan before 8.45
- **GIVEN** groep 7's register for Monday 5 October is not filled and two pupils were reported absent by their parents
- **WHEN** Daan opens Today at 8.30
- **THEN** the card reads "Het register van vandaag is nog niet ingevuld" with "Twee leerlingen zijn afgemeld door hun ouders" and the action "Register invullen"
- @e2e exclude spec-only proposal; rule order asserted in the rule list's unit tests

#### Scenario: Nothing first
- **GIVEN** the register is filled and nothing waits
- **WHEN** Daan opens Today
- **THEN** no First today card shows
- @e2e exclude as above

### Requirement: A mentor switches Today to her mentor class

A teacher who is the mentor of a cohort MUST be able to switch Today between Docent and Mentor; the Mentor view MUST narrow signals, conference counts and messages to the pupils of her mentor class. A teacher who is no mentor MUST NOT see the switch.

#### Scenario: Mevrouw Kramer as mentor
- **GIVEN** Sanne Kramer is mentor of H4b
- **WHEN** she switches to Mentor
- **THEN** she reads "Mentorgesprekken volgende week, 16 van 27 gepland" and the signals of H4b only
- @e2e exclude staff screen; spec-only proposal

### Requirement: Each role tile counts what that role acts on and opens the list it counts

Today MUST offer per role the tiles of the proposal's table that learniq can count: registers not filled, parent reports waiting, submissions to mark, conference times chosen and to confirm, plans ending without evaluation, registers filled per group, pupils with three or more insufficient grades per year, lessons cancelled today, leave to decide. Each tile MUST open the list it counts with the same filter, and MUST count only rows the user may read.

#### Scenario: The directeur at 9.05
- **GIVEN** seven of eight groups filled their register
- **WHEN** the directeur opens Today
- **THEN** "Aanwezigheid per groep" reads "7 van 8 registers ingevuld", and opening it lists groep 3 as not filled
- @e2e exclude staff screen; spec-only proposal
