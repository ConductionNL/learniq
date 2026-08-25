#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// seed-example-data.mjs — best-effort import of the learniq OpenRegister register
// (lib/Settings/learniq_register.json) into a running Nextcloud + OpenRegister,
// then create a small coherent example dataset so the index pages + dashboard KPI
// widgets have content. Idempotent: re-running skips objects that already exist.
//
// Run:  OR_BASE_URL=http://localhost:8088 node tests/e2e/seed-example-data.mjs
// Env:  OR_BASE_URL (REQUIRED — no default; PLAYWRIGHT_BASE_URL / BASE_URL also accepted)
//       OR_USER / OR_PASS (default admin / admin)
//
// ⚠️ There is deliberately NO `localhost:8080` default. This script CREATES a
// register and a full example dataset; the old default pointed it at the shared
// developer container, which bind-mounts real host checkouts.
//
// Exit code: 0 if the register imported essentially completely (≥30 of 35 schemas)
//              and example objects were seeded — the e2e specs then run their
//              deeper assertions (no JS error / ≥1 row per index page);
//            2 if the import was partial (the OpenRegister register-import gap,
//              openregister#1487 — the e2e specs then run only the smoke checks);
//            1 only if Nextcloud is unreachable.
//
// Known limitation: OR's register-import endpoint is partial in some builds
// (openregister#1487 / scholiq#35) — this script imports what it can and logs
// what failed; the e2e index-page specs tolerate empty index pages where a
// schema couldn't be imported.

import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

const __dirname = dirname(fileURLToPath(import.meta.url))
const REPO_ROOT = join(__dirname, '..', '..')

// Every name the shared CI quality workflow exports is accepted (BASE_URL,
// NEXTCLOUD_URL, NC_BASE_URL — it does NOT export PLAYWRIGHT_BASE_URL), plus
// this script's own OR_BASE_URL and the repo's historical PW_BASE_URL.
const RAW_BASE = process.env.OR_BASE_URL
	?? process.env.PLAYWRIGHT_BASE_URL
	?? process.env.BASE_URL
	?? process.env.NEXTCLOUD_URL
	?? process.env.NC_BASE_URL
	?? process.env.PW_BASE_URL
if (!RAW_BASE) {
	console.error('[seed] OR_BASE_URL (or PLAYWRIGHT_BASE_URL / BASE_URL / NEXTCLOUD_URL / NC_BASE_URL) must be set — '
		+ 'refusing to fall back to the shared developer instance on :8080.')
	process.exit(1)
}
const BASE = RAW_BASE.replace(/\/$/, '')
const USER = process.env.OR_USER ?? 'admin'
const PASS = process.env.OR_PASS ?? 'admin'
const AUTH = 'Basic ' + Buffer.from(`${USER}:${PASS}`).toString('base64')

const REGISTER_SLUG = 'learniq'

function log(...a) { console.log('[seed]', ...a) }
function warn(...a) { console.warn('[seed]', ...a) }

async function api(method, path, body, { raw = false } = {}) {
	const headers = {
		Authorization: AUTH,
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	}
	let payload
	if (body !== undefined) {
		headers['Content-Type'] = 'application/json'
		payload = JSON.stringify(body)
	}
	let res
	try {
		res = await fetch(`${BASE}${path}`, { method, headers, body: payload })
	} catch (e) {
		return { ok: false, status: 0, err: String(e), json: null }
	}
	const text = await res.text()
	let json = null
	try { json = text ? JSON.parse(text) : null } catch { /* HTML / non-JSON */ }
	if (raw) return { ok: res.ok, status: res.status, text, json }
	return { ok: res.ok, status: res.status, json, text }
}

// ── 1. Ping NC ───────────────────────────────────────────────────────────────
async function pingNc() {
	const r = await api('GET', '/status.php')
	if (!r.ok || !r.json || r.json.installed !== true) {
		warn(`Nextcloud not reachable/installed at ${BASE} (status ${r.status}). Aborting.`)
		return false
	}
	log(`Nextcloud ${r.json.versionstring ?? '?'} at ${BASE} — OK`)
	return true
}

// ── 2. Import the register ───────────────────────────────────────────────────
function loadRegister() {
	return JSON.parse(readFileSync(join(REPO_ROOT, 'lib', 'Settings', 'learniq_register.json'), 'utf8'))
}

// ⚠️ `_limit`, NOT `limit`. OpenRegister's list endpoints treat a bare `limit`
// as a PROPERTY FILTER and ignore it as a page size — measured on this endpoint,
// `?limit=3` returns all 142 rows while `?_limit=3` returns 3. The old
// `?limit=500` therefore only ever worked because the endpoint happens to
// default to "everything"; the day it grows a default page size, this read would
// silently truncate and the seeder would re-POST schemas that already exist.
// ci-seed.sh already uses `_limit` for the same reads.
async function fetchSchemaRows() {
	const r = await api('GET', '/index.php/apps/openregister/api/schemas?_limit=1000')
	return r.json?.results ?? r.json?.data ?? (Array.isArray(r.json) ? r.json : [])
}

