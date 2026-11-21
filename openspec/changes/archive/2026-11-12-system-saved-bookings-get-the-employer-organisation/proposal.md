# Proposal: system-saved-bookings-get-the-employer-organisation

## Why

An employer books places through the portal. Learniq saves that booking without a user (`_rbac` and `_multitenancy` off), so OpenRegister stamps the default organisation in `@self.organisation`, not the organisation the company belongs to. Once portal reads act for the portal's organisation (portaliq change `portal-acts-for-its-organisation`, openregister change `act-for-an-organisation`), such a booking falls outside the company's organisation.

## What changes

- A booking the employer makes is created inside OpenRegister's `runForOrganisation()` for the company row's `@self.organisation`, so it gets that organisation.
- A company without an organisation books as before. This needs an OpenRegister with `runForOrganisation()`, so it merges after that change.
- An organisation that does not exist books nothing and answers 502, like any failed write.
- The OpenRegister test stub mirrors `ObjectService::runForOrganisation()`.

## Not in this change

- Bookings saved before this change keep the default organisation. A restamp with a dry run first is a separate change.
- Enrolments the employer adds stay as they are.
