// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Write shots/<id>/side-by-side.html: every screenshot of a portal next to
 * the board it stands for (school-design/<id>/preview/<Board>.png). A pixel
 * diff is not the gate: data and fonts differ slightly, so a person looks.
 *
 *   node tests/e2e/portal-design/side-by-side.mjs [school-design folder]
 */

import { existsSync, readdirSync, writeFileSync } from 'node:fs'
import { join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = fileURLToPath(new URL('.', import.meta.url))
const shots = join(here, 'shots')
const designs =
	process.argv[2]
	|| process.env.PORTAL_DESIGN_DIR
	|| '/home/rubenlinde/memcap-work/school-design'

if (!existsSync(shots)) {
	console.error(`no screenshots in ${shots}: run the suite first`)
	process.exit(1)
}

for (const id of readdirSync(shots, { withFileTypes: true })
	.filter((d) => d.isDirectory())
	.map((d) => d.name)) {
	const dir = join(shots, id)
	const rows = readdirSync(dir)
		.filter((f) => f.endsWith('.png'))
		.sort()
		.map((file) => {
			const board = file
				.replace(/-(desktop|phone)\.png$/, '')
				.replace(/\.png$/, '')
				.replace(/-signed-out$/, '')
			const reference = join(designs, id, 'preview', `${board}.png`)
			const ref = existsSync(reference)
				? `<img src="${relative(dir, reference)}" alt="Board ${board}">`
				: '<p>No board with this name.</p>'
			return `<section><h2>${file}</h2><div class="pair"><figure><figcaption>Board</figcaption>${ref}</figure><figure><figcaption>Rendered</figcaption><img src="${file}" alt="Rendered ${board}"></figure></div></section>`
		})
	const html = `<!doctype html><html lang="nl"><head><meta charset="utf-8"><title>${id}: board and rendered page</title>
<style>body{font-family:system-ui,sans-serif;margin:16px}.pair{display:grid;grid-template-columns:1fr 1fr;gap:16px}img{max-width:100%;border:1px solid #ccc}</style></head>
<body><h1>${id}</h1>${rows.join('\n')}</body></html>\n`
	writeFileSync(join(dir, 'side-by-side.html'), html)
	console.log(
		`wrote ${join(dir, 'side-by-side.html')} (${rows.length} screenshots)`,
	)
}
