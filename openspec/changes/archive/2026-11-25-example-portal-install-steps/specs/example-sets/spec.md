## ADDED Requirements

### Requirement: The install guide names every step a fresh portal install needs

The installation guide MUST name, in order, the steps that make a loaded school set a working portal: the loads, `occ maintenance:repair` with cron, the portal's organisation, the organisation's DigiD and eHerkenning issuer, and that a second load after the organisation gives the learners their portal accounts.

#### Scenario: An operator follows the guide on a fresh instance
- **GIVEN** a fresh instance with openregister, thematiq, portaliq and learniq enabled
- **WHEN** the operator follows "Example portals: load a set from the command line" in `docs/installation.md`
- **THEN** every step portal proof run 1 had to work out by hand is in the guide
- @e2e exclude documentation only

### Requirement: Loading a set gives its declared learners a portal account

A declared account with a `portal` entry MUST get, on load, an active portal account for the `nextcloud` sign-in of its set's portal, with that audience and the declared claims, through portaliq's events and never through portaliq's register. An account that already has the audience, the active status and every declared claim MUST be kept without any request to portaliq. Without an organisation on the portal the account MUST wait and the load MUST say so. A portaliq without the Nextcloud provisioning MUST be asked nothing. A refusal MUST be reported with its reason and MUST NOT be followed by a claim.

#### Scenario: Tom gets his participant account once
- **GIVEN** the training set's portal `warmtepompacademie` with organisation `default-organisation`, and Tom Verbeek's Nextcloud account
- **WHEN** the operator loads the training set, then loads it again
- **THEN** the first load gives Tom an active `participant` account with `learnerRef` `ee06000c-0000-4000-8000-000000000158`, and the second load asks portaliq nothing
- @e2e exclude covered by PHPUnit `ExamplePortalAccountGrantsTest`; the sign-in itself by `tests/e2e/portal-design/warmtepompacademie.spec.ts`

### Requirement: The demo trainer gets her accounts through the load

The mbo set MUST declare a demo account for the trainer Petra Bakker of Bakker Techniek BV, with a `praktijkopleider` portal entry whose `practicalTrainerId` claim is her trainer record in the set. The load MUST give her the Nextcloud account and the portal account the same way it gives the learners theirs. A real trainer MUST keep the invitation, which completes on her first eHerkenning sign-in.

#### Scenario: Petra approves Milan's hours on a fresh install
- **GIVEN** a fresh instance where the mbo set was loaded, the portal linked to its organisation, and the set loaded again
- **WHEN** Petra signs in to Esdoornveen with her Nextcloud account
- **THEN** she sees Milan's placement and can approve his week, without any account made by hand
- @e2e exclude declaration and provisioning covered by PHPUnit `ExamplePortalAccountGrantsTest` and `ExamplePortalDeclarationsTest`; the approval itself by the proof run's function probe
