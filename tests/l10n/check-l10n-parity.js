#!/usr/bin/env node
/**
 * l10n translation-PARITY gate.
 *
 * Guards that every REQUIRED locale carries a real translation for every
 * English source key. Without this, a new English string ships and the other
 * languages silently fall back to English with a green pipeline — the app
 * slowly stops "fully supporting" those languages.
 *
 * The required set is the official language of every European country plus
 * Russian and Turkish (ISO 639-1). Override with L10N_REQUIRED_LOCALES.
 *
 * For BOTH translation sets that exist in the app:
 *   • frontend  l10n/en.js   (OC.L10N.register)  -> l10n/<locale>.js
 *   • backend   l10n/en.json ({ translations })  -> l10n/<locale>.json
 * it asserts, for every required locale:
 *   1. the locale file exists,
 *   2. it contains every key present in the English source (no MISSING keys),
 *   3. no value is empty / whitespace-only (no UNTRANSLATED placeholders);
 *      for plural arrays, no element may be empty.
 *
 * Values identical to English are allowed (cognates / proper nouns / acronyms
 * are legitimately the same) and only counted.
 *
 * Sparse override locales (en, en_US and any other regional en_*) are skipped.
 *
 * Dependency-free pure Node so CI can run it in a bare node container:
 *   node tests/l10n/check-l10n-parity.js
 *
 * Exit codes:
 *   0  every required locale is at full parity for every existing source set
 *   1  one or more locales are missing keys, missing files, or empty values
 *
 * RATCHET MODE (`--ratchet`, what `npm run check:l10n` runs)
 *
 * Full parity is the goal, not today's state: most locales translate under a
 * quarter of the catalogue, so the full gate above would be red on every PR.
 * The ratchet holds the line instead, against l10n/.l10n-parity-baseline.json:
 *   • STRICT locales (L10N_STRICT_LOCALES, default `nl`) may gain no new gap.
 *     A new English key without its Dutch value fails, naming the key.
 *   • Every other required locale may not LOSE a translation: its count of
 *     translated English keys may not drop below the baseline.
 *   • The down path fails too. A strict gap that closed, or a locale that
 *     gained translations, fails until `--update` records the tighter
 *     baseline, so the margin cannot be spent quietly by the next change.
 * `--update` rewrites the baseline from the current tree.
 *
 * Ratchet exit codes: 0 at the baseline; 1 worse, better, or no baseline.
 *
 * @spec openspec/changes/archive/2026-09-29-wire-l10n-parity-ci-gate/tasks.md#task-3
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

const fs = require('fs')
const path = require('path')
const vm = require('vm')

const ROOT = process.cwd()
const L10N_DIR = path.join(ROOT, 'l10n')

// Official language of every European country (ISO 639-1) + Russian + Turkish.
// nl/de/fr/es/it lead (the original supported set); then the EU-24 remainder,
// wider-Europe national languages, and micro-state / co-official nationals.
const EUROPEAN = [
	'nl', 'de', 'fr', 'es', 'it',
	'bg', 'hr', 'cs', 'da', 'et', 'fi', 'el', 'hu', 'ga', 'lv', 'lt', 'mt',
	'pl', 'pt', 'ro', 'sk', 'sl', 'sv',
	'sq', 'is', 'nb', 'sr', 'bs', 'mk', 'uk', 'be', 'ru', 'tr',
	'ca', 'lb', 'rm',
].join(',')

function readJson (p) {
	return JSON.parse(fs.readFileSync(p, 'utf8'))
}

const appId = process.env.L10N_APP_ID
	|| (fs.existsSync(path.join(ROOT, 'package.json'))
		? readJson(path.join(ROOT, 'package.json')).name
		: null)

const REQUIRED = (process.env.L10N_REQUIRED_LOCALES || EUROPEAN)
	.split(',').map((s) => s.trim()).filter(Boolean)

if (!fs.existsSync(L10N_DIR)) {
	console.error(`l10n-parity: no l10n/ directory at ${L10N_DIR}`)
	process.exit(2)
}

/** Load an OC.L10N.register(...) .js file into its translations object. */
function loadJs (file) {
	const code = fs.readFileSync(file, 'utf8')
	let captured = null
	const sandbox = { OC: { L10N: { register: (id, obj) => { captured = obj } } } }
	vm.createContext(sandbox)
	vm.runInContext(code, sandbox, { filename: file, timeout: 5000 })
	return captured || {}
}

