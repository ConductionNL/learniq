# Proposal: leaf-integrations

## Summary

Adopt OpenRegister's app-agnostic integration leaves beyond the two leaf types Scholiq uses today. Scholiq currently consumes exactly two of OpenRegister's ~17 integration providers: **files** (17 `{"type": "integration", "integrationId": "files"}` manifest widgets across detail pages) and **talk** (`linkedTypes: ["talk"]` on `Cohort` and `Session`, with `CohortTalkMembershipHandler` keeping the class-space conversation's participants in sync with active Enrolments). This change adds five more leaf types where they genuinely serve the teaching workflow: **calendar** (session schedules, assignment deadlines, credential-renewal dates as linked CalDAV events), **contacts** (learner/teacher/practical-trainer profiles linked to NC Contacts cards), **forms** (assignment submission intake and class-level excuse-request intake), **deck** (BPV placement follow-up cards), and **polls** (in-course quick polls, deliberately distinct from formal assessments). Every leaf is declarative: a `linkedTypes` entry on the schema in `lib/Settings/scholiq_register.json` plus an integration widget in `src/manifest.json` — no new PHP except zero-or-one listener, no new Vue.

## Motivation

- **The leaf infrastructure is already paid for.** OpenRegister ships the providers (`CalendarProvider`, `ContactsProvider`, `FormsProvider`, `DeckProvider`, `PollsProvider`, …), the three-stage filter (registry → `Schema::linkedTypes` → surface), and the render path (`CnObjectSidebar` + manifest integration widgets). Scholiq's cost per leaf is a JSON declaration.
- **Teachers currently leave the app for exactly these five jobs.** "When is the next class", "who is this learner's practical trainer", "hand in via this form", "chase the BPV company", "quick show of hands" are all served by NC apps Scholiq already sits next to, with no link back to the Course/Cohort/Session object that gives them context.
- **The talk adoption proved the pattern.** `talk-classroom-spaces` (archived) established the archetype rule this change extends: comms-style leaves belong on **delivery-run** archetypes (Cohort, Session), never on catalog definitions (Course, Programme, CurriculumPlan). This change stays inside that rule.
- **Doing it declaratively keeps the AVG surface auditable.** A leaf that exists only as a `linkedTypes` string plus a manifest widget can be enumerated by grepping two files; hand-rolled per-page integrations cannot.

## Affected Projects

- [ ] Project: scholiq — `lib/Settings/scholiq_register.json` (`linkedTypes` on 8 schemas), `src/manifest.json` (integration widgets on 9 detail pages), one new e2e spec-coverage file
- [ ] Project: openregister — **no code change**; consumed read-only as the leaf registry and render layer

## Capabilities

- `integration-leaves` — new capability: which OpenRegister integration leaves Scholiq declares, on which archetypes, and why the rest are OFF

## Scope

### In Scope

- `calendar` leaf on `Session`, `Assignment`, `Credential`, `Cohort` (+ widgets on SessionDetail, AssignmentDetail, CredentialDetail, CohortDetail)
- `contacts` leaf on `LearnerProfile`, `Praktijkopleider` (+ widgets on LearnerProfileDetail, PraktijkopleiderDetail)
- `forms` leaf on `Assignment`, `Cohort` (+ widgets on AssignmentDetail, CohortDetail)
- `deck` leaf on `BpvPlacement` (+ widget on BpvPlacementDetail)
- `polls` leaf on `Session`, `Cohort` (+ widgets on SessionDetail, CohortDetail)
- A Playwright spec-coverage test asserting the new widgets render on their detail pages
- Documentation of the OFF list (email, maps, photos, shares, bookmarks, collectives, notes, time-tracker, xwiki, analytics, activity) with per-leaf reasons

### Out of Scope

- Any leaf on a catalog-definition archetype (`Course`, `Programme`, `CurriculumPlan`, `CourseTemplate`, `Regulation`) — the talk-classroom-spaces hard rule stands
- Mail intake (`configuration.linkedTypes: ["mail"]` / `mailObjectTemplate`) — Scholiq's intake flows are enrolment- and application-driven, not email-driven; no record archetype maps to "an email becomes an object" without an AVG review of unsolicited personal data landing in a school register
- Automatic event/card/poll **creation** (e.g. generating a VEVENT from `Session.startsAt`) — leaves link user-curated entities; derivation from properties is a separate change with its own idempotency questions
- Membership sync for any new leaf (only talk has `CohortTalkMembershipHandler`; calendar/deck/polls leaves carry no participant model to sync)
- Changes to OpenRegister providers

## Approach

1. Add `linkedTypes` arrays to the 8 schemas in `lib/Settings/scholiq_register.json` (schema-level key, exactly as `Cohort`/`Session` declare `["talk"]` today; values validated by OpenRegister against `IntegrationRegistry::listIds()`).
2. Add `{"type": "integration", "integrationId": ..., "id": ..., "title": ..., "icon": ...}` widgets to the 9 detail pages in `src/manifest.json`, following the shape of the existing 19 integration widgets.
3. Add `tests/e2e/spec-coverage/integration-leaves.spec.ts` covering widget presence on the touched detail pages.

## New Dependencies

None. The leaf providers require their NC apps (`calendar`, `contacts`, `forms`, `deck`, `polls`) at runtime; OpenRegister's providers self-disable (`isEnabled()`) when the app is absent, so a missing NC app degrades to a hidden widget, not an error.

## Impact

- `lib/Settings/scholiq_register.json` is re-imported; `linkedTypes` is additive and does not touch properties, `authorization`, or lifecycle config.
- 12 new manifest widgets; no existing widget moves or changes id.
- No REST, no PHP, no migration.

## Cross-Project Dependencies

- **openregister** ≥ the commit carrying the pluggable integration registry and the five named providers (all present at `origin/development`; verified in `lib/Service/Integration/Providers/`).

## Risks

### Risk 1: A leaf surfaces personal data on a page where it wasn't before

- **Severity**: Medium
- **Detail**: The `contacts` leaf on `LearnerProfile` links an NC Contacts card to a learner record. The card lives in the viewer's own address book and the leaf renders only for users who can already read the `LearnerProfile` object (OpenRegister RBAC gates the object read before any leaf resolves), so no new read path is created — but the *linking* act copies nothing and must stay that way.
- **Mitigation**: REQ-003 pins that the leaf links, never copies; the AVG verwerkingsregister question is recorded in Open Questions rather than silently skipped.

### Risk 2: Polls next to assessments invites grading-by-poll

- **Severity**: Medium
- **Detail**: A quick poll on a Session looks adjacent to a formal assessment; a teacher could start using poll results as grades, bypassing `AssessmentGradeGuard` and the whole grading model.
- **Mitigation**: REQ-006 makes the distinction binding (polls leaves appear only on delivery-run archetypes, never on `Assessment`/`Assignment`-as-grading surfaces; widget copy says "Quick poll (not graded)"), and the polls leaf is deliberately NOT declared on `Assessment`.

### Risk 3: Widget clutter on already-dense detail pages

- **Severity**: Low
- **Mitigation**: Every page touched gains at most two leaf widgets; CohortDetail (the densest, gaining calendar + forms + polls next to the existing talk widget) is explicitly checked in the e2e test for layout sanity, and the sidebar three-stage filter lets an instance disable any leaf centrally.

## Rollback Strategy

Revert the commit. Re-importing the register drops the added `linkedTypes` entries; the manifest widgets disappear with the manifest. Links already created by users (VEVENTs, Deck cards, form shares, poll links) live in their owning NC apps and simply lose their Scholiq-side rendering — nothing is deleted.

## Open Questions

- Does linking an NC Contacts card to a `LearnerProfile` require a new entry in `openspec/specs/avg-verwerkingsregister/` (the link itself is a personal-data processing act, even though no field is copied)?
- Should `Session` calendar links eventually be *derived* from `startsAt`/`endsAt` (auto-created VEVENTs) rather than user-curated? Deferred: derivation needs an idempotency key and a decision about which calendar owns the event.
- `ConferenceSlot` / `TeacherAvailability` are calendar-shaped but carry per-person availability data; they stay OFF pending the same AVG review as contacts.
