<?php

/**
 * Learniq LearnerDocuments
 *
 * The learner's own documents on her portal (vaartveld/Documenten,
 * esdoornveen/Documenten): her published report cards, her approved subject
 * choice as a statement, her work placement agreement with its signing state
 * and her certificates, for one of her enrolments. Portaliq calls this only
 * for an enrolment it read through her own scoped collection
 * (`studentEnrolments`, scoped on her learnerRef claim).
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
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The documents of one of the learner's enrolments, grouped per kind.
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
 */
class LearnerDocuments {

	private const REGISTER = 'learniq';

	/**
	 * The most records one read holds.
	 */
	private const LIMIT = 200;

	/**
	 * The subject choice states that make it a statement: approved by the school.
	 */
	private const SETTLED_CHOICE = ['approved', 'locked'];

	/**
	 * The agreement states a learner sees: on its way to signing, or signed.
	 */
	private const OFFERED_AGREEMENT = ['pending-signatures', 'active', 'completed'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService     $objectService OpenRegister reads (system context: portaliq proved the enrolment first).
	 * @param PortalFileEntries $files         The files of a record as entries.
	 * @param IFactory          $l10nFactory   The words of the groups and the signing state.
	 * @param LoggerInterface   $logger        Logs a read that failed.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly PortalFileEntries $files,
		private readonly IFactory $l10nFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The documents of one enrolment of the learner: reports, statements,
	 * agreements and certificates, each row with its group.
	 *
	 * @param string $enrolmentId The enrolment's id.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
	 */
	public function forEnrolment(string $enrolmentId): array {
		$enrolment  = $this->enrolment(id: $enrolmentId);
		$learnerRef = trim((string)($enrolment['learnerRef'] ?? ''));
		if ($learnerRef === '') {
			return [];
		}

		$l10n = $this->l10nFactory->get('learniq');

		return array_merge(
			$this->grouped(rows: $this->reports(learnerRef: $learnerRef), schema: 'report-card', group: $l10n->t('Reports')),
			$this->grouped(rows: $this->choices(learnerRef: $learnerRef), schema: 'subject-choice', group: $l10n->t('Choices and statements')),
			// Files kept on the enrolment itself, such as her proof of enrolment (T4).
			$this->grouped(rows: [$enrolment], schema: 'enrolment', group: $l10n->t('Choices and statements')),
			$this->agreements(learnerRef: $learnerRef, l10n: $l10n),
			$this->grouped(rows: $this->certificates(enrolmentId: $enrolmentId), schema: 'credential', group: $l10n->t('Certificates'))
		);
	}//end forEnrolment()

	/**
	 * The signing state of an agreement in words: signed by all, or whose
	 * signature it waits on first.
	 *
	 * @param array<string, mixed>             $agreement  The agreement as read.
	 * @param array<int, array<string, mixed>> $signatures Its signatures as read.
	 * @param IL10N                            $l10n       The words.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
	 */
	public function signingState(array $agreement, array $signatures, IL10N $l10n): string {
		$required = ['student', 'school', 'praktijkopleider'];
		if (($agreement['parentSignatureRequired'] ?? false) === true) {
			$required[] = 'parent';
		}

		$signed  = array_map(static fn (array $signature): string => (string)($signature['signerRole'] ?? ''), $signatures);
		$missing = array_values(array_diff($required, $signed));
		if ($missing === [] || ($agreement['isFullySigned'] ?? false) === true) {
			if (count($required) === 3) {
				return $l10n->t('Signed by all three');
			}

			return $l10n->t('Signed by everyone');
		}

		return match ($missing[0]) {
			'student' => $l10n->t('Waiting for your signature'),
			'school' => $l10n->t('Waiting for the school'),
			'praktijkopleider' => $l10n->t('Waiting for the training company'),
			default => $l10n->t('Waiting for your parent'),
		};
	}//end signingState()

	/**
	 * The learner's report cards published to her and her parents.
	 *
	 * @param string $learnerRef The learner's ref.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function reports(string $learnerRef): array {
		return $this->matching(
			rows: $this->read(schema: 'report-card', filters: ['learnerRef' => $learnerRef, 'lifecycle' => 'published-to-parents']),
			field: 'learnerRef',
			value: $learnerRef,
			states: ['published-to-parents']
		);
	}//end reports()

	/**
	 * The learner's subject choices the school approved.
	 *
	 * @param string $learnerRef The learner's ref.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function choices(string $learnerRef): array {
		return $this->matching(
			rows: $this->read(schema: 'subject-choice', filters: ['learnerRef' => $learnerRef]),
			field: 'learnerRef',
			value: $learnerRef,
			states: self::SETTLED_CHOICE
		);
	}//end choices()

	/**
	 * The issued certificates of the enrolment.
	 *
	 * @param string $enrolmentId The enrolment's id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function certificates(string $enrolmentId): array {
		return $this->matching(
			rows: $this->read(schema: 'credential', filters: ['enrolmentId' => $enrolmentId, 'lifecycle' => 'issued']),
			field: 'enrolmentId',
			value: $enrolmentId,
			states: ['issued']
		);
	}//end certificates()

	/**
	 * The work placement agreements of the learner's placements, each row
	 * with its signing state as status.
	 *
	 * @param string $learnerRef The learner's ref.
	 * @param IL10N  $l10n       The words.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function agreements(string $learnerRef, IL10N $l10n): array {
		$placements = $this->matching(
			rows: $this->read(schema: 'bpv-placement', filters: ['learnerRef' => $learnerRef]),
			field: 'learnerRef',
			value: $learnerRef,
			states: []
		);

		$entries = [];
		foreach ($placements as $placement) {
			$placementId = (string)($placement['id'] ?? '');
			if ($placementId === '') {
				continue;
			}

			$agreements = $this->matching(
				rows: $this->read(schema: 'praktijkovereenkomst', filters: ['bpvPlacementId' => $placementId]),
				field: 'bpvPlacementId',
				value: $placementId,
				states: self::OFFERED_AGREEMENT
			);
			foreach ($agreements as $agreement) {
				$signatures = $this->matching(
					rows: $this->read(schema: 'pok-signature', filters: ['subjectId' => (string)($agreement['id'] ?? '')]),
					field: 'subjectId',
					value: (string)($agreement['id'] ?? ''),
					states: []
				);
				$status  = $this->signingState(agreement: $agreement, signatures: $signatures, l10n: $l10n);
				$context = [trim((string)($placement['trainingCompanyName'] ?? ''))];
				foreach ($this->files->forRecord(schema: 'praktijkovereenkomst', row: $agreement) as $entry) {
					$entries[] = (new DocumentRow())->complete(entry: $entry, group: $l10n->t('Contracts'), status: $status, context: $context);
				}
			}
		}//end foreach

		return $entries;
	}//end agreements()

	/**
	 * The file entries of these records, each with the group set.
	 *
	 * @param array<int, array<string, mixed>> $rows   The records.
	 * @param string                           $schema Their schema slug.
	 * @param string                           $group  The group they show under.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function grouped(array $rows, string $schema, string $group): array {
		$entries = [];
		foreach ($rows as $row) {
			foreach ($this->files->forRecord(schema: $schema, row: $row) as $entry) {
				$entries[] = (new DocumentRow())->complete(entry: $entry, group: $group, status: (string)($entry['status'] ?? ''));
			}
		}

		return $entries;
	}//end grouped()

	/**
	 * The rows that still match what was asked, whatever the store answered:
	 * the field holds the value, and the lifecycle is one of the states when
	 * states are named.
	 *
	 * @param array<int, array<string, mixed>> $rows   The rows as read.
	 * @param string                           $field  The field that must hold the value.
	 * @param string                           $value  The value.
	 * @param array<int, string>               $states The allowed lifecycle states; none named: any.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function matching(array $rows, string $field, string $value, array $states): array {
		return array_values(
			array_filter(
				$rows,
				static fn (array $row): bool => (string)($row[$field] ?? '') === $value
					&& ($states === [] || in_array((string)($row['lifecycle'] ?? ''), $states, true) === true)
			)
		);
	}//end matching()

	/**
	 * The enrolment as an array; empty when it cannot be read.
	 *
	 * @param string $id The enrolment's id.
	 *
	 * @return array<string, mixed>
	 */
	private function enrolment(string $id): array {
		if (trim($id) === '') {
			return [];
		}

		foreach ($this->read(schema: 'enrolment', filters: ['id' => $id]) as $row) {
			if ((string)($row['id'] ?? '') === $id) {
				return $row;
			}
		}

		return [];
	}//end enrolment()

	/**
	 * The records of one schema under these filters, as arrays; none when the read fails.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function read(string $schema, array $filters): array {
		try {
			$found = $this->objectService->findAll(
				config: ['filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters), 'limit' => self::LIMIT],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Learniq: the learner portal documents could not be read', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return [];
		}

		return array_map(
			static function ($object): array {
				if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
					return (array)$object->jsonSerialize();
				}

				return (array)$object;
			},
			(array)$found
		);
	}//end read()
}//end class
