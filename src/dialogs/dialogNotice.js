// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * One refusal or result banner in a board dialog, drawn above the footer
 * buttons. The library's CnDialog draws it through its `notices` prop once
 * the installed version takes that prop; until then the dialog draws the same
 * notice itself, so it never shows twice and never shows nowhere.
 *
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */

import { CnDialog } from '@conduction/nextcloud-vue'
import { refusalNotice } from './dialogBodies.js'

/**
 * Whether the installed CnDialog draws `notices` itself.
 *
 * @param {object} [dialog] The dialog component, CnDialog by default.
 * @return {boolean} True once the library takes the prop.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export function dialogDrawsNotices(dialog = CnDialog) {
	return Boolean(dialog?.props && Object.hasOwn(dialog.props, 'notices'))
}

/**
 * The props to bind on CnDialog for one notice: empty while the library does
 * not draw notices, so nothing unknown falls through to NcDialog.
 *
 * @param {object|null} notice The notice, or null for none.
 * @param {object} [dialog] The dialog component, CnDialog by default.
 * @return {object} The props.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export function dialogNoticeProps(notice, dialog = CnDialog) {
	if (!dialogDrawsNotices(dialog)) {
		return {}
	}
	return { notices: notice ? [notice] : [], noticesPlacement: 'bottom' }
}

/**
 * The notice for a failed request: the server's own sentence when it sent
 * one, else `fallback`.
 *
 * @param {unknown} error The axios error.
 * @param {string} title The bold first line.
 * @param {string} fallback The sentence when the server said nothing.
 * @return {object} The notice.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export function requestRefusal(error, title, fallback) {
	const data = error?.response?.data
	return refusalNotice(title, data?.error || data?.message || fallback)
}
