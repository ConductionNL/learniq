---
sidebar_position: 9
title: See which goals your curriculum covers
description: Read the curriculum coverage matrix and gap list per framework, year and subject.
---

# Curriculum coverage

See at a glance which goals of a framework your curriculum covers, per year and per subject.

Open it from **Learning > Curriculum coverage**. Teachers, coordinators, team leads and administration managers can
use it.

## What the page shows

Pick a framework, and a subject if you want to narrow it down. The grid lists the goals under their domains, with a
column for each year the framework uses (such as groep 5 or leerjaar 2). A goal shows in the years it belongs to:

| Cell | Meaning |
|---|---|
| Planned and assessed | A lesson or course works on the goal, and an assignment or assessment tests it. |
| Planned | A lesson or course works on the goal, but nothing tests it yet. |
| Assessed | An assignment or assessment tests the goal, but no lesson or course works on it. |
| Not covered | Nothing refers to the goal. |

When a lesson says how far it takes a goal, the deepest level shows in brackets, for example "Planned (master)".

Below the grid, the gap list names per subject and year the goals that are not covered and the goals that are taught
but never tested. Start there when you plan next period.

## What it does not show

The page shows your plan. It does not say what learners have mastered. For that, use the skills gap dashboard and the
attainment pages.

## How coverage fills in

Coverage updates on its own whenever a goal, lesson, course, assignment or assessment is saved. For a framework with
no coverage yet, save one of its goals or lessons, or ask your administrator to run
`occ learniq:curriculum-coverage:recompute`.

## Make it work for your school

1. Give each goal the years it belongs to, and the subject (a course) it belongs to. A goal without its own years or
   subject takes them from the domain above it.
2. On each lesson, course, assignment and assessment, add the goals it works on, with a depth from the framework's
   levels if you want to say how far it goes.
3. Open Curriculum coverage and work down the gap list.
