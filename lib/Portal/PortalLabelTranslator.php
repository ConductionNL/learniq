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
	private const VISIBLE_KEYS = ['label', 'submitLabel', 'successMessage'];

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
	 *
	 * @return array<array-key, mixed> The same manifest, its visible strings translated.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function translate(array $manifest): array {
		if ($this->l10n === null) {
			return $manifest;
		}

		foreach ($manifest as $key => $value) {
			if (is_array($value) === true) {
				$manifest[$key] = $this->translate(manifest: $value);
				continue;
			}

			if (is_string($key) === true && is_string($value) === true
				&& in_array($key, self::VISIBLE_KEYS, true) === true
			) {
				$manifest[$key] = $this->l10n->t($value);
			}
		}

		return $manifest;
	}//end translate()
}//end class
