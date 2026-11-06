<?php

/**
 * Learniq Excuse Request Staff Owner
 *
 * The owner fields of an absence report a member of staff files in learniq:
 * the signed-in caller as `submittedBy`, and, when the report names the pupil
 * only by profile (`learnerRef`, as the staff form does), the pupil's
 * `learnerId` and school from that profile. ExcuseRequestOwnerStamp applies
 * them; a client value for `submittedBy` is never kept.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Attendance
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
 * @spec openspec/specs/attendance/spec.md#requirement-the-server-stamps-who-an-excuse-request-is-about-and-who-filed-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Attendance;

use OCA\Learniq\Service\LearnerRefResolver;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Submitter, pupil and school of a staff absence report.
 *
 * @spec openspec/specs/attendance/spec.md#requirement-the-server-stamps-who-an-excuse-request-is-about-and-who-filed-it
 */
class ExcuseRequestStaffOwner {

	/**
	 * Constructor.
	 *
	 * @param LearnerRefResolver $profiles    LearnerProfile by uuid.
	 * @param IUserSession       $userSession The signed-in caller.
	 * @param LoggerInterface    $logger      PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LearnerRefResolver $profiles,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The owner fields a signed-in create stores: the caller as `submittedBy`,
	 * and the pupil from the profile when no `learnerId` was sent. Empty for
	 * an update or without a signed-in caller.
	 *
	 * @param bool                 $isCreate Whether the write creates the report.
	 * @param array<string, mixed> $payload  The ExcuseRequest being written.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-the-server-stamps-who-an-excuse-request-is-about-and-who-filed-it
	 */
	public function ownerFields(bool $isCreate, array $payload): array {
		$userId = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($isCreate === false || $userId === '') {
			return [];
		}

		$stamp = ['submittedBy' => $userId];
		if (self::text(value: ($payload['learnerId'] ?? null)) !== '') {
			return $stamp;
		}

		$learnerId = self::text(value: ($this->profileOf(learnerRef: self::text(value: ($payload['learnerRef'] ?? null)))['ncUserId'] ?? null));
		if ($learnerId !== '') {
			$stamp['learnerId'] = $learnerId;
		}

		return $stamp;
	}//end forCreate()

	/**
	 * The school a report without one stores: that of the pupil's profile.
	 * Empty when the report names a school or the profile has none.
	 *
	 * @param array<string, mixed> $payload    The ExcuseRequest being written.
	 * @param string|null          $learnerRef The pupil's LearnerProfile uuid.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/attendance/spec.md#requirement-the-server-stamps-who-an-excuse-request-is-about-and-who-filed-it
	 */
	public function schoolFields(array $payload, ?string $learnerRef): array {
		if (self::text(value: ($payload['tenant_id'] ?? null)) !== '' || $learnerRef === null) {
			return [];
		}

		$tenant = self::text(value: ($this->profileOf(learnerRef: $learnerRef)['tenant_id'] ?? null));
		if ($tenant === '') {
			return [];
		}

		return ['tenant_id' => $tenant];
	}//end schoolFields()

	/**
	 * A LearnerProfile by uuid, or an empty array when unknown or unreadable.
	 *
	 * @param string $learnerRef The LearnerProfile uuid.
	 *
	 * @return array<string, mixed>
	 */
	private function profileOf(string $learnerRef): array {
		if ($learnerRef === '') {
			return [];
		}

		try {
			return ($this->profiles->byRef(learnerRef: $learnerRef) ?? []);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ExcuseRequestStaffOwner] Could not read the learner profile: {msg}',
				['msg' => $exception->getMessage()]
			);
			return [];
		}
	}//end profileOf()

	/**
	 * A string value, or '' for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end text()
}//end class
