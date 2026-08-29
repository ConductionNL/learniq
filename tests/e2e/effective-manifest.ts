// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * The effective (post-`buildManifest`) manifest: `src/manifest.json` merged
 * with every `src/manifest.d/*.json` fragment via `src/menu-layout.json`,
 * assembled exactly the way `src/main.js` assembles it at runtime (ADR-037
 * fragment pipeline).
 *
 * Since `manifest-fragment-split`, `src/manifest.json` alone only carries
 * four utility menu singles and their four pages — the other 271 pages live
 * in `manifest.d/*.json`. A spec that imports `src/manifest.json` directly
 * and iterates `manifest.pages` (or looks a route up in it) now sees a
 * near-empty page table instead of the full 275; for a spec that GENERATES
 * one `test()` per page at module-load time, that failure mode is silent —
 * the run reports fewer tests, not a red one. Specs needing the full page/
 * menu table MUST import `effectiveManifest` from here instead.
 *
 * `require.context` (webpack, used by `src/main.js`) has no Node
 * equivalent, so fragment discovery here is done with `fs.readdirSync` +
 * a sort, which is the same enumeration `require.context('./manifest.d/',
 * false, /\.json$/).keys().sort()` performs at build time.
 */
// Deep import (not the package barrel): the barrel re-exports components
// that pull in `@nextcloud/vue`, whose `package.json` declares an `exports`
// map with no unconditional `main` — resolving it outside a bundler throws
// `ERR_PACKAGE_PATH_NOT_EXPORTED`. `buildManifest.js` itself has no such
// dependency, so importing it directly sidesteps the problem entirely.
import { buildManifest } from '@conduction/nextcloud-vue/dist/esm/utils/buildManifest.js'
import * as fs from 'fs'
import * as path from 'path'
import baseManifest from '../../src/manifest.json'
import menuLayout from '../../src/menu-layout.json'

const FRAGMENT_DIR = path.resolve(__dirname, '..', '..', 'src', 'manifest.d')

/**
 * Read and parse every `src/manifest.d/*.json` fragment, sorted by
 * filename — mirroring `require.context(...).keys().sort()`.
 *
 * @return {Array<object>} The parsed fragment objects, in filename order.
 */
function loadFragments(): Array<Record<string, unknown>> {
	return fs
		.readdirSync(FRAGMENT_DIR)
		.filter((file) => file.endsWith('.json'))
		.sort()
		.map((file) =>
			JSON.parse(fs.readFileSync(path.join(FRAGMENT_DIR, file), 'utf8')),
		)
}

/**
 * The merged manifest — computed once at module load (specs that generate
 * `test()` calls per page need this to be synchronous and ready before test
 * collection runs).
 */
export const effectiveManifest = buildManifest(
	baseManifest,
	loadFragments(),
	menuLayout,
) as {
	pages: Array<{
		id: string
		route: string
		type?: string
		component?: string
		title?: string
		config?: Record<string, unknown>
	}>
	menu: Array<Record<string, unknown>>
}