async function existingSchemaSlugs() {
	return new Set((await fetchSchemaRows()).map((s) => s.slug).filter(Boolean))
}

async function ensureRegisterRow() {
	const r = await api('GET', '/index.php/apps/openregister/api/registers?_limit=500')
	const items = r.json?.results ?? r.json?.data ?? (Array.isArray(r.json) ? r.json : [])
	const found = items.find((x) => x.slug === REGISTER_SLUG)
	if (found) { log(`register "${REGISTER_SLUG}" exists (id ${found.id})`); return found }
	const c = await api('POST', '/index.php/apps/openregister/api/registers', {
		slug: REGISTER_SLUG, title: 'Learniq', description: 'Learniq LVS/LMS register', version: '0.1.0',
	})
	if (c.ok) { log(`created register "${REGISTER_SLUG}" (id ${c.json?.id})`); return c.json }
	warn(`could not create register row (status ${c.status})`); return null
}

// Read the register's own schema list and link anything missing, with ONE PATCH.
//
// The repair is additive and it refuses to act on a read it could not parse:
// PATCH replaces the list wholesale, so basing the new list on an unreadable
// read would DELETE every existing linkage. An unreadable result is not "nothing
// is linked" — it is "I could not tell", and it must not take the write branch.
async function ensureRegisterLinkage(registerRow, wanted) {
	const registerId = registerRow?.id ?? null
	if (registerId === null) {
		warn('linkage: no register id — cannot verify which schemas the register carries')
		return
	}
	const reg = await api('GET', `/index.php/apps/openregister/api/registers/${registerId}`)
	const linked = reg.ok === true ? (reg.json?.schemas ?? null) : null
	if (Array.isArray(linked) === false) {
		warn(`linkage: could not read register ${registerId} (status ${reg.status}) — NOT patching. `
			+ 'An unreadable list must never become the base of a replace.')
		return
	}

	const rows = await fetchSchemaRows()
	const idBySlug = new Map(rows.filter((s) => s.slug).map((s) => [s.slug, s.id]))
	const linkedIds = new Set(linked.map(String))
	const missing = []
	for (const slug of wanted.keys()) {
		const id = idBySlug.get(slug)
		if (id !== undefined && linkedIds.has(String(id)) === false) missing.push(id)
	}
	log(`linkage: register "${REGISTER_SLUG}" carries ${linked.length} schema(s); `
		+ `${wanted.size - missing.length}/${wanted.size} learniq schemas linked`)
	if (missing.length === 0) return

	const merged = [...linked, ...missing]
	const p = await api('PATCH', `/index.php/apps/openregister/api/registers/${registerId}`, { schemas: merged })
	if (p.ok) {
		log(`linkage: linked ${missing.length} previously unlinked schema(s) — register now carries ${merged.length}`)
		return
	}
	warn(`linkage: could not link ${missing.length} schema(s) (status ${p.status}): ${(p.text || '').slice(0, 200)}`)
}

