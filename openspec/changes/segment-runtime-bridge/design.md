# Design: segment-runtime-bridge

## Architecture Overview

```
LearniqSettings (OpenRegister singleton, segment enum)
        │  findAll, _rbac: false
        ▼
SegmentService::currentSegment()  ── newest valid row, else "corporate"
        │
PageController::index()  ── IInitialState::provideInitialState('segment', …), SegmentService resolved lazily
        │  (next to primaryRole, dashboardRole, dashboardRoles)
        ▼
src/main.js  ── loadState('learniq', 'segment', 'corporate')
        │  buildWorkspaceRuntime() from src/utils/workspaceRuntime.js
        ▼
manifest.runtime.workspace.segment  ── what visibleIf {"workspace.segment": …} reads
```

This copies the only runtime path that already works in the app. `runtime.user.primaryRole` is fed by `PageController` through `IInitialState` and read with `loadState` in `main.js`; `segment-feature-flags` design.md ("Discovery") traced that there is no generic settings-to-runtime bridge in `@conduction/nextcloud-vue`, so each path is wired by hand.

## Nextcloud Integration
- Controllers: `PageController::index()` gains one `provideInitialState('segment', …)` call inside the existing signed-in branch; `SegmentService` is resolved lazily through `ContainerInterface` (Decision 5).
- Services: new `OCA\Learniq\Service\SegmentService` (constructor: OpenRegister `ObjectService`, `LoggerInterface`).
- OCP: `OCP\AppFramework\Services\IInitialState` (server), `@nextcloud/initial-state` `loadState` (browser).
- Mappers/Entities: none. Events/Hooks: none.

## Decisions

### Decision 1: the newest valid row wins, not the first row
`SovereigntyPolicyService::currentPolicy()` takes `findAll(limit: 1)[0]`. That is not good enough here. The generated demo register ships three `LearniqSettings` rows (gate 101 requires three demo objects per schema), and OpenRegister does not enforce a singleton. With "first row wins" the answer depends on storage order, so loading the demo data could silently switch an instance's menus.

The service therefore reads up to 50 rows, drops rows whose value is not one of the six codes, and returns the row with the latest `@self.updated`. The last deliberate write wins: an admin editing the record on the App settings page, or the wizard step in `segment-wizard-choice`, always produces the newest row.

Alternatives considered:
- *Sort in the query* (`sort: {updated: DESC}`): depends on how the magic mapper exposes the `_updated` metadata column to `sort`, which this change could not verify against a live instance. Sorting 50 rows in PHP is cheap and testable.
- *Store the row uuid in IAppConfig and read that row*: a second source of truth that goes stale the moment an admin creates a new row on the settings page.
- *Pick by `setAt`*: `setAt` is written by the client, and the plain declarative detail page does not write it, so an edit there would lose to a stale demo row.

### Decision 2: the demo rows carry `corporate`
Even with Decision 1, a demo import done after an admin chose a segment produces newer rows. Making every demo row `corporate` turns the worst case into the no-behaviour-change default instead of a random school kind. The rows were `po`, `vo`, `mbo` only because the generator cycles the enum.

### Decision 3: the read skips RBAC, the answer is only the code
`LearniqSettings` has no `authorization` block and inherits the register cascade, which a learner does not satisfy. Every signed-in user's menu depends on the value, so the server reads it with `_rbac: false` (the same posture `AssessmentResultAudience` uses for its server-side lookups) and hands only the six-letter-or-shorter code to the browser. No other field leaves the server.

### Decision 4: a pure helper in the browser
`main.js` is not unit-testable (webpack `require.context`, a mount side effect). The logic that matters, "unknown or missing becomes corporate" and "keep other workspace keys", goes into `src/utils/workspaceRuntime.js` (`SEGMENTS`, `DEFAULT_SEGMENT`, `resolveSegment()`, `buildWorkspaceRuntime()`), tested with `node --test` like the other `tests/unit-js/` modules.

### Decision 5: PageController resolves SegmentService lazily
`PageController` serves the app's default route. Injecting `SegmentService` (which reads OpenRegister) into its constructor makes the start screen unconstructable on an instance without OpenRegister, so it 500s instead of explaining what is missing (ADR-083 rule 3; gate-66 `openregister-dependency-shape` flagged exactly this on the first gate run). The controller therefore takes `Psr\Container\ContainerInterface` and resolves `SegmentService` at call time inside a catch that degrades to `corporate`. The lookup names an app class, not an OpenRegister class, so ADR-083 rule 1 (no string lookups of OpenRegister types) is untouched.

