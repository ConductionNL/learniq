<?php

/**
 * Learniq Accessibility Statement Publish Guard
 *
 * Lifecycle guard for the AccessibilityStatement schema's `draft -> published`
 * transition. Structurally enforces the "no unverifiable conformance claim"
 * posture required by the Tijdelijk besluit digitale toegankelijkheid
 * overheid (BDTO): a toegankelijkheidsverklaring cannot go live without
 * recorded evaluation evidence, and it cannot claim `fully-compliant` while
 * a known accessibility issue is still open.
 *
 * Two independent checks, both must pass:
 *   1. `status`, `evaluationMethod`, `evaluationDate`, and a non-empty
 *      `feedbackContact` must all be set (evidence pre-condition).
 *   2. When `status` is `fully-compliant`, no `open` or `mitigated`
 *      AccessibilityLimitation may reference this statement — a cross-object
 *      invariant no JSON-logic expression on a single schema can check,
 *      mirroring BsaDecisionGuard's "no negative BSA without a logged
 *      warning" cross-object query shape. Legitimate PHP per ADR-031
 *      §"Lifecycle guards".
 *   3. Every AccessibilityCriterionResult of this statement that records a
 *      `fail` links a limitation of this statement for the same criterion
 *      that is not fixed (governance-wcag-evidence-report). A failure with
 *      no disclosed limitation is exactly the unverifiable claim check 2
 *      exists to stop, so the denial names the criteria.
 *
 * Mirrors AttestationSigningGuard/CoursePublishGuard's `requires` pattern.
 * Referenced from AccessibilityStatement.x-openregister-lifecycle.transitions.
 * publish.requires in learniq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
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
 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-a-statement-must-not-publish-without-evaluation-evidence
 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-per-criterion-conformance-record
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\Learniq\Service\Accessibility\WcagCriteriaCatalogue;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the AccessibilityStatement `draft -> published` lifecycle transition.
 */
class AccessibilityStatementPublishGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'This accessibility statement is not complete enough to publish yet.';

	/**
	 * OR register slug for Learniq objects.
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * OR schema slug for the AccessibilityLimitation register.
	 */
	private const LIMITATION_SCHEMA = 'accessibility-limitation';

	/**
	 * OR schema slug for the per-criterion conformance records.
	 */
	private const RESULT_SCHEMA = 'accessibility-criterion-result';

	/**
	 * Valid AccessibilityStatement.status values.
	 *
	 * @var string[]
	 */
	private const VALID_STATUSES = [
		'fully-compliant',
		'partially-compliant',
		'non-compliant',
	];

	/**
	 * Valid AccessibilityStatement.evaluationMethod values.
	 *
	 * @var string[]
	 */
	private const VALID_EVALUATION_METHODS = [
		'self-assessment',
		'expert-review',
		'user-testing',
		'automated-scan',
	];

	/**
	 * AccessibilityLimitation lifecycle states that block a fully-compliant claim.
	 *
	 * @var string[]
	 */
	private const BLOCKING_LIMITATION_STATES = [
		'open',
		'mitigated',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object query service for
	 *                                     AccessibilityLimitation lookup.
	 * @param LoggerInterface $logger PSR logger for guard rejections.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Authorise or deny the transition this guard is named on (LifecycleGuardInterface).
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow, or deny with the reason shown to the caller.
	 *
	 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-a-statement-must-not-publish-without-evaluation-evidence
	 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->allows(object: $object) === false) {
			return GuardResult::deny(self::DENIAL);
		}

		$statementId = $object['uuid'] ?? $object['id'] ?? null;
		if (is_string($statementId) === false || $statementId === '') {
			return GuardResult::allow();
		}

		$unlinked = $this->failuresWithoutLimitation(
			statementId: $statementId,
			tenantId: (string)($object['tenant_id'] ?? '')
		);
		if ($unlinked !== []) {
			$this->logger->info(
				'AccessibilityStatementPublishGuard: failing criteria without a limitation on statement {id}: {criteria}.',
				['id' => $statementId, 'criteria' => implode(', ', $unlinked)]
			);
			return GuardResult::deny(
				'These criteria are recorded as failing but have no known limitation linked: '
				. implode(', ', $unlinked)
				. '. Link a limitation to each of them, or change the result, before publishing.'
			);
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * The rule behind check(), answered as a boolean.
	 *
	 * Called by OpenRegister's lifecycle engine before executing the
	 * `draft -> published` transition on an AccessibilityStatement object.
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 *
	 * @return bool True when evaluation evidence is complete and (for a
	 *              fully-compliant status) no open/mitigated limitation
	 *              references this statement; false blocks the transition.
	 *
	 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-a-statement-must-not-publish-without-evaluation-evidence
	 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement
	 */
	private function allows(array $object): bool {
		$status = $object['status'] ?? null;
		$tenantId = $object['tenant_id'] ?? '';

		if ($this->hasCompleteEvaluationEvidence(object: $object) === false) {
			$this->logger->info(
				'AccessibilityStatementPublishGuard: missing evaluation evidence '
				. '(status/evaluationMethod/evaluationDate/feedbackContact) — blocking publish.'
			);
			return false;
		}

		if ($status === 'fully-compliant') {
			$statementId = $object['uuid'] ?? $object['id'] ?? null;

			if ($statementId !== null
				&& $this->hasBlockingLimitation(statementId: $statementId, tenantId: $tenantId) === true
			) {
				$this->logger->info(
					'AccessibilityStatementPublishGuard: an open/mitigated AccessibilityLimitation '
					. 'references statement {id} — blocking fully-compliant publish.',
					['id' => $statementId]
				);
				return false;
			}
		}

		return true;
	}//end allows()

	/**
	 * Assert status, evaluationMethod, evaluationDate, and feedbackContact
	 * are all set on the record.
	 *
	 * @param array<string,mixed> $object The AccessibilityStatement property array.
	 *
	 * @return bool True when all four evidence fields are present and valid.
	 *
	 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-a-statement-must-not-publish-without-evaluation-evidence
	 */
	private function hasCompleteEvaluationEvidence(array $object): bool {
		$status = $object['status'] ?? null;
		$evaluationMethod = $object['evaluationMethod'] ?? null;
		$evaluationDate = $object['evaluationDate'] ?? null;
		$feedbackContact = $object['feedbackContact'] ?? null;

		if (in_array($status, self::VALID_STATUSES, true) === false) {
			return false;
		}

		if (in_array($evaluationMethod, self::VALID_EVALUATION_METHODS, true) === false) {
			return false;
		}

		if (is_string($evaluationDate) === false || trim($evaluationDate) === '') {
			return false;
		}

		if (is_string($feedbackContact) === false || trim($feedbackContact) === '') {
			return false;
		}

		return true;
	}//end hasCompleteEvaluationEvidence()

	/**
	 * Query OR for an `open` or `mitigated` AccessibilityLimitation referencing
	 * the given AccessibilityStatement.
	 *
	 * @param string $statementId UUID of the AccessibilityStatement being published.
	 * @param string $tenantId Tenant ID to scope the query.
	 *
	 * @return bool True when at least one blocking limitation exists.
	 *
	 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-known-limitations-must-be-evidence-backed-and-linked-from-the-published-statement
	 */
	private function hasBlockingLimitation(string $statementId, string $tenantId): bool {
		$filters = ['accessibilityStatementId' => $statementId];

		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$results = $this->objectService->findAll(
			[
				'filters' => array_merge(
					$filters,
					[
						'register' => self::LEARNIQ_REGISTER,
						'schema' => self::LIMITATION_SCHEMA,
					]
				),
			]
		);

		foreach (self::rows(objects: $results) as $limitation) {
			$lifecycle = $limitation['lifecycle'] ?? null;
			if (in_array($lifecycle, self::BLOCKING_LIMITATION_STATES, true) === true) {
				return true;
			}
		}

		return false;
	}//end hasBlockingLimitation()

	/**
	 * The criteria this statement records as failing with no usable limitation.
	 *
	 * A failure is covered when its `limitationId` names a limitation of the
	 * same statement, for the same criterion number, that is not `fixed`: a
	 * fixed limitation cannot explain a criterion that still fails.
	 *
	 * @param string $statementId UUID of the AccessibilityStatement being published.
	 * @param string $tenantId Tenant ID to scope the query.
	 *
	 * @return string[] The uncovered criterion numbers, in record order.
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-per-criterion-conformance-record
	 */
	private function failuresWithoutLimitation(string $statementId, string $tenantId): array {
		$filters = ['accessibilityStatementId' => $statementId];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$failures = self::rows(
			objects: $this->objectService->findAll(
				[
					'filters' => array_merge(
						$filters,
						[
							'result' => 'fail',
							'register' => self::LEARNIQ_REGISTER,
							'schema' => self::RESULT_SCHEMA,
						]
					),
				]
			)
		);
		$failures = array_values(
			array_filter($failures, static fn (array $row): bool => ($row['result'] ?? null) === 'fail')
		);
		if ($failures === []) {
			return [];
		}

		$limitations = [];
		$limitationRows = self::rows(
			objects: $this->objectService->findAll(
				[
					'filters' => array_merge(
						$filters,
						[
							'register' => self::LEARNIQ_REGISTER,
							'schema' => self::LIMITATION_SCHEMA,
						]
					),
				]
			)
		);
		foreach ($limitationRows as $limitation) {
			$limitationId = $limitation['id'] ?? $limitation['uuid'] ?? null;
			if (is_string($limitationId) === true) {
				$limitations[$limitationId] = $limitation;
			}
		}

		$unlinked = [];
		foreach ($failures as $failure) {
			$number = WcagCriteriaCatalogue::numberOf(reference: $failure['wcagCriterion'] ?? null) ?? '?';
			$limitation = $limitations[(string)($failure['limitationId'] ?? '')] ?? null;

			$covers = $limitation !== null
				&& ($limitation['lifecycle'] ?? 'open') !== 'fixed'
				&& WcagCriteriaCatalogue::numberOf(reference: $limitation['wcagCriterion'] ?? null) === $number;
			if ($covers === false) {
				$unlinked[] = $number;
			}
		}

		return $unlinked;
	}//end failuresWithoutLimitation()

	/**
	 * OpenRegister answers findAll() with ObjectEntity instances; read them as arrays.
	 *
	 * The limitation check used to index the entities as arrays, which only a
	 * test double that returns plain arrays could satisfy.
	 *
	 * @param array<int,mixed> $objects The findAll() result.
	 *
	 * @return array<int,array<string,mixed>> The rows.
	 */
	private static function rows(array $objects): array {
		$rows = [];
		foreach ($objects as $object) {
			if (is_array($object) === true) {
				$rows[] = $object;
				continue;
			}

			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$rows[] = (array)$object->jsonSerialize();
			}
		}

		return $rows;
	}//end rows()
}//end class
