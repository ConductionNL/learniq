## ADDED Requirements

### Requirement: The institute invites an employer over HTTP

`POST /api/portal/employers/{organisationRef}/invite` MUST invite the contact person of a client company exactly as `occ learniq:portal:invite-employer` does. Only members of `admin`, `administration-managers` or `hr` MUST be allowed, and only into a portal organisation the caller belongs to. A refusal for the caller's input (an unknown or inactive company, an address that is no address, no organisation) MUST answer 400; any other refusal 502.

#### Scenario: Linda signs in with eHerkenning after the invitation
- **GIVEN** the training set, and the organisation's eHerkenning issuer points at the stub
- **WHEN** the administration invites Jansen Installatietechniek BV and Linda signs in with eHerkenning
- **THEN** she lands on Mijn academie, her chip names "Jansen Installatietechniek BV", and her overview asks for Youssef's birth date
- @e2e tests/e2e/portal-design/warmtepompacademie.spec.ts

#### Scenario: A trainer may not invite an employer
- **GIVEN** a member of `instructors` only
- **WHEN** they post an invitation
- **THEN** the answer is 403 and nothing is provisioned
- @e2e exclude authorisation, covered by PHPUnit `PortalEmployerInviteControllerTest`
