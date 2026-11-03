# Tasks: system-saved-bookings-get-the-employer-organisation

- [x] **T01**: `PortalEmployerBookings::book()` creates the booking acting for the company's organisation. Verification: `EmployerBookingOrganisationTest`.
- [x] **T02**: `tests/Stubs/Service/ObjectService.php` mirrors `runForOrganisation()`. Verification: `OpenRegisterContractTest`, `StubSignatureParityTest` against an OpenRegister with the change.
- [ ] **T03**: Restamp the bookings saved before this change, dry run first. Not done: a separate change.
