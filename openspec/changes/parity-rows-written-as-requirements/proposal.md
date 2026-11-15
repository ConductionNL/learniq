---
kind: spec
---

# Write the requirement each built row already rests on

## Why

These 25 capability rows are built, or have a screen, but no spec requirement says what they do. Each row cited a spec dir, and none of that dir's scenarios said the row. So the rows could not record a `built.requirement`, and nothing tied the behaviour to a testable statement.

This change writes those requirements from the code as it is: the register in `lib/Settings/learniq_register.json` and the views named in each row's evidence. It adds no behaviour.

### Matrix rows (`learniq` `openspec/parity/capabilities.json`)

| row | capability | spec | requirement |
|---|---|---|---|
| `comp-deadline-per-enrolment` | Give each mandatory enrolment a deadline the learner can see. | `enrolment` | A mandatory enrolment shows its deadline to the learner |
| `comp-overdue-visible-to-learner` | Let a learner see at a glance which of their courses is late. | `enrolment` | A learner sees which of their courses is late |
| `cred-expiry-countdown` | See how many days are left before a certificate lapses. | `certification` | A certificate shows the days left before it lapses |
| `cred-revoke-with-reason` | Withdraw a certificate and record why. | `certification` | Revoking a certificate requires a reason |
| `cred-holder-only-visibility` | Keep a certificate visible to its holder and your admins, nobody else. | `certification` | A certificate is read by its holder and the certificate managers only |
| `cont-draft-before-publish` | Work on a course in draft and publish it when you are ready. | `course-management` | A course is worked on in draft and published when ready |
| `cont-course-in-two-languages` | Give a course a Dutch name next to its English one. | `course-management` | A course carries a Dutch name next to its English name |
| `enr-enrol-one-person` | Sign one person up for a course. | `enrolment` | Staff enrol one learner in a course |
| `enr-review-board-decides` | Let a review panel decide on applications together. | `enrolment` | Applications are decided from the admissions review board |
| `ass-build-question-bank` | Keep a bank of questions and reuse them across tests. | `assessment` | Items in an item bank are reused by more than one test |
| `ass-import-qti-package` | Import a QTI question package from another system. | `assessment` | A QTI package is imported into an item bank |
| `ass-assemble-a-test` | Assemble a test from the bank with a time limit and a scoring scheme. | `assessment` | A test is assembled from items with a time limit and a scoring scheme |
| `asg-set-an-assignment` | Set an assignment with instructions and a hand-in date. | `assignments` | A teacher sets an assignment with instructions and a hand-in date |
| `asg-mark-against-a-rubric` | Mark work against a rubric with weighted criteria. | `assignments` | A teacher marks a submission against a rubric with weighted criteria |
| `grd-see-what-a-mark-does` | Show a learner what one more mark would do to their average. | `grading` | A grade shows what it does to the final grade |
| `grd-gradebook-view` | Give a teacher one grid of every learner and every component. | `grading` | A teacher reads one grid of every learner and every component |
| `sup-plan-template-per-kind` | Start a plan from a template instead of a blank page. | `learning-plan` | A learning plan starts from a template for its kind |
| `sup-evaluate-the-plan-on-date` | Evaluate a plan on the agreed date and record the outcome. | `learning-plan` | A learning plan is evaluated on its agreed date and the outcome is recorded |
| `sup-log-a-behaviour-incident` | Log a behaviour incident and what was done about it. | `pupil-dossier` | A behaviour incident is logged with what was done about it |
| `att-schedule-a-lesson` | Schedule a lesson with a time, a room and a teacher. | `school-structure` | A lesson is scheduled with a time, a room and a teacher |
| `gov-log-who-read-what` | Log who read a learner's record, not only who changed it. | `avg-verwerkingsregister` | Reading a learner's record is logged, not only changing it |
| `gov-say-when-to-review-a-purpose` | Set a review date on each processing purpose. | `avg-verwerkingsregister` | Each processing purpose carries a review date |
| `wpl-supervisor-on-record` | Keep the workplace supervisor on record as a person. | `bpv` | The workplace supervisor is kept on record as a person |
| `att-late-and-excused-marks` | Record a learner as late or excused, not only present or absent. | `attendance` | An attendance mark records late and excused next to present and absent |
| `enr-one-people-overview-for-hr` | See learners, enrolments, attendance and diplomas of the whole school on one page, with search. | `dashboard` | HR reads learners, enrolments, attendance and diplomas on one searchable page |

## What changes

- ADDED requirements in 13 capabilities, one per row, each with at least one scenario.
- Each row records its new requirement in `built.requirement`.

## Capabilities

### Modified capabilities

- `enrolment`, `certification`, `course-management`, `assessment`, `assignments`, `grading`, `learning-plan`, `pupil-dossier`, `school-structure`, `avg-verwerkingsregister`, `bpv`, `attendance`, `dashboard`: ADDED requirements.

## Impact

- **Specs only.** No code, register or manifest change.