/** Load an l10n .json file into its translations object. */
function loadJsonSet (file) {
	return readJson(file).translations || {}
}

/** True when a translation value is empty (string) or has an empty plural. */
function isEmpty (v) {
	if ((v === null || v === undefined)) {
		return true
	}
	if (Array.isArray(v)) {
		return v.length === 0 || v.some((e) => typeof e !== 'string' || e.trim() === '')
	}
	return typeof v !== 'string' || v.trim() === ''
}

const sets = [
	{
		kind: 'frontend (.js)',
		enFile: path.join(L10N_DIR, 'en.js'),
		file: (loc) => path.join(L10N_DIR, `${loc}.js`),
		load: loadJs,
	},
	{
		kind: 'backend (.json)',
		enFile: path.join(L10N_DIR, 'en.json'),
		file: (loc) => path.join(L10N_DIR, `${loc}.json`),
		load: loadJsonSet,
	},
]

const failures = []
let checkedSets = 0

// Per set, per locale: the English keys with no usable translation.
// A missing or unparseable file has every key as a gap.
const observed = {}

for (const set of sets) {
	if (!fs.existsSync(set.enFile)) {
		continue // this app does not ship this translation set
	}
	checkedSets++
	const enKeys = Object.keys(set.load(set.enFile))
	observed[set.kind] = { total: enKeys.length, gaps: {} }
	for (const loc of REQUIRED) {
		const locFile = set.file(loc)
		observed[set.kind].gaps[loc] = enKeys
		if (!fs.existsSync(locFile)) {
			failures.push({ set: set.kind, loc, kind: 'MISSING FILE', detail: path.relative(ROOT, locFile) })
			continue
		}
		let locObj
		try {
			locObj = set.load(locFile)
		} catch (e) {
			failures.push({ set: set.kind, loc, kind: 'UNPARSEABLE', detail: e.message })
			continue
		}
		const missing = enKeys.filter((k) => !Object.hasOwn(locObj, k))
		const empty = enKeys.filter((k) => Object.hasOwn(locObj, k) && isEmpty(locObj[k]))
		observed[set.kind].gaps[loc] = missing.concat(empty)
		if (missing.length || empty.length) {
			failures.push({ set: set.kind, loc, kind: 'INCOMPLETE', missing, empty, total: enKeys.length })
		}
	}
}

const label = appId ? `[${appId}]` : ''
console.log(`l10n-parity ${label}: ${REQUIRED.length} required locales; checked ${checkedSets} translation set(s)`)

if (checkedSets === 0) {
	console.log('l10n-parity: no en.js / en.json source set found — nothing to check')
	process.exit(0)
}

if (process.argv.includes('--ratchet') || process.argv.includes('--update')) {
	process.exit(ratchet(observed))
}

if (failures.length === 0) {
	console.log('l10n-parity: OK — every required locale is at full parity (no missing keys, no empty values)')
	process.exit(0)
}

console.error('\nl10n-parity: FAIL — required language support is incomplete:')
for (const f of failures) {
	if (f.kind === 'MISSING FILE') {
		console.error(`  • ${f.set} ${f.loc}: locale file missing (${f.detail})`)
	} else if (f.kind === 'UNPARSEABLE') {
		console.error(`  • ${f.set} ${f.loc}: cannot parse (${f.detail})`)
	} else {
		console.error(`  • ${f.set} ${f.loc}: ${f.missing.length} missing key(s), `
			+ `${f.empty.length} empty value(s) of ${f.total}`)
		for (const k of f.missing.slice(0, 8)) {
			console.error(`      missing: ${JSON.stringify(k)}`)
		}
		if (f.missing.length > 8) {
			console.error(`      … +${f.missing.length - 8} more missing`)
		}
		for (const k of f.empty.slice(0, 4)) {
			console.error(`      empty:   ${JSON.stringify(k)}`)
		}
	}
}
console.error('\nEvery required locale must translate every English source key. '
	+ 'Add the missing/empty translations to the locale file(s) above.')