async function importRegister() {
	const register = loadRegister()
	const registerJson = JSON.stringify(register)
	const schemaNames = Object.keys(register.components?.schemas ?? {})
	log(`register declares ${schemaNames.length} schemas`)

	// Try the configurations import endpoints (these vary by OR build).
	const beforeImport = await existingSchemaSlugs()
	// (a) create a Configuration entity, then import into it
	const cfg = await api('POST', '/index.php/apps/openregister/api/configurations', {
		title: 'learniq-seed', type: REGISTER_SLUG,
	})
	if (cfg.ok && cfg.json?.id) {
		const imp = await api('POST', `/index.php/apps/openregister/api/configurations/${cfg.json.id}/import`, { json: registerJson })
		log(`configurations/${cfg.json.id}/import → status ${imp.status}${imp.json?.message ? ` (${imp.json.message})` : ''}`)
	}
	// (b) bare configurations/import
	const imp2 = await api('POST', '/index.php/apps/openregister/api/configurations/import', { json: registerJson })
	log(`configurations/import → status ${imp2.status}${imp2.json?.message ? ` (${imp2.json.message})` : ''}`)
	if (imp2.json?.imported?.schemas) {
		log(`  imported schemas: ${imp2.json.imported.schemas.map((s) => s.slug).join(', ') || '(none)'}`)
	}

	// (c) for any learniq schema still missing, POST it individually.
	const registerRow = await ensureRegisterRow()

	// ── `?register=` is what makes an individually-created schema REACHABLE ───
	// OpenRegister keeps the register↔schema linkage in
	// `openregister_registers.schemas`. Since openregister#2526 that list is the
	// BOUNDARY every register-scoped slug read is checked against: a
	// `POST /api/objects/learniq/<slug>` answers `Schema not found: '<slug>'`
	// when the register does not carry the slug — even though the schema row
	// exists and `GET /api/schemas` lists it happily. openregister#2535 then made
	// `POST /api/schemas?register=<id|uuid|slug>` maintain that list, and this is
	// the call site that has to pass it.
	//
	// Measured WITHOUT the parameter (CI run 31957570239, reproduced locally
	// against openregister development incl. #2535): 118/118 schemas present,
	// register carries 0, and all 33 object creates below fail with 404 while
	// this function still logs a cheerful "118/118 learniq schemas now present".
	//
	// A register value that does not resolve is a 400 from OpenRegister BEFORE
	// the schema is written, so a wrong value fails loudly instead of silently
	// producing unreachable schemas.
	const registerRef = registerRow?.id ?? registerRow?.uuid ?? registerRow?.slug ?? null
	if (registerRef === null) {
		warn('!! no register row could be resolved — schemas created below would be UNREACHABLE by slug '
			+ '(the register\'s schema list is the boundary for every /api/objects/<register>/<slug> read).')
	}
	const registerQuery = registerRef === null ? '' : `?register=${encodeURIComponent(registerRef)}`

	const after = await existingSchemaSlugs()
	// This count used to be computed and then thrown away. It is the one number
	// that says how far the BULK import got before it stopped, and its absence is
	// why an import that aborted on the 5th schema — leaving 4 orphan, unlinked
	// rows behind — looked exactly like an import that did nothing at all.
	const imported = [...after].filter((s) => !beforeImport.has(s)).length
	log(`bulk import produced ${imported} new schema row(s) before the per-schema fallback`)
	const wanted = new Map(Object.entries(register.components.schemas).map(([name, s]) => [s.slug, { name, s }]))
	let createdIndividually = 0
	for (const [slug, { name, s }] of wanted) {
		if (after.has(slug)) continue
		// Build a minimal schema body OR doesn't choke on (slug/title/required/properties + x-openregister-*).
		const body = { ...s }
		const r = await api('POST', `/index.php/apps/openregister/api/schemas${registerQuery}`, body)
		if (r.ok) { createdIndividually++; log(`  + created schema "${slug}" individually`); continue }

		// ── Diagnose a top-level `allOf` carrying OBJECTS ────────────────────
		// OpenRegister's `allOf` is a NARROWER dialect than JSON Schema's: an
		// array of PARENT SCHEMA REFERENCES (id, uuid or slug). See its
		// docs/Features/schemas.md (`"allOf": ["42"]`, `"allOf": ["person"]`) and
		// Schema::setAllOf() — "Array of schema IDs, UUIDs, or slugs".
		// SchemaMapper::extractAllOfDelta() passes each entry straight to
		// loadSchema(string|int $identifier), so an OBJECT entry raises a
		// TypeError. It is a TypeError, not an Exception, so that method's own
		// `catch (Exception)` does not catch it: a single POST 500s, and the
		// whole configuration import returns {"success":false} and stops, which
		// is what left `course` and `credential` present-but-unlinked below.
		//
		// This register carried three such blocks (lesson, grade-entry,
		// portfolio-entry) until they were removed. They were never enforced
		// anyway: Schema::getSchemaObject(), the only thing handed to the Opis
		// validator, emits title/description/version/type/required/$schema/$id/
		// properties and nothing else — no allOf, no if/then/else ever reaches a
		// validator in any OpenRegister build.
		//
		// Removing the three blocks from the register is the real fix, and it was
		// measured to work (settings/load goes {"success":false} → true and the
		// bulk import links all 118 by itself). It is NOT done here because it
		// also lands on two unit tests that assert the construct and on three
		// integration tests that only pass today by SKIPPING on the missing
		// register — its own slot. Until then the retry stands, and it now says
		// exactly what it is working around.
		if (Array.isArray(s.allOf) && s.allOf.some((e) => e !== null && typeof e === 'object')) {
			warn(`  !! schema "${slug}" has a top-level allOf containing OBJECTS. OpenRegister's allOf takes parent-schema `
				+ `REFERENCES (id/uuid/slug) only; an object entry raises a TypeError in SchemaMapper::extractAllOfDelta() `
				+ `and aborts the WHOLE register import. OpenRegister evaluates no if/then/else at all, so the rule this `
				+ `expresses is not enforced by the register in any build.`)
			const { allOf, ...withoutAllOf } = s
			const r2 = await api('POST', `/index.php/apps/openregister/api/schemas${registerQuery}`, withoutAllOf)
			if (r2.ok) {
				createdIndividually++
				warn(`  ~ created schema "${slug}" WITHOUT its top-level allOf. Nothing is lost at runtime — see above — but `
					+ `the register's own bulk import still cannot complete while the block is there.`)
				continue
			}
			warn(`  ! could not create schema "${slug}" (status ${r.status}; retry without allOf also failed with ${r2.status}: ${(r2.text || '').slice(0, 200)})`)
			continue
		}
		warn(`  ! could not create schema "${slug}" (status ${r.status}): ${(r.text || '').slice(0, 200)}`)
	}

	// ── Verify the register actually CARRIES the schemas ─────────────────────
	// "the schema row exists" and "the register lists it" are different
	// questions, and only the second governs /api/objects/<register>/<slug>.
	// The `if (after.has(slug)) continue` skip above asks the first one, so any
	// schema left behind UNLINKED by an aborted bulk import is skipped here and
	// never gets a `?register=` create. Measured: an import that aborted on the
	// 5th schema left `course` and `credential` — 2 of the 6 slugs ci-seed.sh
	// gates on — present-but-unlinked, three times over.
	await ensureRegisterLinkage(registerRow, wanted)
	const finalSet = await existingSchemaSlugs()
	const present = [...wanted.keys()].filter((slug) => finalSet.has(slug))
	const missing = [...wanted.keys()].filter((slug) => !finalSet.has(slug))
	log(`register import: ${present.length}/${wanted.size} learniq schemas now present` +
		(createdIndividually ? ` (${createdIndividually} created individually)` : ''))
	if (missing.length) warn(`still missing: ${missing.join(', ')}`)
	return { presentSlugs: new Set(present), missingSlugs: new Set(missing) }
}

