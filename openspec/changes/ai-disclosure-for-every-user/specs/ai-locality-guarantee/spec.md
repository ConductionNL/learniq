## MODIFIED Requirements

### Requirement: The system SHALL compose an AI-processing disclosure a school can hand to its DPO

The system SHALL provide `AiProcessingDisclosureController::index()` (`#[NoAdminRequired]`, answering every
signed-in user) composing, for every `AiFeature` registered in Hermiq's `agentaifeature` register (cross-app
read, when Hermiq is installed): the feature's `slug`/`name`/`riskCategory`/`lifecycle`, the app's own
`scholiq-ai-features` AVG Art. 30 processing-activity fields (doelbinding, data categories, on
`avg-verwerkingsregister`'s existing carrier, not a new activity), and the `AiLocalityClassifier`/
`SovereigntyPolicyService` verdict for the currently active provider, together with the school's own
disclosure text (see "An admin SHALL edit the school's part of the AI disclosure"). The system SHALL render
this as a singleton `LearniqAiProcessingDisclosure.vue` page (no `:id` route) that every signed-in user can
read, with three visually distinct verdict states: compliant (green), violates policy (red), unverified
(amber). The amber `unverified` state MUST render regardless of the currently configured policy tier, even
under `third-country-allowed` where the feature is nonetheless allowed to run. No verdict SHALL render as
compliant/green unless `verified: true`.

#### Scenario: The disclosure page lists every Hermiq-governed feature with its locality verdict

- @e2e exclude spec-only proposal, not built yet

- **GIVEN** Hermiq is installed with the `assessment-ai-proctor-review` feature registered
- **WHEN** any signed-in user opens the AI processing disclosure page
- **THEN** the feature is listed with its DPO/lifecycle state, its AVG processing-activity fields, and its locality verdict badge

#### Scenario: An unverified locality never renders as compliant

- @e2e exclude spec-only proposal, not built yet

- **GIVEN** the active Hermiq provider classifies as `unverified`
- **AND** the school's `SovereigntyPolicy` is `third-country-allowed` (the feature is therefore allowed to run)
- **WHEN** the disclosure page renders that feature's row
- **THEN** the badge shows `unverified` (amber), never `compliant` (green)

#### Scenario: Hermiq absent degrades gracefully

- @e2e exclude Graceful-degradation backend behaviour verified by PHPUnit AiProcessingDisclosureControllerTest::testHermiqAbsentReturnsEmptyFeatureListNotError

- **GIVEN** Hermiq is not installed
- **WHEN** the disclosure page loads
- **THEN** it shows the school's own `SovereigntyPolicy` and an empty/absent Hermiq-features section with an "install Hermiq" notice, not an error

## REMOVED Requirements

### Requirement: The AI processing disclosure page SHALL be registered and reachable

**Reason**: It gated the navigation entry to `admin`/`compliance-officer`, and every signed-in user now reads the disclosure (decision 399).

**Migration**: Replaced by "The AI processing disclosure page SHALL be reachable by every signed-in user" below. The registry and manifest registration it named carry over unchanged.

## ADDED Requirements

### Requirement: The AI processing disclosure page SHALL be reachable by every signed-in user

The system SHALL register `LearniqAiProcessingDisclosure.vue` in `src/registry.js`'s `customComponents` map
and declare its corresponding `src/manifest.json` custom page (per ADR-024). A navigation entry visible to
every signed-in user SHALL link to it, without a role gate.

#### Scenario: A student finds the disclosure in the navigation

- @e2e exclude spec-only proposal, not built yet

- **GIVEN** a signed-in student without the `admin` or `compliance-officer` role
- **WHEN** they scan the navigation menu
- **THEN** an entry reaches the AI processing disclosure page, which opens read-only

#### Scenario: An admin finds the same entry

- @e2e exclude spec-only proposal, not built yet

- **GIVEN** an admin or compliance-officer signed in
- **WHEN** they scan the navigation menu
- **THEN** the same entry reaches the AI processing disclosure page, with its edit mode


### Requirement: An admin SHALL edit the school's part of the AI disclosure

The system SHALL keep the school's own disclosure text (an introduction, a contact for questions, and a
plain-language explanation per feature slug) as one OpenRegister object whose create and update are
restricted to `admin` and `compliance-officer`. The disclosure page SHALL show that text to every reader
beside the composed facts, and SHALL offer an edit mode only to `admin` and `compliance-officer`. The
composed facts (features, processing-activity fields and verdict badges) SHALL NOT be editable by anyone.

#### Scenario: An admin explains a feature in the school's own words

- @e2e exclude spec-only proposal, not built yet

- **GIVEN** an admin on the AI processing disclosure page
- **WHEN** they write an explanation for `assessment-ai-proctor-review` and a contact address, and save
- **THEN** every reader of the page sees that explanation beside the feature and the contact at the top

#### Scenario: A reader cannot edit

- @e2e exclude spec-only proposal, not built yet

- **GIVEN** a student on the AI processing disclosure page
- **WHEN** the page renders
- **THEN** no edit control is shown
- **AND** a write to the school's disclosure text from that account is refused

#### Scenario: Verdicts are not editable

- @e2e exclude spec-only proposal, not built yet

- **WHEN** an admin edits the school's disclosure text
- **THEN** the verdict badges and processing-activity fields stay as composed, with no control to change them
