/**
 * The `workspace` half of the manifest runtime: which kind of organisation
 * this instance serves, published at `manifest.runtime.workspace.segment` so a
 * menu `visibleIf: {"workspace.segment": …}` resolves against a defined value.
 *
 * The server resolves the segment (SegmentService) and hands it over as the
 * `segment` initial state; this module only validates it and places it.
 *
 * 🔴 AN UNDEFINED RUNTIME PATH HIDES THE ITEM FOR EVERYONE. The shared
 * library's `visibleIf` fail-safe treats a missing runtime value as "not
 * matched", so a missing or garbled segment must become the default here
 * rather than reach the manifest as `undefined`.
 *
 * Plain ES module (not a .vue SFC) so it is directly importable from a Node
 * test runner without a build step.
 *
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-the-segment-reaches-the-manifest-runtime
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * The six segment codes, in schema enum order. Must match
 * `LearniqSettings.segment.enum` in lib/Settings/learniq_register.json and
 * `SegmentService::SEGMENTS`; tests on both sides compare against the schema.
 *
 * @type {ReadonlyArray<string>}
 */
export const SEGMENTS = Object.freeze([
	'po',
	'vo',
	'mbo',
	'he',
	'corporate',
	'training',
])

/**
 * The no-behaviour-change segment, the schema default.
 *
 * @type {string}
 */
export const DEFAULT_SEGMENT = 'corporate'

/**
 * Validate a segment code from the server.
 *
 * @param {unknown} raw The value `loadState()` returned.
 * @return {string} A known segment code, or DEFAULT_SEGMENT.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-the-segment-reaches-the-manifest-runtime
 */
export function resolveSegment(raw) {
	return typeof raw === 'string' && SEGMENTS.includes(raw) ? raw : DEFAULT_SEGMENT
}

/**
 * Validate the chosen segment from the server.
 *
 * `null` means nobody chose: the install runs on the default and keeps every
 * menu. A menu that hides for a chosen company declares
 * `visibleIf: {"workspace.chosenSegment": {"notIn": ["corporate"]}}`, which
 * passes for `null` (decision D26).
 *
 * @param {unknown} raw The value `loadState('learniq', 'chosenSegment', …)` returned.
 * @return {string|null} A known segment code, or null.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-the-page-tells-a-chosen-segment-apart-from-the-default
 */
export function resolveChosenSegment(raw) {
	return typeof raw === 'string' && SEGMENTS.includes(raw) ? raw : null
}

/**
 * Build `runtime.workspace`, keeping any key the bundled manifest already set.
 *
 * @param {object|undefined} existing The bundled manifest's `runtime.workspace`, if any.
 * @param {unknown} rawSegment The value `loadState('learniq', 'segment', …)` returned.
 * @param {unknown} rawChosen The value `loadState('learniq', 'chosenSegment', …)` returned.
 * @return {{segment: string, chosenSegment: (string|null)}} The workspace runtime.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-the-segment-reaches-the-manifest-runtime
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-the-page-tells-a-chosen-segment-apart-from-the-default
 */
export function buildWorkspaceRuntime(existing, rawSegment, rawChosen = null) {
	return {
		...(existing && typeof existing === 'object' ? existing : {}),
		segment: resolveSegment(rawSegment),
		chosenSegment: resolveChosenSegment(rawChosen),
	}
}