// ── 3. Seed example objects ──────────────────────────────────────────────────
// Each entry: schemaSlug, a stable "marker" field+value to dedupe on, and the object body.
function uid(p) { return `${p}-${Date.now().toString(36)}` }

async function objectExists(slug, markerField, markerValue) {
	const r = await api('GET', `/index.php/apps/openregister/api/objects/${REGISTER_SLUG}/${slug}?${encodeURIComponent(markerField)}=${encodeURIComponent(markerValue)}&_limit=1`)
	const items = r.json?.results ?? r.json?.data ?? (Array.isArray(r.json) ? r.json : [])
	return items.length > 0 ? items[0] : null
}

async function createObject(slug, body) {
	const r = await api('POST', `/index.php/apps/openregister/api/objects/${REGISTER_SLUG}/${slug}`, body)
	if (!r.ok) { warn(`  ! create ${slug} failed (status ${r.status}): ${(r.text || '').slice(0, 160)}`); return null }
	return r.json
}

// Seed in dependency order. Returns a map slug -> first created object (for refs).
async function seedObjects(presentSlugs) {
	const created = {}
	const counts = {}
	async function seed(slug, marker, body) {
		if (!presentSlugs.has(slug)) { return null }
		const existing = await objectExists(slug, marker.field, marker.value)
		if (existing) { counts[slug] = (counts[slug] ?? 0) + 1; if (!created[slug]) created[slug] = existing; return existing }
		const obj = await createObject(slug, body)
		if (obj) { counts[slug] = (counts[slug] ?? 0) + 1; if (!created[slug]) created[slug] = obj }
		return obj
	}
	const id = (o) => o && (o.uuid ?? o.id ?? o['@self']?.uuid)
	// tenant_id must be a UUID. Use OR's active organisation if we can discover it;
	// otherwise a fixed demo UUID (OR may still reject it on multitenancy grounds —
	// then those creates 400 and we just log + continue).
	let TENANT = '00000000-0000-0000-0000-00000000d3a0'
	try {
		const meR = await api('GET', '/index.php/apps/openregister/api/registers?limit=1')
		const items = meR.json?.results ?? meR.json?.data ?? (Array.isArray(meR.json) ? meR.json : [])
		const org = items[0]?.organisation
		if (typeof org === 'string' && /^[0-9a-f-]{36}$/i.test(org)) { TENANT = org; log(`using active org as tenant_id: ${TENANT}`) }
	} catch { /* keep the fixed demo UUID */ }

	// Programme + CurriculumPlan
	const prog = await seed('programme', { field: 'code', value: 'DEMO-HBOV' }, {
		name: 'HBO-V bachelor (demo)', code: 'DEMO-HBOV', level: 'hbo', description: 'Demo nursing bachelor', tenant_id: TENANT,
	})
	const plan = await seed('curriculum-plan', { field: 'name', value: 'HBO-V curriculum (demo)' }, {
		name: 'HBO-V curriculum (demo)', kind: 'oer', formula: 'weighted-average',
		components: [{ componentId: 'c1', label: 'Exam', weight: 3, period: 'P1', kind: 'assessment' }, { componentId: 'c2', label: 'Essay', weight: 1, period: 'P1', kind: 'assignment' }],
		periods: [{ periodId: 'P1', label: 'Period 1', startDate: '2026-09-01', endDate: '2026-12-31' }], tenant_id: TENANT,
	})
	// Courses (one recursive)
	const courseRoot = await seed('course', { field: 'code', value: 'DEMO-ANAT' }, { code: 'DEMO-ANAT', name: 'Anatomy (demo)', level: 'hbo', language: 'nl', mandatoryTraining: false, tenant_id: TENANT, ...(id(plan) ? { curriculumPlanId: id(plan) } : {}), ...(id(prog) ? { programmeIds: [id(prog)] } : {}) })
	const courseSub = await seed('course', { field: 'code', value: 'DEMO-ANAT-1' }, { code: 'DEMO-ANAT-1', name: 'Anatomy — module 1 (demo)', level: 'hbo', language: 'nl', mandatoryTraining: false, tenant_id: TENANT, ...(id(courseRoot) ? { parentCourseId: id(courseRoot) } : {}) })
	const courseCompliance = await seed('course', { field: 'code', value: 'DEMO-AVG' }, { code: 'DEMO-AVG', name: 'AVG refresher (demo)', level: 'corporate', language: 'nl', mandatoryTraining: true, regulationSlug: 'AVG', tenant_id: TENANT })
	// Lessons
	let complianceLesson = null
	for (const [n, cid] of [[1, id(courseSub)], [2, id(courseSub)], [3, id(courseRoot)], [4, id(courseRoot)], [5, id(courseCompliance)]]) {
		if (!cid) continue
		const l = await seed('lesson', { field: 'name', value: `Demo lesson ${n}` }, { courseId: cid, name: `Demo lesson ${n}`, order: n, contentType: 'text', mandatoryTraining: n === 5, tenant_id: TENANT })
		if (n === 5) complianceLesson = l
	}
	// Cohort + LearnerProfiles
	const cohort = await seed('cohort', { field: 'name', value: 'Demo cohort 2026' }, { name: 'Demo cohort 2026', period: 'P1', academicYear: '2026', learnerIds: ['demo-learner-1', 'demo-learner-2', 'demo-learner-3'], tenant_id: TENANT, ...(id(courseRoot) ? { courseId: id(courseRoot) } : {}), ...(id(prog) ? { programmeId: id(prog) } : {}) })
	for (let n = 1; n <= 3; n++) {
		await seed('learner-profile', { field: 'ncUserId', value: `demo-learner-${n}` }, { ncUserId: `demo-learner-${n}`, givenName: `Demo${n}`, familyName: 'Learner', roles: n === 1 ? ['learner', 'manager'] : ['learner'], tenant_id: TENANT })
	}
	// Sessions
	let firstSession = null
	for (const [n, when] of [[1, '2026-09-07T10:00:00Z'], [2, '2026-09-14T10:00:00Z']]) {
		if (!id(cohort)) break
		const sess = await seed('session', { field: 'title', value: `Demo session ${n}` }, { cohortId: id(cohort), title: `Demo session ${n}`, startsAt: when, endsAt: when.replace('10:00', '12:00'), location: 'Room A', tenant_id: TENANT, ...(id(courseRoot) ? { courseId: id(courseRoot) } : {}) })
		if (n === 1) firstSession = sess
	}
	// Materials
	for (const n of [1, 2]) await seed('material', { field: 'title', value: `Demo material ${n}` }, { title: `Demo material ${n}`, kind: 'reading', fileRef: `demo://material/${n}`, order: n, tenant_id: TENANT, ...(id(courseRoot) ? { courseId: id(courseRoot) } : {}) })
	// Rubric + Assignments + Submissions
	const rubric = await seed('rubric', { field: 'name', value: 'Demo rubric' }, { name: 'Demo rubric', criteria: [{ criterionId: 'r1', label: 'Content', weight: 1, levels: [{ levelId: 'l1', label: 'Poor', points: 1 }, { levelId: 'l2', label: 'Good', points: 5 }] }], maxPoints: 5, tenant_id: TENANT })
	// `dueAt` is `format: date-time`, not `date`. A bare '2026-10-01' is rejected
	// with "should match format 'date-time'", which the seeder used to log and
	// swallow — so Assignment silently had zero rows while the index spec's
	// seeded-schema list still claimed it had some.
	const a1 = await seed('assignment', { field: 'title', value: 'Demo assignment 1' }, { title: 'Demo assignment 1', instructions: 'Write an essay.', dueAt: '2026-10-01T23:59:00Z', maxPoints: 10, allowLateSubmission: true, latePenaltyPercent: 10, tenant_id: TENANT, ...(id(courseRoot) ? { courseId: id(courseRoot) } : {}), ...(id(rubric) ? { rubricId: id(rubric) } : {}) })
	await seed('assignment', { field: 'title', value: 'Demo assignment 2' }, { title: 'Demo assignment 2', instructions: 'Group work.', dueAt: '2026-11-01T23:59:00Z', maxPoints: 10, groupSubmission: true, tenant_id: TENANT, ...(id(courseCompliance) ? { courseId: id(courseCompliance) } : {}) })
	if (id(a1)) { for (const ln of ['demo-learner-1', 'demo-learner-2']) await seed('submission', { field: 'assignmentId', value: id(a1) }, { assignmentId: id(a1), learnerIds: [ln], attachmentRefs: [`demo://sub/${ln}`], submittedAt: '2026-09-30', tenant_id: TENANT }) }
	// Assessment + Item + Result
	const item = await seed('item', { field: 'title', value: 'Demo MC item' }, { name: 'Demo MC item', title: 'Demo MC item', interactionType: 'choice', qtiBody: '<qti-assessment-item/>', correctResponse: { value: 'A' }, maxScore: 1, tenant_id: TENANT })
	const assess = await seed('assessment', { field: 'title', value: 'Demo quiz' }, { title: 'Demo quiz', scoringScheme: 'points', maxAttempts: 1, keepScore: 'last', tenant_id: TENANT, ...(id(item) ? { itemRefs: [{ itemId: id(item), points: 1 }] } : {}), ...(id(courseRoot) ? { courseId: id(courseRoot) } : {}) })
	if (id(assess)) await seed('assessment-result', { field: 'assessmentId', value: id(assess) }, { assessmentId: id(assess), learnerId: 'demo-learner-1', attemptNumber: 1, responses: [], startedAt: '2026-09-15T10:00:00Z', submittedAt: '2026-09-15T10:30:00Z', tenant_id: TENANT })
	// GradeScale + GradeEntries + FinalGrade.
	// GradeScale.kind, GradeEntry.curriculumPlanId/gradeScaleId and
	// FinalGrade.curriculumPlanId/gradeScaleId are all in the register's
	// `required` list; omitting them made every create 400 and left the whole
	// grading surface with zero rows.
	const scale = await seed('grade-scale', { field: 'name', value: 'NL 1-10 (demo)' }, { name: 'NL 1-10 (demo)', kind: 'numeric', tenant_id: TENANT })
	if (id(plan) && id(scale)) {
		for (let n = 1; n <= 3; n++) {
			await seed('grade-entry', { field: 'value', value: 6 + n }, {
				learnerId: 'demo-learner-1', curriculumPlanId: id(plan), gradeScaleId: id(scale),
				value: 6 + n, period: 'P1', componentId: 'c1', weight: 1, lifecycle: 'published', tenant_id: TENANT,
			})
		}
		await seed('final-grade', { field: 'learnerId', value: 'demo-learner-1' }, {
			learnerId: 'demo-learner-1', curriculumPlanId: id(plan), gradeScaleId: id(scale),
			value: 7.5, passed: true, tenant_id: TENANT, ...(id(courseRoot) ? { courseId: id(courseRoot) } : {}),
		})
	}
	// LearningPlan stack
	const lpt = await seed('learning-plan-template', { field: 'kind', value: 'opp' }, { name: 'OPP-VO template (demo)', kind: 'opp', goalDomains: ['leren-en-ontwikkeling'], requiredSignerRoles: ['learner', 'parent', 'coordinator'], tenant_id: TENANT })
	// `coordinatorId` is required on LearningPlan; `evaluatedAt`/`evaluatedBy` on
	// LearningPlanEvaluation; `subjectKind` on Signature. All three were omitted.
	const lp = await seed('learning-plan', { field: 'learnerId', value: 'demo-learner-2' }, { learnerId: 'demo-learner-2', kind: 'opp', coordinatorId: 'coordinator-1', period: '2026-2027', version: 1, goals: [{ goalId: 'g1', description: 'Improve reading', domain: 'leren-en-ontwikkeling', status: 'open' }], tenant_id: TENANT, ...(id(lpt) ? { templateId: id(lpt) } : {}), ...(id(cohort) ? { cohortId: id(cohort) } : {}) })
	if (id(lp)) { await seed('learning-plan-evaluation', { field: 'learningPlanId', value: id(lp) }, { learningPlanId: id(lp), narrative: 'First review', evaluatedAt: '2026-12-15', evaluatedBy: 'coordinator-1', nextReviewAt: '2027-01-15', tenant_id: TENANT }); await seed('signature', { field: 'subjectId', value: id(lp) }, { subjectKind: 'learning-plan', subjectId: id(lp), subjectVersion: 1, signerId: 'coordinator-1', signerRole: 'coordinator', signedAt: '2026-09-10T10:00:00Z', assuranceLevel: 'basic', method: 'click-to-confirm', tenant_id: TENANT }) }
	// Attendance stack
	const thr = await seed('attendance-threshold', { field: 'name', value: 'Leerplicht 16uur (demo)' }, { name: 'Leerplicht 16uur (demo)', kind: 'leerplicht-16uur', scope: 'per-learner', window: { type: 'rolling-weeks', weeks: 4 }, metric: 'unexcused-lesuren', limit: 16, lesuurMinutes: 60, onCross: { notify: true, notifyRoles: ['mentor', 'coordinator'], createFlag: true, dataExchangeTarget: 'leerplicht' }, active: true, tenant_id: TENANT })
	// `sessionId` is required on AttendanceRecord — without it all five creates
	// 400'd and the attendance surface had zero rows.
	for (let n = 1; n <= 5; n++) { if (!id(cohort) || !id(firstSession)) break; await seed('attendance-record', { field: 'learnerId', value: `demo-learner-${(n % 3) + 1}` }, { sessionId: id(firstSession), learnerId: `demo-learner-${(n % 3) + 1}`, status: n === 4 ? 'absent-unexcused' : 'present', minutesAttended: n === 4 ? 0 : 120, markedBy: 'teacher-1', markedAt: `2026-09-0${n}T12:00:00Z`, tenant_id: TENANT, ...(id(cohort) ? { cohortId: id(cohort) } : {}) }) }
	await seed('excuse-request', { field: 'learnerId', value: 'demo-learner-3' }, { learnerId: 'demo-learner-3', submittedBy: 'parent-3', dateFrom: '2026-09-08', dateTo: '2026-09-09', reason: 'illness', reasonKind: 'illness', submittedAuthLevel: 'substantial', tenant_id: TENANT })
	if (id(thr)) await seed('attendance-flag', { field: 'attendanceThresholdId', value: id(thr) }, { learnerId: 'demo-learner-2', attendanceThresholdId: id(thr), windowStart: '2026-09-01', windowEnd: '2026-09-28', metricValue: 16, breachingRecordIds: [], tenant_id: TENANT })
	// Compliance: Regulations, Attestations, Credentials, Enrolments.
	// `audienceScope` (Regulation) and `issuerDid`/`signature`/`openbadges3Payload`
	// (Credential) are required; Credential.learnerId is `format: uuid`, so the
	// LearnerProfile UUID is used rather than the NC user id.
	for (const slug of ['AVG', 'NIS2']) await seed('regulation', { field: 'slug', value: slug }, { slug, name: `${slug} (demo)`, audienceScope: 'all-employees', tenant_id: TENANT })
	for (let n = 1; n <= 2; n++) await seed('attestation', { field: 'learnerId', value: `demo-learner-${n}` }, { learnerId: `demo-learner-${n}`, lessonId: id(complianceLesson) ?? id(courseCompliance) ?? 'demo-lesson', courseId: id(courseCompliance) ?? 'demo-course', regulationSlug: 'AVG', score: 90, lifecycle: 'drafted', tenant_id: TENANT })
	for (let n = 1; n <= 2; n++) {
		const learner = created['learner-profile']
		await seed('credential', { field: 'issuedBy', value: `Conduction ${n}` }, {
			learnerId: id(learner) ?? TENANT,
			kind: 'certificate',
			issuedAt: '2026-09-01T09:00:00Z',
			issuerDid: 'did:web:demo.conduction.nl',
			signature: `demo-signature-${n}`,
			openbadges3Payload: { '@context': ['https://www.w3.org/ns/credentials/v2'], type: ['VerifiableCredential', 'OpenBadgeCredential'] },
			issuedBy: `Conduction ${n}`, source: 'system', regulationSlug: 'AVG', lifecycle: 'issued', tenant_id: TENANT,
			...(id(courseCompliance) ? { courseId: id(courseCompliance) } : {}),
		})
	}
	// `lifecycle: 'active'` on the first enrolment is load-bearing, not colour.
	// Enrolment.lifecycle DEFAULTS TO 'pending' and this seeder never set it, so
	// the instance held no active enrolment at all — which silently disabled
	// talk-classroom-spaces.spec.ts's "an enrolled learner sees the join-call
	// action" scenario: its `lifecycle === 'active'` guard matched nothing and
	// the test SKIPPED on every green run rather than failing. The schema calls
	// the field engine-managed ("do not set directly"), but this is fixture data
	// for a scenario that is *about* an enrolled learner, and this file already
	// seeds lifecycle directly elsewhere (published / drafted / issued / queued).
	// The Sessions above are seeded against the same `cohort`, so the active
	// enrolment and a Session genuinely share a cohortId.
	for (let n = 1; n <= 2; n++) await seed('enrolment', { field: 'learnerId', value: `demo-learner-${n}` }, { learnerId: `demo-learner-${n}`, courseId: id(courseCompliance) ?? id(courseRoot) ?? 'demo-course', mandatory: n === 1, dueDate: '2026-12-01', source: 'bulk', lifecycle: n === 1 ? 'active' : 'pending', tenant_id: TENANT, ...(id(cohort) ? { cohortId: id(cohort) } : {}) })
	// xAPI, DataExchange. (AiFeature governance is delegated to Hermiq — learniq seeds no AiFeature objects.)
	// `stored` (XapiStatement), `direction`/`sourceSchema` (DataMappingProfile)
	// and `requestedAt` (DataExchangeJob) are required and were all missing.
	await seed('xapi-statement', { field: 'verb', value: 'completed' }, { actor: { account: { name: 'demo-learner-1' } }, verb: { id: 'http://adlnet.gov/expapi/verbs/completed' }, object: { id: 'demo://lesson/5' }, stored: '2026-09-30T10:00:01Z', version: '1.0.3', timestamp: '2026-09-30T10:00:00Z', tenant_id: TENANT })
	await seed('data-mapping-profile', { field: 'name', value: 'Demo BRON mapping' }, { name: 'Demo BRON mapping', target: 'bron-rod', direction: 'export', sourceSchema: 'enrolment', tenant_id: TENANT })
	await seed('data-exchange-job', { field: 'target', value: 'bron-rod' }, { direction: 'export', target: 'bron-rod', scope: { cohortId: id(cohort) ?? null }, lifecycle: 'queued', requestedBy: 'admin', requestedAt: '2026-09-30T10:00:00Z', tenant_id: TENANT })

	// Portfolio + PortfolioEntry — the third schema OpenRegister 500s on. Seeded
	// so its index page has a row once the allOf-strip retry lands it.
	const portfolio = await seed('portfolio', { field: 'learnerId', value: 'demo-learner-1' }, { learnerId: 'demo-learner-1', kind: 'personal', title: 'Demo portfolio', tenant_id: TENANT })
	if (id(portfolio)) {
		await seed('portfolio-entry', { field: 'title', value: 'Demo reflection' }, {
			portfolioId: id(portfolio), learnerId: 'demo-learner-1', title: 'Demo reflection',
			evidenceKind: 'reflection', reflectionText: 'What I learned this period.', tenant_id: TENANT,
		})
	}

	return counts
}

