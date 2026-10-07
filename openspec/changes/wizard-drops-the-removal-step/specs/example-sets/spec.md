## REMOVED Requirements

### Requirement: The wizard lists every loaded example set with its own remove button

**Reason**: The setup wizard no longer removes example data (live audit A1, Ruben 7 October 2026). The shared wizard card cannot remove, and a separate removal step is not what the other apps offer.

**Migration**: Remove a set with `php occ learniq:example-set:remove <id> --apply`. `POST /api/setup/action/remove-example-set-<id>` stays for an administrator who calls it.

## MODIFIED Requirements

### Requirement: The wizard removes a loaded example set through OpenRegister's import jobs

The setup wizard MUST NOT offer a step that removes example data. The setup action `remove-example-set` stays on the server: its action MUST remove the example set stored as the wizard's answer by calling OpenRegister's `ConfigurationService::softDeleteAppImports()` with that set's import app id: `learniq.profile.<id>` for a shipped set, `learniq.demo` for the generated one. The call MUST be duck-typed: when the method does not exist, the action MUST NOT fail silently and MUST answer `success: false` with the `occ` command that removes the set instead (`php occ learniq:example-set:remove <id> --apply` for a shipped set). When no set was loaded (no answer, or "None"), or no import job was recorded, the action MUST say so and remove nothing. When OpenRegister reports errors, the action MUST answer `success: false`, name the count, and name `occ openregister:objects:purge --import-job <id>` to finish. A successful removal MUST keep the load step answered, so the wizard does not reopen.

#### Scenario: The wizard has no removal step

- **GIVEN** the setup wizard as the manifest declares it
- **WHEN** an administrator goes through it
- **THEN** its steps are welcome, example data, kind of organisation and done, and none removes data

#### Scenario: Removing the company set through the action

- **GIVEN** OpenRegister records one import job for `learniq.profile.corporate` and the wizard's answer is the company set
- **WHEN** an administrator posts `remove-example-set`
- **THEN** `softDeleteAppImports('learniq.profile.corporate')` is called once
- **AND** the answer says how many objects moved to the trash
