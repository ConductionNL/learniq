# Nextcloud App Specification

## ADDED Requirements

### Requirement: LearniqSettings records the instance's segment
The system MUST persist `LearniqSettings` (`segment`: `po`/`vo`/`mbo`/`he`/`corporate`, default `corporate`) as a flat, un-lifecycled singleton OpenRegister object, mirroring the `SovereigntyPolicy` singleton convention.

#### Scenario: An administrator records the school's segment
- **GIVEN** the `LearniqSettings` schema is registered
- **WHEN** an administrator sets `segment` to `po`
- **THEN** the value persists on the `LearniqSettings` object

#### Scenario: An instance with no LearniqSettings object yet defaults safely
- **GIVEN** no `LearniqSettings` object has been created
- **WHEN** the schema's default is read
- **THEN** `segment` defaults to `corporate`, the no-behaviour-change default for existing customers

### Requirement: LearniqSettings is reachable as an admin-only declarative page
`LearniqSettings` MUST render as a manifest-declared index+detail page pair, visible only to `admin`, with no custom Vue component and no bespoke PHP controller.

#### Scenario: An administrator opens the segment setting
- **GIVEN** the `LearniqSettings` page is configured
- **WHEN** an administrator navigates to it
- **THEN** the page renders via the standard declarative data/related widgets, matching every other schema's detail page in this app

### Requirement: Segment-based menu visibility is not implemented by a config-kind change
No `visibleIf` condition MUST reference `segment` (or any runtime path derived from it) in a `config`-kind change, because `visibleIf` resolves exclusively against `manifest.runtime.*`, which only an app-specific PHP `IInitialState` provider plus JS wiring can populate — a `code`-kind change. Declaring such a condition without that wiring would activate the shared library's undefined-runtime fail-safe (hide the item for every tenant), which is a regression, not a feature.

#### Scenario: No menu item's visibleIf references segment yet
- **GIVEN** this change's manifest diff
- **WHEN** every `visibleIf` block in the changed files is inspected
- **THEN** none references `segment` or a `workspace.segment`/`config.segment` runtime path

#### Scenario: The follow-up code change closes the loop
- **GIVEN** a future `code`-kind change adds the PHP `IInitialState` provider and the `src/main.js` runtime population for `segment`
- **WHEN** that change also adds `visibleIf: {"workspace.segment": {...}}` to corporate-flavoured menu items
- **THEN** the gating becomes real, because the runtime path it reads is now actually populated
