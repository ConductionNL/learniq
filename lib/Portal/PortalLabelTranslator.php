<?php

/**
 * Learniq portal label translator
 *
 * Puts the visible words of a portal manifest into the reader's language.
 * Portaliq shows a contribution's `label`, `submitLabel` and `successMessage`
 * strings exactly as the app sends them: its normaliser keeps a column label
 * only when it is a plain string, so a per-language map would be dropped.
 * The app therefore answers in the reader's language itself, through its own
 * catalogue (l10n/<lang>.json). Nextcloud picks that language per request
 * from the browser's Accept-Language when the portal visitor has no account,
 * so a guardian on a Dutch browser reads "Mijn kinderen" instead of
 * "My children".
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use OCP\IL10N;

/**
 * Translates the visible strings of a portal manifest, and nothing else.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
class PortalLabelTranslator {

	/**
	 * The manifest keys whose string value a reader sees.
	 *
	 * Identifiers, field names, schema slugs and `labelField` (a field NAME)
	 * are never translated: portaliq matches on them.
	 *
	 * @var array<int, string>
	 */
	private const VISIBLE_KEYS = [
		'label',
		'submitLabel',
		'successMessage',
		'unit',
		'fallback',
		'group',
		'otherLabel',
		'requiredMessage',
		'buttonLabel',
		'template',
		'eyebrow',
		'soonLabel',
		'noteLabel',
		// The heading and help text of the messages form (portal-message-contacts).
		'composeLabel',
		'composeHint',
	];

	/**
	 * Keys a reader sees only inside a calendar source (portal-parent-child-record):
	 * elsewhere `kind` is a machine value (`inbox`, `timedTask`).
	 *
	 * @var array<int, string>
	 */
	private const SOURCE_KEYS = ['kind', 'title'];

	/**
	 * The manifest key whose map VALUES a reader sees: how a stored value
	 * reads (portaliq contribution-value-labels). Its keys are stored values
	 * and never move.
	 *
	 * @var string
	 */
	private const VALUE_LABELS_KEY = 'valueLabels';

	/**
	 * Keys whose value may be a word in two forms, `{one, other}`, both of
	 * which a reader sees: a figure card's unit and a detail's label
	 * (portaliq kpi-unit-singular-and-plural).
	 *
	 * @var array<int, string>
	 */
	private const COUNTED_KEYS = ['unit', 'label'];

	/**
	 * The keys whose children are read in a context named after the key.
	 */
	private const NESTED_CONTEXTS = ['sources', 'values', 'phrases', 'confirmation'];

	/**
	 * The two forms of a counted word.
	 *
	 * @var array<int, string>
	 */
	private const COUNTED_FORMS = ['one', 'other'];

	/**
	 * Constructor.
	 *
	 * @param IL10N|null $l10n Learniq's catalogue in the reader's language, or null to keep the English source.
	 */
	public function __construct(
		private readonly ?IL10N $l10n=null,
	) {
	}//end __construct()

	/**
	 * The manifest with every visible string in the reader's language.
	 *
	 * @param array<array-key, mixed> $manifest The manifest, in English.
	 * @param string $context Where the manifest sits: '' at the top, `sources`, `source`, `values` or `counted` below.
	 *
	 * @return array<array-key, mixed> The same manifest, its visible strings translated.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function translate(array $manifest, string $context=''): array {
		if ($this->l10n === null) {
			return $manifest;
		}

		foreach ($manifest as $key => $value) {
			if ($key === self::VALUE_LABELS_KEY && is_array($value) === true) {
				$manifest[$key] = $this->translateValueLabels(labels: $value);
				continue;
			}

			if (is_array($value) === true) {
				$manifest[$key] = $this->translate(manifest: $value, context: $this->contextOf(key: $key, context: $context));
				continue;
			}

			if (is_string($value) === true && $this->isVisible(key: $key, context: $context) === true) {
				$manifest[$key] = $this->l10n->t($value);
			}
		}

		return $manifest;
	}//end translate()

	/**
	 * The context the children of a key are read in: `sources` items are
	 * calendar sources, `values` holds a lookup's labels by value, and a
	 * `unit` or `label` map holds a counted word's singular and plural.
	 *
	 * @param int|string $key The key of the nested array.
	 * @param string $context The context of its parent.
	 *
	 * @return string
	 */
	private function contextOf(int|string $key, string $context): string {
		if (in_array($key, self::NESTED_CONTEXTS, true) === true) {
			return (string)$key;
		}

		// A summary's phrases are maps of answer to words, one per field; a
		// calendar's sources are a list of sources.
		$byParent = ['phrases' => 'values', 'sources' => 'source'];
		if (isset($byParent[$context]) === true && ($context === 'phrases' || is_int($key) === true)) {
			return $byParent[$context];
		}

		if (is_string($key) === true && in_array($key, self::COUNTED_KEYS, true) === true) {
			return 'counted';
		}

		return '';
	}//end contextOf()

	/**
	 * Whether a string value under this key is one a reader sees.
	 *
	 * @param int|string $key The key.
	 * @param string $context Where the key sits.
	 *
	 * @return bool
	 */
	private function isVisible(int|string $key, string $context): bool {
		if ($context === 'values') {
			return true;
		}

		if ($context === 'counted') {
			return in_array($key, self::COUNTED_FORMS, true) === true;
		}

		if ($context === 'confirmation') {
			return in_array($key, ['title', 'body', 'next'], true) === true;
		}

		if (is_string($key) === false) {
			return false;
		}

		return in_array($key, self::VISIBLE_KEYS, true) === true
			|| ($context === 'source' && in_array($key, self::SOURCE_KEYS, true) === true);
	}//end isVisible()

	/**
	 * A `valueLabels` map with every string label in the reader's language;
	 * the stored values it is keyed by stay as they are.
	 *
	 * @param array<array-key, mixed> $labels The map, in English.
	 *
	 * @return array<array-key, mixed> The same map, its labels translated.
	 *
	 * @spec openspec/changes/parent-portal-value-labels/specs/portal-contribution/spec.md
	 */
	private function translateValueLabels(array $labels): array {
		foreach ($labels as $value => $label) {
			if (is_string($label) === true && $this->l10n !== null) {
				$labels[$value] = $this->l10n->t($label);
			}
		}

		return $labels;
	}//end translateValueLabels()
}//end class
