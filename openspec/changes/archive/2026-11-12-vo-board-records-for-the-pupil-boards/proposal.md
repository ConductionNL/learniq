---
kind: spec
depends_on: [example-sets-are-the-four-schools]
---

# Proposal: vo-board-records-for-the-pupil-boards

## Why

The learniq detail boards on identity.conduction.nl/screens draw class 3HV-B of the secondary school. A board can only be measured against the live app when the loaded set holds a record of the kind it draws. Several of them had none in the vo set, so their measurement pointed at the closest record there was: a pupil with another name, a closed exam of another class, a reading report instead of an essay.

## What changes

Only the generator `scripts/example-sets/vo.py` and the file it writes. A new step, `add_board_pupils()`, runs after the board layer and draws no random number, so every existing uuid stays.

- **Three classmates of Fenna carry the boards' names**: Daan Visser, Lucas van Dijk and Julia Peters, with their parents' family names and their report card comments. They stay in 3HV-B, so the class keeps its 27 pupils.
- **Daan**: a mentor's introduction note, a care note of 2 October (care team only) and a support request in deliberation with high urgency.
- **Lucas**: an unexcused absence at Monday's Nederlands, and the mentor's call home.
- **Julia**: a concept 8,2 for "Toets hoofdstuk 1, grammatica", weight 2. The component is added to the Nederlands toetsplan of 3HV-B.
- **Tests of 3HV-B**: Biologie hoofdstuk 2 open to take this week, Biologie hoofdstuk 3 published with an access code for 13 October, and Monday's Nederlands grammar test closed.
- **Betoog schrijven**: due Friday 9 October, two markers per submission, 14 handed-in and 2 draft submissions (Fenna's among them), and the two marks on Fenna's work. The assignment carries the class's learner refs, as a live save stamps them, so it is on Fenna's homework list too.
- **Emma de Vries**: an admission after the intake, in a new open round Brugklas 2027-2028.
- **The school's own threshold**: Vroeg signaal bij verzuim, 8 unexcused lesson hours in 4 weeks.
- **Biologie 3 tweetalig**: a course with the English name Biology 3 bilingual and the Dutch name beside it.
- **The Meldcode**: a regulation with the slug MELDCODE.

`submission`, `submission-mark` and `regulation` are appended to the schema order.

## Not in this change

- An attendance flag for Fenna de Jong (LqVerzuimsignaal draws 17 unexcused hours): her story has one day ill and no unexcused absence, and a flag must list its records.
- Boards whose schema the vo set has no rows of (group plan, learning plan, item bank, portfolio, lesson, credential, concern report, data-subject request), and the mbo and corporate stories.
