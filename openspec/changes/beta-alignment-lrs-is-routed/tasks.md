## 1. Spec

- [x] 1.1 Rewrite the cmi5/xAPI scenario to the routes that ship since 30 Sep.

## 2. Public surfaces

- [x] 2.1 Check `appinfo/info.xml` and `docs/intro.md` against the rewritten scenario and describe the LRS and cmi5 launch as working, with the admin launch key step.
  - Done: both surfaces already described the LRS and the cmi5 launch as working. Both now also say that an administrator generates the launch key once, before the first launch (`info.xml` in English and Dutch, and `docs/intro.md`). The claim was checked against `appinfo/routes.php` (`lrs#postStatements`, `lrs#getStatements`, `cmi5Launch#launch`, `cmi5KeyAdmin#generateKey`) and `Cmi5LaunchTokenService::isEnabled()`.
