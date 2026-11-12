---
kind: spec
---

# Say the rest of what each capability row does

## Why

A review on 12 Nov 2026 re-read the 196 rows whose `built.requirement` changed since 11 Nov. For 66 of them the linked requirement's scenarios said only part of the row, or something else. Example: a row says a coordinator places a learner at a company, and the linked requirement only says placements are stored.

For 19 rows an existing requirement already says the missing part: those rows now record it next to their first link. For the other rows this change writes the missing requirement, from the code as it is.

Where the code does not do what the row says, the row loses `built` (decision 389): its state becomes `specified`, its `built.plan` points here, and its evidence carries a "Not built:" line. The tasks below name that work.

## What changes

- 47 ADDED requirements across 24 capabilities. Most describe behaviour the code already has; the tasks in section 2 name the ones it does not.
- No code changes.

### Matrix rows (`learniq` `openspec/parity/capabilities.json`)

| row | capability | spec | requirement |
|---|---|---|---|
| `comp-signed-attestation` | Ask a learner to sign that they read and understood the training. | `compliance-audit` | A learner confirms and signs their own attestation after the training |
| `comp-external-needs-verifier` | Make a second person verify outside training before it counts. | `external-training-recording` | A second officer verifies outside training before it counts toward coverage |
| `comp-audit-pack-tamper-report` | Show the auditor that nobody changed the evidence afterwards. | `compliance-audit` | The audit pack report says the evidence chain is intact when nothing changed |
| `cred-issue-on-completion` | Hand out a certificate the moment someone finishes a course. | `certification` | Completing a course issues its certificate without a staff action |
| `cred-cryptographic-signature` | Sign each certificate so a third party can check it is genuine. | `certification` | A certificate whose signature was altered is answered as not genuine |
| `cred-holder-only-visibility` | Keep a certificate visible to its holder and your admins, nobody else. | `certification` | Hr and compliance officers read every certificate of their school |
| `cont-attach-files-to-lesson` | Attach a file, a video or a reading to a lesson. | `course-management` | A designer attaches a new file to a lesson as a Material |
| `cont-import-report-per-item` | See exactly what an import kept, changed or dropped, item by item. | `course-management` | An import report marks a reduced resource as degraded with its reason |
| `enr-take-applications` | Take applications from people who want to join. | `enrolment` | An applicant submits an application to an open admissions round |
| `enr-review-board-decides` | Let a review panel decide on applications together. | `enrolment` | A review panel decides an application together |
| `enr-choose-electives` | Let a learner choose their elective subjects against the rules. | `school-structure` | A learner picks and submits their electives from the picker |
| `enr-elective-needs-consent` | Ask a guardian to agree to the subject choice before it counts. | `school-structure` | A minor's own subject choice waits for a guardian's consent |
| `ass-draw-random-items` | Give each learner a different draw of questions from the bank. | `assessment` | Each learner gets their own random draw from the item pool |
| `ass-shuffle-answer-order` | Shuffle the answer options so neighbours see a different order. | `assessment` | Each learner sees the answer options in their own order |
| `ass-test-reliability-alpha` | Check whether a test hangs together, using Cronbach's alpha. | `assessment` | Cronbach's alpha is computed once 30 graded attempts exist |
| `ass-handle-a-fraud-case` | Run a cheating case from report to hearing to reasoned decision. | `exam-board` | A fraud case moves from report to a scheduled and held hearing |
| `asg-hand-in-a-file` | Let a learner hand in a file from their own drive. | `assignments` | A learner hands in a file from their own Nextcloud files |
| `asg-close-the-window` | Stop hand-ins after the deadline. | `assignments` | A hand-in after the deadline is refused when late work is not accepted |
| `asg-rubric-must-be-complete` | Stop a mark being released while a rubric criterion is unscored. | `assignments` | A teacher's mark is not released while a rubric criterion is unscored |
| `grd-weighted-final-grade` | Work out the final grade from weighted components. | `grading` | The final grade is the weighted average of its component marks |
| `grd-see-what-a-mark-does` | Show a learner what one more mark would do to their average. | `grading` | A learner sees what one more mark would do to their average |
| `grd-competency-framework` | Load a competency framework and mark against its outcomes. | `competency` | A school loads a competency framework and marks against its outcomes |
| `grd-skills-gap-view` | See where a group's skills are thin, on one page. | `competency` | A group view shows which skills are thin across a cohort |
| `grd-decision-carries-its-evidence` | Make a binding study decision carry the evidence it rests on. | `study-progress` | A decided study decision keeps the credits and warnings it rests on |
| `enr-sign-up-for-an-optional-lesson` | Sign up for or withdraw from a single optional lesson, and see free places and when sign-up opens or closes. | `enrolment` | A learner withdraws from an optional lesson and sees when sign-up opens |
| `sup-individual-learning-plan` | Write an individual plan for a learner who needs one. | `learning-plan` | A coordinator writes a plan of a chosen kind for a named learner and it shows on that learner |
| `sup-plan-keeps-its-versions` | Keep every version of a plan, so you can see what changed. | `learning-plan` | A user opens a plan's earlier versions and sees what changed between them |
| `sup-everyone-signs-the-plan` | Have the learner, the guardian and the school all sign the plan. | `learning-plan` | The learner's own signature is required and counted when the template asks for it |
| `sup-group-plan-with-subgroups` | Plan instruction for a whole class in three ability groups. | `learning-plan` | A coordinator plans a class in ability subgroups with learners assigned to each |
| `sup-apply-for-extra-support` | Apply for an extra-support ruling and trace it back to the request. | `learning-plan` | A TLV application is made for a support request and points back to it |
| `sup-municipality-must-answer` | Hold the file open until the municipality has given its view. | `attendance` | A reported attendance flag is not closed until the municipality's feedback is recorded |
| `att-book-a-room` | Book a room or a lab, and respect its capacity. | `timetabling` | A planner books a room or lab for a session and an overfull booking is flagged |
| `att-never-auto-resolve-a-clash` | Leave a clash for a person to resolve, never guess at it. | `timetabling` | A coordinator acknowledges and resolves a timetable conflict by hand |
| `att-import-a-timetable` | Import a timetable from your scheduling software. | `timetabling` | An import started from the timetable page reports its outcome and refreshes the conflicts |
| `att-tell-people-a-lesson-moved` | Tell everyone when a lesson moves or is cancelled. | `timetabling` | A lesson moved to another room or time notifies the affected learners and parents |
| `gua-teacher-states-free-slots` | Let each teacher say when they are free. | `parent-conferences` | A teacher enters and submits their own free times for a conference round |
| `gua-guardian-asks-for-slots` | Let a guardian ask for the teachers they want to speak to. | `parent-conferences` | A guardian chooses which teachers they want to speak to in a signup |
| `gua-take-your-record-with-you` | Let a learner download their whole learning record, signed. | `portable-learning-record` | A learner downloads the signed bundle of their record and it carries a verifiable signature |
| `wpl-match-learner-to-employer` | Match a learner to a company for their work placement. | `bpv` | A coordinator links a learner to a training company in a placement |
| `wpl-visit-report` | Write up the visit or the three-way conversation. | `bpv` | A school coach writes up a visit or a three-way conversation for a placement |
| `gov-record-of-processing` | Keep the GDPR record of processing up to date by itself. | `avg-verwerkingsregister` | A schema that is added or changed reaches the record of processing without anyone editing the record |
| `gov-push-data-to-another-system` | Push learner data to a national register or another system. | `data-exchange` | A user requests an export to a chosen target from the data exchange page |
| `gov-dutch-and-english-throughout` | Read the whole product in Dutch as well as English. | `i18n-locale-parity` | A user on the Dutch locale reads the screens and notifications in Dutch |
| `gov-wcag-aa` | Meet WCAG 2.1 AA and show the evidence on request. | `accessibility-evidence` | Learniq's own conformance record shows every WCAG 2.1 A and AA criterion as passing |
| `ass-mark-question-by-question` | Mark one open question for every learner in turn before moving to the next. | `assessment` | A teacher scores the same open question across every learner's attempt before the next question |
| `gov-see-which-connections-work` | See which outside connections work, and what each one is missing. | `integrations` | Each connection on the Integrations page says what it is missing |
| `gov-fill-profiles-from-sign-in` | Fill learner profiles from what the school sign-in sends along. | `identity-federation` | Signing in writes the mapped fields to the learner's profile |
