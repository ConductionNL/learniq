#!/usr/bin/env bash
#
# SPDX-License-Identifier: EUPL-1.2
# Copyright (C) 2026 Conduction B.V.
#
# Provision Scholiq's OpenRegister register + schemas + example dataset on a
# freshly installed Nextcloud, for the shared `E2E Tests (Playwright)` CI job.
#
# Wired up as the workflow's `playwright-seed-command`. That step runs AFTER
# `php -S` is up and with cwd set to the Nextcloud server root, so this is
# invoked as:
#
#     playwright-seed-command: 'bash apps/learniq/tests/e2e/ci-seed.sh'
#
# WHY THIS IS NEEDED
# ------------------
# `occ app:enable learniq` runs a post-migration repair step that is supposed
# to import `lib/Settings/learniq_register.json` into OpenRegister. Three
# things make that unreliable as the sole fresh-install path, and ALL THREE
# fail silently:
#
#   1. An IRepairStep runs with NO user session. OpenRegister's RBAC evaluates
#      the acting user, so the import can be denied outright — and the repair
#      step catches its own exception and downgrades it to a warning,
#      explicitly "Non-fatal". `occ app:enable` still exits 0.
#   2. The non-forced import path is version-guarded: `loadSettings(force:
#      false)` can advance the recorded configuration version WITHOUT applying
#      the register, so a second run then sees "already current" and does
#      nothing either.
#   3. OpenRegister's bulk register-import is partial on some builds
#      (openregister#1487): a subset of the 118 schemas lands and the rest are
#      silently dropped.
#
# In every one of those states the app enables cleanly, the SPA boots, and the
# register simply is not there. The e2e failure mode is a wall of empty index
# pages and "page body should not be blank" — messages that point at the specs,
# not at the missing import.
#
# So this script does the import EXPLICITLY over the admin HTTP API (which has
# a real session and passes RBAC) with the app's forced-import endpoint, then
# runs the repo's own seeder — which fills any schemas the bulk import dropped
# by POSTing them individually and creates the example objects the index-page
# specs count rows on — and then VERIFIES the register and a core set of
# schemas actually exist. A failed provision becomes one loud step failure
# here instead of ~200 misleading spec failures later.
#
# It is idempotent: the import is idempotent server-side and the seeder dedupes
# on stable marker fields, so re-running only re-verifies.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"

# ── Target resolution ────────────────────────────────────────────────────────
# The shared workflow's "Seed test data" step exports BASE_URL / NEXTCLOUD_URL /
# NC_BASE_URL / ADMIN_USER / ADMIN_PASSWORD. Accept all of them.
#
# The CI fallback is gated on actually being in CI. On a developer box
# `localhost:8080` is the SHARED dev container, and this script performs ADMIN
# WRITES — it must never silently import a register and 200-odd objects into
# somebody else's environment. Off CI, an unset target is a hard error.
BASE="${PLAYWRIGHT_BASE_URL:-${BASE_URL:-${NEXTCLOUD_URL:-${NC_BASE_URL:-}}}}"
if [ -z "$BASE" ]; then
	if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
		BASE="http://localhost:8080"
	else
		echo "ERROR: no base URL set. Export PLAYWRIGHT_BASE_URL or BASE_URL." >&2
		echo "       Refusing to default to http://localhost:8080 outside CI —" >&2
		echo "       that is the SHARED dev container and this script writes to it." >&2
		exit 1
	fi
fi
BASE="${BASE%/}"

USER_NAME="${ADMIN_USER:-${NC_ADMIN_USER:-admin}}"
USER_PASS="${ADMIN_PASSWORD:-${NC_ADMIN_PASS:-admin}}"

echo "[ci-seed] target:   ${BASE}"
echo "[ci-seed] app root: ${APP_ROOT}"

# ── 1. Force-import the Scholiq configuration ────────────────────────────────
# `settings#load` (POST /api/settings/load) calls
# `SettingsService::loadConfiguration(force: true)` — the forced path that
# defeats the version guard described above. It is
# `#[AuthorizedAdminSetting(AdminSettings::class)]`, so it needs a real admin
# identity; basic auth supplies one, and NC's CSRF check passes because a
# cookie-less request carries no session to forge against.
IMPORT_URL="${BASE}/index.php/apps/learniq/api/settings/load"
echo "[ci-seed] POST ${IMPORT_URL}"

