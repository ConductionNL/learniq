<?php

/**
 * Learniq Contribution Raiser
 *
 * Raises a FeeItem's contributions in shillinq for the guardians of its
 * learners (D19, payments-to-shillinq-migration), through shillinq's contract
 * extracurricular-fee-to-shillinq v1. Learniq decides who is charged for what;
 * shillinq makes the invoices and payment requests, takes the money and books
 * it, and portaliq shows the pay screen.
 *
 * WHO. The fee's learners are the learners of its group (`linkedCohortId`,
 * Cohort.learnerIds) or the pending and active enrolments of its course
 * (`linkedCourseId`). For each learner one recipient: the first guardian on
 * the LearnerProfile (`parentIds`) with an e-mail address as the debtor, or
 * the learner themself when the profile names no guardian (an adult learner,
 * an employee); the learner is always the beneficiary. A learner nobody can be
 * mailed about is reported as not sent, never guessed.
 *
 * ENTITLEMENTS. A non-voluntary fee (a paid course, contractonderwijs) unlocks
 * something, so each learner gets a pending Entitlement if they have none, and
 * its `paymentRequestRef` is stamped from shillinq's answer. The settled signal
 * then grants it. A voluntary fee creates no Entitlement: under the Wet
 * vrijwillige ouderbijdrage paying must never unlock or withhold anything.
 *
 * Idempotent through shillinq: a second raise for the same fee and learner is
 * answered `skipped` with the request that already stands.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-a-school-raises-a-fees-contributions-in-shillinq-from-learniq
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserManager;

/**
 * Builds and sends the shillinq contribution raise for one FeeItem.
 *
 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-a-school-raises-a-fees-contributions-in-shillinq-from-learniq
 */
class ContributionRaiser {

	private const REGISTER = 'learniq';
	private const MAX_ROWS = 1000;

	/**
	 * FeeItem.kind to the contract's `kind`.
	 *
	 * @var array<string, string>
	 */
	private const CONTRACT_KIND = [
		'schoolkassa' => 'parental-contribution',
		'school-trip' => 'school-trip',
	];

