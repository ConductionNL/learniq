# timetabling

## MODIFIED Requirements

### Requirement: Cancellation or substitution notifies affected learners and parents

At the `cancel`, `substitute-teacher`, and `substitute-teacher-in-progress`
transitions, the system MUST materialise `affectedLearnerIds` (from the
Session's `Cohort.learnerIds`) and `affectedParentIds` (from each affected
learner's `LearnerProfile.parentIds`) onto the Session, then declare
`x-openregister-notifications` `transition`-triggered rules (`action: [cancel,
substitute-teacher, substitute-teacher-in-progress]`) with `recipients:
[{kind: field, field: affectedLearnerIds}, {kind: field, field:
affectedParentIds}]` and an inline `nl`/`en` subject. This change MUST
introduce no local quiet-hours or delivery-suppression logic; delivery timing
and per-user opt-out MUST be governed entirely by OpenRegister's existing
dispatcher and preference API, per `scholiq-notifications`'s existing
requirements.

<!-- Extends the action list from {cancel, substitute-teacher} to also include
     substitute-teacher-in-progress: SessionChangeNoticeHandler's own
     WATCHED_ACTIONS constant already reacts to all three (the in-progress
     counterpart of substitute-teacher, split into its own transition name per
     the Session schema's own transition docblock), and the declared
     notification must cover every action the handler materialises data for,
     or a substitution made mid-lesson would silently notify nobody even
     though affectedLearnerIds/affectedParentIds were correctly written. -->

#### Scenario: Cancelling a Session notifies every affected learner and parent

- **GIVEN** a Session belonging to a cohort with 28 learners, 19 of whom have a linked parent account
- **WHEN** the Session is cancelled with a reason
- **THEN** `affectedLearnerIds` contains all 28 learners and `affectedParentIds` contains the 19 linked
  parents
- **AND** each receives an `nc-notification` via the declared `transition` rule

#### Scenario: Assigning a substitute teacher mid-lesson also notifies

- **GIVEN** a Session that is already `in-progress`
- **WHEN** a substitute teacher is assigned via the `substitute-teacher-in-progress` transition
- **THEN** the same `rosterChanged` notification rule fires for the Session's affected learners and parents,
  exactly as it does for the `scheduled`-state `substitute-teacher` transition

#### Scenario: A learner who opted out of Session-change notifications receives nothing

<!-- @e2e exclude Preference-off delivery gate is OpenRegister dispatcher behaviour (per scholiq-notifications), not scholiq-local logic; no new DOM surface — reuses the existing settings panel already covered by scholiq-notifications' own scenario. -->

- **GIVEN** a learner who disabled Session-cancellation notifications via the existing per-user settings
  panel
- **WHEN** a Session in their cohort is cancelled
- **THEN** OpenRegister's dispatcher records a `preference-off` skip and that learner receives nothing,
  while other affected learners/parents are still notified
