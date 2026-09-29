<?php

/**
 * Learniq Support Request Submit Handler
 *
 * IEventListener for SupportRequest lifecycle → `submitted`
 * (the OR ObjectTransitionedEvent with schema=support-request, to=submitted).
 *
 * Algorithm:
 * 1. Ask integriq for an `swv` exchange job over this support request, with
 *    the integriq mapping `learniq-swv-export-zorgvraag`.
 * 2. Stamp the job's id back onto the SupportRequest's dataExchangeJobId.
 * 3. Open a pending DossierReview for the parents: the exchange gate refuses
 *    the job until a parent approved the file.
 *
 * The file itself is composed when integriq asks learniq's gate
 * (ExchangeGateService, DataExchangePayloadBuilder::composeSwvFile()), not
 * here. Without integriq nothing is requested and nothing is opened.
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
 * @spec openspec/changes/archive/2026-07-13-zorgvraag-swv-tlv-chain/tasks.md#task-4.5
 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\IntegriqExchangeClient;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asks integriq for the SWV exchange job when a SupportRequest is submitted.
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
 */
class SupportRequestSubmitHandler implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const SUPPORT_REQUEST_SCHEMA = 'support-request';
	private const DOSSIER_REVIEW_SCHEMA = 'dossier-review';
	private const SWV_TARGET = 'swv';
	public const SWV_MAPPING = 'learniq-swv-export-zorgvraag';

	/**
	 * App config key naming the integriq SWV receiver (`swv-kindkans`, `swv-ldos`).
	 */
	public const RECEIVER_CONFIG_KEY = 'swv_receiver_id';

	/**
	 * Constructor.
	 *
	 * @param ObjectService          $objectService OR object access service.
	 * @param IntegriqExchangeClient $integriq      Asks integriq for the job.
	 * @param IAppConfig             $appConfig     Reads the school's SWV receiver.
	 * @param LoggerInterface        $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IntegriqExchangeClient $integriq,
		private readonly IAppConfig $appConfig,
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
	 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		if ($event->getRegister() !== self::LEARNIQ_REGISTER
			|| $event->getSchema() !== self::SUPPORT_REQUEST_SCHEMA
			|| $event->getTo() !== 'submitted'
		) {
			return;
		}

		$this->requestSwvJob(event: $event);

	}//end handle()

	/**
	 * Ask integriq for the SWV job, stamp it, and open the parents' review.
	 *
	 * @param ObjectTransitionedEvent $event The submitted-state transition event.
	 *
	 * @return void
	 */
	private function requestSwvJob(ObjectTransitionedEvent $event): void {
		$supportRequest = $event->getObject()->jsonSerialize();
		$supportRequestId = (string)($supportRequest['id'] ?? ($supportRequest['uuid'] ?? ''));
		$learnerId = (string)($supportRequest['learnerId'] ?? '');
		$tenantId = (string)($supportRequest['tenant_id'] ?? '');

		if ($supportRequestId === '' || $learnerId === '') {
			$this->logger->warning(
				'[SupportRequestSubmitHandler] SupportRequest {id} has no id or learnerId, so no SWV exchange is requested.',
				['id' => $supportRequestId]
			);
			return;
		}

		$requestedBy = (string)($supportRequest['raisedBy'] ?? '');
		if ($requestedBy === '') {
			$requestedBy = 'system';
		}

		try {
			$jobId = $this->integriq->requestJob(
				target: self::SWV_TARGET,
				direction: 'export',
				ownerRef: self::SUPPORT_REQUEST_SCHEMA . '/' . $supportRequestId,
				scope: [
					'schema' => self::SUPPORT_REQUEST_SCHEMA,
					'recordIds' => [$supportRequestId],
					'tenantId' => $tenantId,
					'receiverId' => $this->appConfig->getValueString('learniq', self::RECEIVER_CONFIG_KEY, ''),
				],
				mappingSlug: self::SWV_MAPPING,
				requestedBy: $requestedBy,
				name: 'SWV zorgvraag'
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[SupportRequestSubmitHandler] No SWV exchange for SupportRequest {id}: {msg}',
				['id' => $supportRequestId, 'msg' => $exception->getMessage()]
			);
			return;
		}

		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::SUPPORT_REQUEST_SCHEMA,
			object: array_merge($supportRequest, ['dataExchangeJobId' => $jobId]),
			uuid: $supportRequestId
		);

		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::DOSSIER_REVIEW_SCHEMA,
			object: [
				'exchangeJobId' => $jobId,
				'target' => self::SWV_TARGET,
				'learnerUserId' => $learnerId,
				'status' => 'pending',
				'tenant_id' => $tenantId,
			]
		);

	}//end requestSwvJob()
}//end class
