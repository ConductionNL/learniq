/**
 * Apply a Reports card's `visibleIf` before the page renders.
 *
 * `CnReportsPage` in @conduction/nextcloud-vue renders every `config.cards`
 * entry and has no `visibleIf` of its own (checked on 2.57.1), while
 * `CnAppNav` and `CnNavCardGrid` do evaluate it. A card declared
 * `visibleIf: {"workspace.chosenSegment": {"notIn": ["corporate"]}}` would
 * therefore still show for a company. This drops such cards from the
 * manifest, using the library's own evaluator so the semantics cannot drift.
 * When the library honours card `visibleIf` itself, this module can go and
 * the manifest stays as it is.
 *
 * Plain ES module (not a .vue SFC) so it is directly importable from a Node
 * test runner without a build step.
 *
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-the-company-segment-hides-the-school-only-menus
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import { passesContextPredicates } from '@conduction/nextcloud-vue/src/utils/visibleIfContext.js'

/**
 * Whether one Reports card renders against the runtime.
 *
 * @param {object} card A `config.cards` entry.
 * @param {object|null|undefined} runtime The manifest runtime.
 * @return {boolean} True when the card has no gate or its gate passes.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-the-company-segment-hides-the-school-only-menus
 */
export function reportCardPasses(card, runtime) {
	if (!card || typeof card.visibleIf !== 'object' || card.visibleIf === null) {
		return true
	}
	return passesContextPredicates(card.visibleIf, runtime)
}

/**
 * Drop the Reports cards whose `visibleIf` fails, on every `type: "reports"`
 * page. Other pages and cards without a gate are returned untouched, and the
 * kept cards keep their order.
 *
 * @param {object} manifest The manifest, with `runtime` already built.
 * @return {object} A manifest whose Reports pages carry only visible cards.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-the-company-segment-hides-the-school-only-menus
 */
export function applyReportCardGates(manifest) {
	if (!manifest || !Array.isArray(manifest.pages)) {
		return manifest
	}
	const runtime = manifest.runtime
	return {
		...manifest,
		pages: manifest.pages.map((page) => {
			if (page?.type !== 'reports' || !Array.isArray(page.config?.cards)) {
				return page
			}
			return {
				...page,
				config: {
					...page.config,
					cards: page.config.cards.filter((card) =>
						reportCardPasses(card, runtime),
					),
				},
			}
		}),
	}
}
