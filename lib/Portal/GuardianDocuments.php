<?php

/**
 * Learniq GuardianDocuments
 *
 * What a guardian may download on "Rapporten en documenten" (wilgenboom/Documenten).
 * Portaliq calls this through the provider for ONE row it read through the
 * guardian's own scoped collection (a child in `parentChildren`, a school in
 * `parentSchools`), so the id is never taken on trust from the browser. The
 * rule of what leaves the server is written here once:
 *  - a child's report: a file attached to one of that child's report cards in
 *    `published-to-parents`; a card in draft, in review or finalised gives nothing;
 *  - a school paper: a file attached to one of that school's school documents
 *    that is `published` and for `guardians`; a draft or staff-only one gives nothing.
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
 * @spec openspec/changes/guardian-reports-and-documents/specs/portal-contribution/spec.md#requirement-a-guardian-reads-her-childrens-published-reports-and-the-schools-papers-on-one-page
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The papers of one child or one school, for the guardian's portal.
 *
 * @spec openspec/changes/guardian-reports-and-documents/specs/portal-contribution/spec.md#requirement-a-guardian-reads-her-childrens-published-reports-and-the-schools-papers-on-one-page
 */
class GuardianDocuments {

	private const REGISTER = 'learniq';

	/**
	 * The most records one read holds.
	 */
	private const LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param ObjectService     $objectService OpenRegister reads (system context: portaliq proved the row first).
	 * @param PortalFileEntries $files         The files of a record as entries.
	 * @param LoggerInterface   $logger        Logs a read that failed.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly PortalFileEntries $files,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The files of a child's published report cards.
	 *
	 * @param string $profileId The child's learner profile id.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/guardian-reports-and-documents/specs/portal-contribution/spec.md#requirement-a-guardian-reads-her-childrens-published-reports-and-the-schools-papers-on-one-page
	 */
	public function childReports(string $profileId): array {
		$filters = ['learnerRef' => $profileId, 'lifecycle' => 'published-to-parents'];
		return $this->papers(query: new PaperQuery(schema: 'report-card', filters: $filters));
	}//end childReports()

	/**
	 * The files of a school's published documents for guardians.
	 *
	 * @param string $schoolId The school's id.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/guardian-reports-and-documents/specs/portal-contribution/spec.md#requirement-a-guardian-reads-her-childrens-published-reports-and-the-schools-papers-on-one-page
	 */
	public function schoolPapers(string $schoolId): array {
		$filters = ['schoolId' => $schoolId, 'lifecycle' => 'published', 'audience' => 'guardians'];
		return $this->papers(query: new PaperQuery(schema: 'school-document', filters: $filters));
	}//end schoolPapers()

	/**
	 * The entries of every record the query finds and that still matches it.
	 *
	 * @param PaperQuery $query The records to read.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function papers(PaperQuery $query): array {
		if ($query->isComplete() === false) {
			return [];
		}

		$entries = [];
		foreach ($this->rows(query: $query) as $row) {
			if ($query->matches(row: $row) === true) {
				array_push($entries, ...$this->files->forRecord(schema: $query->schema, row: $row));
			}
		}

		return $entries;
	}//end papers()

	/**
	 * The records of the query, as arrays; none when the read fails.
	 *
	 * @param PaperQuery $query The records to read.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(PaperQuery $query): array {
		try {
			$found = $this->objectService->findAll(
				config: ['filters' => array_merge(['register' => self::REGISTER, 'schema' => $query->schema], $query->filters), 'limit' => self::LIMIT],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Learniq: the guardian portal papers could not be read', ['schema' => $query->schema, 'reason' => $e->getMessage()]);
			return [];
		}

		return array_map(fn ($object): array => $this->asRow(object: $object), (array)$found);
	}//end rows()

	/**
	 * One found record as an array.
	 *
	 * @param mixed $object An object entity, or a row already.
	 *
	 * @return array<string, mixed>
	 */
	private function asRow(mixed $object): array {
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			return (array)$object->jsonSerialize();
		}

		return (array)$object;
	}//end asRow()
}//end class
