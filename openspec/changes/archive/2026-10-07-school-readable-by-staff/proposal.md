# Proposal: school-readable-by-staff

## Why

Found in the primary-school live check (2026-10-02). After #1602 gave `learner-profile.schoolId` a `$ref` to `School`, portaliq's News screen could offer the school as an audience, but only an administrator saw it. A teacher (po-leerkracht-09, group `instructors`) read 0 rows from `learniq/school`.

The cause, traced on the instance:

- `School` carries no authorization block, so OpenRegister resolves it through the register's `read-write` role (`instructors`, `hr`, `compliance-officers`, `team-leads`). The row grant is correct: the RBAC filter alone returns the school.
- OpenRegister's multitenancy bypass (`MagicRbacHandler::hasConditionalRulesBypassingMultitenancy()`) reads only the schema's OWN authorization block, not that cascade. With no block it does not bypass, multitenancy applies, and the school row (which carries no organisation) is filtered away for every non-admin. `Cohort`, which declares its own block, is not affected.

The OpenRegister inconsistency is reported to the OpenRegister lane. This change gives `School` its own block so staff read it today.

## What changes

- `School` declares `authorization`: `read` for the staff groups (`instructors`, `hr`, `compliance-officers`, `team-leads`, `coordinators`, `administration-managers`); `create` and `update` for exactly the groups the register cascade already gave (`instructors`, `hr`, `compliance-officers`, `team-leads`); no `delete`, so only an administrator deletes, as before.
- Register version 0.34.28, `School` 0.2.1.

## Not changed

- Guardians and pupils get no read on `School` (a school's name reaches the portal through portaliq, server side).
- The other learniq schemas without their own block have the same exposure to the OpenRegister behaviour; they are out of scope here and listed for the OpenRegister report.
