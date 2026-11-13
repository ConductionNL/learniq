<?php

/**
 * Learniq GradeEntry learnerRef Stamp
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Stamps `learnerRef` (the LearnerProfile UUID the portal scopes on) onto
 * every GradeEntry as it is created or updated, derived from `learnerId`.
 *
 * Eight code paths create a GradeEntry (the assessment and portfolio grade
 * bridges, marking a submission, the cohort gradebook, the LTI score poll,
 * exemptions, werkproces assessments, the generic form) and none of them sets
 * `learnerRef`, so no grade ever reached a pupil or parent through the portal.
 * Stamping on the write path covers every one of them, and the next one.
 *
 * Posture, mirroring AssessmentResultAudience:
 * - the server derives the value; a `learnerRef` sent by the client is
 *   ignored, so nobody can route a grade to another pupil's parents;
 * - a learner without a profile gets `learnerRef: null`, which keeps the
 *   grade out of the portal (fail-closed);
 * - the stamp never blocks a write. When the lookup fails on create the
 *   value is null; on update the stored value is kept, so a transient error
 *   never hides a grade that was visible.
 *
 * ADR-031 exception: a cross-schema lookup (GradeEntry to LearnerProfile by a
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
 * @spec openspec/specs/grading/spec.md#requirement-every-gradeentry-carries-a-server-stamped-learnerref
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Kernel\Contracts\LearnerRefResolverInterface;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Derives GradeEntry.learnerRef from GradeEntry.learnerId on every write.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/grading/spec.md#requirement-every-gradeentry-carries-a-server-stamped-learnerref
 */
class GradeEntryLearnerRefStamp implements IEventListener {

	private const GRADE_ENTRY_SCHEMA = 'grade-entry';

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
		$this->stamping = new LearnerRefStamping(learnerRefs: $learnerRefs, logger: $logger, label: 'GradeEntryLearnerRefStamp');
	}//end __construct()

	/**
	 * Stamp learnerRef on a GradeEntry create or update.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/grading/spec.md#requirement-every-gradeentry-carries-a-server-stamped-learnerref
	 */
	public function handle(Event $event): void {
		$write = SchemaWrite::of(event: $event, schemaResolver: $this->schemaResolver);
		if ($write === null || $write->slug !== self::GRADE_ENTRY_SCHEMA) {
			return;
		}

		$this->stamping->stamp(write: $write);
	}//end handle()
}//end class
