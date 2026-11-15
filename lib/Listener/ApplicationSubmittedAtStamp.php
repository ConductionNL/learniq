<?php

/**
 * Learniq Application SubmittedAt Stamp
 *
 * Writes `submittedAt` on an Application the moment its `submit` transition
 * lands, so the moment is the server's and never the applicant's form.
 *
 * ADR-031 legitimate exception: a transition cannot write a server-side
 * timestamp declaratively, so a listener on the transition does it.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-an-applicant-submits-an-application-to-an-open-admissions-round
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Stamps `submittedAt` on a submitted Application that has none.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#scenario-an-applicant-applies-before-the-deadline
 */
class ApplicationSubmittedAtStamp implements IEventListener {

	private const REGISTER = 'learniq';
	private const SCHEMA = 'application';

	/**
	 * Constructor.
	 *
	 * @param ObjectService                   $objectService OR object access service.
	 * @param ListenerSchemaResolverInterface $schemas       Resolves the event's register and schema ids to slugs.
	 * @param ITimeFactory                    $time          Clock for the stamp.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ListenerSchemaResolverInterface $schemas,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Stamp a just-submitted application.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#scenario-an-applicant-applies-before-the-deadline
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectTransitionedEvent === false || $event->getTo() !== 'submitted') {
			return;
		}

		if ($this->schemas->eventRegister(event: $event) !== self::REGISTER || $this->schemas->eventSchema(event: $event) !== self::SCHEMA) {
			return;
		}

		$application = $event->getObject()->jsonSerialize();
		$stamped = ($application['submittedAt'] ?? null);
		if (is_string($stamped) === true && $stamped !== '') {
			return;
		}

		$application['submittedAt'] = (new DateTimeImmutable('@' . $this->time->getTime()))->format(DATE_ATOM);
		$this->objectService->saveObject(
			object: $application,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: self::REGISTER, schema: self::SCHEMA)
			)
		);
	}//end handle()
}//end class
