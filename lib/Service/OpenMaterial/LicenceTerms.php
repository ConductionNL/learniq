<?php

/**
 * Learniq Licence Terms
 *
 * Reads a normalised licence code (`CC-BY-4.0`, `CC-BY-NC-ND-3.0`, `CC0-1.0`,
 * `PDM`, `rights-reserved`, `unknown`) and answers what it allows.
 *
 * @category Service
 * @package  OCA\Learniq\Service\OpenMaterial
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\OpenMaterial;

/**
 * What a normalised licence code allows.
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
 */
class LicenceTerms {

	/**
	 * The public-domain codes: open, without conditions.
	 */
	private const PUBLIC_DOMAIN = ['CC0-1.0', 'PDM'];

	/**
	 * A Creative Commons BY licence in its 3.0 or 4.0 form.
	 */
	private const CC_BY_PATTERN = '/^CC-BY(-SA|-NC|-NC-SA|-ND|-NC-ND)?-(3\.0|4\.0)$/';

	/**
	 * Whether the code is an open licence: CC0, PDM or a CC BY variant.
	 *
	 * @param string $licence The normalised licence code.
	 *
	 * @return bool True when open.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
	 */
	public function isOpen(string $licence): bool {
		return in_array($licence, self::PUBLIC_DOMAIN, true) === true
			|| preg_match(self::CC_BY_PATTERN, $licence) === 1;
	}//end isOpen()

	/**
	 * Whether the licence forbids derivatives (ND).
	 *
	 * @param string $licence The normalised licence code.
	 *
	 * @return bool True for an ND licence.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
	 */
	public function forbidsDerivatives(string $licence): bool {
		return $this->isOpen(licence: $licence) === true && str_contains($licence, '-ND-') === true;
	}//end forbidsDerivatives()

	/**
	 * Whether the licence forbids commercial use (NC).
	 *
	 * @param string $licence The normalised licence code.
	 *
	 * @return bool True for an NC licence.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
	 */
	public function forbidsCommercialUse(string $licence): bool {
		return $this->isOpen(licence: $licence) === true && str_contains($licence, '-NC') === true;
	}//end forbidsCommercialUse()

	/**
	 * The code a Material stores: the licence itself when open, otherwise `other`.
	 *
	 * @param string $licence The normalised licence code.
	 *
	 * @return string The code for Material.license.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
	 */
	public function storedCode(string $licence): string {
		if ($this->isOpen(licence: $licence) === true) {
			return $licence;
		}

		return 'other';
	}//end storedCode()
}//end class
