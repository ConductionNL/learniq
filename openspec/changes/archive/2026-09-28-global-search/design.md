# Design: global-search

## Context

`usability.md`'s "global search scope" criterion (max 3) scored learniq a 0: no in-app search across
cohorts/learners exists anywhere, only Nextcloud's own header search (files/apps/messages, not app data).
gibbon's Fast Finder answers the same need in 2 clicks from anywhere in the app. `placement.md` scopes the
learniq-side fix to a single widget on the People dashboard rather than a platform-wide unified-search
provider, calling it "cheap (rung 4, one widget)".

## Goals / Non-Goals

**Goals:** a search box on `PeopleDashboard` that finds a learner, staff member, or cohort by name and
navigates to its detail page in one click; a query-builder pure enough to unit-test without a browser.

**Non-Goals:** a Nextcloud unified-search provider (header-level, every page); searching schemas beyond
`learner-profile`/`cohort`; a new top-level menu entry (`change-plan.md` says so explicitly).

## Decisions

### Decision 1: Two OpenRegister queries + client-side role classification, not a `roles`-filtered third query

**Alternatives considered:** issuing three backend queries (Learners, Staff, Cohorts) by adding a `roles`
filter param to the `learner-profile` request twice. Rejected: OpenRegister's generic object-list filter
semantics for "array field contains one of N values" are not documented anywhere this change's research
touched, and guessing wrong would silently drop real matches (e.g. a mentor with roles
`['mentor','learner']` might not match either filter depending on AND/OR semantics). Two queries + a pure,
fully-tested `classifyPersonKind()` function sidesteps the whole question and is exactly as testable.

### Decision 2: `NcListItem` with a `to` route, not a custom keyboard-nav combobox

**Alternatives considered:** an ARIA combobox/listbox pattern with roving `tabindex` and `ArrowUp`/
`ArrowDown` handling (the "traditional" search-suggestions UX). Rejected for this pass: `NcListItem`'s `to`
prop already renders a real `<router-link>`, which is natively `Tab`-focusable and `Enter`-activatable — the
brief's "keyboard reachable" bar — without hand-rolled ARIA code that is easy to get subtly wrong (focus
trap, missing `aria-activedescendant`, screen-reader announcement of group changes). A richer
type-ahead-with-arrow-keys experience is a legitimate future enhancement, not required by this change's
scope.

### Decision 3: Hand-rolled `setTimeout` debounce, not a new npm dependency

A 300ms `setTimeout`/`clearTimeout` pair is the entire debounce; adding `lodash.debounce` (or similar) for
one call site is not worth a new dependency per this proposal's "New Dependencies: none" commitment.

## Declarative-vs-imperative decision (ADR-031)

Not applicable — this change introduces no lifecycle, aggregation, calculation, notification, relation, or
dashboard-widget-as-OpenRegister-schema-behaviour. `type: 'custom'` on the `CnDashboardPage` widgets array is
the same mechanism `PeopleDashboard.vue` already uses for its four `manage-*` widgets (each backed by a Vue
component in a named slot); this change adds a fifth of the same kind, not a new mechanism.

## Seed Data

Not applicable — this change adds no OpenRegister schema and no seed data; it queries `LearnerProfile`/
`Cohort` rows that already exist from whatever seed the environment carries.

## Risks / Trade-offs

- [Risk] `_search` behaviour unverified against a live instance (see proposal Risk 1). → Mitigation: isolated
  to `fetchOne()`; the tested contract (`buildGlobalSearchRequests`) does not change if the param name or
  shape needs adjusting later.
- [Risk] Two parallel requests per keystroke (debounced) could be chatty on a slow connection. → Mitigation:
  300ms debounce + a monotonic `requestToken` guard so only the latest keystroke's response is ever rendered
  (an in-flight stale response is discarded, not raced onto the screen).

## Migration Plan

Not applicable — no schema or data change; `migration.md` is skipped per its own `skipWhen` condition.

## Open Questions

- Live browser verification of `_search` behaviour — deferred, see proposal.
