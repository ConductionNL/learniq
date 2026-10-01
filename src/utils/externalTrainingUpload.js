// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The spreadsheet upload on the external training page
// (compliance-external-training-spreadsheet-upload). The browser reads the
// CSV and posts plain rows; ExternalTrainingImport on the server checks every
// row, in a dry run for the preview and again on confirm. Pure helpers, no Vue
// and no Nextcloud imports, so node --test covers them.

/** The import route, ExternalTrainingController::import. */
export const IMPORT_URL = '/apps/learniq/api/external-training/import'

/**
 * The columns a row carries, in the order the failed-rows file writes them,
 * with the headings a provider's list may use for each (English and Dutch).
 *
 * @type {Array<{key: string, heading: string, aliases: string[]}>}
 */
export const COLUMNS = [
	{
		key: 'learner',
		heading: 'learner',
		aliases: [
			'learner',
			'email',
			'e-mail',
			'learner id',
			'learner reference',
			'personal number',
			'leerling',
			'deelnemer',
			'medewerker',
			'personeelsnummer',
		],
	},
	{
		key: 'title',
		heading: 'title',
		aliases: ['title', 'training', 'course', 'titel', 'cursus', 'opleiding'],
	},
	{
		key: 'provider',
		heading: 'provider',
		aliases: ['provider', 'aanbieder', 'opleider'],
	},
	{ key: 'kind', heading: 'kind', aliases: ['kind', 'type', 'soort'] },
	{
		key: 'completedAt',
		heading: 'completed on',
		aliases: [
			'completed on',
			'completedat',
			'completed',
			'date',
			'afgerond op',
			'behaald op',
			'datum',
		],
	},
	{
		key: 'validUntil',
		heading: 'valid until',
		aliases: [
			'valid until',
			'validuntil',
			'expires',
			'geldig tot',
			'vervalt op',
		],
	},
	{
		key: 'regulationSlug',
		heading: 'regulation',
		aliases: ['regulation', 'regulationslug', 'regeling', 'wet'],
	},
	{
		key: 'evidenceNote',
		heading: 'evidence note',
		aliases: [
			'evidence note',
			'evidencenote',
			'note',
			'opmerking',
			'toelichting',
		],
	},
]

/** Columns a file cannot do without. */
const REQUIRED = ['learner', 'title', 'provider', 'completedAt']

/** Row statuses the server reports that count as failed. */
const FAILED = ['unmatched', 'invalid']

/**
 * Split CSV text into rows of cells (RFC 4180 quoting). The delimiter is a
 * comma or, as a Dutch spreadsheet saves it, a semicolon: whichever the
 * first line holds more of.
 *
 * @param {string} text The file contents.
 * @return {string[][]} The rows, empty lines dropped.
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-spreadsheet-import-of-external-training
 */
export function parseCsv(text) {
	const source = String(text ?? '').replace(/^\uFEFF/, '')
	const firstLine = source.split(/\r?\n/, 1)[0] ?? ''
	const delimiter =
		(firstLine.match(/;/g) ?? []).length > (firstLine.match(/,/g) ?? []).length
			? ';'
			: ','

	const rows = []
	let row = []
	let cell = ''
	let quoted = false
	for (let i = 0; i < source.length; i++) {
		const char = source[i]
		if (quoted) {
			if (char === '"' && source[i + 1] === '"') {
				cell += '"'
				i++
			} else if (char === '"') {
				quoted = false
			} else {
				cell += char
			}
			continue
		}
		if (char === '"') {
			quoted = true
		} else if (char === delimiter) {
			row.push(cell)
			cell = ''
		} else if (char === '\n' || char === '\r') {
			if (char === '\r' && source[i + 1] === '\n') {
				i++
			}
			row.push(cell)
			rows.push(row)
			row = []
			cell = ''
		} else {
			cell += char
		}
	}
	row.push(cell)
	rows.push(row)

	return rows.filter((cells) => cells.some((value) => value.trim() !== ''))
}

/**
 * Turn a parsed CSV into the rows the import route takes.
 *
 * @param {string} text The file contents.
 * @return {{rows: object[], missing: string[], ignored: string[]}} The rows, the required
 *   columns the header lacks (by heading), and the headings that were not recognised.
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-spreadsheet-import-of-external-training
 */
export function rowsFromCsv(text) {
	const [header = [], ...body] = parseCsv(text)
	const keys = header.map((heading) => {
		const normal = heading.trim().toLowerCase()
		return COLUMNS.find((column) => column.aliases.includes(normal))?.key ?? null
	})

	const missing = REQUIRED.filter((key) => !keys.includes(key)).map(
		(key) => COLUMNS.find((column) => column.key === key).heading,
	)
	const ignored = header.filter(
		(heading, index) => keys[index] === null && heading.trim() !== '',
	)

	const rows = body.map((cells) => {
		const row = {}
		keys.forEach((key, index) => {
			if (key !== null && row[key] === undefined) {
				row[key] = (cells[index] ?? '').trim()
			}
		})
		return row
	})

	return { rows, missing, ignored }
}

/**
 * Whether a reported row failed and belongs in the failed-rows file.
 *
 * @param {object} line A row of the server's report.
 * @return {boolean}
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-import-result-report
 */
export function isFailed(line) {
	return FAILED.includes(line?.status)
}

/**
 * A row's reason with its placeholders filled in (English, for the file).
 *
 * @param {object} line A row of the server's report.
 * @return {string} The reason, or ''.
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-import-result-report
 */
export function reasonText(line) {
	const params = line?.reasonParams ?? {}
	return String(line?.reason ?? '').replace(/\{(\w+)\}/g, (whole, name) =>
		name in params ? String(params[name]) : whole,
	)
}

/**
 * A CSV cell: quoted, and a leading formula character neutralised so a
 * spreadsheet opens the file as text.
 *
 * @param {unknown} value The value.
 * @return {string} The cell.
 */
function csvCell(value) {
	let text = String(value ?? '')
	if (/^[=+\-@\t\r]/.test(text)) {
		text = `'${text}`
	}
	return `"${text.replace(/"/g, '""')}"`
}

/**
 * The failed rows as a CSV the officer can correct and upload again: the
 * original columns plus a `reason` column, which the upload ignores.
 *
 * @param {object[]} rows The rows that were posted, in order.
 * @param {object[]} report The server's per-row report (`row` is 1-based).
 * @return {string} The CSV, header first; only the header when nothing failed.
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#scenario-the-officer-fixes-the-failed-rows
 */
export function failedRowsCsv(rows, report) {
	const lines = [
		[...COLUMNS.map((column) => column.heading), 'reason']
			.map(csvCell)
			.join(','),
	]
	for (const line of Array.isArray(report) ? report : []) {
		if (!isFailed(line)) {
			continue
		}
		const row = rows[line.row - 1] ?? {}
		lines.push(
			[...COLUMNS.map((column) => row[column.key]), reasonText(line)]
				.map(csvCell)
				.join(','),
		)
	}
	return lines.join('\r\n') + '\r\n'
}

/**
 * Counts for the preview header: rows by what will happen to them.
 *
 * @param {object[]} report The server's per-row report.
 * @return {{ready: number, skipped: number, failed: number}}
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#scenario-an-officer-uploads-a-providers-attendance-list
 */
export function previewCounts(report) {
	const counts = { ready: 0, skipped: 0, failed: 0 }
	for (const line of Array.isArray(report) ? report : []) {
		if (line?.status === 'ready' || line?.status === 'created') {
			counts.ready++
		} else if (isFailed(line)) {
			counts.failed++
		} else {
			counts.skipped++
		}
	}
	return counts
}
