---
kind: code
depends_on: []
---

# Proposal: content-lti-launch-through-integriq

## Summary

Learniq's half of the LTI 1.3 launch that integriq's `connectors-lti-platform-launch` builds. When a learner opens an LTI lesson or block, learniq raises integriq's typed launch event instead of posting to a route integriq never served, and the lesson player submits the login form integriq hands back, in a new tab or in the lesson frame. Grades that the tool sends back are matched to the exact placement by line item, and the connection registry stops saying LTI does not work once integriq is present.

## Why

This change is the learniq half of two rows in learniq's `openspec/parity/capabilities.json`, both owned by integriq and specified by integriq's `connectors-lti-platform-launch` (integriq#2207, merged 413357ec):

- `cont-embed-external-lti-tool` ("Drop an external tool into a lesson over LTI 1.3"), rated `no`, `built.state: none`, evidence "The placement model and lib/Controller/LtiToolPlacementController.php exist, but lib/Settings/connections.json marks lti unavailable: a launch calls api/lti/deployments/[id]/launch on integriq, which has no such endpoint, so no tool opens". Five competitors rate yes: moodle ("public/mod/lti/version.php:53 (External tool activity) with LTI 1.3 registration"), moodle-workplace (https://docs.moodle.org/502/en/External_tool), ilias ("components/ILIAS/LTIConsumer/classes/class.ilLTIConsumeProviderFormGUI.php:207-220"), totara (https://totara.help/docs/add-an-external-tool-at-site-level) and chamilo ("public/plugin/ImsLti/login.php:1").
- `cont-lti-grades-come-back` ("Let an external tool send its grade back into your gradebook"), evidence "lib/BackgroundJob/LtiAgsScorePollJob.php no-ops without a subscription id, and lib/Settings/connections.json notes grades only come back from a tool that opened, which none does". Four competitors rate yes: moodle ("public/mod/lti/service/gradebookservices/version.php:30"), moodle-workplace (https://docs.moodle.org/502/en/External_tool), totara (https://totara.help/docs/external-tool-activity-settings) and chamilo ("public/plugin/ImsLti/ags2.php:1").

Integriq's proposal names this half in its section "Sibling half": replace `callOpenConnectorLaunch()` with a `class_exists` check and `dispatchTyped()`, render `{formActionUrl, method, fields}` as a form, resolve the placement from the event's `lineItemId` before the deployment, configure `lti_ags_subscription_id`, and drop `available: false` for `lti`.

## What learniq has today

Read at learniq `development` a84b6273.

- `lib/Controller/LtiToolPlacementController.php:153` `OPENCONNECTOR_LAUNCH_PATH = '/apps/openconnector/api/lti/deployments/%s/launch'`; `launch()` (:207) resolves the placement and `callOpenConnectorLaunch()` (:296) posts with a bearer token from `openconnector_api_token` (:162), expecting `{formActionUrl, idToken}`, and adds `launchMode` (:250). Its docblock (:98-149) records that the route never existed.
- `src/views/LessonPlayer.vue:1134` `launchLti()` and :891 `launchLtiForBlock()` call `POST /api/lti-placements/{placementId}/launch` (`appinfo/routes.php:121`) and refuse a response without `formActionUrl` and `idToken` (:929, :1171); `submitLtiLaunchForm()` (:1360-1375) posts one hidden `id_token` field, to a new tab for `resource-link` and to the lesson frame for `deep-linking`.
- `lib/BackgroundJob/LtiAgsScorePollJob.php:152-156` does nothing without `lti_ags_subscription_id`; `resolvePlacementForMessage()` (:305-332) matches a score to a placement by `deploymentUuid` only, so two placements on one deployment cannot be told apart.
- `lib/Settings/connections.json:23-29` entry `lti`, `available: false`.
- `openspec/specs/course-management/spec.md:117-148` requires the launch to go over REST with the `openconnector_api_token` bearer token. This change replaces that requirement.

## What this change builds

1. `LtiToolPlacementController::launch()` raises `OCA\Integriq\Event\LtiLaunchRequestedEvent` (looked up by class name, never imported), with the placement, deployment, user, message type from `launchMode`, role from the user's groups, the course as context and a return URL, and answers with the event's login initiation `{formActionUrl, method, fields}` plus `launchMode`, or with the event's refusal. Without integriq it answers 503 with a plain message.
2. `LessonPlayer.vue` accepts that shape and submits every field of `fields` to `formActionUrl` with `method`, in a new tab or in the frame as today.
3. `LtiAgsScorePollJob` resolves the placement by the event's `lineItemId` (integriq sets it to the placement id) and falls back to the deployment only when it is missing.
4. An admin setting on the connection registry page to create or name the event subscription the poll job reads, instead of a hidden app config value.
5. `connections.json` `lti` reports available when integriq's launch event class exists.
6. The OpenConnector launch path constant, the token read and their docblock go.

## Out of scope

- Deep linking content selection (integriq keeps REQ-LTI-006's second half).
- Registering tools; that is integriq's registration screen.

## Affected projects

- [x] `learniq`: `lib/Controller/LtiToolPlacementController.php`, `src/views/LessonPlayer.vue`, `lib/BackgroundJob/LtiAgsScorePollJob.php`, `lib/Settings/connections.json`, admin settings, specs.
- Depends on integriq's `connectors-lti-platform-launch` landing first in time; learniq builds against its event contract and fails closed until it exists.

## Risks

- The event class changes shape before integriq ships. Mitigation: learniq reads the result slot through the three methods integriq's design names (`isHandled()`, `getRefusal()`, the login initiation getter) and a unit test with a stand-in class pins them.
