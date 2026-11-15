# Proposal: portal-parent-child-record

## Why

Ruben reviewed the primary-school parent portal on 2026-10-02 (approved the same day):

- "The student record for parents is empty, it should be filled with report cards, grades, homework etc."
- "The student file on the parent side should contain KPI cards for absence, on time." He chose three cards: absence this school year (days, with and without permission), late arrivals this school year (count and minutes), and unexcused absence, highlighted.
- "The parent portal should contain a calendar of upcoming events": school events (study days, sports day, trips, kept by the school in learniq), holidays and free days, parent evenings and the guardian's own conversation times.
- "Parent should read the news in the portal."
- Learniq's parent sections still read in English on the Dutch site.

## What changes

- **One page per child.** "Mijn kinderen" becomes portaliq's record page of the children list (portaliq `contribution-record-page`). A guardian opens one child and sees, for that child only: three figure cards from the attendance summary (lane lq-attendance, `attendance-summary`), the published report cards, the report card grades, the homework of the child's group with handed in or open, the attendance list, the coming calendar items and the school news.
- **Homework.** `Assignment.learnerRefs` holds the pupils of the assignment's group, stamped by the server from the group's enrolments (`AssignmentLearnerRefsStamp`). The guardian reads published assignments through the reverse join on her children by that list, which is never projected, so no guardian sees another pupil's uuid. Handed in or open comes from the child's own submissions.
- **A school calendar.** A new `school-event` record (title, start and end, kind, the whole school or some groups, the school) that coordinators, the office and the director keep on a new "School calendar" page. Report periods gain `schoolId`, so the holidays and study days they already hold reach that school's parents. Both are joined on the child's school (`targetField: schoolId`); an event for some groups shows only for a child in one of them.
- **A calendar page** for the guardian: every child's school events, holidays, study days and conversation times, and the last day to book a conversation.
- **Dutch.** Every new visible string has a Dutch entry, and the translator also translates a card's unit, a calendar source's kind and fixed title, and a lookup's labels. Portaliq now asks the API in the site's language, so a Dutch site reads Dutch whatever the browser prefers.
- **Example set.** The primary school set ships school events for 2025-2026 and 2026-2027, report periods that name the school, homework for every group and submissions for part of it.

## Not changed

- The scope rules: every collection reads through the reverse join on the guardian's own children. Drafts never show (report cards are filtered on `published-to-parents`, homework on `published`).
- The student audience.

## Depends on

- portaliq `contribution-record-page` (ConductionNL/portaliq branch `feat/parent-child-record`).
- learniq `feature/attendance-roll-call` (lane lq-attendance) for `attendance-summary`; merged into this branch.
