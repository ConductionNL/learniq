---
kind: code
---

# Proposal: attendance-threshold-calculation

## Summary

The leerplicht 16-uur crossing can never fire, for two independently
confirmed reasons: (1) `AttendanceThreshold.x-openregister-notifications.
thresholdCrossed` declares a `calculatedChange` trigger watching a field,
`unexcusedLesuren`, that the schema never actually declares a calculation
for — so the trigger is inert by construction; (2)
`AttendanceFlagCreationHandler` listens for an `ObjectTransitionedEvent` with
`getTo() === 'threshold-crossed'`, but `AttendanceThreshold`'s
`x-openregister-lifecycle.transitions` declares no transition named or
targeting `threshold-crossed` — and OpenRegister's `TransitionEngine` only
ever dispatches `ObjectTransitionedEvent` from a genuinely-executed named
transition, never from a bare `calculatedChange` evaluation. A third,
previously unconfirmed defect was found while implementing this fix:
`AttendanceFlagCreationHandler` calls `$event->getContext()` — a method that
does not exist anywhere on the real `ObjectTransitionedEvent` class at this
OpenRegister version (verified: its full public method list is
`isAutomatic`/`getObject`/`getAction`/`getFrom`/`getTo`/`getUserId`/
`getRegister`/`getSchema` — no `getContext`). Even if the transition-naming
gap were fixed alone, this handler would still throw
(`Error: Call to undefined method`) the moment it ran, silently swallowed by
`TransitionEngine::dispatchTransitioned()`'s catch-and-log boundary, and no
`AttendanceFlag` would ever be created.

