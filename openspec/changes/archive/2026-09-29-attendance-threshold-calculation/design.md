# Design: attendance-threshold-calculation

## Context

Two independently-confirmed defects (triage entry 2) plus one newly-found bug
(this design doc) leave leerplicht 16-uur crossing detection completely dark:
no calculation exists for the field the notification trigger watches; no
transition exists that could ever produce the `ObjectTransitionedEvent`
`AttendanceFlagCreationHandler` listens for; and that handler calls a method,
`ObjectTransitionedEvent::getContext()`, that does not exist on the real class
(confirmed by reading its full public method list at
`openregister/lib/Event/ObjectTransitionedEvent.php`).

## Goals / Non-Goals

**Goals:**
- Satisfy the existing `attendance` spec requirement's calculation half
  completely (`unexcusedLesuren` + `isThresholdCrossed`, calculatedChange
  notification fires for real).
- Make `AttendanceFlagCreationHandler` capable of actually creating an
  `AttendanceFlag` today, via a guarded manual path.

**Non-Goals:**
- Automatic, continuous, per-individual-learner crossing detection (named
  platform gap — no `autoWhen`/per-learner-aggregate mechanism available yet).
- Holiday exclusion, DUO reporting changes.

## Declarative-vs-imperative decision (ADR-031)

**Calculation half: fully declarative.** `unexcusedLesuren`/
`isThresholdCrossed` are `x-openregister-calculations`/`x-openregister-
aggregations` entries — no PHP. This is the default, mandatory path per
ADR-031 for a derived/aggregated field, and per the `attendance` spec's own
explicit "NOT a PHP TimedJob" instruction.

**Flag-creation half: legitimate imperative exception, already accepted.**
`AttendanceFlagCreationHandler` (pre-existing, unchanged in kind by this fix)
is the same "lifecycle transition creates a linked object" ADR-031 exception
class as `ExemptionGrantHandler`/`CredentialRenewalListener`. This fix does
not introduce a new exception category — it corrects an existing one so it
can run at all. `AttendanceThresholdCrossingGuard` is a standard lifecycle
guard (same class as `OrderTotalValidationGuard`/`CohortMembershipGuard`).

## Decisions

### Decision 1: Per-cohort aggregate, not an attempted (impossible) per-learner one

Verified at HEAD: `x-openregister-aggregations`'s `where` clause resolves
`@self.<field>` against the aggregating object's own fields
(`Regulation.mandatoryEnrolledCount`'s `regulationSlug: "@self.slug"` is the
only demonstrated form in this register). `AttendanceThreshold` has no
`learnerId` — it is a shared rule definition (`scope: per-learner` describes
how it should be EVALUATED, not a field that exists on it). There is
therefore no declarative expression for "this one learner's unexcused
lesuren" as a materialised field on the Threshold object. A per-`cohortId`
aggregate is the closest real, honest declarative signal: it correctly
detects a cohort accumulating unexcused lesuren in aggregate, and is wired
through the exact same DSL vocabulary `Regulation` already uses successfully.

**Alternative considered and rejected:** inventing a per-learner instance
schema (one Threshold-state row per learner) so `@self.learnerId` could work.
Rejected as out of scope: this is a data-model change affecting every
consumer of `AttendanceThreshold`/`AttendanceFlag`, not a targeted defect fix,
and was not requested.

### Decision 2: The guarded manual transition carries per-learner context as `inputs`-merged transient properties, not a nonexistent event context

Verified at HEAD: `TransitionEngine::transition(string $objectId, string
$action, array $data = [])`'s `$data` is validated against the transition's
declared `inputs` allowlist and merged directly onto the object's own fields
BEFORE the object is saved and `ObjectTransitionedEvent` is dispatched
(`applyTransition()`: "Validate the payload against the transition's inputs
allowlist and merge the accepted values... Mutate the lifecycle field.");
`ObjectTransitionedEvent` carries no separate context payload at all. So the
only way a caller's per-learner data reaches a listener is by declaring it as
real (transient, overwritten-on-next-check) properties on
`AttendanceThreshold` itself, merged in via `inputs`, then read back via
`$event->getObject()->jsonSerialize()`.

**Alternative considered and rejected:** waiting for `getContext()` (or
equivalent) to be added to OpenRegister. Rejected: this fix ships a working
path today using the mechanism that actually exists, and would not need to
change if such a method is added later (Decision is data-source-additive, not
a workaround that later becomes wrong).

### Decision 3: Rename `THRESHOLD_CROSSED_TO` to `CHECK_THRESHOLD_ACTION`, matching an action name, not a state name

The handler's own docblock originally claimed `getTo() === 'threshold-crossed'`
would be the marker; since `check-threshold` is a genuine self-loop
(`active` → `active`, matching `Session.substitute-teacher`'s established
self-loop precedent in this register), `getTo()` is always `active` and
cannot serve as the marker — `getAction() === 'check-threshold'` is the
correct discriminator, exactly the one-line rename the triage itself
anticipated.

## Risks / Trade-offs

- [Risk] A future OpenRegister release could ship a real per-learner
  aggregation primitive that would make the guarded-manual path redundant →
  Mitigation: not a regression, just an opportunity for a follow-up
  simplification; both paths coexist harmlessly today.

## Migration Plan

None — register additions (calculations, transition, transient properties)
plus a new PHP guard class and a two-line correction to an existing,
never-successfully-run listener. No database migration.

## Open Questions

None.
