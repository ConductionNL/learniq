## ADDED Requirements

### Requirement: The example portal offers the sign-in modes its audiences need

The portal learniq provisions for an example set MUST declare the sign-in modes the audiences in that set use: `nextcloud` where the set has pupils, workplace trainers or external assessors, and `digid` where it has guardians. Re-provisioning MUST NOT overwrite the modes of a portal that already exists, because a school may have chosen its own.

#### Scenario: A school with pupils and BPV
- GIVEN the mbo example set is loaded on a fresh instance
- WHEN its portal is provisioned
- THEN the portal offers `digid` and `nextcloud`
- @e2e exclude asserted on the provisioner, from the caller

#### Scenario: A portal the school already changed
- GIVEN a portal that offers only `digid`, chosen by the school
- WHEN the set is loaded again
- THEN its modes are left exactly as they are
- @e2e exclude as above

### Requirement: A test suite never edits a portal's sign-in modes

An e2e suite MUST NOT write a portal's `authentication.modes`. Where a suite needs a mode the portal does not offer, it MUST skip with a reason naming the portal and the mode.

#### Scenario: The instance does not offer the mode
- GIVEN a portal that offers only `digid`
- WHEN the pupil suite runs against it
- THEN it skips, naming the portal and the mode it needs
- @e2e exclude the skip is the suite's own behaviour, asserted by reading its result