This change declares the missing calculation (closing the existing
`attendance` capability spec's "Threshold crossing is a declared calculation
trigger" requirement, satisfied for the first time), and separately ships a
guarded manual `check-threshold` transition plus a corrected
`AttendanceFlagCreationHandler` so a real crossing can actually produce an
`AttendanceFlag` today — the automatic, continuously-live, per-INDIVIDUAL-
learner crossing detection the leerplicht rule ultimately needs remains a
named platform gap (see Scope and design.md).

## Motivation

Learniq round-1 defect triage (`learniq-defect-triage.md`, entry 2, "Leerplicht
16-uur crossing can never fire — and the mechanism it waits for does not exist
in OpenRegister at all") is the single most-researched defect in the corpus
and the only one rated L effort with an explicit cross-repo platform
dependency. `change-plan.md`'s "Foundational / defects" table lists it as the
highest-effort fix, ranked behind the four S/M fixes precisely because a
"smallest fix that is actually sufficient" needs a genuine aggregate
calculation plus a resolution to the transition-dispatch gap. This change
delivers the calculation half completely (satisfying the pre-existing
`attendance` spec requirement outright, no PHP change needed for that half)
and the flag-creation half as far as is honestly achievable without a
cross-repo OpenRegister change, naming precisely where the remaining gap is.

## Affected Projects

- [x] Project: scholiq (app id `learniq`) — schema additions, one new guard,
  one corrected listener, and their tests.

## Capabilities

- Modified: `attendance` (fully satisfies "Threshold crossing is a declared
  calculation trigger"; adds the guarded manual crossing-check path)

## Scope

### In Scope

- `AttendanceThreshold.x-openregister-calculations`:
  - `unexcusedLesuren`: a materialised, `x-openregister-aggregations`-backed
    **per-cohort** aggregate — count of `attendance-record` rows where
    `cohortId == @self.cohortId` and `status == 'absent-unexcused'`, scaled by
    `lessonHourMinutes`. This is the same declarative machinery
    `Regulation.coveragePercent`/`ragStatus` already use in this register
    (`x-openregister-aggregations` + an arithmetic `x-openregister-
    calculations` expression) — satisfying "reuse the same threshold
    machinery as compliance-Regulation coverage thresholds (no parallel
    mechanism — ADR-022)" literally.
  - `isThresholdCrossed`: `unexcusedLesuren >= limit`, materialised.
  - With this in place, the *already-declared* `thresholdCrossed` notification
    (trigger: `calculatedChange` on `unexcusedLesuren`, `recipients: groups:
    mentor`) starts firing for real — closing the `attendance` capability's
    "Threshold crossing is a declared calculation trigger" requirement
    completely, with no PHP change.
- A guarded manual `check-threshold` transition (self-loop, `active` →
  `active`), `requires: AttendanceThresholdCrossingGuard`, accepting
  `checkedLearnerId`/`checkedMetricValue`/`checkedWindowStart`/
  `checkedWindowEnd`/`checkedBreachingRecordIds` as `inputs` — the caller
  (today: a manual admin/mentor action; a future scheduled per-learner
  aggregation job is the natural long-term caller, not built here) supplies
  the per-learner crossing detail the per-cohort aggregate above cannot
  express.
- `AttendanceThresholdCrossingGuard`: refuses the transition unless
  `checkedMetricValue >= limit` and `checkedLearnerId` is set — "guarded", per
  the brief.
- Corrects `AttendanceFlagCreationHandler`: replaces the
  `getTo() === 'threshold-crossed'` check (a transition state that will never
  exist) with `getAction() === 'check-threshold'`; replaces the
  nonexistent `$event->getContext()` call with reads from
  `$event->getObject()->jsonSerialize()`'s `checkedLearnerId`/
  `checkedMetricValue`/`checkedWindowStart`/`checkedWindowEnd`/
  `checkedBreachingRecordIds`. Renames `THRESHOLD_CROSSED_TO` to
  `CHECK_THRESHOLD_ACTION`.
- Tests proving: the per-cohort calculation materialises correctly; the guard
  blocks a non-crossing check and allows a real one; a `check-threshold`
  transition that passes the guard results in `AttendanceFlagCreationHandler`
  creating an `AttendanceFlag`.

### Out of Scope — named platform gaps, not silently assumed solved

- **True per-individual-learner, continuously-live crossing detection.**
  OpenRegister's `x-openregister-aggregations` `where` clause resolves
  `@self.<field>` against the aggregating object's OWN fields only;
  `AttendanceThreshold` (a shared rule definition, not a per-learner
  instance) has no `learnerId` field, so there is no declarative way to
  express "this specific learner's unexcused lesuren" as a materialised
  field on the Threshold object today. The per-cohort aggregate above is the
  most complete declarative signal achievable without a data-model change
  (e.g. a new per-(threshold, learner) state schema) that is out of scope for
  this fix.
- **Automatic (non-manual) transition dispatch from a `calculatedChange`
  condition.** Confirmed at HEAD: `TransitionEngine` dispatches
  `ObjectTransitionedEvent` only from a named transition someone actually
  invokes (`transition($objectId, $action, $data)`); a `calculatedChange`
  condition crossing drives OpenRegister's notification dispatcher only,
  never a synthetic transition. The `lifecycle-auto-transitions` OpenRegister
  change (open, `openregister/openspec/changes/lifecycle-auto-transitions`,
  18/18 tasks checked, `autoWhen` verified present in this checkout's
  `AutoTransitionPass`/`LifecycleAnnotationValidator`) may close part of this
  gap in a future OpenRegister release — whether the specific release
  `appinfo/info.xml` requires already carries it was not re-verified here
  (same open question the triage itself named). Adopting `autoWhen` once
  available is a follow-up, not part of this fix.
- Holiday exclusion in the rolling 4-week window (M1 row `4.7`'s second cited
  gap) — untouched by this fix, stays partial regardless.
- The DUO verzuimloket melding pipeline (`DataExchangePayloadBuilder`) that
  reads from `AttendanceFlag` — unaffected; this fix makes flags creatable,
  it does not change how they are subsequently reported.

## Approach

Two independent, additive tracks in one register/PHP change: (1) a pure
declarative calculation closing the existing spec requirement outright; (2) a
guarded manual transition plus a two-line correction to already-dead code, so
the flag-creation path this app's own PHP already anticipated can actually
run. Both tracks reuse established precedents in this register
(`Regulation`'s aggregate-calculation shape; `OrderTotalValidationGuard`'s
guard shape; `ExemptionGrantHandler`'s create-and-link shape).

## New Dependencies

None.

## Impact

`AttendanceThreshold` schema (calculations, transitions, new transient
input properties); `lib/Lifecycle/AttendanceThresholdCrossingGuard.php` (new);
`lib/Lifecycle/AttendanceFlagCreationHandler.php` (corrected, two lines);
`tests/Unit/` gains new test files for both.

## Cross-Project Dependencies

Yes, named explicitly (unlike every other change in this lane): full,
continuous, per-learner automatic crossing detection depends on OpenRegister
shipping and this app adopting the `lifecycle-auto-transitions` change's
`autoWhen` mechanism (or an equivalent). This fix does not block on that
dependency — it ships the calculation (spec-complete on its own) and a
guarded manual path that works today without it.

## Risks

### Risk 1: The per-cohort aggregate does not detect a true per-learner leerplicht crossing

**Severity:** Medium — **Mitigation:** named explicitly in Out of Scope; the
notification still fires correctly for the case the aggregate CAN express
(cohort-wide unexcused-lesuren accumulation), which is a real improvement
over today's complete silence; the guarded manual transition is the
documented bridge for the genuinely per-learner case until the platform gap
closes.

### Risk 2: `AttendanceFlagCreationHandler`'s fix could be wrong if `getContext()` is added to `ObjectTransitionedEvent` in a future OpenRegister release, silently un-fixing this code

**Severity:** Low — **Mitigation:** the fix does not merely stop calling a
missing method; it reads the needed values from `$event->getObject()`, which
is a stable, always-present accessor — a future `getContext()` addition would
be an alternative data source this code simply never adopts, not a
regression.

## Rollback Strategy

Revert the register additions (calculations, transition, transient
properties) and the two touched PHP files (`AttendanceThresholdCrossingGuard.
php` new file removed; `AttendanceFlagCreationHandler.php` reverted to its
prior — already dead — state). No data migration to unwind.

## Open Questions

None — the platform dependency is named as a risk/cross-project-dependency,
not left open.
