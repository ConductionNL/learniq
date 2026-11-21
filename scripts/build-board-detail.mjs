#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// build-board-detail.mjs — write the board detail pattern into the manifest.
//
// A detail page that declares `config.board` gets its tab strip, its history
// tab and side card, and its single layout cell from src/utils/boardDetail.js.
// The result is written into src/manifest.d/*.json, so the manifest holds what
// renders. Only fragments that change are written.
//
// Usage:
//   node scripts/build-board-detail.mjs           rewrite the fragments
//   node scripts/build-board-detail.mjs --check   exit 1 when one is out of date

import { readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { expandBoardDetailPages } from '../src/utils/boardDetail.js'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const dir = resolve(root, 'src/manifest.d')
const check = process.argv.includes('--check')
const stale = []

for (const file of readdirSync(dir).filter((name) => name.endsWith('.json')).sort()) {
	const path = resolve(dir, file)
	const text = readFileSync(path, 'utf8')
	const fragment = JSON.parse(text)
	if (!(fragment.pages ?? []).some((page) => page?.config?.board)) {
		continue
	}
	const next = JSON.stringify(expandBoardDetailPages(fragment), null, '\t') + '\n'
	if (next === text) {
		continue
	}
	stale.push(file)
	if (!check) {
		writeFileSync(path, next)
	}
}

if (check && stale.length > 0) {
	console.error(`board-detail: out of date, run node scripts/build-board-detail.mjs: ${stale.join(', ')}`)
	process.exit(1)
}
console.log(check ? 'board-detail: up to date' : `board-detail: wrote ${stale.length} fragment(s)`)