### Decision 6: labels through `x-enum-labels`
`fieldsFromSchema()` and the index-page filter both read `x-enum-labels` and translate each label through the app catalogue (`node_modules/@conduction/nextcloud-vue/src/utils/schema.js`). No property in this register used it yet; `check-schema-l10n.js` already counts its values as schema strings, so the six labels need en and nl keys.

| code | English label | Dutch label |
|---|---|---|
| `po` | Primary school | Basisschool (po) |
| `vo` | Secondary school | Middelbare school (vo) |
| `mbo` | Vocational education (MBO) | Middelbaar beroepsonderwijs (mbo) |
| `he` | Higher education (HBO or university) | Hoger onderwijs (hbo of wo) |
| `corporate` | Company | Bedrijf |
| `training` | Training institute | Opleidingsinstituut |

## Declarative-vs-imperative decision (ADR-031)
No lifecycle, aggregation, calculation, relation, notification or widget behaviour is introduced. The PHP is framework glue that publishes a stored value to the page, the same category as the existing `primaryRole` provider in `PageController`. It cannot be declarative: `manifest.runtime` is filled by the app's own bootstrap, and OpenRegister has no declaration that pushes a field into another app's initial state.

## Security Considerations
- The read bypasses RBAC by design (Decision 3); the only value returned is one of six fixed codes, validated against a constant before it is provided.
- The initial state is provided only inside the existing `$user !== null` branch, so an anonymous deep link receives nothing.
- Who may write `LearniqSettings` is unchanged: no `authorization` block, so the register cascade (`instructors`, `hr`, `compliance-officers`, `team-leads`, plus admins) applies, exactly as for `SovereigntyPolicy`. Once `segment-menu-gating` lands, the value decides which menus render; menu visibility is not an access boundary (every page's data stays behind its own schema authorization), so this is recorded as a follow-up rather than widened into this change.

## File Structure
```
lib/
  Controller/PageController.php         (changed: segment initial state)
  Service/SegmentService.php            (new)
  Settings/learniq_register.json        (LearniqSettings: training, x-enum-labels, versions)
  Settings/learniq_mock_register.json   (three LearniqSettings rows -> corporate)
src/
  main.js                               (runtime.workspace)
  utils/workspaceRuntime.js             (new)
l10n/en.json, l10n/nl.json (+ regenerated .js)
tests/
  Unit/Service/SegmentServiceTest.php   (new)
  Unit/Controller/PageControllerTest.php (new case)
  Unit/Settings/SegmentFeatureFlagsRegisterTest.php (six values, labels)
  unit-js/workspaceRuntime.test.mjs     (new)
```

## Seed Data
No new schema. `LearniqSettings` keeps no curated `x-openregister-seed` (a singleton must not be seeded twice, per `segment-feature-flags` design.md). The generated demo register keeps its three rows for gate 101, each now `corporate`:

### Schema: `learniqsettings`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | learniqsettings-learniqsettings-1-1 | learniqsettings-learniqsettings-2-2 | learniqsettings-learniqsettings-3-3 |
| segment | corporate | corporate | corporate |
| setBy | Voorbeeld Setby 1 | Voorbeeld Setby 2 | Voorbeeld Setby 3 |
| setAt | 2026-03-01T09:00:00+00:00 | 2026-03-02T09:00:00+00:00 | 2026-03-03T09:00:00+00:00 |

Related items: none.

## Risks / Trade-offs
- [An admin picks `po` and nothing changes yet] → `segment-menu-gating` follows in this lane; until then the value is visible in `runtime.workspace.segment`.
- [Multitenancy] → the read keeps OpenRegister's default multitenancy filter, like `SovereigntyPolicyService`. On a multi-organisation instance each organisation sees its own row or the default. A single school instance, the normal case, has one.
- [More than 50 rows] → unrealistic for a singleton; the cap keeps the page request cheap. Rows beyond it are ignored.

## Migration Plan
No data migration. Deploy is the PR. Rollback: revert; set any `training` row to another value first, because the reverted enum would reject it.

## Open Questions
None.
