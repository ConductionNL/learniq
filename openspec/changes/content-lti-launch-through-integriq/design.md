# Design: content-lti-launch-through-integriq

## Context

The launch path learniq posts to does not exist (`lib/Controller/LtiToolPlacementController.php:98-153`), and the answer it expects, a finished `id_token`, is the shape of a launch that skips the LTI login step. Integriq's `connectors-lti-platform-launch` builds the platform side correctly and reaches learniq's world through a typed event (ADR-041), `OCA\Integriq\Event\LtiLaunchRequestedEvent`, whose result slot is a login initiation form (integriq design D1, D2). Learniq keeps its placement model (`LtiToolPlacement`, archived change 2026-07-13-lti-tool-placement) and its grade pull (`LtiAgsScorePollJob`).

## Controller

`LtiToolPlacementController::launch(string $placementId)`, route unchanged (`appinfo/routes.php:121`), `#[NoAdminRequired]`:

1. Signed-in check and placement resolution stay as they are (:207-225).
2. `class_exists('OCA\Integriq\Event\LtiLaunchRequestedEvent')` false: answer 503 `{error: "LTI tools need integriq, which is not installed"}`.
3. Build the event with `sourceApp: learniq`, `placementId`, `deploymentUuid: placement.openconnectorDeploymentId`, `userId`, `messageType` (`LtiDeepLinkingRequest` for `launchMode: deep-linking`, else `LtiResourceLinkRequest`), `role` (`Instructor` when the user is in `instructors`, `team-leads` or `compliance-officers`, else `Learner`), `contextId` and `contextTitle` from the placement's course, `returnUrl` the lesson's URL. `IEventDispatcher::dispatchTyped()`.
4. Not handled: 503 with "No LTI launch handler answered". Refused: 409 with the refusal's reason. Handled: 200 `{formActionUrl, method, fields, launchMode}`.
5. `callOpenConnectorLaunch()`, `OPENCONNECTOR_LAUNCH_PATH`, `OPENCONNECTOR_TOKEN_KEY` and the long docblock about the missing route are removed.

## Lesson player

`launchLti()` (`src/views/LessonPlayer.vue:1134`) and `launchLtiForBlock()` (:891) accept a response with `formActionUrl` and a `fields` object; the check at :929 and :1171 changes to that shape. `submitLtiLaunchForm()` (:1360) creates one hidden input per entry of `fields` and uses `method` (default `POST`), keeping the tab or frame choice by `launchMode`. Learniq still reads no claim.

## Grades

`resolvePlacementForMessage()` (`lib/BackgroundJob/LtiAgsScorePollJob.php:305`) first reads `data.lineItemId`; when it names an `LtiToolPlacement` whose `openconnectorDeploymentId` equals `data.deploymentUuid`, that placement is used. Otherwise the current deployment lookup runs. The equality check stops a message from one deployment landing on another deployment's placement.

The subscription: the connection registry admin page (`adopt-connection-registry`) gets, on the `lti` row, a field for the event subscription id and a button "Create subscription" that raises integriq's subscription event if integriq offers one, else explains where to create it in integriq. The value is still stored as `lti_ags_subscription_id`.

## Connection registry

`lib/Settings/connections.json` `lti`: `available` is computed at read time: true when the launch event class exists, false with the message "LTI tools need integriq" otherwise. The static `unavailableMessage` about the missing route goes.

## Spec

The requirement "LessonPlayer delegates the OIDC launch to the openconnector adapter" (`openspec/specs/course-management/spec.md:117-148`) is replaced: the delegation stays, the transport becomes the typed event and the response becomes a login initiation form. The rule that learniq never builds, signs or reads an LTI token stays.

## Declarative versus imperative

| behaviour | path | reason |
|---|---|---|
| launch | imperative, controller raising an event | ADR-041 cross-app command; external integration exception of ADR-031 |
| placement match for grades | imperative, poll job | existing job |
| availability flag | computed in the connection registry reader | depends on another app's presence |

## Seed data

No schema changes. The existing `LtiToolPlacement` demo rows stay; one gains a `launchMode: deep-linking` twin so both paths can be shown.
