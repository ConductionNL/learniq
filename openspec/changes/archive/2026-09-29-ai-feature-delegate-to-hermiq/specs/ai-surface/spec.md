# ai-surface Specification (delta)

This change delegates Scholiq's EU AI Act high-risk **AI-feature governance** to the fleet-wide **Hermiq** app. The local `AiFeature` governance register (lifecycle + DPO-acknowledgement), the `/ai-features` register/detail pages, and the local admin AI-features table are removed; a minimal `AiFeature` schema is retained only as the AVG Art. 30 processing-activity carrier (`scholiq-ai-features`). The standalone "AI features" nav-entry requirement (REQ-SAI-002) and the routable-local-pages requirement (REQ-SAI-003) no longer apply and are removed. REQ-SAI-004 is replaced so Settings surfaces governance **via Hermiq**: the old requirement is removed and re-added under its new title, because it drops the local `/ai-features` scenario. A new REQ-SAI-006 records the delegation and the Hermiq-sourced proctoring DPO gate. REQ-SAI-001 (the Assistant chat entry) was already removed by `relocate-dataexchange-remove-assistant` and is untouched here.

## REMOVED Requirements

### Requirement: REQ-SAI-002 — The system SHALL remove the standalone "AI features" nav entry

**Reason**: This requirement governed a local "AI features" governance register that no longer exists in Scholiq. With AI-feature governance delegated to Hermiq, there is no local AI-features nav entry, register, or governance surface for the requirement to constrain — it is subsumed by the delegation (REQ-SAI-006).

**Migration**: No user data is affected. Governance objects live in Hermiq's `agentaifeature` register, not Scholiq. The local `AiFeatures`/`AiFeatureDetail` pages are removed (see REQ-SAI-006); the AVG Art. 30 `scholiq-ai-features` processing activity is retained (see REQ-SAI-004).

### Requirement: REQ-SAI-003 — The system SHALL keep the AI features register and Assistant pages routable

**Reason**: The `AiFeatures` (`/ai-features`) and `AiFeatureDetail` (`/ai-features/:id`) pages are removed by this change — the AI-feature register they rendered is delegated to Hermiq. The `Assistant` (`/assistant`) page was already removed by `relocate-dataexchange-remove-assistant`. There are therefore no local AI-surface pages left to keep routable.

**Migration**: The `KpiSchemasWidget` (whose only link targeted `/ai-features`) is removed. Users reach the AI-feature register through Hermiq (see REQ-SAI-004 and REQ-SAI-006). No local deep link to `/ai-features` is expected to resolve within Scholiq after this change.

### Requirement: REQ-SAI-004 — The system SHALL surface the AI features register from Settings

**Reason**: The local `/ai-features` register this requirement deep-linked to is removed by this change. Settings now surfaces AI-feature governance through Hermiq instead, which the added requirement of the same number (REQ-SAI-004, "via Hermiq") states in full, including the scenario for the AVG Art. 30 block this requirement also carried.

**Migration**: The "Manage AI features" affordance becomes "Open the AI-feature register in Hermiq", or an install notice when Hermiq is absent. No user data moves.

## ADDED Requirements

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
