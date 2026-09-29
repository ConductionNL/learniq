# Design: registry-component-fix

## Context

`src/registry.js` is the kind-tagged map passed as the `registry` prop to
`CnAppRoot` (ADR-036). `CnPageRenderer.resolveCustomComponent()` resolves a
`type: "custom"` manifest page's `component` string against this map only — there
is no fallback to the shared `@conduction/nextcloud-vue` library's own export
catalogue. 45 of learniq's own view components are registered; the eight shared
library components that 14 manifest pages name are not. This is a pure frontend
wiring gap: no PHP, no schema, no OpenRegister behaviour is involved, so the
ADR-031 declarative-vs-imperative decision section does not apply.

## Goals / Non-Goals

**Goals:**
- Register the eight missing components so the 14 pages that name them mount.
- Add a mechanical guard (a test) so a future manifest/registry drift is caught
  before merge, not by a user reporting a blank page.

**Non-Goals:**
- Making `CnPageRenderer` itself fall back to the library's export catalogue —
  that is a platform-level change to `@conduction/nextcloud-vue`, out of scope for
  an app-level fix, and would mask the same class of gap in every consuming app
  rather than surfacing it at build time.
- Completing the two routes the triage flags as staying partial regardless
  (`OsoDossierReviewView`'s integriq-delegated adapter; the QTI export page that
  does not exist yet).

## Decisions

### Decision 1: Register in `src/registry.js`, not by changing the manifest

The manifest's `component` strings already match the library's export names
verbatim (e.g. `"component": "CnDataMatrix"` at `src/manifest.d/learning.json:6377`
against the real export `CnDataMatrix`). The registry is the only side that omits
them. Editing 14 manifest occurrences to point somewhere else would be a much
larger, riskier diff for no behavioural gain — the fix belongs entirely in the
16-line registry file.

**Alternative considered:** teach `CnPageRenderer.resolveCustomComponent()` a third
fallback path onto a library-wide catalogue. Rejected for this change: it is a
cross-app platform change (touches every consumer of `@conduction/nextcloud-vue`,
not just learniq), it needs its own design and test surface in the library repo,
and it would silently paper over the exact defect class this fix's guard test is
meant to catch at build time in every app, not just this one.

### Decision 2: The guard test is a `node --test` file, not vitest

The task brief that seeded this change describes the guard as "a vitest." This
repo has no vitest binary and no vitest config anywhere in `package.json` or the
tree (`find . -maxdepth 2 -iname vitest.config*` returns nothing); its actual JS
unit-test runner is `node --test tests/unit-js/*.test.mjs` (see
`tests/unit-js/connectionRegistry.test.mjs`, which already does an equivalent
manifest-vs-implementation coverage check for the connection-registry page). Introducing
a new test runner and its config for one guard test would be a disproportionate
footprint change. The guard is written as
`tests/unit-js/registryComponentCoverage.test.mjs` in the existing convention,
runs under the existing `npm run test:js-unit` script, and is functionally
identical to what was asked for: it diffs every `type: "custom"` page's
`component` against the registry's keys and fails on a miss.

## Risks / Trade-offs

- [Risk] The guard test only checks that a name resolves to *some* registry entry
  — it does not verify the entry's `kind` is `"page"` (a `"widget"`-kind entry with
  the same name would also satisfy a naive key check and still fail to render as a
  page). → Mitigation: the test explicitly asserts `entry.kind === 'page'` for
  every custom-page component name, not just key presence.

## Migration Plan

None — no schema, database, or deployment migration. The change ships as a normal
frontend PR; `npm run build` proves the bundle compiles with the new imports.

## Open Questions

None.
