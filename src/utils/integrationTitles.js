// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Manifest titles for integration cards that name their title prop
 * `titleLabel`.
 *
 * CnDetailWidgetHost hands every `type: "integration"` widget its manifest
 * title as the `title` prop. Most cards read `title` (CnIntegrationCard,
 * CnTalkCard, ...), so they show it. nextcloud-vue's CnContactsCard and
 * CnContactmomentCard name the prop `titleLabel` instead, so `title` falls
 * through as a plain HTML attribute and the card shows its own default:
 * LearnerProfileDetail's "Contact card" leaf rendered as "Contacts".
 *
 * Boot therefore copies each such widget's title into `props.titleLabel`,
 * which the host spreads onto the card after `title`. An explicit
 * `props.titleLabel` in the manifest wins. Once the library card reads
 * `title` itself, this becomes a no-op and can go.
 *
 * @spec exclude shim for a nextcloud-vue prop-name mismatch (CnContactsCard reads titleLabel, the host passes title); no learniq requirement covers widget titles
 */

/**
 * Integration ids whose card reads its heading from `titleLabel`.
 *
 * @type {Readonly<Record<string, string>>}
 */
export const TITLE_PROP_BY_INTEGRATION = Object.freeze({
	contacts: 'titleLabel',
	contactmoment: 'titleLabel',
})

/**
 * Give every integration widget whose card reads `titleLabel` its manifest
 * title under that prop. Other widgets, and a widget with no title, are left
 * as they are.
 *
 * @param {object} manifest The merged manifest (mutated in place and returned).
 * @return {object} The same manifest.
 * @spec exclude shim for a nextcloud-vue prop-name mismatch (CnContactsCard reads titleLabel, the host passes title); no learniq requirement covers widget titles
 */
export function applyIntegrationTitles(manifest) {
	for (const page of manifest?.pages ?? []) {
		const widgets = page?.config?.widgets
		if (!Array.isArray(widgets)) {
			continue
		}
		for (const widget of widgets) {
			const prop =
				widget?.type === 'integration'
					? TITLE_PROP_BY_INTEGRATION[widget.integrationId]
					: undefined
			if (!prop || typeof widget.title !== 'string' || widget.title === '') {
				continue
			}
			const props =
				widget.props
				&& typeof widget.props === 'object'
				&& !Array.isArray(widget.props)
					? widget.props
					: {}
			if (props[prop] !== undefined) {
				continue
			}
			widget.props = { ...props, [prop]: widget.title }
		}
	}
	return manifest
}
