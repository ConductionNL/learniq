<?php

/**
 * Learniq ProcessingActivityCatalogue
 *
 * The AVG Art. 30 processing activities learniq declares, read from the
 * `x-openregister-processing` annotations in learniq_register.json, so the
 * admin page lists what the register declares instead of a hand-kept copy.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCP\IL10N;

/**
 * Lists the declared processing activities, one per code.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
class ProcessingActivityCatalogue {

	private const ANNOTATION = 'x-openregister-processing';

	/**
	 * Constructor.
	 *
	 * @param IL10N       $l10n         Translates names, purposes and legal bases.
	 * @param string|null $registerPath The register file; learniq_register.json when null.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly ?string $registerPath = null,
	) {
	}//end __construct()

	/**
	 * The declared activities in register order, the first declaration of a code winning.
	 *
	 * @return array<int, array{code: string, name: string, purpose: string, basis: string}>
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function activities(): array {
		$path = ($this->registerPath ?? __DIR__ . '/../Settings/learniq_register.json');
		if (is_readable($path) === false) {
			return [];
		}

		$raw = file_get_contents($path);
		if ($raw === false) {
			return [];
		}

		$decoded = json_decode($raw, true);
		$schemas = [];
		if (is_array($decoded) === true && is_array($decoded['components']['schemas'] ?? null) === true) {
			$schemas = $decoded['components']['schemas'];
		}

		$activities = [];
		foreach ($schemas as $schema) {
			$annotation = $schema[self::ANNOTATION] ?? null;
			$code       = $annotation['code'] ?? null;
			if (is_array($annotation) === false || is_string($code) === false || isset($activities[$code]) === true) {
				continue;
			}

			$activities[$code] = [
				'code' => $code,
				'name' => $this->l10n->t((string)($annotation['naam'] ?? $code)),
				'purpose' => $this->l10n->t((string)($annotation['doelbinding'] ?? '')),
				'basis' => $this->basisLabel(basis: (string)($annotation['rechtsgrond'] ?? '')),
			];
		}

		return array_values($activities);
	}//end activities()

	/**
	 * The words for a legal basis (AVG article 6).
	 *
	 * @param string $basis The declared basis.
	 *
	 * @return string
	 */
	private function basisLabel(string $basis): string {
		return match ($basis) {
			'consent' => $this->l10n->t('Consent'),
			'contract' => $this->l10n->t('Contract'),
			'legal-obligation' => $this->l10n->t('Legal obligation'),
			'vital-interest' => $this->l10n->t('Vital interest'),
			'public-task' => $this->l10n->t('Public task'),
			'legitimate-interest' => $this->l10n->t('Legitimate interest'),
			default => $basis,
		};
	}//end basisLabel()
}//end class
