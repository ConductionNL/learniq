<?php

/**
 * Learniq LvsResult learnerRef Stamp
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Stamps `learnerRef` (the LearnerProfile UUID, the domain reference to the
 * pupil) onto every LvsResult as it is created or updated, derived from
 * `learnerId` (the Nextcloud user id).
 *
 * Two paths create an LvsResult: the import landing for an integriq
 * `lvs-results` job (a Cito, IEP, Boom or Dia delivery) and a result entered
 * by hand. Neither set `learnerRef`, so a Cito result was linked to its pupil
 * only through the user id. Stamping on the write path covers both, and the
 * next one.
 *
 * Posture, mirroring GradeEntryLearnerRefStamp:
 * - the server derives the value; a `learnerRef` sent by the client is
 *   ignored, so nobody can file a result under another pupil;
 * - a learner without a profile gets `learnerRef: null` (fail-closed);
 * - the stamp never blocks a write. When the lookup fails on create the
 *   value is null; on update the stored value is kept while the result stays
 *   with the same learner.
 *
 * The import runs inside integriq's background job, without a session, so the
 * profile is looked up in the tenant the result carries (`tenant_id`), not in
 * the session's tenant. A result without a tenant falls back to the session.
 *
 * ADR-031 exception: a cross-schema lookup (LvsResult to LearnerProfile by a
 * non-key field) that no schema calculation can express.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lvs-result-learner-ref/specs/data-exchange/spec.md#requirement-every-lvsresult-names-its-pupil-by-learnerprofile-reference
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Kernel\Contracts\LearnerRefResolverInterface;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Derives LvsResult.learnerRef from LvsResult.learnerId on every write.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/lvs-result-learner-ref/specs/data-exchange/spec.md#requirement-every-lvsresult-names-its-pupil-by-learnerprofile-reference
 */
class LvsResultLearnerRefStamp implements IEventListener {

	private const LVS_SCHEMA = 'lvs-result';

	/**
	 * Stamps learnerRef onto the writes this listener owns.
	 *
	 * @var LearnerRefStamping
	 */
	private readonly LearnerRefStamping $stamping;

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Entity schema id to slug.
	 * @param LearnerRefResolverInterface $learnerRefs Nextcloud user id to LearnerProfile UUID.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		LearnerRefResolverInterface $learnerRefs,
		LoggerInterface $logger,
	) {
		$this->stamping = new LearnerRefStamping(learnerRefs: $learnerRefs, logger: $logger, label: 'LvsResultLearnerRefStamp');
	}//end __construct()

	/**
	 * Stamp learnerRef on an LvsResult create or update.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lvs-result-learner-ref/specs/data-exchange/spec.md#requirement-every-lvsresult-names-its-pupil-by-learnerprofile-reference
	 */
	public function handle(Event $event): void {
		$write = SchemaWrite::of(event: $event, schemaResolver: $this->schemaResolver);
		if ($write === null || $write->slug !== self::LVS_SCHEMA) {
			return;
		}

		$this->stamping->stampInOwnTenant(write: $write);
	}//end handle()
}//end class
