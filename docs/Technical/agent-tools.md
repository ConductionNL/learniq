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

## Migrating from the old tool names

`learniq.listCourses` and `learniq.getCourseDetails` are gone. Use `learniq.course.search`, and `learniq.course.get` plus `learniq.lesson.search` with `courseId`.
