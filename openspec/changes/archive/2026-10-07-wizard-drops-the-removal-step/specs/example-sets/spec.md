## REMOVED Requirements

### Requirement: The wizard lists every loaded example set with its own remove button

**Reason**: The setup wizard no longer removes example data (live audit A1, Ruben 7 October 2026). The shared wizard card cannot remove, and a separate removal step is not what the other apps offer.

**Migration**: An administrator removes a set from the Example data section on learniq's admin page (see the ADDED requirement below), or with `php occ learniq:example-set:remove <id> --apply`.

## ADDED Requirements

### Requirement: An administrator removes a loaded example set on the admin page

learniq's admin settings page MUST show an Example data section that lists every loaded example set (`example_sets_loaded`) by its label, read through the admin-only `GET /api/setup/example-sets`. Each set MUST have its own Remove button. A click MUST ask for confirmation first, and only a confirmation MUST post `POST /api/setup/action/remove-example-set-<id>`, which moves the set's objects to OpenRegister's trash. The section MUST show the answer's message as a success or an error, and MUST read the list again, so a set removed without errors disappears and a set with errors stays. With no set loaded, the section MUST say so.

#### Scenario: Removing the company set

- **GIVEN** the company and training sets are loaded
- **WHEN** the administrator clicks Remove next to "Company" and confirms
- **THEN** `POST /api/setup/action/remove-example-set-corporate` is sent
- **AND** the section shows how many objects moved to the trash
- **AND** only the training set is still listed

#### Scenario: The administrator changes their mind

- **GIVEN** the company set is loaded
- **WHEN** the administrator clicks Remove and cancels the question
- **THEN** nothing is posted and the set stays listed

#### Scenario: Nothing loaded

- **GIVEN** no example set was loaded
- **WHEN** the administrator opens the admin page
- **THEN** the Example data section says no example set is loaded

## MODIFIED Requirements

### Requirement: The wizard removes a loaded example set through OpenRegister's import jobs

The setup wizard MUST NOT offer a step that removes example data. The setup action `remove-example-set` stays on the server: its action MUST remove the example set stored as the wizard's answer by calling OpenRegister's `ConfigurationService::softDeleteAppImports()` with that set's import app id: `learniq.profile.<id>` for a shipped set, `learniq.demo` for the generated one. The call MUST be duck-typed: when the method does not exist, the action MUST NOT fail silently and MUST answer `success: false` with the `occ` command that removes the set instead (`php occ learniq:example-set:remove <id> --apply` for a shipped set). When no set was loaded (no answer, or "None"), or no import job was recorded, the action MUST say so and remove nothing. When OpenRegister reports errors, the action MUST answer `success: false`, name the count, and name `occ openregister:objects:purge --import-job <id>` to finish. A successful removal MUST keep the load step answered, so the wizard does not reopen.

#### Scenario: The wizard has no removal step

- **GIVEN** the setup wizard as the manifest declares it
- **WHEN** an administrator goes through it
- **THEN** its steps are welcome, example data, kind of organisation and done, and none removes data

#### Scenario: Removing the company set
- **GIVEN** the wizard loaded the company set and OpenRegister records one import job for `learniq.profile.corporate`
- **WHEN** an administrator posts the `remove-example-set` action
- **THEN** `softDeleteAppImports('learniq.profile.corporate')` is called once
- **AND** the answer says how many objects moved to the trash

#### Scenario: An OpenRegister without the method
- **GIVEN** OpenRegister's ConfigurationService has no `softDeleteAppImports`
- **WHEN** an administrator posts the `remove-example-set` action for the company set
- **THEN** the answer is `success: false` and names `php occ learniq:example-set:remove corporate --apply`

#### Scenario: Nothing was loaded
- **GIVEN** the wizard's example set answer is "None"
- **WHEN** an administrator posts the `remove-example-set` action
- **THEN** nothing is called and the answer says there is nothing to remove
