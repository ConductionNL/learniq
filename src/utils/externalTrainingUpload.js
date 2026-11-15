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
 * The delimiter of a CSV file: a semicolon when its first line holds more
 * semicolons than commas, else a comma.
 *
 * @param {string} source The file contents.
 * @return {string} `;` or `,`.
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-spreadsheet-import-of-external-training
 */
function csvDelimiter(source) {
	const firstLine = source.split(/\r?\n/, 1)[0] ?? ''
	const semicolons = (firstLine.match(/;/g) ?? []).length
	return semicolons > (firstLine.match(/,/g) ?? []).length ? ';' : ','
}

/**
 * Close the cell being read and start the next one.
 *
 * @param {{row: string[], cell: string}} run The reader.
 * @return {void}
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-spreadsheet-import-of-external-training
 */
function endCell(run) {
	run.row.push(run.cell)
	run.cell = ''
}

/**
 * Close the row being read at a line break; a CRLF counts as one break.
 *
 * @param {{source: string, at: number, rows: string[][], row: string[], cell: string}} run The reader.
 * @return {void}
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-spreadsheet-import-of-external-training
 */
function endRow(run) {
	if (run.source[run.at] === '\r' && run.source[run.at + 1] === '\n') {
		run.at++
	}
	endCell(run)
	run.rows.push(run.row)
	run.row = []
}

/**
 * Read one character inside quotes: a doubled quote is a quote, a single
 * one closes the quotes.
 *
 * @param {{source: string, at: number, cell: string, quoted: boolean}} run The reader.
 * @return {void}
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-spreadsheet-import-of-external-training
 */
function readQuotedChar(run) {
	const char = run.source[run.at]
	if (char !== '"') {
		run.cell += char
	} else if (run.source[run.at + 1] === '"') {
		run.cell += '"'
		run.at++
	} else {
		run.quoted = false
	}
}

/**
 * Read one character outside quotes.
 *
 * @param {{source: string, at: number, delimiter: string, rows: string[][], row: string[], cell: string, quoted: boolean}} run The reader.
 * @return {void}
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-spreadsheet-import-of-external-training
 */
function readPlainChar(run) {
	const char = run.source[run.at]
	if (char === '"') {
		run.quoted = true
	} else if (char === run.delimiter) {
		endCell(run)
	} else if (char === '\n' || char === '\r') {
		endRow(run)
	} else {
		run.cell += char
	}
}

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
	const run = { source, delimiter: csvDelimiter(source), rows: [], row: [], cell: '', quoted: false, at: 0 }
	for (; run.at < source.length; run.at++) {
		if (run.quoted) {
			readQuotedChar(run)
		} else {
			readPlainChar(run)
		}
	}
	endCell(run)
	run.rows.push(run.row)

	return run.rows.filter((cells) => cells.some((value) => value.trim() !== ''))
}

/**
 * Turn a CSV into rows keyed by column, recognising each heading by its aliases.
 *
 * @param {string} text The file contents.
 * @param {{columns: Array<{key: string, heading: string, aliases: string[]}>, required: string[]}} layout
 *   The columns a row carries, and the keys of the columns a file cannot do without.
 * @return {{rows: object[], missing: string[], ignored: string[], keys: Array<string|null>}} The rows,
 *   the required columns the header lacks (by heading), the headings that were not recognised,
 *   and the column key each heading was recognised as (null when it was not).
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-spreadsheet-import-of-external-training
 */
export function rowsByColumns(text, { columns, required }) {
	const [header = [], ...body] = parseCsv(text)
	const keys = header.map((heading) => {
		const normal = heading.trim().toLowerCase()
		return columns.find((column) => column.aliases.includes(normal))?.key ?? null
	})

	const missing = required.filter((key) => !keys.includes(key)).map(
		(key) => columns.find((column) => column.key === key).heading,
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

	return { rows, missing, ignored, keys }
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
	const { rows, missing, ignored } = rowsByColumns(text, { columns: COLUMNS, required: REQUIRED })
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
 * One CSV line of quoted cells.
 *
 * @param {Array<unknown>} values The values.
 * @return {string} The line.
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#scenario-the-officer-fixes-the-failed-rows
 */
function csvLine(values) {
	return values.map(csvCell).join(',')
}

/**
 * The line of one failed row: its original columns and the reason.
 *
 * @param {object} row The row that was posted.
 * @param {object} line Its row in the server's report.
 * @return {string} The CSV line.
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#scenario-the-officer-fixes-the-failed-rows
 */
function failedRowLine(row, line) {
	return csvLine([...COLUMNS.map((column) => row[column.key]), reasonText(line)])
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
	const lines = [csvLine([...COLUMNS.map((column) => column.heading), 'reason'])]
	for (const line of (Array.isArray(report) ? report : []).filter(isFailed)) {
		lines.push(failedRowLine(rows[line.row - 1] ?? {}, line))
	}
	return lines.join('\r\n') + '\r\n'
}