IMPORT_BODY="$(mktemp)"
IMPORT_CODE="$(
	curl -sS -o "$IMPORT_BODY" -w '%{http_code}' \
		-u "${USER_NAME}:${USER_PASS}" \
		-X POST \
		-H 'Content-Type: application/json' \
		-H 'OCS-APIRequest: true' \
		--data '{}' \
		"$IMPORT_URL" || echo 000
)"

echo "[ci-seed] import HTTP ${IMPORT_CODE}"
head -c 2000 "$IMPORT_BODY"; echo

if [ "$IMPORT_CODE" != "200" ]; then
	echo "::error::Scholiq configuration import failed (HTTP ${IMPORT_CODE}). The e2e suite cannot render any index page without the register."
	exit 1
fi

# ⚠️ HTTP 200 is NOT success here. Measured on CI (run 30796644591) the endpoint
# returned 200 with
#   {"success":false,"message":"OCA\\OpenRegister\\Db\\SchemaMapper::loadSchema():
#    Argument #1 ($identifier) must be of type string|int, array given"}
# and imported nothing at all. A status-code-only check would have declared the
# provisioning step green and handed the suite an empty register.
#
# This is a WARNING, not a hard failure, because the repair path below (the
# repo's own seeder, which POSTs each schema individually) recovers from it and
# the REAL gate is the explicit register/schema verification further down. The
# annotation keeps the upstream OpenRegister defect visible instead of silent.
IMPORT_OK="$(python3 -c "
import json, sys
try:
    body = json.load(open(sys.argv[1]))
except Exception:
    print('unparseable'); raise SystemExit
print('true' if body.get('success') is True else 'false')
print(str(body.get('message', ''))[:400])
" "$IMPORT_BODY" 2>/dev/null | head -1 || true)"

if [ "$IMPORT_OK" != "true" ]; then
	echo "::warning::The Scholiq settings/load import returned HTTP 200 but did NOT report success. Falling back to the per-schema repair path below; the register/schema verification remains the gate."
fi

# ── 2. Seed the example dataset (and repair a partial import) ────────────────
# seed-example-data.mjs re-runs the register import through OpenRegister's own
# endpoints, POSTs individually any schema the bulk import dropped
# (openregister#1487), and creates the coherent example dataset the index-page
# row-count assertions need.
#
# Exit codes are meaningful and NOT all failures:
#   0 — register imported essentially completely AND objects seeded
#   2 — partial import; seeded what was possible (specs fall back to smoke checks)
#   1 — Nextcloud unreachable  ← the only genuinely fatal one
SEED_STATUS="none"
set +e
NC_ADMIN_USER="$USER_NAME" NC_ADMIN_PASS="$USER_PASS" \
	OR_BASE_URL="$BASE" OR_USER="$USER_NAME" OR_PASS="$USER_PASS" \
	node "${SCRIPT_DIR}/seed-example-data.mjs"
SEED_RC=$?
set -e
case "$SEED_RC" in
	0) SEED_STATUS="full" ;;
	2) SEED_STATUS="partial"
	   echo "::warning::Scholiq example-data seed reported a PARTIAL register import (openregister#1487). Index-page row-count assertions will be skipped." ;;
	*) echo "::error::Scholiq example-data seed failed (exit ${SEED_RC}) — Nextcloud unreachable at ${BASE}."
	   exit 1 ;;
esac
echo "[ci-seed] seed status: ${SEED_STATUS}"

# ── 3. Verify the register and core schemas are actually there ───────────────
# The import reporting success is not the same as the register existing.
# Verify against OpenRegister directly, using the slugs the manifest's index
# pages resolve by.
verify() {
	python3 - "$1" "$2" <<'PY'
import json, sys
path, kind = sys.argv[1], sys.argv[2]
required = {
    # The core entities every smoke spec touches. Deliberately NOT all 118 —
    # openregister#1487 can drop long-tail schemas, and a gate that fails on
    # those would be failing for a defect in a different repo. These six are
    # the ones whose absence means the import did not happen at all.
    'registers': ['learniq'],
    'schemas': ['course', 'lesson', 'cohort', 'learner-profile', 'enrolment', 'credential'],
}[kind]
with open(path) as fh:
    raw = fh.read()
try:
    body = json.loads(raw)
except json.JSONDecodeError:
    print(f'::error::{kind} endpoint did not return JSON. First 500 bytes:')
    print(raw[:500])
    sys.exit(1)
items = body if isinstance(body, list) else (body.get('results') or body.get('data') or [])
slugs = {i.get('slug') for i in items if isinstance(i, dict)}
missing = [s for s in required if s not in slugs]
print(f'[ci-seed] {kind}: {len(slugs)} present')
if missing:
    print(f'[ci-seed] {kind} present: {sorted(s for s in slugs if s)}')
    print(f'::error::Scholiq {kind} missing after import: {missing}')
    sys.exit(1)
print(f'[ci-seed] {kind} OK ({len(required)} required slugs present)')
PY
}

