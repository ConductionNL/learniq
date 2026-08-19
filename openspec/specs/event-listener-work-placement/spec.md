# event-listener-work-placement Specification

## Purpose
Places the work Scholiq's OpenRegister object-lifecycle listeners do according to what the event can still influence (ADR-078). Pre-`*ing` events may veto or mutate and stay synchronous; post-`*ed` events cannot change the write they observe, so their work is queued onto an actor-forwarded background job instead of being charged to the learner's request. Covers the shared job, the handler allow-list, the acting-user contract, the reconcile-against-current-state rule, the process-wide guard that stops a listener's own write from re-entering it and re-queuing itself for ever, and the single closed-category exception this app claims.

@e2e exclude backend work placement — a listener queueing instead of writing has no browser surface. The observable behaviour (queued entry, job effect, no request-path write, no re-queue loop, stale entry ignored) is asserted by PHPUnit in tests/Unit/Listener and tests/Integration/Lifecycle/XapiCompletionHandlerIntegrationTest.php.

## Requirements

### Requirement: Deferred post-event work runs in one actor-forwarded job

Every Scholiq listener registered on `ObjectCreatedEvent`, `ObjectUpdatedEvent`
or `ObjectDeletedEvent` that performs outbound I/O, a write, or an unbounded
query MUST implement `OCA\Scholiq\Listener\DeferredObjectWork` and MUST NOT do
that work inside `handle()`, unless it carries the closed-category exception
described below.

`handle()` MAY only: reject events of the wrong type, resolve the entity's
register/schema through `ListenerSchemaResolver`, evaluate short-circuits that
are answerable from the event payload alone (the completion-verb check on an
xAPI statement is the worked example), test the re-entrancy guard, and call
`ListenerDeferralService::defer()` with `DeferredObjectListenerJob::class` and
an entry carrying `handler` (the listener's `HANDLER_KEY`) and `uuid`. It MUST
NOT carry the object body as the source of truth.

`DeferredObjectListenerJob::runDeferred(DeferredListenerContext $context): void`
MUST, for each entry:

- resolve `handler` against a **hardcoded allow-list** of the app's own
  listeners and log-and-skip an unknown key. A class name taken from a persisted
  job row and passed to the container would be an instantiate-anything
  primitive;
- log-and-skip when the resolved service does not implement
  `DeferredObjectWork`;
- claim `DeferredWorkGuard::key(handler, uuid)` and skip the entry entirely when
  the claim fails;
- call `runDeferredWork($entry)` and release the claim in a `finally`;
- catch `Throwable` per entry, log it, and continue — the same blast radius the
  inline listeners had, and never a rethrow into cron.

The job extends `OCA\OpenRegister\BackgroundJob\ActorForwardedJob`, so the user
who performed the write is re-established for the duration of the work
(ADR-078 Rule 6) and the job is a one-shot `QueuedJob` that is removed from the
job list once it has run.

ONE JOB CLASS SERVES ALL CONVERTED LISTENERS. The deferral service buffers per
job class, and three of these listeners react to the same XapiStatement write,
so a single class coalesces one request's listener work into one job row.

#### Scenario: a lesson completion queues instead of writing

- **GIVEN** a LessonCompletion is created for a learner with an active Enrolment
- **WHEN** `EnrolmentProgressRollupHandler::handle()` runs
- **THEN** exactly one entry is deferred to `DeferredObjectListenerJob`, carrying that handler key and the object's uuid
- **AND** no Enrolment is written during the request

#### Scenario: the queued entry does the work

- **WHEN** the job runs that entry
- **THEN** the Enrolment's `progressPercent` is recomputed and saved

#### Scenario: an event on an uninterested schema costs nothing

- **GIVEN** an `ObjectCreatedEvent` for a schema the listener does not subscribe to
- **WHEN** `handle()` runs
- **THEN** nothing is written AND no entry is queued

### Requirement: Deferred work reconciles against current state

`runDeferredWork()` MUST re-read the object it acts on rather than trusting the
payload captured at dispatch time. Delivery is at-least-once and ordering
against the write is not guaranteed (ADR-078 Rule 7).

An object that no longer resolves MUST be treated as a stale no-op — logged at
most, never an error. Every condition that decided to queue the work (the
completion verb, an already-resolved `competencyId`, an active Enrolment for the
learner and course) MUST be re-evaluated against the re-read state before
acting.

#### Scenario: a deleted object is a stale no-op

- **GIVEN** a queued entry whose LessonCompletion has been deleted before the job runs
- **WHEN** the job runs
- **THEN** nothing is written and the job completes normally

#### Scenario: an entry naming an unknown handler is dropped, not resolved

- **GIVEN** a job entry whose `handler` is not in the allow-list
- **WHEN** the job runs
- **THEN** a warning is logged and no service is resolved from the container

### Requirement: A listener's own write must not re-queue it

`DeferredWorkGuard` holds a process-wide claim on `<handler>|<uuid>` for the
duration of the deferred work. Every converted listener MUST test
`DeferredWorkGuard::isRunning()` for its own `(HANDLER_KEY, uuid)` pair before
calling `defer()`, and MUST return without deferring when the claim is held.

This is load-bearing, not defensive. `ObjectService::saveObject()` causes
OpenRegister's mapper to dispatch an `Object*edEvent` for the write, which
re-enters the same listener with an object that still satisfies its entry
conditions. Inline, that recursed on one request's stack. Without the guard, the
deferred form enqueues a fresh job on every turn, and since `cron.php` runs one
job per web call, that job starves every other job on the instance.

The claim is keyed on the OBJECT as well as the handler, so a listener whose
work legitimately creates a DIFFERENT object of the same schema — the
streak-milestone bonus PointAward — still rolls that new object up. Terminating
THAT chain remains the job of the listener's own `sourceKind` check.

`leave()` MUST be called from a `finally`. The claim is deliberately static:
Nextcloud resolves a listener from the container per dispatch, so a re-entrant
dispatch is not guaranteed to reach the same instance, and the process context
is torn down per request and per cron job.

#### Scenario: the deferred write re-enters the listener exactly once and stops

- **GIVEN** an object service that dispatches the listener's event for every write, as the mapper does
- **WHEN** the job runs the deferred recompute
- **THEN** the recompute's write happens exactly once
- **AND** no further entry is queued, and no second drain pass is needed

### Requirement: A post-event listener may stay inline only with a closed-category reason

A post-event listener MAY keep its work inside `handle()` only when it carries
`@listener-placement inline <category> — <reason>` on that handler, where
`<category>` is one of ADR-078's four closed categories (`realtime`,
`sapi-memory`, `cheap-bounded`, `correctness`). A bare annotation, an unknown
category, or a category with no reason is a failure, not an exemption.

Scholiq claims exactly one: `AssessmentDrawResolver`, under `correctness`. The
caller reads back the value this listener writes within the same interaction —
`TakeAssessmentView` POSTs the AssessmentResult, immediately GETs it by id, and
renders the exam from `drawnItemRefs` — with no poll and no retry. Deferring it
would land the draw on the next cron turn and every attempt would open with zero
items. Falling back to `Assessment.itemRefs` client-side is not an alternative:
it reinstates exactly the client-supplied draw the listener exists to prevent.

#### Scenario: the draw is resolved before the attempt is read back

- **GIVEN** an AssessmentResult is created for a published Assessment
- **WHEN** the create request returns
- **THEN** `drawnItemRefs` is already resolved and a subsequent GET renders a non-empty item set
