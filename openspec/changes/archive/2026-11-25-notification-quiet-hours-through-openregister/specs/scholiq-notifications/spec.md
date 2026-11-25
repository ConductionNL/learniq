## ADDED Requirements

### Requirement: Quiet hours are read and saved through OpenRegister's delivery window

The notification settings panel MUST read a user's quiet hours from `GET /apps/openregister/api/notification-delivery-window` and MUST save them with `PUT` on the same path, sending `enabled`, `start`, `end` and the browser's IANA `timezone`. Turning quiet hours off MUST send `enabled: false`. The panel MUST NOT write quiet hours to the notification-preferences endpoint or keep them anywhere else. It MUST state that the window applies to every app that notifies through Open Register.

#### Scenario: A teacher sets quiet hours

- **GIVEN** a teacher with no delivery window
- **WHEN** she turns on quiet hours from 22:00 to 07:00 in the settings panel
- **THEN** `PUT /apps/openregister/api/notification-delivery-window` receives `enabled: true`, `start: "22:00"`, `end: "07:00"` and her time zone
- **AND** reopening the panel shows 22:00 to 07:00 switched on

#### Scenario: Turning quiet hours off clears the window

- **GIVEN** a teacher with quiet hours on
- **WHEN** she turns them off
- **THEN** the endpoint receives `enabled: false` and a later `GET` answers `enabled: false`

#### Scenario: A refused value is explained

- **GIVEN** an end time OpenRegister refuses
- **WHEN** the teacher saves
- **THEN** the panel shows OpenRegister's message next to the fields and keeps what she typed

#### Scenario: An OpenRegister without the endpoint

- **GIVEN** an OpenRegister that answers 404 on the delivery-window path
- **WHEN** the teacher saves quiet hours
- **THEN** the panel shows that her Nextcloud does not enforce quiet hours yet and the notification switches keep working