REG_BODY="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/openregister/api/registers?_limit=300" -o "$REG_BODY"
verify "$REG_BODY" registers

SCH_BODY="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/openregister/api/schemas?_limit=1000" -o "$SCH_BODY"
verify "$SCH_BODY" schemas

echo "[ci-seed] Scholiq register + core schemas provisioned."

# ── 3b. Floor gate on the seeded dataset ─────────────────────────────────────
# index-pages.spec.ts derives its "this index page must show ≥1 row" set from
# test-results/.seeded-schemas.json — whatever the seeder REALLY created. That
# is the right source of truth (a hand-written list had already drifted to
# claiming rows for 15 schemas that had none), but on its own it is also a
# ratchet that can silently fall to zero: if every create started failing, the
# file would be empty, no page would be row-checked, and the suite would go
# green having asserted nothing about data.
#
# So gate the floor here. The number is the count observed once the fixture
# bodies were corrected; it is a minimum, not a target.
SEEDED_FILE="${APP_ROOT}/.e2e-state/seeded-schemas.json"
MIN_SEEDED_SCHEMAS=15
SEEDED_COUNT="$(python3 -c "
import json, sys
try:
    print(len(json.load(open(sys.argv[1]))))
except Exception:
    print(0)
" "$SEEDED_FILE" 2>/dev/null || echo 0)"
echo "[ci-seed] schemas with seeded rows: ${SEEDED_COUNT} (floor ${MIN_SEEDED_SCHEMAS})"
if [ "$SEEDED_COUNT" -lt "$MIN_SEEDED_SCHEMAS" ]; then
	echo "::error::Only ${SEEDED_COUNT} schemas have seeded rows (expected at least ${MIN_SEEDED_SCHEMAS}). The index-page row assertions derive from this set, so a collapse here silently guts the suite."
	echo "::error::Look for '! create <slug> failed' lines above — those are fixture bodies that no longer satisfy the register's required properties."
	exit 1
fi

# Record the outcome for global-setup.ts, which would otherwise repeat the whole
# seed (several minutes of redundant existence checks) inside the Playwright run.
#
# ⚠️ NOT under test-results/. Playwright's `createRemoveOutputDirsTask` deletes
# every project `outputDir` at the start of the run — before globalSetup, and
# long after this step has finished — so anything this script leaves in
# test-results/ is gone by the time a spec looks for it. Verified in
# playwright/lib/runner: the task removes the whole folder, not just artifacts.
mkdir -p "${APP_ROOT}/.e2e-state"
printf '%s' "$SEED_STATUS" > "${APP_ROOT}/.e2e-state/ci-seeded"
echo "[ci-seed] wrote ${APP_ROOT}/.e2e-state/ci-seeded (${SEED_STATUS})"

# ── 4. Warm the SPA so the first spec doesn't pay the cold start ─────────────
# The shared workflow serves Nextcloud with `php -S`. It now sets
# PHP_CLI_SERVER_WORKERS=8, but the first hit still pays a cold opcache and the
# first parse of the webpack bundle. The measured effect on a sibling repo was
# confined to whichever spec happened to run first: it blew its 60s test
# timeout and then passed in 9.1s on retry, while every later spec ran in 4-7s.
# Nothing about the assertion was wrong; it was measuring server warm-up.
#
# So warm it here, in the environment-preparation step where it belongs. The
# alternative — raising that spec's timeout — would hide the cold start inside
# the assertion instead of removing it, and would keep drifting upward.
# Failures are ignored on purpose: this is a warm-up, not a gate. The real
# checks are above and below.
for path in \
	"/index.php/apps/learniq/" \
	"/index.php/apps/learniq/api/settings" \
	"/index.php/apps/openregister/api/registers?_limit=1" \
	"/index.php/apps/openregister/api/schemas?_limit=1"
do
	code="$(curl -sS -o /dev/null -w '%{http_code}' -u "${USER_NAME}:${USER_PASS}" \
		-H 'OCS-APIRequest: true' "${BASE}${path}" || echo 000)"
	echo "[ci-seed] warm ${path} -> ${code}"
