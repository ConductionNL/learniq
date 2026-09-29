# Design: segment-feature-flags

## Context
Row 14.6 asks for "feature flags per segment (hide corporate menus for a school)". Two halves are bundled in that one sentence: (1) an admin-settable segment value, and (2) menu items that read it. Before writing any manifest JSON, the actual `visibleIf` resolution path was traced end to end.

## Discovery: `visibleIf` has no generic settings-to-runtime bridge
`node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json`'s `visibleIfCondition` definition says plainly: each condition key is "a dot-separated path into `manifest.runtime`." `src/main.js` populates the only existing path, `runtime.user.primaryRole` (and the three `canXDashboard` booleans), by calling `loadState('learniq', 'primaryRole', 'learner')` — Nextcloud's `IInitialState` bridge, fed by a PHP controller (`PageController`, per `main.js`'s own comment). A `grep` across `@conduction/nextcloud-vue`'s composables/utils for any OTHER `runtime.*` producer found none generic: `runtime.theme` (a different, unrelated `useScopedTheme` feature) is the only other consumer, and it too is populated by the manifest author's own code, not by an AppHost/OpenRegister-generic mechanism. There is no "declare a schema, get its field auto-published to runtime" convention anywhere in this stack.

The consequence: adding `visibleIf: {"workspace.segment": {...}}` to a menu item today, with nothing populating `runtime.workspace`, does not "fail open" (show the item) — `main.js`'s own comment states the opposite: "absent runtime would (by lib fail-safe) hide every role-gated menu item." Shipping that half alone would hide the gated items for every tenant, including corporate tenants who are supposed to keep seeing them. That is worse than shipping nothing.

## Goals / Non-Goals
- **Goal**: give an administrator a real, working way to record their instance's segment.
- **Goal**: name the missing bridge precisely enough that a follow-up `code` change can close it without re-discovering this.
- **Non-goal**: menu-level segment gating in this change (see Discovery).
- **Non-goal**: deciding which menus are "corporate-flavoured" — a call for the follow-up change once it can be tested against real gating.

## Decisions

### Decision 1: `LearniqSettings` mirrors `SovereigntyPolicy`'s singleton shape exactly
Same `x-openregister` posture (active, not hard-deleted, not searchable — a singleton has nothing to search), no lifecycle, a nullable `setBy`/`setAt` pair for the same "who/when changed this policy" traceability `SovereigntyPolicy` already establishes. Reusing an in-repo precedent rather than inventing a new settings-record shape.

### Decision 2: default `segment` is `corporate`, not `po`
Every existing Learniq/Scholiq customer today runs the undifferentiated, corporate-flavoured build (the app's own baseline capture notes "Vocabulary is corporate/VO/HE"). Defaulting to `corporate` means creating this object changes nothing about what any existing customer sees, even after the follow-up `code` change adds real gating — a school administrator has to actively choose `po`/`vo`/`mbo`/`he` to narrow their own menu.

### Decision 3: a plain index+detail page, not a bespoke settings controller
`SovereigntyPolicy` itself is edited through a bespoke `AiProcessingDisclosureController` + custom Vue component — real code, built for a different (EU AI Act disclosure) reason. `LearniqSettings` has no such requirement, so it gets the plain, zero-code index+detail pattern this app already uses for `School`/`Staff`/every other reference-data schema, keeping this change genuinely `config`-only.

## Declarative-vs-imperative decision (ADR-031)
No lifecycle, aggregation, calculation, or notification behaviour. One flat schema and two manifest pages, JSON only.

## Seed Data (ADR-001)
No seed for `LearniqSettings` — a singleton settings record should not exist twice, and OpenRegister's seed mechanism does not enforce singleton uniqueness; leaving it unseeded means the first administrator to open the page creates the one real record, matching `SovereigntyPolicy`'s own precedent (also unseeded).

## Risks / Trade-offs
[Risk] A school admin sets `segment` to `po` and sees no menu change, since the gating half does not exist yet → Mitigation: the admin-settings page description text says so explicitly (see tasks.md); the follow-up `code` change is named in this change's proposal and specs so it is discoverable, not a silent dead end.

## Migration Plan
Declarative only. Revert the JSON diffs to roll back.

## Open Questions
None.
