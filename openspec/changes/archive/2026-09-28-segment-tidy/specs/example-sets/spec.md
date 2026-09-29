# Example Sets Specification

## ADDED Requirements

### Requirement: The wizard lists every loaded example set with its own remove button
The app MUST record each example set the wizard loads, with its label, in app config (`example_sets_loaded`), and MUST drop a set from that list only after OpenRegister removed a recorded import of it without errors. The page MUST hand the list to the browser as the `loadedExampleSets` initial state, and the browser MUST replace the single `remove-example-set` step with one run-action step per loaded set, step and action `remove-example-set-<id>`, each with its own button (D34). With no set recorded the single step MUST stay. `POST /api/setup/action/remove-example-set-<id>` MUST remove that set through the same path as the single step, and MUST answer 400 for an id that names no set. The setup status MUST report every `remove-example-set-<id>` step as done, so none of them runs by itself or reopens the wizard.

#### Scenario: Two sets were loaded
- **GIVEN** the company set and then the training set were loaded
- **WHEN** an admin opens the setup wizard
- **THEN** it shows "Remove the example set \"Company\"" and "Remove the example set \"Training institute\"", each with its own button

#### Scenario: Removing one of two loaded sets
- **GIVEN** the company and training sets are loaded
- **WHEN** the admin clicks the training set's button and OpenRegister removes its recorded import without errors
- **THEN** only `softDeleteAppImports('learniq.profile.training')` is called
- **AND** the company set stays on the list

#### Scenario: A removal with errors
- **GIVEN** OpenRegister reports errors for a set's import
- **WHEN** the admin clicks that set's button
- **THEN** the set stays on the list, so its button stays
