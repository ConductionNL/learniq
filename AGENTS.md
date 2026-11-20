# AGENTS.md

Instructions for coding agents (and people) working in this repository. Every command below exists in `composer.json` or `package.json`.

## What this app is

Learniq is a Nextcloud learning system for schools, training providers and companies: courses, progress, certification and compliance. It is built manifest-first on [OpenRegister](https://github.com/ConductionNL/openregister), which must be installed first.

More context: [README.md](README.md) and [docs/Technical/architecture.md](docs/Technical/architecture.md).

## Setup

Evaluation stack (Nextcloud with Learniq and OpenRegister, built from this repository):

```bash
docker compose -f docker-compose.yml up -d --build
# open http://localhost:8080/apps/learniq/ and sign in as admin / admin
docker compose -f docker-compose.yml down -v   # remove the stack and its data
```

Dependencies for development:

```bash
composer install   # also sets git core.hooksPath to .githooks
npm install
```

## Build

```bash
npm run build   # production webpack build
npm run dev     # development build
npm run watch   # rebuild on change
```

## Test

```bash
composer test:unit       # PHPUnit (phpunit.xml: tests/Unit, tests/unit, tests/Integration)
npm run test:js-unit     # Node test runner, tests/unit-js
npm run test:e2e         # Playwright (playwright.config.ts, tests/e2e)
```

## Lint and quality

```bash
composer check:strict   # lint, phpcs, phpmd, psalm, phpstan, test:all
composer check          # quicker: lint, phpcs, psalm, test:unit
composer cs:fix         # fix PHP code style
npm run lint            # ESLint
npm run stylelint       # CSS and Vue styles
npm run format          # Prettier check
npm run check:specs     # JSON, manifest, register, menu role gates and l10n checks
```

## OpenSpec flow

Work is specified before it is built, in [`openspec/`](openspec/) (see [openspec/README.md](openspec/README.md)):

1. Propose a change in `openspec/changes/<change-name>/` with `proposal.md`, `design.md`, `tasks.md` and spec deltas in `specs/<capability>/spec.md`.
2. Build it, ticking tasks in `tasks.md`.
3. Merge the spec deltas into `openspec/specs/<capability>/spec.md`.
4. Archive the change to `openspec/changes/archive/`.

Project rules for proposals, specs and designs are in [openspec/config.yaml](openspec/config.yaml).

## Architecture decisions

App-level decision records are in [`openspec/architecture/`](openspec/architecture/). Design decisions for a single change are in that change's `design.md`.

## Repository conventions

- Entities, lifecycles, notifications and dashboard widgets are declared as configuration, not hand-coded in PHP services ([README.md](README.md)). The schemas are in `lib/Settings/learniq_register.json` (OpenAPI 3.0.0), imported into OpenRegister. Check them with `npm run check:register`.
- Data lives in OpenRegister, not in app database tables ([openspec/config.yaml](openspec/config.yaml)).
- Pages and menus are manifest configuration: `src/manifest.json` plus fragments in `src/manifest.d/`. Check them with `npm run check:manifest`.
- Every user-visible string is translated; see `l10n/` and `npm run check:l10n`.

## Commits

Use [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/), for example `docs: add AGENTS.md with build, test and lint commands`. See [CONTRIBUTING.md](CONTRIBUTING.md).
