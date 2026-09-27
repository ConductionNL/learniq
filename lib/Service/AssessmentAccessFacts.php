<?php

/**
 * Learniq Assessment Access Facts
 *
 * Reports, for the release-status endpoint, the two facts TakeAssessmentView
 * needs before it starts an attempt: why the availability window is shut (a
 * reason code the page can translate) and whether the Assessment is behind an
 * access code. The code is write-only, so the Assessment is read raw to learn
 * that it exists; the code itself is never returned (learniq#946).
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
 * @spec openspec/specs/assessment/spec.md#requirement-an-attempt-starts-only-inside-the-availability-window-and-with-the-access-code
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Attempt-gate facts for one Assessment.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-an-attempt-starts-only-inside-the-availability-window-and-with-the-access-code
 */
class AssessmentAccessFacts {

	private const LEARNIQ_REGISTER = 'learniq';
	private const ASSESSMENT_SCHEMA = 'exam';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param AssessmentAccessPolicy $policy Window and access-code rules.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly AssessmentAccessPolicy $policy,
	) {
	}//end __construct()

	/**
	 * The attempt-gate facts TakeAssessmentView needs before it starts an
	 * attempt: why the window is shut (a code it can translate) and whether an
	 * access code is needed. The code itself is write-only and is never
	 * returned; the Assessment is read raw here only to report that one exists.
	 *
	 * @param string $assessmentId UUID of the Assessment.
	 * @param array<string, mixed> $item The rendered Assessment row.
	 *
	 * @return array{reasonCode: string|null, requiresAccessCode: bool}
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-an-attempt-starts-only-inside-the-availability-window-and-with-the-access-code
	 */
	public function assessmentAccess(string $assessmentId, array $item): array {
		$window = $this->policy->windowBlock(assessment: $item, now: new DateTimeImmutable());

		$raw = [];
		try {
			$object = $this->objectService->find(
				id: $assessmentId,
				register: self::LEARNIQ_REGISTER,
				schema: self::ASSESSMENT_SCHEMA,
				_rbac: false,
				_render: false
			);
			if ($object !== null) {
				$raw = $object->jsonSerialize();
			}
		} catch (Throwable $exception) {
			$raw = [];
		}

		return [
			'reasonCode' => ($window['reason'] ?? null),
			'requiresAccessCode' => $this->policy->requiresAccessCode(assessment: $raw),
		];
	}//end assessmentAccess()
}//end class
