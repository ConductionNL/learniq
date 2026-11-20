# The public surfaces may claim the LRS and cmi5 launch as live

## Why

`openspec/specs/beta-alignment` still says `appinfo/routes.php` has no `lrs`, `cmi5` or `xapi`
route and that `Cmi5LaunchTokenService::isEnabled()` hardcodes `false`. Both stopped being true
on 30 Sep: `routes.php` routes `POST` and `GET /api/lrs/statements` (`LrsController`), the xAPI
State and Agent Profile documents (`LrsDocumentController`), the cmi5 launch and fetch
(`Cmi5LaunchController`) and the admin launch key (`Cmi5KeyAdminController`).
`isEnabled()` now returns true once an admin has generated the launch key. The scenario tells
the public surfaces to deny a feature that ships.

## What changes

- "Public surfaces SHALL only claim shipped capabilities as live" is replaced by "Public
  surfaces SHALL claim only what ships as live", with the same rule. Its cmi5/xAPI scenario now
  says the statement ingest and cmi5 launch are routed, and that a public surface may describe
  them as working, with the launch key as an admin step.
- The requirement names the app learniq. The docs subdomain keeps its old name.

## Impact

- `openspec/specs/beta-alignment`. Task 2.1 checks `appinfo/info.xml` and `docs/intro.md` against it.
