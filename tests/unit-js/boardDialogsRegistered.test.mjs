// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Every board dialog lane LQB-C adds is a registry modal a manifest action can
// open (`type: "open-modal"`), and is open by being mounted: CnAppRoot mounts
// it with the action's props and nothing else, so a root that waits for an
// `open` prop would render nothing and say nothing.
//
// @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = fileURLToPath(new URL('../..', import.meta.url))
const registry = readFileSync(`${ROOT}src/registry.js`, 'utf8')

const DIALOGS = [
	'AccessibilityBarrierDialog',
	'CohortCreateDialog',
	'ConcernReportDialog',
	'ConfidentialNoteCreateDialog',
	'DossierNoteCreateDialog',
	'GradeEntryCreateDialog',
]

for (const name of DIALOGS) {
	test(`${name} is a registry modal that opens on mount`, () => {
		assert.match(registry, new RegExp(`import ${name} from './dialogs/${name}\\.vue'`))
		assert.match(registry, new RegExp(`\\t${name}: \\{\\n\\t\\tkind: 'modal',\\n\\t\\tcomponent: ${name},`))

		const source = readFileSync(`${ROOT}src/dialogs/${name}.vue`, 'utf8')
		assert.match(source, /<CnDialog\n\t\t:open="true"/)
		assert.doesNotMatch(source, /v-if="open"/)
		assert.match(source, /emits: \['close'\]/)
	})
}
