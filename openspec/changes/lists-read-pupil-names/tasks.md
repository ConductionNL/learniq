# Tasks: lists-read-pupil-names

- [x] **1.1** `src/utils/learnerName.js`: the lookups of a cell (learnerRef first, user id otherwise, arrays too), the profile's name, a resolver that fetches each profile once and falls back to the user id
  - node test `tests/unit-js/listsReadPupilNames.test.mjs`
- [x] **1.2** `LearnerNameCell.vue` registered on CnAppRoot as the `learnerName` cell widget
- [x] **1.3** every staff list that showed `learnerId` uses `learnerName` under "Learner"; no list keeps a bare learner id column
  - node test "no staff list shows a bare learner id"
- [x] **1.4** the enrolments, grades, final grades and assessment results index pages declare a teacher's columns, with no uuid column
  - node test "the four index pages declare columns without a uuid"
- [x] **1.5** course and group columns on the touched lists read by name; every heading has a Dutch entry
  - node test "every heading has a Dutch entry"