done

# ── 5. Bundle gate ───────────────────────────────────────────────────────────
# Pull the main webpack bundle once so it is in the page cache — and, on CI,
# assert it is actually JavaScript.
#
# Do NOT hardcode the URL. Nextcloud serves an app's assets from whichever apps
# directory it was installed into — `/apps/learniq/js/...` on the CI runner,
# `/custom_apps/learniq/js/...` in the docker dev images — and asking for the
# wrong one does not 404. It returns **HTTP 200 with `text/html`**: the NC error
# page, served through index.php. A status-code check therefore reports success
# while fetching a 40 KB HTML page instead of a multi-MB bundle.
#
# Read the real src out of the rendered app page instead, and verify the
# response content type.
APP_HTML="$(mktemp)"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/learniq/" -o "$APP_HTML" || true

# `|| true` is load-bearing: grep exits 1 when it matches nothing, and under
# `set -euo pipefail` that aborts the script right here — so the case the gate
# below exists to explain (no bundle) would die with a bare non-zero exit and
# none of the diagnosis. Let it fall through to the gate instead.
BUNDLE_SRC="$(grep -oE 'src="[^"]*learniq-main[^"]*"' "$APP_HTML" \
	| head -1 | sed 's/^src="//; s/"$//' || true)"

if [ -n "$BUNDLE_SRC" ]; then
	BUNDLE_INFO="$(curl -sS -o /dev/null \
		-w '%{http_code} %{content_type} %{size_download}' \
		-u "${USER_NAME}:${USER_PASS}" "${BASE}${BUNDLE_SRC}" || echo '000 - 0')"
	echo "[ci-seed] warm bundle ${BUNDLE_SRC} -> ${BUNDLE_INFO}"
else
	echo "[ci-seed] could not locate the learniq-main bundle src in the rendered app page."
	BUNDLE_INFO=""
fi

# On CI this is a GATE, not a warm-up.
#
# The single most likely way this job "succeeds" dishonestly is by passing
# without ever loading the app — and the environment hides it well: when the
# bundle is absent, Nextcloud does not 404. It serves its HTML error page with
# **HTTP 200 and Content-Type text/html**, so `npm run build` producing nothing
# looks, to every status-code check in the pipeline, exactly like success.
#
# The content type alone is NOT enough, and this is the second half of the same
# trap. A TRUNCATED bundle — `npm run build` exiting 0 after webpack wrote an
# empty chunk, an artifact that uploaded before the write finished — still
# serves as `application/javascript`, with HTTP 200, at zero bytes. Content
# type says "JavaScript", the status says "fine", and the SPA mounts nothing.
# So the size is gated too. The real bundle measured 11,688,751 bytes
# (run 30889902343); the floor is 1 MB, two orders of magnitude below that and
# far above anything a truncation would leave behind.
BUNDLE_MIN_BYTES=1048576
if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
	BUNDLE_TYPE="$(printf '%s' "$BUNDLE_INFO" | awk '{print $2}')"
	BUNDLE_BYTES="$(printf '%s' "$BUNDLE_INFO" | awk '{print $3}')"
	case "$BUNDLE_TYPE" in
		*javascript*) ;;
		*)
			echo "::error::The Scholiq frontend bundle did not serve as JavaScript (got: ${BUNDLE_INFO:-<not found>})."
			echo "::error::The SPA cannot mount, so every UI spec would fail on a selector timeout with a misleading cause."
			echo "::error::Check the 'Build app frontend' step — a missing bundle returns HTTP 200 text/html, not 404."
			exit 1
			;;
	esac
	if [ "${BUNDLE_BYTES:-0}" -lt "$BUNDLE_MIN_BYTES" ]; then
		echo "::error::The Scholiq frontend bundle served as JavaScript but is only ${BUNDLE_BYTES:-0} bytes (floor ${BUNDLE_MIN_BYTES})."
		echo "::error::A truncated bundle keeps the JavaScript content type and the HTTP 200, so the type check above cannot see it."
		echo "::error::The SPA would mount nothing and every UI spec would fail on a selector timeout with a misleading cause."
		exit 1
	fi
	echo "[ci-seed] bundle verified as JavaScript, ${BUNDLE_BYTES} bytes (floor ${BUNDLE_MIN_BYTES})."
fi

echo "[ci-seed] done."
