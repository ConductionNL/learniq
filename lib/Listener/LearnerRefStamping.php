<?php

/**
 * Learniq learnerRef stamping.
 *
 * Derives `learnerRef` (the LearnerProfile UUID) from `learnerId` (the
 * Nextcloud user id) on a write and stamps it onto the payload. Shared by the
 * stamps of the schemas that name their pupil this way.
 *
 * Posture:
 * - the server derives the value; a `learnerRef` sent by the client is
 *   ignored;
 * - a learner without a profile gets `learnerRef: null` (fail-closed);
 * - the stamp never blocks a write. When the lookup fails on create the
 *   value is null; on update the stored value is kept while the object stays
 *   with the same learner.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/specs/grading/spec.md#requirement-every-gradeentry-carries-a-server-stamped-learnerref
 * @spec openspec/changes/lvs-result-learner-ref/specs/data-exchange/spec.md#requirement-every-lvsresult-names-its-pupil-by-learnerprofile-reference
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\LearnerRefResolver;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps learnerRef, derived from learnerId, onto one write.
 *
 * @spec openspec/specs/grading/spec.md#requirement-every-gradeentry-carries-a-server-stamped-learnerref
 * @spec openspec/changes/lvs-result-learner-ref/specs/data-exchange/spec.md#requirement-every-lvsresult-names-its-pupil-by-learnerprofile-reference
 */
final class LearnerRefStamping {

	/**
	 * Constructor.
	 *
	 * @param LearnerRefResolver $learnerRefs Nextcloud user id to LearnerProfile UUID.
	 * @param LoggerInterface $logger PSR logger.
	 * @param string $label The owning listener's name, prefixed to its warnings.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LearnerRefResolver $learnerRefs,
		private readonly LoggerInterface $logger,
		private readonly string $label,
	) {
	}//end __construct()

	/**
	 * Stamp learnerRef, looking the profile up in the session's tenant.
	 *
	 * @param SchemaWrite $write The write and its schema slug.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/grading/spec.md#requirement-every-gradeentry-carries-a-server-stamped-learnerref
	 */
	public function stamp(SchemaWrite $write): void {
		$this->stampWith(write: $write, ownTenant: false);
	}//end stamp()

	/**
	 * Stamp learnerRef, looking the profile up in the tenant the object
	 * carries (`tenant_id`), or in the session's tenant when it carries none.
	 *
	 * @param SchemaWrite $write The write and its schema slug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lvs-result-learner-ref/specs/data-exchange/spec.md#requirement-every-lvsresult-names-its-pupil-by-learnerprofile-reference
	 */
	public function stampInOwnTenant(SchemaWrite $write): void {
		$this->stampWith(write: $write, ownTenant: true);
	}//end stampInOwnTenant()

	/**
	 * Stamp the derived learnerRef onto the write's modified data.
	 *
	 * @param SchemaWrite $write The write and its schema slug.
	 * @param bool $ownTenant Whether to look in the object's own tenant.
	 *
	 * @return void
	 */
	private function stampWith(SchemaWrite $write, bool $ownTenant): void {
		$event = $write->event;
		$lookup = $this->lookupOf(write: $write, ownTenant: $ownTenant);

		$event->setModifiedData(
			array_merge(
				$event->getModifiedData(),
				['learnerRef' => $this->derive(lookup: $lookup)]
			)
		);
	}//end stampWith()

	/**
	 * What to look up for this write.
	 *
	 * @param SchemaWrite $write The write and its schema slug.
	 * @param bool $ownTenant Whether to read the object's own tenant.
	 *
	 * @return LearnerRefLookup
	 */
	private function lookupOf(SchemaWrite $write, bool $ownTenant): LearnerRefLookup {
		$event = $write->event;
		$payload = array_merge(($write->entity()->getObject() ?? []), $event->getModifiedData());
		$learnerId = self::stringOf(value: ($payload['learnerId'] ?? ''));
		$tenantId = '';
		if ($ownTenant === true) {
			$tenantId = self::stringOf(value: ($payload['tenant_id'] ?? ''));
		}

		$stored = null;
		if ($event instanceof ObjectUpdatingEvent === true) {
			$stored = self::storedRef(event: $event, learnerId: $learnerId);
		}

		return new LearnerRefLookup(learnerId: $learnerId, tenantId: $tenantId, stored: $stored);
	}//end lookupOf()

	/**
	 * The learnerRef to store: the resolved profile UUID, null when there is
	 * none, and on a lookup error the value already stored (null on create).
	 *
	 * @param LearnerRefLookup $lookup The learner, tenant and stored value.
	 *
	 * @return string|null
	 */
	private function derive(LearnerRefLookup $lookup): ?string {
		try {
			if ($lookup->tenantId !== '') {
				return $this->learnerRefs->resolveInTenant(learnerId: $lookup->learnerId, tenantId: $lookup->tenantId);
			}

			return $this->learnerRefs->resolve(learnerId: $lookup->learnerId);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'['.$this->label.'] Could not resolve the learner profile, keeping {kept}: {msg}',
				['kept' => ($lookup->stored ?? 'null'), 'msg' => $exception->getMessage()]
			);
			return $lookup->stored;
		}
	}//end derive()

	/**
	 * The learnerRef the object carries before this update, or null. Null too
	 * when the update moves the object to another learner: the old value then
	 * names the wrong profile, so a failed lookup must fail closed.
	 *
	 * @param ObjectUpdatingEvent $event The update event.
	 * @param string $learnerId The learnerId the object will carry.
	 *
	 * @return string|null
	 */
	private static function storedRef(ObjectUpdatingEvent $event, string $learnerId): ?string {
		$old = $event->getOldObject();
		if ($old === null) {
			return null;
		}

		$oldData = ($old->getObject() ?? []);
		if (($oldData['learnerId'] ?? null) !== $learnerId) {
			return null;
		}

		$ref = ($oldData['learnerRef'] ?? null);
		if (is_string($ref) === false || $ref === '') {
			return null;
		}

		return $ref;
	}//end storedRef()

	/**
	 * A payload value as a string, empty when it is not one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function stringOf(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end stringOf()
}//end class