// ── main ─────────────────────────────────────────────────────────────────────
// The register declares 118 schemas (this constant said 35 — it predates most of
// them and made every ratio in the log wrong).
const TOTAL_SCHEMAS = Object.keys(loadRegister().components?.schemas ?? {}).length
const FULL_IMPORT_THRESHOLD = Math.floor(TOTAL_SCHEMAS * 0.9) // ≥ 90% imported = "complete enough" → exit 0

/**
 * Record which schemas actually have rows after seeding, keyed by the register's
 * schema NAME (`Course`, `GradeEntry`, …) — the same value `manifest.pages[].
 * config.schema` carries.
 *
 * ⚠️ It lives in `.e2e-state/`, NOT `test-results/`. Playwright's
 * `createRemoveOutputDirsTask` deletes every project `outputDir` at the start of
 * the run — after the workflow's seed step, before globalSetup — so a marker
 * written into test-results/ by ci-seed.sh is gone before any spec can read it.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * index-pages.spec.ts used to carry a hand-written `SEEDED_SCHEMAS` set of 32
 * names and assert "≥1 row" for each. The seeder actually produced rows for 17.
 * The other 15 creates were failing validation, being logged as a warning, and
 * swallowed — so the spec asserted rows for schemas that provably had none, and
 * the list had no mechanism to notice it had drifted. Deriving the set from what
 * the seeder REALLY created makes the seeder the single source of truth: fix a
 * fixture and coverage grows automatically; break one and it shrinks visibly
 * (ci-seed.sh gates on the floor).
 *
 * @param {object} counts        Per-slug object counts from seedObjects().
 * @param {object} schemasByName The register's `components.schemas` map.
 */