	/**
	 * FeeItem.kind to Entitlement.grantedResourceKind; a kind without one unlocks nothing.
	 *
	 * @var array<string, string>
	 */
	private const RESOURCE_KIND = [
		'course-enrolment' => 'course-access',
		'mbo-contractonderwijs' => 'contractonderwijs-access',
		'school-trip' => 'trip-participation',
		'materials' => 'material-access',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param ShillinqContributionClient $shillinq Shillinq's raise service, duck-typed.
	 * @param IUserManager $userManager Guardian and learner names and e-mail addresses.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ShillinqContributionClient $shillinq,
		private readonly IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * Whether shillinq is there to raise in.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-a-school-raises-a-fees-contributions-in-shillinq-from-learniq
	 */
	public function isAvailable(): bool {
		return $this->shillinq->isAvailable();
	}//end isAvailable()

	/**
	 * Raise the contributions of one FeeItem.
	 *
	 * @param array<string, mixed> $feeItem The FeeItem, with its id.
	 * @param string $administrationId The school's shillinq administration.
	 * @param array<string, string> $options Optional invoiceDate, dueDate, revenueAccount.
	 *
	 * @return array<string, mixed> Counts per outcome and one result per learner.
	 *
	 * @throws InvalidArgumentException When the fee names no course or group, or has no learners.
	 *
	 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-a-school-raises-a-fees-contributions-in-shillinq-from-learniq
	 */
	public function raise(array $feeItem, string $administrationId, array $options = []): array {
		$learnerIds = $this->learnersOf(feeItem: $feeItem);
		if ($learnerIds === []) {
			throw new InvalidArgumentException('This fee has no learners to charge yet.');
		}

		$recipients = [];
		$byIndex = [];
		$notSent = [];
		foreach ($learnerIds as $learnerId) {
			$recipient = $this->recipientFor(learnerId: $learnerId);
			if ($recipient === null) {
				$notSent[] = ['learnerId' => $learnerId, 'status' => 'not-sent', 'reason' => 'no e-mail address for a guardian or the learner'];
				continue;
			}

			$byIndex[count($recipients)] = $learnerId;
			$recipients[] = $recipient;
		}

		$results = $this->send(feeItem: $feeItem, administrationId: $administrationId, options: $options, recipients: $recipients, byIndex: $byIndex);
		$this->linkEntitlements(feeItem: $feeItem, results: $results);

		$all = array_merge($results, $notSent);
		$counts = array_count_values(array_column($all, 'status'));

		return [
			'feeItemId' => (string)$feeItem['id'],
			'raised' => ($counts['raised'] ?? 0),
			'skipped' => ($counts['skipped'] ?? 0),
			'failed' => ($counts['failed'] ?? 0),
			'notSent' => ($counts['not-sent'] ?? 0),
			'results' => $all,
		];
	}//end raise()

	/**
	 * Send the recipients to shillinq in chunks and map every result back to its learner.
	 *
	 * @param array<string, mixed> $feeItem The FeeItem.
	 * @param string $administrationId The school's shillinq administration.
	 * @param array<string, string> $options Optional invoiceDate, dueDate, revenueAccount.
	 * @param array<int, array<string, mixed>> $recipients The contract recipients.
	 * @param array<int, string> $byIndex Recipient index to learner id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function send(array $feeItem, string $administrationId, array $options, array $recipients, array $byIndex): array {
		$results = [];
		foreach (array_chunk($recipients, ShillinqContributionClient::MAX_RECIPIENTS, true) as $chunk) {
			$offset = (int)array_key_first($chunk);
			$answer = $this->shillinq->raise(
				$this->payload(feeItem: $feeItem, administrationId: $administrationId, options: $options, recipients: array_values($chunk))
			);
			foreach ((array)($answer['results'] ?? []) as $result) {
				$index = ($offset + (int)($result['index'] ?? 0));
				$results[] = [
					'learnerId' => ($byIndex[$index] ?? ''),
					'status' => (string)($result['status'] ?? 'failed'),
					'reason' => ($result['reason'] ?? null),
					'paymentRequestId' => ($result['paymentRequestId'] ?? null),
				];
			}
		}

		return $results;
	}//end send()

	/**
	 * The contract's request body for one chunk.
	 *
	 * @param array<string, mixed> $feeItem The FeeItem.
	 * @param string $administrationId The school's shillinq administration.
	 * @param array<string, string> $options Optional invoiceDate, dueDate, revenueAccount.
	 * @param array<int, array<string, mixed>> $recipients Up to 200 recipients.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/payments-to-shillinq-migration/specs/payments/spec.md#requirement-a-school-raises-a-fees-contributions-in-shillinq-from-learniq
	 */
	public function payload(array $feeItem, string $administrationId, array $options, array $recipients): array {
		$payload = [
			'chargeable' => [
				'app' => ContributionBeneficiaryResolver::APP,
				'type' => 'fee-item',
				'register' => self::REGISTER,
				'schema' => 'fee-item',
				'id' => (string)$feeItem['id'],
			],
			'kind' => (self::CONTRACT_KIND[(string)($feeItem['kind'] ?? '')] ?? 'other'),
			'description' => (string)($feeItem['name'] ?? ''),
			'amount' => (float)($feeItem['amount'] ?? 0),
			'currency' => (string)($feeItem['currency'] ?? 'EUR'),
			'voluntary' => (($feeItem['voluntary'] ?? false) === true),
			'administrationId' => $administrationId,
			'language' => 'nl',
			'recipients' => $recipients,
		];
		foreach (['invoiceDate', 'dueDate', 'revenueAccount'] as $optional) {
			if (($options[$optional] ?? '') !== '') {
				$payload[$optional] = $options[$optional];
			}
		}

		return $payload;
	}//end payload()

	/**
	 * The learners a FeeItem charges: its group's learners, or its course's live enrolments.
	 *
	 * @param array<string, mixed> $feeItem The FeeItem.
	 *
	 * @return array<int, string> Distinct Nextcloud user ids.
	 *
	 * @throws InvalidArgumentException When the fee names neither a course nor a group.
	 */
	private function learnersOf(array $feeItem): array {
		$cohortId = (string)($feeItem['linkedCohortId'] ?? '');
		$courseId = (string)($feeItem['linkedCourseId'] ?? '');
		if ($cohortId !== '') {
			$cohort = $this->objectService->find(id: $cohortId, register: self::REGISTER, schema: 'cohort', _rbac: false, _multitenancy: false);
			$ids = (array)($cohort?->jsonSerialize()['learnerIds'] ?? []);
		} elseif ($courseId !== '') {
			$live = array_filter(
				$this->rows(schema: 'enrolment', filters: ['courseId' => $courseId]),
				static fn (array $row): bool => in_array(($row['lifecycle'] ?? ''), ['pending', 'active'], true)
			);
			$ids = array_column($live, 'learnerId');
		} else {
			throw new InvalidArgumentException('This fee names no course or group, so learniq cannot tell whom to charge.');
		}

		return array_values(array_unique(array_filter($ids, static fn ($id): bool => is_string($id) && $id !== '')));
	}//end learnersOf()

	/**
	 * One contract recipient for a learner, or null when nobody can be mailed.
	 *
	 * @param string $learnerId The learner's Nextcloud user id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function recipientFor(string $learnerId): ?array {
		$profile = ($this->rows(schema: 'learner-profile', filters: ['ncUserId' => $learnerId], limit: 1)[0] ?? null);
		$payers = array_values(array_filter((array)($profile['parentIds'] ?? []), 'is_string'));
		if ($payers === []) {
			$payers = [$learnerId];
		}

		$debtor = null;
		foreach ($payers as $uid) {
			$user = $this->userManager->get($uid);
			$email = (string)($user?->getEMailAddress() ?? '');
			if ($email !== '') {
				$debtor = ['name' => (string)$user->getDisplayName(), 'email' => $email];
				break;
			}
		}

		if ($debtor === null) {
			return null;
		}

		$beneficiary = ['type' => 'learner', 'id' => $learnerId];
		$profileId = (string)($profile['id'] ?? ($profile['uuid'] ?? ''));
		if ($profileId !== '') {
			$beneficiary = ['type' => 'learner', 'register' => self::REGISTER, 'schema' => 'learner-profile', 'id' => $profileId];
		}

		return ['debtor' => $debtor, 'beneficiary' => $beneficiary];
	}//end recipientFor()

	/**
	 * For a fee that unlocks something: a pending Entitlement per learner, linked to its request.
	 *
	 * @param array<string, mixed> $feeItem The FeeItem.
	 * @param array<int, array<string, mixed>> $results One result per learner.
	 *
	 * @return void
	 */
	private function linkEntitlements(array $feeItem, array $results): void {
		$resourceKind = (self::RESOURCE_KIND[(string)($feeItem['kind'] ?? '')] ?? null);
		if (($feeItem['voluntary'] ?? false) === true || $resourceKind === null) {
			return;
		}

		foreach ($results as $result) {
			$requestId = $result['paymentRequestId'];
			if (is_string($requestId) === false || $requestId === '' || $result['learnerId'] === '') {
				continue;
			}

			$existing = $this->rows(schema: 'entitlement', filters: ['feeItemId' => (string)$feeItem['id'], 'learnerId' => $result['learnerId']], limit: 1);
			$entitlement = ($existing[0] ?? [
				'feeItemId' => (string)$feeItem['id'],
				'learnerId' => $result['learnerId'],
				'grantedResourceKind' => $resourceKind,
				'grantedResourceId' => ($feeItem['linkedCourseId'] ?? ($feeItem['linkedCohortId'] ?? null)),
				'lifecycle' => 'pending',
				'tenant_id' => ($feeItem['tenant_id'] ?? null),
			]);
			if (($entitlement['lifecycle'] ?? 'pending') !== 'pending' || ($entitlement['paymentRequestRef'] ?? null) === $requestId) {
				continue;
			}

			$this->objectService->saveObject(
				object: array_merge($entitlement, ['paymentRequestRef' => $requestId]),
				register: self::REGISTER,
				schema: 'entitlement',
				uuid: ($entitlement['id'] ?? null),
				_rbac: false,
				_multitenancy: false
			);
		}//end foreach
	}//end linkEntitlements()

	/**
	 * Rows of one learniq schema as arrays, without RBAC.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string, mixed> $filters Property filters.
	 * @param int $limit Row cap.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(string $schema, array $filters, int $limit = self::MAX_ROWS): array {
		$objects = $this->objectService->findAll(
			config: [
				'filters' => array_merge($filters, ['register' => self::REGISTER, 'schema' => $schema]),
				'limit' => $limit,
			],
			_rbac: false,
			_multitenancy: false
		);

		$rows = [];
		foreach ($objects as $object) {
			if (is_array($object) === false) {
				$object = $object->jsonSerialize();
			}

			$rows[] = $object;
		}

		return $rows;
	}//end rows()
}//end class
