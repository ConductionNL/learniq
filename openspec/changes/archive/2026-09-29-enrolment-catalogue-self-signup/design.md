# Design: enrolment-catalogue-self-signup

## Context

Enrolment is a staff act today. `Enrolment.authorization.create` is `instructors`, `hr`, `compliance-officers`, `team-leads` (`lib/Settings/learniq_register.json:2624` onwards), and the `source: self` value has no writer. Course and Programme are readable by every signed-in user and have a publish lifecycle. `EnrolmentPrerequisiteListener` vetoes an enrolment create with unmet prerequisites on `ObjectCreatingEvent`, whoever creates it (`openspec/specs/enrolment/spec.md:41-55`), so a sign-up written by a service is checked the same way.

Integriq's `connectors-course-marketplace` (design D2) writes a provider course as `Course` (`code` prefixed with the provider, `author` the provider, `license` `all-rights-reserved`, `lifecycle` `draft`), one `Lesson` of type `lti` and one `LtiToolPlacement`. This change reads those rows as they are; it asks integriq for no new field.

## Data model

- `Course` (0.4.1 to 0.5.0) and `Programme` (0.2.0 to 0.3.0): `selfEnrolment` enum `closed`, `open`, `on-request`, default `closed`. Every stored course reads as `closed`, so nothing appears in the catalogue until a school opens it.
- `Enrolment` (0.2.1 to 0.3.0): `requestedAt` (date-time, nullable), `declineReason` (string, nullable), `programmeId` (uuid, nullable, `$ref: Programme`): set when the enrolment came from signing up for a programme.
- `Enrolment.x-openregister-notifications`: the `activated` notification gets a condition `source != self`; a new `selfActivated` with the text "You are signed up for {course}" and a `requested` notification to the manager or the course's teachers for `on-request`.

## Endpoints

All `#[NoAdminRequired]` with their checks in the body (gate 7), writing with `_rbac: false` only after the checks, like the other learner-write services:

- `POST /api/catalogue/courses/{id}/sign-up`: the course is `published` and `selfEnrolment` is not `closed`; the caller has no enrolment for it that is `pending` or `active`. Creates `Enrolment {learnerId: caller, courseId, source: self, requestedAt: now}`; for `open` it then fires `activate`. A prerequisite veto is returned with the listener's message.
- `POST /api/catalogue/programmes/{id}/sign-up`: the same for the programme, then one enrolment per course in `Programme.courseIds` that the caller is not already on, with `programmeId` set. Courses whose prerequisites are other courses of the same programme are created `pending` and activated in order by the existing completion flow; the service does not bypass the listener.
- `POST /api/enrolments/{id}/withdraw`: the caller is the enrolment's `learnerId`, `source` is `self`, lifecycle is `pending` or `active` and `progressPercent` is 0. Fires `withdraw`.
- `GET /api/catalogue`: published, open or on-request courses and programmes with paging, search on name, description and tags, filters on `level`, `language`, `subject`, `author`.

## Screens

- `/catalogue` (`CourseCatalogue.vue`, custom page, menu entry under My learning for every signed-in user): cards, search, filters, and a detail panel with the action that fits (Sign up, Request a place, Withdraw, or "You are signed up"). The prerequisite message shows inline.
- `CourseDetail` and `ProgrammeDetail`: the `selfEnrolment` field in the form.
- `SignUpRequests` (index page on `enrolment` filtered `source: self`, `lifecycle: pending`) for staff and for managers through the existing `managerId` read rule, with the `activate` and `withdraw` actions.
- `Courses` index (`src/manifest.d/learning.json:529`): a saved filter "Imported, not yet published" (`lifecycle: draft` and `license: all-rights-reserved` with a lesson of type `lti`), so the marketplace import is reviewed where courses are already managed.

## Declarative versus imperative

| behaviour | path | reason |
|---|---|---|
| `selfEnrolment` fields, notifications | declarative | register properties and notification dialect |
| requests view | declarative index page | the manifest covers it |
| sign-up and withdraw on the learner's own behalf | imperative, `CatalogueSignUpService` | ADR-031 exception: a write the caller's own object rights do not allow, which must stay limited to the caller |

## Seed data

Training example set: "Excel voor gevorderden" (`selfEnrolment: open`), "Leidinggeven aan hybride teams" (`on-request`), the programme "Basis projectmanagement" (three courses, `open`), and two provider courses as integriq writes them (`author: "Go1"`, `draft`), one of them published. Three `Enrolment` rows with `source: self`: one active, one pending request, one withdrawn.
