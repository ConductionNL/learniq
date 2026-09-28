# Timetabling and Substitution Specification

## ADDED Requirements

### Requirement: Sessions has today- and week-scoped index views
The system MUST provide `SessionsToday` and `SessionsThisWeek` as manifest-declared `type: index` pages on the `Session` schema, each pre-scoped via `config.filter.startsAt` using the shared `@today`/`@today+Nd` filter-token grammar (`resolveFilterMap` → `resolveFilterTokens`, the same mechanism `LessonIndex`/`Submissions`/etc. already use with `@route.*` tokens).

#### Scenario: A teacher opens today's sessions
- **GIVEN** `Session` rows exist across multiple days
- **WHEN** `SessionsToday` is opened
- **THEN** only sessions whose `startsAt` falls within the current day are listed

#### Scenario: A teacher opens this week's sessions
- **GIVEN** `Session` rows exist across multiple weeks
- **WHEN** `SessionsThisWeek` is opened
- **THEN** only sessions whose `startsAt` falls within the next 7 days from today are listed

### Requirement: A per-teacher filter on TimetableConflictQueue is out of scope for a config change
`TimetableConflictQueue` is a registered custom Vue component (`src/views/TimetableConflictQueue.vue`), not a manifest-declarative page. A `config`-kind change MUST NOT modify it; adding a per-teacher filter control there requires a `code`-kind change per ADR-032 and is explicitly deferred.

#### Scenario: TimetableConflictQueue is unmodified by this change
- **GIVEN** this change's diff
- **WHEN** `src/views/TimetableConflictQueue.vue` is inspected
- **THEN** it is unchanged; the per-teacher filter is tracked as a follow-up `code` change
