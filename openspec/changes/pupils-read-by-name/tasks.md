# Tasks: pupils-read-by-name

- [x] **1.1** the learner profile names itself from its given and family name; every field the template reads is declared
  - node test `tests/unit-js/pupilsReadByName.test.mjs` ("a learner profile names itself from the given and family name")
- [x] **1.2** every profile in the primary school set carries both names
  - node test "every profile in the primary school set carries both names"
- [x] **1.3** `BackfillLearnerProfileNames` names stored profiles without a session, saves nothing on a second run, skips a profile without names, counts a failed save and continues
  - `tests/Unit/Repair/BackfillLearnerProfileNamesTest.php`, over `RegisterFaithfulStore`, which now hydrates `@self.name` from the shipped `objectNameField` the way OpenRegister does; the saved payload is validated against the shipped learner-profile fragment
- [x] **1.4** the step is registered after InitializeSettings and the app version moves
- [x] **1.5** the round page, absence reports, attendance records and lesson attendance show the pupil by name, name every column, in Dutch too
  - node tests "shows the pupil by name, not by user id" and "names every column, in Dutch too"
- [x] **1.6** every code those lists show has a label, in Dutch too
  - node test "every code a teacher list shows reads as a word, in Dutch too"
- [x] **1.7** register 0.34.39, schema versions bumped
