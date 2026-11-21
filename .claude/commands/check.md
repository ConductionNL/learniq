---
description: Run Learniq's full quality suite (PHP and frontend)
---
Run the quality suite from the repository root and report each failure with its file and line.

1. `composer check:strict` (lint, phpcs, phpmd, psalm, phpstan, test:all). Set `COMPOSER_PROCESS_TIMEOUT=0` for a long run.
2. `npm run lint`
3. `npm run stylelint`
4. `npm run check:specs` (JSON, manifest, register, menu role gates and l10n checks)

Fix PHP style with `composer cs:fix` and JavaScript style with `npm run lint-fix`. Do not add baseline entries or suppressions to get a green run.
