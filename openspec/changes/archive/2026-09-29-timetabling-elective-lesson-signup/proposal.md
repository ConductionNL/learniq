---
kind: code
depends_on: []
---

# Proposal: timetabling-elective-lesson-signup

## Summary

A school offers optional lessons (keuzewerktijd, flex hours, extra support or an elective workshop): each offer names its lessons, a capacity and a sign-up window that can open a set number of days before each lesson. Learners of the eligible groups sign up and withdraw inside the window. After the window closes, a coordinator places learners who did not sign up. Another system signs learners up or withdraws them through learniq's API, under the same rules as a learner on the screen.

## Why

This change covers two rows of planninq's matrix (`ConductionNL/planninq openspec/parity/capabilities.json`, planninq#665), both owed to learniq.

`tt-enrolment-deadline` ("Set an enrolment window for optional lessons and place students who missed the deadline."), `none`. Decision: build, two competitors rate yes.

- zermelo, yes: https://support.zermelo.nl/guides/medewerker/aanmaken-van-een-inschrijfprocedure, enrolment rounds in relative weeks, "Leerlingen mogen zich een week van te voren inschrijven voor lessen"; https://support.zermelo.nl/guides/medewerker/voorinschrijvingen-in-het-portal, enrolments are filled "in het geval dat leerlingen zelf de kans hebben gehad zich in te schrijven en zichzelf niet of niet voldoende hebben ingeschreven".
- xedule, yes: https://support.xedule.nl/hc/nl/articles/37415902430738-Beheer-Configuratie-MyX "Inschrijftermijn opent / sluit ... Om toch deze lessen toe te delen is het mogelijk dit vanuit Xedule te doen via het Toewijzen aan verlopen inschrijflessen".
- untis, partial: https://help.untis.at/hc/de/articles/360016846039 "Anmeldezeiträume", placing late students not described.
- timeedit, partial: https://www.academy.timeedit.com/product-updates/169667324, registration periods per group.

`tt-api-enrolment-write` ("Enrol or unenrol students in elective lessons through the API from another system."), `none`. Decision: build, roadmap demand and two competitors rate yes. It is the API door of the same sign-up, so it shares this change.

- Roadmap demand: https://docs.zportal.nl/docs/print.html, Zermelo: "Momenteel is het alleen mogelijk om leesrechten te geven aan Partners. We zien inschrijven/uitschrijven via /liveschedule en gebruikersbeheer via /users als de belangrijkste endpoints om bewerken op mogelijk te maken".
- xedule, yes: https://developer.connect.xedule.nl/developer/apis/calendar-myxedule-prod/operations?api-version=2022-04-01-preview "PATCH /Appointments/{id}/Subscriptions".
- timeedit, yes: https://developer.timeedit.com/reference/post_objects-update-members-by-type "UPDATE Object members".
- zermelo and untis, no: their APIs are read-only for this today.

## What learniq has today

Read at learniq `development` 8bb8401d.

- `SubjectChoice` (`lib/Settings/learniq_register.json:5376`): a learner's elective courses for a school year, with guardian consent and validation; no window, and it chooses courses, not lessons.
- `Enrolment` (:2624): enrolment in a course, created by staff only; `EnrolmentPrerequisiteListener` shows the pattern for a rule that must hold on every create, whatever the caller (`openspec/specs/enrolment/spec.md:41-55`).
- `Session` (:6130): a lesson for one cohort (`cohortId` required). Nothing lets a learner of another group, or a subset of a group, sign up for a lesson.
- `AdmissionsWaitlistPromoter` handles capacity for admission rounds only.

## What this change builds

1. `ElectiveOffer`: a named offer with its lessons, capacity per lesson, eligible cohorts, and a window (fixed dates, or opening a number of days before each lesson and closing a number of hours before it).
2. `ElectiveSignUp`: one learner on one lesson of an offer, with status `signed-up`, `placed` or `withdrawn` and who made it.
3. `ElectiveSignUpRules`, an `ObjectCreatingEvent` and `ObjectUpdatingEvent` listener on `ElectiveSignUp` that enforces eligibility, capacity, the window and one sign-up per learner per lesson for every caller: the learner endpoint, the screens and the object API.
4. Learner view "Optional lessons" with open offers, free places, Sign up and Withdraw.
5. Coordinator view per offer and lesson: who signed up, who from the eligible groups did not, and "Place" for those learners after the window, which may exceed nothing but capacity.
6. The API: another system uses OpenRegister's object API on `elective-sign-up` with an account in the `elective-integrations` group, which may create and withdraw sign-ups for any learner, checked by the same listener.

## Out of scope

- Automatic placement of learners who did not sign up (Zermelo's automaton); a coordinator places them.
- Waiting lists per lesson.
- Choosing whole courses for next year (that is `SubjectChoice`).

## Affected projects

- [x] `learniq`: register (two schemas, a scope), a listener, a learner endpoint, two pages, seed data, l10n.

## Risks

- The integration account can sign up any learner. Mitigation: a dedicated group with only this right on only this schema, named in the register's scopes so OpenRegister provisions it, and every write audited.
