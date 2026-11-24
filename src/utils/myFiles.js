// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Files from the signed-in user's own Nextcloud files, as browser File
 * objects: the paths the file picker gave are read over WebDAV in her own
 * session, so a learner can hand in work she keeps in her files
 * (capability-rows-say-what-they-do 2.5).
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/assignments/spec.md#requirement-a-learner-hands-in-a-file-from-their-own-nextcloud-files
 */

/**
 * The WebDAV address of a file in a user's files.
 *
 * @param {string} remoteBase The DAV root, `generateRemoteUrl('dav')`.
 * @param {string} uid The user id.
 * @param {string} path The path in her files, as the picker gives it (`/Documenten/verslag.pdf`).
 * @return {string} The address, every segment encoded.
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/assignments/spec.md#requirement-a-learner-hands-in-a-file-from-their-own-nextcloud-files
 */
export function davFileUrl(remoteBase, uid, path) {
	const segments = String(path || '').split('/').filter((part) => part !== '' && part !== '.' && part !== '..')
	return String(remoteBase).replace(/\/+$/, '') + '/files/' + encodeURIComponent(uid) + '/' + segments.map(encodeURIComponent).join('/')
}

/**
 * The file's own name: the last segment of its path.
 *
 * @param {string} path The path.
 * @return {string} The name.
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/assignments/spec.md#requirement-a-learner-hands-in-a-file-from-their-own-nextcloud-files
 */
export function fileNameOf(path) {
	const parts = String(path || '').split('/').filter((part) => part !== '')
	return parts.length > 0 ? parts[parts.length - 1] : 'file'
}

/**
 * Read the picked files from the user's files.
 *
 * @param {string|string[]} paths What the picker gave: one path or several.
 * @param {{ http: object, remoteBase: string, uid: string }} context The transport, the DAV root and the user.
 * @return {Promise<File[]>} One File per path, typed as the server answered.
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/assignments/spec.md#requirement-a-learner-hands-in-a-file-from-their-own-nextcloud-files
 */
export async function filesFromMyFiles(paths, { http, remoteBase, uid }) {
	const list = (Array.isArray(paths) ? paths : [paths]).filter((path) => typeof path === 'string' && path !== '')
	return Promise.all(list.map(async (path) => {
		const response = await http.get(davFileUrl(remoteBase, uid, path), { responseType: 'blob' })
		const blob = response.data
		const type = (blob && blob.type) || (response.headers && response.headers['content-type']) || ''
		return new File([blob], fileNameOf(path), { type })
	}))
}
