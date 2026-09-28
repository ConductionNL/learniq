// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Pure helpers behind the AI-translated strings section of the learniq admin
// settings (ai-translated-catalogue-review, decision D24): filtering and paging
// the review list, and showing a plural value as one line.

/** Rows per page on the review list. */
export const PAGE_SIZE = 50

/**
 * A catalogue value as one line: a plural shows its forms joined by " / ".
 *
 * @param {string|Array<string>|null|undefined} value The catalogue value.
 * @return {string}
 */
export function reviewText(value) {
	if (Array.isArray(value)) {
		return value.join(' / ')
	}
	return value === null || value === undefined ? '' : String(value)
}

/**
 * The items whose key, English source or Dutch value contains the query,
 * case-insensitively. An empty query keeps everything.
 *
 * @param {Array<{key: string, source: (string|Array<string>), value: (string|Array<string>|null)}>} items The review list.
 * @param {string} query The filter text.
 * @return {Array<object>}
 */
export function filterReviewItems(items, query) {
	const needle = String(query || '')
		.trim()
		.toLowerCase()
	if (needle === '') {
		return items
	}
	return items.filter((item) =>
		[item.key, reviewText(item.source), reviewText(item.value)].some((text) =>
			String(text).toLowerCase().includes(needle),
		),
	)
}

/**
 * One page of a list.
 *
 * @param {Array<object>} items The list.
 * @param {number} page The zero-based page.
 * @return {Array<object>}
 */
export function pageOf(items, page) {
	const start = Math.max(0, page) * PAGE_SIZE
	return items.slice(start, start + PAGE_SIZE)
}
