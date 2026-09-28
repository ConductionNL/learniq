<?php

/**
 * Learniq School Advies Send To ROD Handler
 *
 * IEventListener for SchoolAdvies lifecycle -> `verzonden-naar-rod`
 * (the OR ObjectTransitionedEvent with schema=school-advies,
 * action=verzendenNaarRod).
 *
 * Algorithm:
 * 1. Ask integriq for a `bron-rod` exchange job for this advice, with
 *    `berichtsoort: schooladvies` in its scope.
 * 2. Stamp the job's id back onto the SchoolAdvies' dataExchangeJobId.
 *
 * The job names mapping `learniq-bron-rod-export-schooladvies`: the gate
 * composes DUO's AanleverenAdviesVO field set for it (decision D32) and
 * refuses the job `statutory-incomplete` when a required field is missing.
 * Without integriq nothing is asked.
 *
 * ADR-031 legitimate exception: cross-app work in response to a lifecycle
 * transition cannot be expressed as schema metadata declarations.
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
 * @spec openspec/specs/enrolment/spec.md#requirement-sending-a-definitief-schooladvies-to-rod-auto-queues-the-existing-bron-rod-dataexchangejob
 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
 * @spec openspec/specs/data-exchange/spec.md#requirement-a-school-advice-goes-to-rod-with-duos-aanleverenadviesvo-field-set
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ExchangeDisclosure;
use OCA\Learniq\Service\IntegriqExchangeClient;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asks integriq for the bron-rod exchange job when a SchoolAdvies is sent to ROD.
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
 */
class SchoolAdviesSendToRodHandler implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const SCHOOL_ADVIES_SCHEMA = 'school-advies';
	private const ROD_TARGET = 'bron-rod';

	/**
	 * Constructor.
	 *
	 * @param ObjectService          $objectService OR object access service.
	 * @param IntegriqExchangeClient $integriq      Asks integriq for the job.
	 * @param LoggerInterface        $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IntegriqExchangeClient $integriq,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enrolment/spec.md#scenario-sending-a-definitief-advies-creates-and-links-a-bron-rod-dataexchangejob
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		if ($event->getRegister() !== self::LEARNIQ_REGISTER
			|| $event->getSchema() !== self::SCHOOL_ADVIES_SCHEMA
			|| $event->getTo() !== 'verzonden-naar-rod'
		) {
			return;
		}

		$this->requestRodJob(event: $event);

	}//end handle()

	/**
	 * Ask integriq for the bron-rod job and stamp it on the advice.
	 *
	 * @param ObjectTransitionedEvent $event The verzonden-naar-rod transition event.
	 *
	 * @return void
	 */
	private function requestRodJob(ObjectTransitionedEvent $event): void {
		$schoolAdvies = $event->getObject()->jsonSerialize();
		$schoolAdviesId = (string)($schoolAdvies['id'] ?? ($schoolAdvies['uuid'] ?? ''));
		$learnerId = (string)($schoolAdvies['learnerId'] ?? '');

		if ($schoolAdviesId === '' || $learnerId === '') {
			$this->logger->warning(
				'[SchoolAdviesSendToRodHandler] SchoolAdvies {id} has no id or learnerId, so no ROD exchange is requested.',
				['id' => $schoolAdviesId]
			);
			return;
		}

		try {
			$jobId = $this->integriq->requestJob(
				target: self::ROD_TARGET,
				direction: 'export',
				ownerRef: self::SCHOOL_ADVIES_SCHEMA . '/' . $schoolAdviesId,
				scope: [
					'schema' => self::SCHOOL_ADVIES_SCHEMA,
					'recordIds' => [$schoolAdviesId],
					'tenantId' => (string)($schoolAdvies['tenant_id'] ?? ''),
					'berichtsoort' => 'schooladvies',
				],
				mappingSlug: ExchangeDisclosure::ROD_SCHOOL_ADVICE_MAPPING,
				requestedBy: 'system',
				name: 'ROD schooladvies'
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[SchoolAdviesSendToRodHandler] No ROD exchange for SchoolAdvies {id}: {msg}',
				['id' => $schoolAdviesId, 'msg' => $exception->getMessage()]
			);
			return;
		}

		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::SCHOOL_ADVIES_SCHEMA,
			object: array_merge($schoolAdvies, ['dataExchangeJobId' => $jobId]),
			uuid: $schoolAdviesId
		);

	}//end requestRodJob()
}//end class
