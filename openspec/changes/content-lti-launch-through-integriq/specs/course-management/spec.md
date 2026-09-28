# course-management Specification

## REMOVED Requirements

### Requirement: LessonPlayer delegates the OIDC launch to the openconnector adapter

**Reason**: The REST route it posts to never existed in openconnector or integriq, and the `{formActionUrl, idToken}` answer it expects skips the LTI login step, so no standards-compliant tool opens. Replaced by the requirement below.

**Migration**: The `openconnector_api_token` app config key is no longer read for LTI. No data changes.

## ADDED Requirements

### Requirement: LessonPlayer delegates the LTI launch to integriq through a typed event

When a user opens a lesson or block that places an LTI tool, learniq MUST raise integriq's `LtiLaunchRequestedEvent` with the placement, deployment, user, message type, role and course, and MUST hand the browser the login initiation form the event returns, submitting every field to the form's address with its method, in a new tab for a resource link launch and in the lesson frame for deep linking. Learniq MUST NOT build, sign, read or verify an LTI token. When integriq is not installed, or no listener handles the event, the launch MUST fail with a plain message and no request leaves the server; a refusal from integriq MUST be shown with its reason.

#### Scenario: A learner opens an external tool from a lesson

<!-- @e2e exclude Needs integriq with an approved tool and a live LTI tool, not in the e2e instance; the launch contract is pinned by tests/Unit/Controller/LtiToolPlacementControllerTest.php and the form by tests/unit-js/ltiLaunchForm.test.mjs. -->

- **GIVEN** integriq is installed with an approved tool, and a lesson whose content is an LTI placement on that tool's deployment
- **WHEN** a learner opens the lesson and chooses "Open tool"
- **THEN** the tool opens in a new tab with the learner signed in to it

#### Scenario: Without integriq the lesson says why

<!-- @e2e exclude Depends on an app being absent; covered by LtiToolPlacementControllerTest::testWithoutIntegriqAnswers503. -->

- **GIVEN** integriq is not installed
- **WHEN** a learner posts to `POST /api/lti-placements/{placementId}/launch`
- **THEN** the answer is 503 with the message that LTI tools need integriq

### Requirement: A returned grade lands on the placement that launched it

When the grade pull receives a score, learniq MUST match it to the placement named by the score's line item when that placement belongs to the score's deployment, and MUST fall back to the deployment only when the line item is missing. A score MUST create at most one concept grade per placement and result.

#### Scenario: Two lessons use the same tool

<!-- @e2e exclude Background job over pulled events; covered by LtiAgsScorePollJobTest::testLineItemPicksThePlacement. -->

- **GIVEN** two placements on one deployment, in the lessons "Kwis hoofdstuk 1" and "Kwis hoofdstuk 2"
- **WHEN** the tool sends a score for the line item of "Kwis hoofdstuk 2"
- **THEN** the concept grade is linked to the placement of "Kwis hoofdstuk 2"

### Requirement: The connection registry says whether LTI works

The connection registry MUST report LTI tools as available when integriq's launch event exists, and as unavailable with the message that LTI tools need integriq otherwise. The `lti` row is reported by learniq (like the timetable row), because integriq's registry page shows what the owning app reports. An administrator MUST be able to set the event subscription the grade pull reads, in an LTI section of learniq's admin settings that the row links to.

#### Scenario: An administrator sees LTI as working

<!-- @e2e exclude The row state comes from integriq's registry page; learniq's report is pinned by tests/Unit/Service/ConnectionReportServiceTest.php and the row and section link by tests/Unit/Settings/ConnectionsDeclarationTest.php. -->

- **GIVEN** integriq with the platform launch installed
- **WHEN** an administrator opens the connections page
- **THEN** the LTI tools row shows as available
- **AND** the row links to the admin section with the field for the grade subscription
