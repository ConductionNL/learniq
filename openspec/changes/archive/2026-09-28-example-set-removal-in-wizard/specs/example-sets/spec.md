# Example Sets Specification

## ADDED Requirements

### Requirement: The wizard removes a loaded example set through OpenRegister's import jobs
The setup wizard MUST offer a `remove-example-set` step. Its action MUST remove the example set stored as the wizard's answer by calling OpenRegister's `ConfigurationService::softDeleteAppImports()` with that set's import app id: `learniq.profile.<id>` for a shipped set, `learniq.demo` for the generated one. The call MUST be duck-typed: when the method does not exist, the action MUST NOT fail silently and MUST answer `success: false` with the `occ` command that removes the set instead (`php occ learniq:example-set:remove <id> --apply` for a shipped set). When no set was loaded (no answer, or "None"), or no import job was recorded, the action MUST say so and remove nothing. When OpenRegister reports errors, the action MUST answer `success: false`, name the count, and name `occ openregister:objects:purge --import-job <id>` to finish. A successful removal MUST keep the load step answered, so the wizard does not reopen.

#### Scenario: Removing the company set
- **GIVEN** the wizard loaded the company set and OpenRegister records one import job for `learniq.profile.corporate`
- **WHEN** the admin runs "Remove the example data"
- **THEN** `softDeleteAppImports('learniq.profile.corporate')` is called once
- **AND** the answer says how many objects moved to the trash

#### Scenario: An OpenRegister without the method
- **GIVEN** OpenRegister's ConfigurationService has no `softDeleteAppImports`
- **WHEN** the admin runs the step for the company set
- **THEN** the answer is `success: false` and names `php occ learniq:example-set:remove corporate --apply`

#### Scenario: Nothing was loaded
- **GIVEN** the wizard's example set answer is "None"
- **WHEN** the admin runs the step
- **THEN** nothing is called and the answer says there is nothing to remove

### Requirement: The removal step never runs by itself
The setup status MUST report the `remove-example-set` step as done at all times, so the shared wizard neither starts it on entering the step (it auto-runs an outstanding run-action step) nor reopens itself for it (it opens while any optional step is outstanding). The step MUST run only when the admin clicks its button.

#### Scenario: Opening the wizard after loading a set
- **GIVEN** an example set was just loaded
- **WHEN** the setup status is read
- **THEN** `steps.remove-example-set.done` is true

### Requirement: Only an administrator or an administration manager chooses the kind of organisation
The wizard's segment answer MUST be written to `LearniqSettings.segment` only when the current user is in the `admin` or the `administration-managers` group, the groups the lane brief assigns to the segment. Any other caller MUST get a 403 with a reason, and nothing MUST be written.

#### Scenario: A delegated admin outside both groups
- **GIVEN** a user who may open the setup wizard but is in neither group
- **WHEN** they post `segment: corporate`
- **THEN** the answer is 403 and `setSegment` is not called

#### Scenario: An administration manager
- **GIVEN** a user in `administration-managers`
- **WHEN** they post `segment: po`
- **THEN** the segment is written with them as the one who set it