function writeSeededManifest(counts, schemasByName) {
	const slugToName = new Map(Object.entries(schemasByName).map(([name, s]) => [s.slug, name]))
	const byName = {}
	for (const [slug, count] of Object.entries(counts)) {
		if (count > 0) byName[slugToName.get(slug) ?? slug] = count
	}
	const outDir = join(REPO_ROOT, '.e2e-state')
	try {
		mkdirSync(outDir, { recursive: true })
		writeFileSync(join(outDir, 'seeded-schemas.json'), JSON.stringify(byName, null, '\t'))
		log(`wrote .e2e-state/seeded-schemas.json (${Object.keys(byName).length} schemas with rows)`)
	} catch (e) {
		warn(`could not write .e2e-state/seeded-schemas.json: ${e}`)
	}
}

async function main() {
	if (!(await pingNc())) process.exit(1)
	const { presentSlugs } = await importRegister()
	if (presentSlugs.size === 0) {
		warn('no learniq schemas present in OR after import — index pages will be empty. (openregister#1487)')
		process.exit(2) // partial — e2e specs run smoke checks only
	}
	const counts = await seedObjects(presentSlugs)
	const total = Object.values(counts).reduce((a, b) => a + b, 0)
	log(`seeded/verified ${total} objects across ${Object.keys(counts).length} schemas: ${JSON.stringify(counts)}`)
	writeSeededManifest(counts, loadRegister().components?.schemas ?? {})
	if (presentSlugs.size < FULL_IMPORT_THRESHOLD) {
		warn(`only ${presentSlugs.size}/${TOTAL_SCHEMAS} schemas imported (OpenRegister register-import gap — openregister#1487). ` +
			`Seeded what was possible; e2e specs will run smoke checks only until the full register imports.`)
		process.exit(2)
	}
	log(`register fully imported (${presentSlugs.size}/${TOTAL_SCHEMAS}) and example data seeded.`)
	process.exit(0)
}

main().catch((e) => { console.error('[seed] fatal:', e); process.exit(1) })
