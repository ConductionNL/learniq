# Agent tools

An AI agent in hermiq can read learniq's course catalogue through tools that OpenRegister derives from the register. Learniq writes no tool code: a schema opts in with an `x-openregister-mcp` block under `configuration`, and OpenRegister turns each declared verb into a tool named `learniq.<schema>.<verb>`.

## What an agent can read

| Tool | What it answers |
|---|---|
| `learniq.course.search`, `learniq.course.get` | which courses exist, their level, language, mandatory flag and regulation |
| `learniq.lesson.search`, `learniq.lesson.get` | the lessons of a course (filter on `courseId`) |
| `learniq.programme.search`, `learniq.programme.get` | which courses make up a programme |
| `learniq.assignment.search`, `learniq.assignment.get` | what an assignment asks and when it is due |
| `learniq.regulation.search`, `learniq.regulation.get` | which regulations apply and whether they need annual renewal |

Every tool reads. None can create, change or delete anything: on these schemas the lifecycle is the gate between a draft and what learners see, so a write would be a publish action.

## Who sees what

The tools run with the rights of the person chatting. On all five schemas the staff groups that write them read everything; every other signed-in user reads only published rows (for assignments, published and closed). This rule lives in the register, so it holds in the app and the API too, not only for agents.

## What stays off

Everything that names a learner or holds exam content: learner profiles, enrolments, grades, attendance, submissions, credentials, cohorts (a class roster), sessions (they carry the ids of affected learners and substitute teachers), item banks, assessments, proctoring and absence reasons. A tool that can fetch one grade by id can be talked into fetching a hundred, so these schemas derive no tool at all.

## What an agent can do

Four curated tools sit next to the derived reads. Each is off for everyone except admins until an admin grants its action under Admin settings > Learniq > Action authorization, and hermiq asks the person behind the agent for approval where its guardrails say so.

| Tool | Action right | What it writes |
|---|---|---|
| `learniq.enrolLearner` | `mcp.enrol-learner` | a pending enrolment; a second call for an open enrolment returns that one |
| `learniq.recordAttendance` | `mcp.record-attendance` | one attendance record for a learner and a session; a second call corrects it |
| `learniq.gradeSubmission` | `mcp.grade-submission` | a concept grade for a handed-in submission; learners see it only after a teacher publishes it in the gradebook |
| `learniq.listExpiringCredentials` | none (reads with your own rights) | nothing; it returns credential id, learner id and name, course id and title, expiry date and renewal course, and nothing else. It returns at most 200 rows; when there are more, `truncated` is true and the agent should narrow the question by course or date |

Every write goes through the same checks as the screens in the app, in the name of the person the agent works for, and says on the record which tool made it. An agent cannot issue a credential: a credential is signed and leaves the school the moment it exists, so there is no draft for a teacher to accept.

Three chat examples:

1. "Which certificates expire this quarter? Re-enrol those people." The agent lists the expiring certificates and enrols each learner in the renewal course.
2. "Record attendance for the 9:00 session: everyone present except Jayden, sick." The agent records one row per learner; the excuse itself still goes through an absence request.
3. "Grade the week-3 submissions against the rubric and let me check them." The agent writes concept grades; you publish them in the gradebook.

## Migrating from the old tool names

`learniq.listCourses` and `learniq.getCourseDetails` are gone. Use `learniq.course.search`, and `learniq.course.get` plus `learniq.lesson.search` with `courseId`.