process.exit(1)

/**
 * Compare today's gaps with the committed baseline, or rewrite it.
 *
 * @param {object} current Per set: { total, gaps: { locale: string[] } }.
 * @return {number} The exit code.
 */
function ratchet (current) {
	const baselineFile = path.join(L10N_DIR, '.l10n-parity-baseline.json')
	const strict = (process.env.L10N_STRICT_LOCALES || 'nl')
		.split(',').map((s) => s.trim()).filter(Boolean)
	const snapshot = { strict, sets: {} }
	for (const [kind, set] of Object.entries(current)) {
		const entry = { strictGaps: {}, translated: {} }
		for (const [loc, gaps] of Object.entries(set.gaps)) {
			if (strict.includes(loc)) {
				entry.strictGaps[loc] = [...new Set(gaps)].sort()
			} else {
				entry.translated[loc] = set.total - new Set(gaps).size
			}
		}
		snapshot.sets[kind] = entry
	}

	if (process.argv.includes('--update')) {
		const doc = {
			$comment: 'l10n parity ratchet (tests/l10n/check-l10n-parity.js --ratchet). Strict locales list every English key they lack; other locales record how many English keys they translate. Rewrite with: node tests/l10n/check-l10n-parity.js --update',
			...snapshot,
		}
		fs.writeFileSync(baselineFile, JSON.stringify(doc, null, '\t') + '\n')
		console.log(`l10n-parity: baseline written to ${path.relative(ROOT, baselineFile)}`)
		return 0
	}

	if (!fs.existsSync(baselineFile)) {
		console.error('l10n-parity: no baseline. Run `node tests/l10n/check-l10n-parity.js --update` and commit it.')
		return 1
	}

	const baseline = readJson(baselineFile)
	const worse = []
	const better = []
	for (const [kind, entry] of Object.entries(snapshot.sets)) {
		const base = (baseline.sets || {})[kind]
		if (!base) {
			worse.push(`${kind}: no baseline for this translation set`)
			continue
		}
		for (const [loc, gaps] of Object.entries(entry.strictGaps)) {
			const known = new Set((base.strictGaps || {})[loc] || [])
			const added = gaps.filter((k) => !known.has(k))
			const closed = [...known].filter((k) => !gaps.includes(k))
			if (added.length) {
				worse.push(`${kind} ${loc}: ${added.length} English key(s) with no ${loc} translation:`
					+ added.slice(0, 20).map((k) => `\n      ${JSON.stringify(k)}`).join('')
					+ (added.length > 20 ? `\n      … +${added.length - 20} more` : ''))
			}
			if (closed.length) {
				better.push(`${kind} ${loc}: ${closed.length} gap(s) closed`)
			}
		}
		for (const [loc, count] of Object.entries(entry.translated)) {
			const was = (base.translated || {})[loc]
			if (was === undefined) {
				worse.push(`${kind} ${loc}: no baseline for this locale`)
			} else if (count < was) {
				worse.push(`${kind} ${loc}: translates ${count} English key(s), baseline ${was} (${was - count} lost)`)
			} else if (count > was) {
				better.push(`${kind} ${loc}: translates ${count}, baseline ${was}`)
			}
		}
	}

	if (worse.length) {
		console.error('\nl10n-parity: FAIL — language support went backwards:')
		worse.forEach((w) => console.error(`  • ${w}`))
		console.error(`\nAdd the ${strict.join('/')} value for every new English key, and do not drop existing translations.`
			+ '\nIf a key was removed from English on purpose, record it with: node tests/l10n/check-l10n-parity.js --update')
		return 1
	}

	if (better.length) {
		console.error('\nl10n-parity: FAIL — better than the baseline; lock it in:')
		better.forEach((b) => console.error(`  • ${b}`))
		console.error('\nRun `node tests/l10n/check-l10n-parity.js --update` and commit l10n/.l10n-parity-baseline.json.')
		return 1
	}

	console.log(`l10n-parity: OK — at the baseline (strict: ${strict.join(', ')}; no locale lost a translation)`)
	return 0
}
