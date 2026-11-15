## ADDED Requirements

### Requirement: A coordinator writes a plan of a chosen kind for a named learner and it shows on that learner

A coordinator MUST be able to create a `LearningPlan` with a `kind` (`opp`, `handelingsplan`, `iep`, `pdp`, `idp` or `generic`), the learner's user id in `learnerId` and themselves as `coordinatorId`. The learner's profile page MUST list that plan in its learning plans panel, with its kind, period and next review date, and open the plan from the row.

#### Scenario: A coordinator writes an action plan for a pupil

- **GIVEN** a learner profile for the pupil "leerling-001"
- **WHEN** a coordinator creates a learning plan of kind `handelingsplan` with `learnerId` "leerling-001"
- **THEN** the plan is stored as `draft` with kind `handelingsplan`, and the coordinator is its `coordinatorId`
- **AND** the learning plans panel on that pupil's profile page lists the plan, and choosing the row opens it

### Requirement: A user opens a plan's earlier versions and sees what changed between them

A learning plan MUST show its version number and link to the version it replaces (`supersedesId`), so a user can walk back through every earlier version. A user MUST be able to see what changed between a version and the one it replaces.

#### Scenario: A coordinator opens the previous version of a plan

- **GIVEN** an active learning plan at version 2 that replaces version 1
- **WHEN** a coordinator opens version 2 and chooses "Replaces" in its versions panel
- **THEN** version 1 opens, read only, with its own goals, support measures and signatures

#### Scenario: A coordinator sees what changed between two versions

- **GIVEN** version 2 of a plan changed one goal and added one support measure compared with version 1
- **WHEN** a coordinator asks what changed between version 2 and version 1
- **THEN** the changed goal and the added support measure are shown, and nothing else

### Requirement: The learner's own signature is required and counted when the template asks for it

When a plan's template lists `learner` in `requiredSignerRoles`, activating the plan MUST require a `Signature` with `signerRole: learner` on that version. The sign endpoint MUST grant the `learner` role only to the user whose id is the plan's `learnerId`.

#### Scenario: A plan waits for the learner's own signature

- **GIVEN** a template requiring `learner`, `parent` and `coordinator`, and a plan signed by the parent and the coordinator only
- **WHEN** the coordinator activates the plan
- **THEN** `LearningPlanSignatureGuard` refuses it and the plan stays `draft`

#### Scenario: The learner signs and the plan can activate

- **GIVEN** the same plan, and the learner whose user id is the plan's `learnerId` signs it as `learner`
- **WHEN** the coordinator activates the plan
- **THEN** the guard counts the learner's signature and the plan becomes `active`

#### Scenario: Someone else cannot sign as the learner

- **GIVEN** a signed-in user who is not the plan's learner
- **WHEN** they ask to sign the plan as `learner`
- **THEN** the sign endpoint answers 403 and no Signature is recorded

### Requirement: A coordinator plans a class in ability subgroups with learners assigned to each

A coordinator MUST be able to make a `GroupPlan` for a cohort and subject, and add `GroupPlanSubgroup`s to it. Each subgroup MUST carry a name, an instruction level (`intensief`, `basis`, `verdiept` or `custom`), a goal and an approach, and the learners assigned to it in `learnerIds`. The group plan's page MUST list its subgroups.

#### Scenario: A teacher splits a class into three groups

- **GIVEN** a group plan for cohort "Groep 6" and subject "Rekenen"
- **WHEN** the coordinator adds the subgroups "Verlengde instructie" (`intensief`), "Basis" (`basis`) and "Plusgroep" (`verdiept`), each with its own goal, approach and learners
- **THEN** the group plan's page lists the three subgroups
- **AND** each subgroup's page shows its instruction level, goal, approach and its learners

### Requirement: A TLV application is made for a support request and points back to it

Every `TlvApplication` MUST name the `SupportRequest` it comes from in `supportRequestId`, and MUST NOT be saved without it. The support request's page MUST list the TLV applications that point to it and count them.

#### Scenario: A coordinator applies for extra support from a support request

- **GIVEN** a submitted support request for a pupil
- **WHEN** a coordinator creates a TLV application with arrangement type and that support request as `supportRequestId`
- **THEN** the TLV application is stored with that `supportRequestId`
- **AND** the support request's page lists it under TLV applications with its arrangement and decision

#### Scenario: A TLV application without a support request is refused

- **GIVEN** a coordinator creating a TLV application
- **WHEN** they save it without a `supportRequestId`
- **THEN** the save is refused because the field is required
