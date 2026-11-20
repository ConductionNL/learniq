---
description: Run Learniq's Playwright end-to-end tests
---
Run the browser tests in `tests/e2e`, configured in `playwright.config.ts`.

1. Make sure a Nextcloud with Learniq and OpenRegister is running, for example `docker compose -f docker-compose.yml up -d --build` (http://localhost:8080, admin / admin).
2. Load the example data if the tests need it: `OR_BASE_URL=http://localhost:8080 npm run seed:e2e` (the URL is required).
3. `npm run test:e2e`, or `npx playwright test $ARGUMENTS` for one spec file.

Report each failing test with its step and the error.
