<?php

/**
 * Learniq Material From Record
 *
 * Builds the linked Material object for a source record and the decision on it. The
 * attribution (makers, licence, source, source address) always comes from the
 * record the server looked up, never from the browser.
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
 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\OpenMaterial;

/**
 * Record plus decision to Material fields.
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
 */
class MaterialFromRecord {

	/**
	 * Constructor.
	 *
	 * @param LicenceTerms $terms Reads what a licence code allows.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
	 */
	public function __construct(
		private readonly LicenceTerms $terms = new LicenceTerms(),
	) {
	}//end __construct()

	/**
	 * The Material fields for one record.
	 *
	 * @param array<string, mixed> $record The normalised source record.
	 * @param array<string, mixed> $added  decision (ImportDecision), target (fields), and file (fileRef, mimeType) for a copy.
	 *
	 * @return array<string, mixed> The Material object.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
	 */
	public function build(array $record, array $added): array {
		$material = array_merge($this->attribution(record: $record), $added['target']);
		$material['title']         = (string)($record['title'] ?? '');
		$material['nonCommercial'] = $added['decision']->isNonCommercial();
		$material['kind']          = 'link';
		$material['url']           = $material['sourceUrl'];
		if (isset($added['file']) === true) {
			$material['kind']    = self::kindOf(mimeType: $added['file']['mimeType']);
			$material['fileRef'] = $added['file']['fileRef'];
		}

		return $material;
	}//end build()

	/**
	 * The Material kind of a copied file, by its media type.
	 *
	 * @param string $mimeType The file's media type.
	 *
	 * @return string video, slides or document.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-material-carries-its-source-and-attribution
	 */
	public static function kindOf(string $mimeType): string {
		$mimeType = strtolower($mimeType);
		if (str_starts_with($mimeType, 'video/') === true) {
			return 'video';
		}

		if (str_contains($mimeType, 'presentation') === true || str_contains($mimeType, 'powerpoint') === true) {
			return 'slides';
		}

		return 'document';
	}//end kindOf()

	/**
	 * The attribution fields, which are always stored.
	 *
	 * @param array<string, mixed> $record The normalised source record.
	 *
	 * @return array<string, mixed> source, sourceId, sourceUrl, sourceCollection, authors, license, accessRights.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
	 */
	public function attribution(array $record): array {
		return [
			'source'           => (string)($record['source'] ?? OpenMaterialSourceClient::SOURCE),
			'sourceId'         => (string)($record['sourceId'] ?? ''),
			'sourceUrl'        => (string)($record['url'] ?? ''),
			'sourceCollection' => ($record['collection'] ?? null),
			'authors'          => array_values(array_map('strval', (array)($record['authors'] ?? []))),
			'license'          => $this->terms->storedCode(licence: (string)($record['license'] ?? 'unknown')),
			'accessRights'     => ($record['accessRights'] ?? null),
		];
	}//end attribution()
}//end class
