<?php

/**
 * Learniq Assessment Result Portal Stamp
 *
 * Stamps, on every new AssessmentResult, the two values the portal lists a
 * pupil's attempts by: `learnerRef` (the LearnerProfile of `learnerId`, the
 * portal's scope key) and `assessmentTitle` (the test's title, so a pupil sees
 * a name instead of a uuid). Client values are overwritten, so nobody can put
 * an attempt in another pupil's portal list.
 *
 * Run by AssessmentAttemptGateListener right after AssessmentResultAudience,
 * for every create the gate lets through, in the same fixed order: a refused
 * attempt is never stamped. A failed lookup stamps null and never stops the
 * create; the attempt then stays out of the portal list (fail closed).
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
 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-every-attempt-carries-a-server-stamped-learnerref-and-assessment-title
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps learnerRef and assessmentTitle on a new AssessmentResult.
 *
 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-every-attempt-carries-a-server-stamped-learnerref-and-assessment-title
 */
class AssessmentResultPortalStamp {

	private const LEARNIQ_REGISTER = 'learniq';
	private const ASSESSMENT_SCHEMA = 'exam';

	/**
	 * Constructor.
	 *
	 * @param LearnerRefResolver $profiles Nextcloud user id to LearnerProfile uuid.
	 * @param ObjectService $objectService OpenRegister object access (the test's title).
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LearnerRefResolver $profiles,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stamp the attempt being created, merging with what other steps set.
	 *
	 * @param ObjectCreatingEvent $event The AssessmentResult creating event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/assessment-portal-endpoints/specs/assessment/spec.md#requirement-every-attempt-carries-a-server-stamped-learnerref-and-assessment-title
	 */
	public function stamp(ObjectCreatingEvent $event): void {
		$payload = array_merge(($event->getObject()->getObject() ?? []), $event->getModifiedData());

		$event->setModifiedData(
			array_merge(
				$event->getModifiedData(),
				[
					'learnerRef' => $this->learnerRef(learnerId: $payload['learnerId'] ?? ''),
					'assessmentTitle' => $this->title(assessmentId: $payload['assessmentId'] ?? ''),
				]
			)
		);
	}//end stamp()

	/**
	 * The LearnerProfile uuid of the learner, or null.
	 *
	 * @param mixed $learnerId The attempt's learnerId.
	 *
	 * @return string|null
	 */
	private function learnerRef(mixed $learnerId): ?string {
		if (is_string($learnerId) === false || $learnerId === '') {
			return null;
		}

		try {
			return $this->profiles->resolveAcrossTenants(learnerId: $learnerId);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[AssessmentResultPortalStamp] Could not resolve the learner profile, stamping null: {msg}',
				['msg' => $exception->getMessage()]
			);
			return null;
		}
	}//end learnerRef()

	/**
	 * The test's title, or null.
	 *
	 * @param mixed $assessmentId The attempt's assessmentId.
	 *
	 * @return string|null
	 */
	private function title(mixed $assessmentId): ?string {
		if (is_string($assessmentId) === false || $assessmentId === '') {
			return null;
		}

		try {
			$assessment = $this->objectService->find(
				id: $assessmentId,
				register: self::LEARNIQ_REGISTER,
				schema: self::ASSESSMENT_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			return null;
		}

		$title = ($assessment?->jsonSerialize()['title'] ?? null);
		if (is_string($title) === false || $title === '') {
			return null;
		}

		return $title;
	}//end title()
}//end class
