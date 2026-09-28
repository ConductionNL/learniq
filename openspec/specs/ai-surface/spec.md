---
status: done
---

# ai-surface Specification

## Purpose
Consolidates Scholiq's AI surfaces into a single interactive "Assistant" navigation entry that opens the LLM chat companion, removing the duplicate standalone "AI features" menu entry. The EU AI Act AI features register and its detail pages remain routable via deep links and are reachable through a "Manage AI features" affordance on the Settings page, keeping the governance register discoverable.

## Requirements

### Requirement: REQ-SAI-002 — The system SHALL remove the standalone "AI features" nav entry
The system SHALL remove the `AiFeaturesMenu` menu array entry from `src/manifest.json.menu[]` so that the "AI features" governance register is no longer a standalone top-level (or settings-section) navigation item.

#### Scenario: AiFeaturesMenu is absent from the menu
- **GIVEN** the parsed `src/manifest.json`
- **WHEN** its `menu[]` array is inspected
- **THEN** no entry with `id: "AiFeaturesMenu"` is present

### Requirement: REQ-SAI-003 — The system SHALL keep the AI features register and Assistant pages routable
The system SHALL retain the `AiFeatures` (`/ai-features`) and `AiFeatureDetail` (`/ai-features/:id`) page objects in `src/manifest.json.pages[]` unchanged, so deep links and the `KpiSchemasWidget` link to `/ai-features` continue to resolve even though the "AI features" menu entry is removed. The `Assistant` (`/assistant`) page is no longer part of this retained set — it is removed by this change (see REQ-SAI-005).
<!-- @e2e exclude AI-features governance reachability is unchanged by this change; covered by the existing ai-surface e2e. This requirement is re-affirmed here only to drop the now-removed Assistant page from the retained-pages set. -->

#### Scenario: AI features deep link still resolves
- **GIVEN** the "AI features" menu entry has been removed
- **WHEN** a user navigates directly to `/ai-features`
- **THEN** the `AiFeatures` index page renders the `AiFeature` register

#### Scenario: AI feature detail deep link still resolves
- **GIVEN** an `AiFeature` object id
- **WHEN** a user navigates to `/ai-features/:id`
- **THEN** the `AiFeatureDetail` page renders that feature's DPO-ack lifecycle context

#### Scenario: KpiSchemasWidget link still works
- **GIVEN** the dashboard `KpiSchemasWidget` whose `link` targets `/ai-features`
- **WHEN** the link is followed
- **THEN** the `AiFeatures` register page loads

### Requirement: REQ-SAI-004 — The system SHALL surface the AI features register from Settings
The system SHALL make the EU AI Act `AiFeature` register reachable from the existing `Settings` page (`section-scholiq` slot, `ScholiqSettings.vue`), which already loads the AI features, by providing a "Manage AI features" affordance that deep-links to `/ai-features`. This keeps the governance register discoverable now that its standalone menu entry is gone, consistent with the IA model that config/governance belongs under Settings.

#### Scenario: AI features reachable from Settings
- **GIVEN** a user on the Scholiq `Settings` page
- **WHEN** they view the `section-scholiq` content
- **THEN** a "Manage AI features" affordance is shown that links to `/ai-features`

#### Scenario: Settings still shows the AVG Art. 30 AI processing block
- **GIVEN** the Scholiq `Settings` page is open
- **WHEN** the AVG Art. 30 processing register is rendered
- **THEN** the `scholiq-ai-features` AI-assisted learning processing block remains visible

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
