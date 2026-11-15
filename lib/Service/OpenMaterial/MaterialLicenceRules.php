<?php

/**
 * Learniq Material Licence Rules
 *
 * Decides from a normalised source record alone whether it is added as a
 * link, may be copied into Nextcloud, or is refused, before anything is
 * written. The rules and their order are the spec's.
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
 * Link, copy or refused for one record.
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
 */
class MaterialLicenceRules {

	public const OPEN_ACCESS = 'OpenAccess';

	/**
	 * Access rights under which a file never lands in Nextcloud.
	 */
	private const CLOSED_ACCESS = ['RestrictedAccess', 'ClosedAccess'];

	/**
	 * Formats that are a web page at the source, so always a link.
	 */
	private const WEB_FORMATS = ['text/html', 'application/x-wikiwijs-arrangement'];

	/**
	 * Constructor.
	 *
	 * @param LicenceTerms $terms Reads what a licence code allows.
	 */
	public function __construct(
		private readonly LicenceTerms $terms = new LicenceTerms(),
	) {
	}//end __construct()

	/**
	 * Decide link, copy or refused for one record.
	 *
	 * @param array<string, mixed> $record          The normalised source record.
	 * @param array<int, string>   $linkCollections Collections an administrator allows as links without an open licence.
	 *
	 * @return ImportDecision The decision.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
	 */
	public function decide(array $record, array $linkCollections): ImportDecision {
		$licence  = (string)($record['license'] ?? 'unknown');
		$decision = $this->baseDecision(record: $record, linkCollections: $linkCollections);
		$nonCommercial = $decision->mode !== ImportDecision::REFUSED && $this->terms->forbidsCommercialUse(licence: $licence);

		return $decision->withNonCommercial(nonCommercial: $nonCommercial);
	}//end decide()

	/**
	 * The file that may be copied: open access and not a web page, or null.
	 *
	 * @param array<string, mixed> $record The normalised source record.
	 *
	 * @return array<string, mixed>|null The file, or null when there is none to copy.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
	 */
	public function copyableFile(array $record): ?array {
		foreach ((array)($record['files'] ?? []) as $file) {
			if (is_array($file) === true && $this->isCopyable(file: $file, record: $record) === true) {
				return $file;
			}
		}

		return null;
	}//end copyableFile()

	/**
	 * The decision before the NC flag, in the spec's order.
	 *
	 * @param array<string, mixed> $record          The normalised source record.
	 * @param array<int, string>   $linkCollections Link-allowed collections.
	 *
	 * @return ImportDecision The decision.
	 */
	private function baseDecision(array $record, array $linkCollections): ImportDecision {
		$licence = (string)($record['license'] ?? 'unknown');
		if ($this->terms->isOpen(licence: $licence) === false) {
			return $this->withoutOpenLicence(record: $record, linkCollections: $linkCollections);
		}

		if ($this->isClosed(accessRights: $record['accessRights'] ?? null) === true) {
			return new ImportDecision(mode: ImportDecision::LINK, reason: 'restricted-access');
		}

		if ($this->terms->forbidsDerivatives(licence: $licence) === true) {
			return new ImportDecision(mode: ImportDecision::LINK, reason: 'no-derivatives');
		}

		if ($this->copyableFile(record: $record) === null) {
			return new ImportDecision(mode: ImportDecision::LINK, reason: 'no-copyable-file');
		}

		return new ImportDecision(mode: ImportDecision::COPY, reason: 'open-licence');
	}//end baseDecision()

	/**
	 * A record without an open licence: a link in an allowed collection, else refused.
	 *
	 * @param array<string, mixed> $record          The normalised source record.
	 * @param array<int, string>   $linkCollections Link-allowed collections.
	 *
	 * @return ImportDecision The decision.
	 */
	private function withoutOpenLicence(array $record, array $linkCollections): ImportDecision {
		if ($this->isClosed(accessRights: $record['accessRights'] ?? null) === true) {
			return new ImportDecision(mode: ImportDecision::REFUSED, reason: 'restricted-without-open-licence');
		}

		if (in_array((string)($record['collection'] ?? ''), $linkCollections, true) === true) {
			return new ImportDecision(mode: ImportDecision::LINK, reason: 'link-allowed-collection');
		}

		return new ImportDecision(mode: ImportDecision::REFUSED, reason: 'no-open-licence');
	}//end withoutOpenLicence()

	/**
	 * Whether one file may be copied.
	 *
	 * @param array<string, mixed> $file   The file: url, format, accessRights.
	 * @param array<string, mixed> $record The record, for its access rights and type.
	 *
	 * @return bool True when the file may land in Nextcloud.
	 */
	private function isCopyable(array $file, array $record): bool {
		$access = ($file['accessRights'] ?? $record['accessRights'] ?? null);
		$format = strtolower((string)($file['format'] ?? ''));

		return $access === self::OPEN_ACCESS
			&& str_starts_with((string)($file['url'] ?? ''), 'https://') === true
			&& in_array($format, self::WEB_FORMATS, true) === false
			&& in_array(strtolower((string)($record['materialType'] ?? '')), self::WEB_FORMATS, true) === false;
	}//end isCopyable()

	/**
	 * Whether access rights keep the file at the source.
	 *
	 * @param mixed $accessRights The record's access rights.
	 *
	 * @return bool True for restricted or closed access.
	 */
	private function isClosed(mixed $accessRights): bool {
		return in_array($accessRights, self::CLOSED_ACCESS, true) === true;
	}//end isClosed()
}//end class
