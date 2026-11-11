// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The rows of one object-store collection, or an empty list when the store
 * cannot list collections, the read fails, or the answer is not a list.
 *
 * @param {object} store the object store
 * @param {{type: string, query: object}} request the object type and its query
 * @return {Promise<Array>}
 */
export async function collectionOrEmpty(store, { type, query }) {
	if (typeof store.fetchCollection !== 'function') {
		return []
	}
	const rows = await store.fetchCollection(type, query).catch(() => [])
	return Array.isArray(rows) ? rows : []
}
