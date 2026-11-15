<?php

/**
 * Learniq PortalExamSittingDocuments
 *
 * Hands an external assessor one exam sitting and its documents (the
 * assessment model and the exam regulations), and only while he holds a live
 * `ExamAssessorAssignment` at that sitting.
 *
 * A sitting he is not assigned to resolves nothing, whether it exists or not:
 * the same refusal for both, so the answer tells nothing about other sittings.
 * Only the documents the sitting names are read, so a document id sent by the
 * caller never reaches the store.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-an-assessor-is-linked-to-an-exam-sitting-by-a-record
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use OCA\Learniq\Kernel\Contracts\PortalExamSittingDocumentsInterface;
use OCA\Learniq\Support\ObjectRow;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads an assigned sitting and its documents for the portal.
 *
 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-an-assessor-is-linked-to-an-exam-sitting-by-a-record
 */
class PortalExamSittingDocuments implements PortalExamSittingDocumentsInterface {

	private const REGISTER = 'learniq';

	/**
	 * The sitting fields an assessor reads.
	 *
	 * @var array<int, string>
	 */
	private const SITTING_FIELDS = ['startsAt', 'endsAt', 'roomIds', 'lifecycle'];

	/**
	 * The document fields an assessor reads.
	 *
	 * @var array<int, string>
	 */
	private const DOCUMENT_FIELDS = ['title', 'kind', 'fileRef', 'url'];

	/**
	 * Turns one OpenRegister result into a plain array.
	 *
	 * @var ObjectRow
	 */
	private readonly ObjectRow $objectRow;

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService Reads the assignment, the sitting and its documents.
	 * @param LoggerInterface $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
		$this->objectRow = new ObjectRow();
	}//end __construct()

	/**
	 * One sitting and its documents, for an assessor assigned to it.
	 *
	 * @param string $assessorRef The `externalAssessorId` claim.
	 * @param string $sittingId   The sitting asked for.
	 *
	 * @return PortalOutcome 200 with the sitting and its documents; 403, 422 or 502 otherwise.
	 *
	 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-an-assessor-is-linked-to-an-exam-sitting-by-a-record
	 */
	public function sitting(string $assessorRef, string $sittingId): PortalOutcome {
		if ($assessorRef === '' || $sittingId === '') {
			return new PortalOutcome(status: 422, body: ['error' => 'incomplete'], reason: 'incomplete');
		}

		try {
			$assigned = $this->rows(
				schema: 'exam-assessor-assignment',
				filters: ['externalAssessorId' => $assessorRef, 'examSittingId' => $sittingId, 'lifecycle' => 'assigned'],
				ids: []
			);
			$sitting  = null;
			if ($assigned !== []) {
				$sitting = ($this->rows(schema: 'exam-sitting', filters: [], ids: [$sittingId])[0] ?? null);
			}

			if ($sitting === null) {
				return new PortalOutcome(status: 403, body: ['error' => 'not_assigned'], reason: 'not-assigned');
			}

			$documents = $this->documents(sitting: $sitting);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[PortalExamSittingDocuments] An exam sitting could not be read: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new PortalOutcome(status: 502, body: ['error' => 'downstream_error'], reason: 'downstream');
		}

		$body = ['id' => $sittingId];
		foreach (self::SITTING_FIELDS as $field) {
			$body[$field] = ($sitting[$field] ?? null);
		}

		$body['documents'] = $documents;
		return new PortalOutcome(status: 200, body: $body);
	}//end sitting()

	/**
	 * The documents the sitting names, in the sitting's order.
	 *
	 * @param array<string, mixed> $sitting The sitting.
	 *
	 * @return array<int, array<string, mixed>> The documents.
	 */
	private function documents(array $sitting): array {
		$named = ($sitting['documentIds'] ?? []);
		if (is_array($named) === false) {
			return [];
		}

		$ids = array_values(array_filter($named, static fn (mixed $id): bool => is_string($id) === true && $id !== ''));
		if ($ids === []) {
			return [];
		}

		$byId = [];
		foreach ($this->rows(schema: 'material', filters: [], ids: $ids) as $material) {
			$byId[$this->idOf(row: $material)] = $material;
		}

		$documents = [];
		foreach ($ids as $id) {
			if (isset($byId[$id]) === false) {
				continue;
			}

			$document = ['id' => $id];
			foreach (self::DOCUMENT_FIELDS as $field) {
				$document[$field] = ($byId[$id][$field] ?? null);
			}

			$documents[] = $document;
		}

		return $documents;
	}//end documents()

	/**
	 * The rows of one schema matching the filters, and the ids when given.
	 *
	 * Every field filtered on is checked again on the row, and an id asked for
	 * is checked on the row's id: the store's filter is not trusted alone.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Field => value.
	 * @param array<int, string>   $ids     Object ids, or [] for any.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rows(string $schema, array $filters, array $ids): array {
		$config = ['filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters), 'limit' => 50];
		if ($ids !== []) {
			$config['ids'] = $ids;
		}

		$rows = [];
		foreach ($this->objectService->findAll(config: $config, _rbac: false, _multitenancy: false) as $object) {
			$row = $this->objectRow->asArray(object: $object);
			if ($this->matches(row: $row, filters: $filters, ids: $ids) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rows()

	/**
	 * Whether a row holds every filtered value and, when ids were asked for, one of them.
	 *
	 * @param array<string, mixed> $row     The row.
	 * @param array<string, mixed> $filters Field => value.
	 * @param array<int, string>   $ids     Object ids, or [] for any.
	 *
	 * @return bool True when it matches.
	 */
	private function matches(array $row, array $filters, array $ids): bool {
		foreach ($filters as $field => $value) {
			if (($row[$field] ?? null) !== $value) {
				return false;
			}
		}

		return $ids === [] || in_array($this->idOf(row: $row), $ids, true) === true;
	}//end matches()

	/**
	 * The id of a row, '' when it has none.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id.
	 */
	private function idOf(array $row): string {
		$id = ($row['id'] ?? ($row['uuid'] ?? null));
		if (is_string($id) === false) {
			return '';
		}

		return $id;
	}//end idOf()
}//end class
