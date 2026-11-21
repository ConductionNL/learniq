# Contract: rename-to-learniq

> This is not a REST-endpoint contract — no new or modified HTTP endpoint is introduced. The
> "interface" being changed is a pair of **identifier strings** (the app id and the OpenRegister
> register slug) that other `apps-extra` projects read at runtime. The template's section
> headers are kept and repurposed below so the coordination is legible in the same shape as every
> other contract in this repo.

## Consumers

Swept every sibling app's `lib/`, `src/`, `appinfo/` trees via `git grep` on 2026-08-19, then
resolved every hit to "runtime literal" vs. "comment/prose/`@spec` reference." **Two runtime
consumers found:**

- `hermiq` (`hermiq/lib/Service/CourseRecommendationEngine.php`): reads two hardcoded string
  constants — `SCHOLIQ_APP_ID = 'scholiq'` and `SCHOLIQ_REGISTER = 'scholiq'` — to (a) gate the
  whole feature on `IAppManager::isInstalled('scholiq')` and (b) call
  `ObjectService::setRegister('scholiq')` when reading Enrolment / XapiStatement / LearningPlan
  signal objects for its course-recommendation ranking.
- `pipelinq` (`pipelinq/lib/Listener/ObjectsMergedSyncListener.php:68`): a runtime class constant —
  `private const DOWNSTREAM_SYSTEMS = ['shillinq', 'procest', 'scholiq', 'opencatalogi', 'decidesk']`
  — iterated inside `dispatchDownstream()` to fan out one OpenRegister `WebhookService::dispatchEvent()`
  call per target system whenever a Master Data Management merge/reverse-merge completes. Each
  dispatch carries `'targetSystem' => $system` (the literal string) in its payload.

**Everything else swept clean**, and it is worth saying so explicitly rather than only naming what
was found:

- `openregister`'s only hit is prose inside a `$fleetComment` string in
  `lib/Settings/credential-providers.json` ("scholiq's tenant RSA key — needs a SIGN operation...") —
  documentation of a broker limitation, no code path reads it.
- `openconnector` and `opencatalogi` mention `scholiq` only in comments and `@spec` PHPDoc references
  pointing at scholiq's own spec files.
- `portaliq` has nothing outside comments.

So the runtime surface this contract has to cover is **exactly hermiq + pipelinq** — not "at least
one," and not "every app that mentions the name."

## Identifiers (in place of "Endpoints")

| Identifier | Old value | New value | Consumer |
|---|---|---|---|
| App id (`IAppManager::isInstalled()` argument) | `scholiq` | `learniq` | `hermiq::CourseRecommendationEngine::SCHOLIQ_APP_ID` |
| OpenRegister register slug (`ObjectService::setRegister()` argument) | `scholiq` | `learniq` | `hermiq::CourseRecommendationEngine::SCHOLIQ_REGISTER` |
| Webhook `targetSystem` payload value | `scholiq` | `learniq` | `pipelinq::ObjectsMergedSyncListener::DOWNSTREAM_SYSTEMS` |

**Auth**: n/a — these are internal PHP constants, OpenRegister slugs, and webhook payload values, not
authenticated HTTP surfaces in their own right (the webhook delivery itself is OpenRegister's
`WebhookService`, whose auth is out of scope for this contract).

## Error Codes (behavior on the consumer side if not updated)

| Condition | Consumer behavior |
|---|---|
| `learniq` is installed but `hermiq` still checks `isInstalled('scholiq')` | `CourseRecommendationEngine` treats the app as absent and returns its already-engineered "unavailable" recommendation set (see `Gate 2 (2.4)` in that class) — **no crash, no 500, no exception**. Course recommendations in hermiq silently stop populating. |
| `hermiq` is updated to check `isInstalled('learniq')` but still calls `setRegister('scholiq')` before this change's register-slug migration has run on that install | The register lookup returns no rows (old slug no longer resolves objects post-migration) — same graceful "unavailable" degradation, not a crash, because every per-schema read in `CourseRecommendationEngine` already independently degrades to null/unavailable on any failure (`@param string $schema Scholiq schema slug` reads are wrapped and logged, not thrown). |
| `pipelinq` still carries `'scholiq'` in `DOWNSTREAM_SYSTEMS` after the rename | `dispatchDownstream()` calls `WebhookService::dispatchEvent()` with `targetSystem: 'scholiq'` inside a `try`/`catch (\Throwable)` that only logs on actual dispatch failure — but a **mismatched** target system is not a dispatch failure, it is a successful dispatch to a name nothing subscribes to any more. **This is the worst shape of the three**: no exception, no error log, no warning — the merged-object sync for the renamed app just stops arriving, and nothing in either app's logs says why. |

## Versioning

This is not a versioned API; it is a rename of literal identifiers. There is no dual-support window
at the protocol level — `hermiq`'s constants either say `learniq` or they say `scholiq`, and only one
of those resolves on a given install post-rename. Coordination is by **install-time sequencing**, not
by version negotiation: an install only has one of `scholiq` or `learniq` present at a time (this is a
rename, not a fork), so `hermiq`'s constants are correct for whichever name matches the install they
are running against.

## Breaking Change Policy

1. This change ships and merges to `development` in the `scholiq`/`learniq` repo first — it is the
   producer side.
2. A follow-up change in the `hermiq` repo (not created by this change — different `allowedEditRoots`)
   updates `SCHOLIQ_APP_ID` and `SCHOLIQ_REGISTER` to `learniq`, and SHOULD rename the constants
   themselves (`LEARNIQ_APP_ID`, `LEARNIQ_REGISTER`) for the same reason this change exists: a
   correctly-valued constant with a stale name is still a landmine for the next reader.
3. A follow-up change in the `pipelinq` repo (also out of `allowedEditRoots`) updates the `'scholiq'`
   entry in `DOWNSTREAM_SYSTEMS` to `'learniq'`. Because the failure mode here is **silent** rather
   than a logged "unavailable" state (see Error Codes above), this follow-up carries more urgency than
   the hermiq one even though the code change is smaller — a fail-quiet defect is invisible to
   monitoring until someone notices merged-object sync stopped reaching learniq, which could be weeks.
4. Because both degradations are non-crashing (hermiq: an explicit, already-tested "unavailable"
   state; pipelinq: a silently-skipped webhook fan-out target), neither follow-up needs to merge in
   the *same deploy window* as this change for this change to be safe to ship. **But both MUST be
   filed — as tracked issues in their respective repos, referencing this contract — before this
   change is archived**, not merely before it merges. Archiving without filing either follow-up would
   leave the cross-app breakage undiscoverable: nothing in either consumer repo's own backlog would
   ever surface it, because neither failure throws, logs at error level, or fails a CI gate on the
   consumer side. This is `tasks.md` Task 13's explicit acceptance criterion.
5. `tasks.md` Task 13 opens both follow-up issues (hermiq and pipelinq) referencing this contract, so
   the coordination is tracked even though the code changes live in different repos, and is a
   precondition of archiving this change, not a nice-to-have filed afterward.

## SLA

Not applicable — no request/response surface. The only "availability" concern is the one captured
above: hermiq's recommendation feature stays available in its degraded "unavailable" state (a defined,
already-tested state of that feature) rather than becoming unavailable in an undefined way (a crash);
pipelinq's merged-object sync to the renamed app simply stops being dispatched, silently, until its
`DOWNSTREAM_SYSTEMS` entry is updated.
