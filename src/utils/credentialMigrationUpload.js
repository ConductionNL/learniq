// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The certificate upload on the certificates page
// (credentials-migrate-with-validity). The browser reads the CSV with the
// external-training upload's parser and posts plain rows;
// CredentialMigrationService on the server checks every row, in a dry run for
// the preview and again on "Take over". Pure helpers, no Vue and no Nextcloud
// imports, so node --test covers them.

import { rowsByColumns } from './externalTrainingUpload.js'

/** The migration route, CredentialMigrationController::migrate. */
export const MIGRATE_URL = '/apps/learniq/api/credentials/migrate'

/**
 * The columns a row carries, with the headings an export from the previous
 * system may use for each (English and Dutch).
 *
 * @type {Array<{key: string, heading: string, aliases: string[]}>}
 */
export const COLUMNS = [
	{
		key: 'learner',
		heading: 'learner',
		aliases: ['learner', 'email', 'e-mail', 'learner id', 'personal number', 'medewerker', 'deelnemer', 'personeelsnummer', 'e-mailadres'],
	},
	{ key: 'course', heading: 'course code', aliases: ['course code', 'course', 'cursuscode', 'cursus', 'opleidingscode'] },
	{ key: 'regulation', heading: 'regulation', aliases: ['regulation', 'regeling', 'verplichting'] },
	{ key: 'issuedAt', heading: 'issued on', aliases: ['issued on', 'issuedat', 'issued', 'behaald op', 'uitgegeven op', 'afgegeven op'] },
	{ key: 'expiresAt', heading: 'valid until', aliases: ['valid until', 'expiresat', 'expires', 'geldig tot', 'vervalt op'] },
	{ key: 'legacyNumber', heading: 'certificate number', aliases: ['certificate number', 'old number', 'legacynumber', 'certificaatnummer', 'oud nummer'] },
	{ key: 'legacyIssuer', heading: 'issuing body', aliases: ['issuing body', 'issuer', 'legacyissuer', 'uitgevende instantie', 'uitgever'] },
	{ key: 'kind', heading: 'kind', aliases: ['kind', 'type', 'soort'] },
]

/** Columns a file cannot do without; a course code or a regulation is checked per row. */
const REQUIRED = ['learner', 'issuedAt', 'legacyNumber']

/**
 * Turn a parsed CSV into the rows the migration route takes.
 *
 * @param {string} text The file contents.
 * @return {{rows: object[], missing: string[], ignored: string[]}} The rows, the required
 *   columns the header lacks (by heading), and the headings that were not recognised.
 * @spec openspec/changes/credentials-migrate-with-validity/specs/certification/spec.md#requirement-certificates-from-the-previous-system-are-uploaded-with-a-dry-run-first
 */
export function migrationRowsFromCsv(text) {
	const { rows, missing, ignored, keys } = rowsByColumns(text, { columns: COLUMNS, required: REQUIRED })
	if (!keys.includes('course') && !keys.includes('regulation')) {
		missing.push('course code / regulation')
	}

	return { rows, missing, ignored }
}

/**
 * Whether the preview has rows worth taking over.
 *
 * @param {object|null} report The server's dry-run report.
 * @return {boolean}
 * @spec openspec/changes/credentials-migrate-with-validity/specs/certification/spec.md#requirement-certificates-from-the-previous-system-are-uploaded-with-a-dry-run-first
 */
export function canTakeOver(report) {
	return Boolean(report && report.commit === false && (report.summary?.ready ?? 0) > 0)
}
