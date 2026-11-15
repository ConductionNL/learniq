---
status: done
---

# ai-surface Specification

## Purpose
Consolidates Scholiq's AI surfaces into a single interactive "Assistant" navigation entry that opens the LLM chat companion, removing the duplicate standalone "AI features" menu entry. The EU AI Act AI features register and its detail pages remain routable via deep links and are reachable through a "Manage AI features" affordance on the Settings page, keeping the governance register discoverable.

## Requirements

### Requirement: REQ-SAI-005 — The system SHALL NOT present an inherited Assistant AI-chat surface
The system SHALL NOT present the inherited generic "Assistant" AI-chat surface. Specifically: `src/manifest.json.menu[]` SHALL contain no entry with `id: "AssistantMenu"`; `src/manifest.json.pages[]` SHALL contain no page with `id: "Assistant"` (`route: "/assistant"`, `type: "chat"`); `src/menu-layout.json#settingsSection` SHALL NOT list `AssistantMenu`; and consequently the nc-vue `CnAppRoot` floating "Open AI chat" FAB — which renders only while a `type: "chat"` page is declared — SHALL NOT be rendered. This removal does not touch the EU AI Act `AiFeature` governance register.
<!-- @e2e exclude Absence / static-manifest / nc-vue-FAB assertions — verified by the manifest unit test (no `AssistantMenu` menu id, no `Assistant` page, no `AssistantMenu` in settingsSection) and an in-browser check of the removed FAB at apply; not positive route-smoke DOM behaviours. -->

#### Scenario: Assistant menu entry is absent
- **GIVEN** the parsed `src/manifest.json`
- **WHEN** its `menu[]` array is inspected
- **THEN** no entry with `id: "AssistantMenu"` is present

#### Scenario: Assistant chat page is absent
- **GIVEN** the parsed `src/manifest.json`
- **WHEN** its `pages[]` array is inspected
- **THEN** no page with `id: "Assistant"` (`route: "/assistant"`, `type: "chat"`) is present
- **AND** `src/menu-layout.json#settingsSection` does not list `AssistantMenu`

#### Scenario: No "Open AI chat" FAB is rendered
- **GIVEN** the Scholiq app shell has no `type: "chat"` page declared
- **WHEN** any Scholiq page is rendered
- **THEN** nc-vue's `CnAppRoot` renders no floating "Open AI chat" action button

### Requirement: REQ-SAI-004 — The system SHALL surface AI-feature governance from Settings via Hermiq
The system SHALL surface EU AI Act AI-feature governance from the Nextcloud **Admin Settings** page (`ScholiqSettings.vue`) by delegating to the central **Hermiq** app rather than a local register. When Hermiq is installed, the "AI Features" section SHALL present an affordance ("Open the AI-feature register in Hermiq") that full-navigates to `generateUrl('/apps/hermiq') + '/ai-features'`. When Hermiq is not installed, the section SHALL present an "install and enable Hermiq" notice instead, with no hard dependency and no crash. The same Settings page SHALL continue to render the AVG Art. 30 `scholiq-ai-features` AI-assisted-learning processing block.

#### Scenario: AI-feature governance reachable via Hermiq when installed
- **GIVEN** a user on the Scholiq Admin Settings page and Hermiq is installed
- **WHEN** they view the "AI Features" section
- **THEN** an "Open the AI-feature register in Hermiq" affordance is shown
- **AND** activating it navigates to Hermiq's `/ai-features` register

#### Scenario: Install notice when Hermiq is absent
- **GIVEN** a user on the Scholiq Admin Settings page and Hermiq is not installed
- **WHEN** they view the "AI Features" section
- **THEN** an "install and enable Hermiq" notice is shown
- **AND** no local AI-features table is rendered and the page does not error

#### Scenario: Settings still shows the AVG Art. 30 AI processing block
- **GIVEN** the Scholiq Admin Settings page is open
- **WHEN** the AVG Art. 30 processing register is rendered
- **THEN** the `scholiq-ai-features` AI-assisted learning processing block remains visible
<!-- @e2e exclude Hermiq-presence branching + Settings deep-link + AVG-block presence — verified by the settings unit/build check and an in-browser check at apply (Hermiq installed vs absent); not positive route-smoke DOM behaviours in the scholiq e2e. -->

### Requirement: REQ-SAI-006 — The system SHALL delegate AI-feature governance to Hermiq
The system SHALL NOT maintain a local EU AI Act AI-feature governance register. Specifically: `src/manifest.json.pages[]` SHALL contain no `AiFeatures` (`/ai-features`) or `AiFeatureDetail` (`/ai-features/:id`) page; `lib/Lifecycle/AiFeatureDpoAckGuard.php` SHALL NOT exist; and the `AiFeature` schema in `lib/Settings/scholiq_register.json` SHALL carry no `x-openregister-lifecycle` governance and no governance properties, retaining only `slug`/`name`/`description` and its `x-openregister-processing` (`scholiq-ai-features`) AVG Art. 30 annotation. Governance of high-risk AI features is delegated to the Hermiq app's `agentaifeature` register. The `AssessmentPublishGuard` SHALL enforce the ADR-005 DPO gate for `ai-assisted` proctoring by looking the feature up in Hermiq's register (`register=hermiq`, `schema=agentaifeature`, `slug=assessment-ai-proctor-review`, `lifecycle=enabled`), failing closed with actionable guidance when Hermiq is unavailable, while leaving manual proctoring and all other transitions unaffected. Scholiq SHALL declare no hard dependency on Hermiq.
<!-- @e2e exclude Static-manifest / file-absence / schema-shape / guard-source assertions — verified by the manifest validator (no AiFeatures/AiFeatureDetail pages), the register-contract unit tests (schema shape + retained processing annotation), phpcs/lint on the re-pointed guard, and the ADR-005 amendment; not positive route-smoke DOM behaviours. -->

#### Scenario: Local AI-feature governance pages are absent
- **GIVEN** the parsed `src/manifest.json`
- **WHEN** its `pages[]` array is inspected
- **THEN** no page with `id: "AiFeatures"` or `id: "AiFeatureDetail"` is present

#### Scenario: The DPO-acknowledgement guard is removed
- **GIVEN** the repository
- **WHEN** `lib/Lifecycle/` is inspected
- **THEN** `AiFeatureDpoAckGuard.php` does not exist
- **AND** the `AiFeature` schema declares no `x-openregister-lifecycle` and no governance properties

#### Scenario: The AVG Art. 30 processing carrier is retained
- **GIVEN** the `AiFeature` schema in `lib/Settings/scholiq_register.json`
- **WHEN** its `x-openregister-processing` annotation is inspected
- **THEN** it declares `code: "scholiq-ai-features"` with the required Art. 30 catalogue fields
- **AND** Scholiq's verwerkingsregister still declares seven processing activities

#### Scenario: The proctoring DPO gate is sourced from Hermiq (fail closed)
- **GIVEN** an Assessment with `proctoring.flagReviewMode: "ai-assisted"` and a non-empty `itemRefs`
- **WHEN** it is published while Hermiq has no `enabled` `assessment-ai-proctor-review` feature (or Hermiq is not installed)
- **THEN** `AssessmentPublishGuard` blocks the publish and logs actionable guidance (install Hermiq / DPO-enable the feature)
- **AND** the same Assessment with `flagReviewMode: "manual"` publishes with only the itemRefs check applied
