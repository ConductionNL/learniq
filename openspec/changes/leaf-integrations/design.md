# Design: leaf-integrations

## Context

OpenRegister ships a pluggable integration registry (`lib/Service/Integration/IntegrationRegistry.php`) with ~17 app-agnostic leaf providers under `lib/Service/Integration/Providers/` — verified ids include `calendar`, `contacts`, `forms`, `deck`, `polls`, `talk`, `files`, `email`, `maps`, `photos`, `shares`, `bookmarks`, `collectives`, `notes`, `activity`, `time-tracker`, `analytics`. Consumption is a three-stage filter (AD-5 of `pluggable-integration-registry`):

1. **registry** — which providers exist on this instance,
2. **schema** — `Schema::linkedTypes` says which are relevant per schema (validated at import against `IntegrationRegistry::listIds()` plus the legacy allow-list),
3. **component** — manifest integration widgets (`{"type": "integration", "integrationId": "..."}`) say which render where.

Scholiq today (verified against `lib/Settings/scholiq_register.json` and `src/manifest.json`):

- `linkedTypes`: `Cohort: ["talk"]`, `Session: ["talk"]` — nothing else, on any of the 118 schemas.
- Manifest integration widgets: 17× `files`, 2× `talk` (CohortDetail "Class space", SessionDetail "Join call").
- `CohortTalkMembershipHandler` (in `lib/Listener/`) syncs the linked Talk conversation's membership with active Enrolments — the only leaf with server-side logic.
- The `talk-classroom-spaces` change (archived) established **HARD RULE 1**, restated in the CohortDetail manifest `_note`: comms leaves attach to delivery-run archetypes (Cohort, Session), never to catalog definitions (Course, Programme, CurriculumPlan).

## Goals / Non-Goals

**Goals**
- Five new leaf types on the archetypes where a teacher/coordinator genuinely needs them, declared entirely in JSON.
- The catalog/delivery archetype rule preserved and extended to non-comms leaves.
- No new personal-data read path: leaves render only after the OpenRegister RBAC read of the parent object succeeds.
- An enumerable, greppable leaf surface (two files list every leaf).

**Non-Goals**
- Deriving calendar events, cards, or polls from object properties (linking only).
- Membership/participant sync for any new leaf.
- Mail intake (see proposal Out of Scope).

## Decisions

### Decision 1: The ON matrix — 8 schemas, 5 leaf types, 12 new widgets

| Schema | Archetype | New `linkedTypes` entries | Widget page(s) | Why |
|---|---|---|---|---|
| `Session` | delivery run | `calendar`, `polls` (keeps `talk`) | SessionDetail | Schedule events for the concrete class occurrence (`startsAt`/`endsAt`/`location` are its own fields; the leaf holds room-change notes, prep meetings, excursions); in-class quick poll. |
| `Cohort` | delivery run | `calendar`, `forms`, `polls` (keeps `talk`) | CohortDetail | Class agenda (trips, parent evenings); class-level excuse-request intake form; class polls. Membership sync stays talk-only. |
| `Assignment` | delivery artefact | `calendar`, `forms` | AssignmentDetail | Deadline/checkpoint events around `dueAt`; a Forms-based structured submission intake next to the existing file-drop (`asn-files`). |
| `Credential` | issued record | `calendar` | CredentialDetail | Renewal-planning events around `expiresAt` (mandatory-training renewals are calendar work today, done outside the app). |
| `LearnerProfile` | person | `contacts` | LearnerProfileDetail | Link the learner's (or their guardian's) NC Contacts card; the profile's own fields stay authoritative. |
| `Praktijkopleider` | person (external) | `contacts` | PraktijkopleiderDetail | The practical trainer at the BPV company is exactly a contact card — phone/email live better in Contacts than in register properties. |
| `BpvPlacement` | process record | `deck` | BpvPlacementDetail | Placement follow-ups (visit planning, contract chase, company feedback) are card-shaped work items spanning weeks. |

12 widgets, 8 schema edits. Every leaf id above was verified against a provider class in openregister `lib/Service/Integration/Providers/` — an unknown id fails register import via `Schema::validateLinkedTypesValue()`, loudly.

### Decision 2: The OFF list, with reasons

- `email` — no Scholiq archetype maps to "an email becomes an object"; unsolicited mail landing in a pupil register is an AVG intake question, not a JSON edit.
- `maps` — `Room.location` / `Session.location` are strings on campus; no geo workflow exists.
- `photos` — learner photos are special-category-adjacent (biometric potential) and Scholiq deliberately stores none; a photos leaf on LearnerProfile would invite them.
- `shares`, `bookmarks`, `collectives`, `notes`, `activity` — generic; nothing in the teaching workflow asked for them, and every widget costs page space (Risk 3).
- `time-tracker`, `analytics`, `xwiki`, `cospend`, `openproject`, `kvk`, `brp-haalcentraal`, `opencorporates` — no matching workflow (`kvk` was considered for `BpvPlacement.trainingCompanyKvkNumber` and deferred: company verification already has a dedicated flow, `trainingCompanyVerification`).
- `polls` on `Assessment` / `Assignment`-as-grading — refused, see Decision 4.

### Decision 3: The archetype rule generalises

HARD RULE 1 said *comms* leaves attach to delivery runs. This change generalises it: **interaction leaves (talk, polls, forms) attach to delivery-run or process archetypes; catalog definitions (Course, Programme, CurriculumPlan, CourseTemplate, Regulation) get no interaction leaves at all.** Calendar and contacts are reference leaves, but they too stay off catalog definitions here — a Course has no dates of its own to plan (its Sessions do), keeping the rule simple: catalog definitions carry **zero** leaves beyond the pre-existing `files`.

### Decision 4: Polls are structurally separated from assessment

A poll is anonymousish, ungraded, and instant; an assessment is identified, graded, and guarded (`AssessmentGradeGuard`, proctoring, item banks). The polls leaf is therefore declared only on `Session` and `Cohort`, never on `Assessment`, `Assignment`, `Item`, or `ItemBank`; widget titles carry "(not graded)"; and REQ-006 makes any future `polls` entry on an assessment-family schema a spec violation, not a judgment call.

### Decision 5: No new server-side logic

`CohortTalkMembershipHandler` exists because a Talk conversation has membership that must track Enrolments. None of the five new leaves has a participant model bound to Scholiq data: calendar events, contact cards, forms, deck cards, and polls are owned and access-controlled by their NC apps. So this change ships **zero** PHP. The moment someone wants "auto-create a VEVENT from `Session.startsAt`", that is a new change with an idempotency design.

## Risks / Trade-offs

- A user without the NC app (e.g. Deck not installed) sees no widget — provider `isEnabled()` handles it; the e2e test must therefore run with the leaf apps enabled or assert conditionally.
- User-curated links can go stale (a Session moves, its linked prep event doesn't). Accepted: same trade-off as the existing files leaf.

## Migration Plan

Pure addition. Register re-import on upgrade applies `linkedTypes`; manifest ships with the app build. No data migration, no rollback complexity beyond reverting the commit.

## Open Questions

Carried in proposal.md (AVG entry for the contacts link; derived-event follow-up; ConferenceSlot/TeacherAvailability).
