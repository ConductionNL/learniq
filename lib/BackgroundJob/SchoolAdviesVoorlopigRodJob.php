<?php

/**
 * Learniq School Advice Voorlopig ROD Job
 *
 * The deferred half of SchoolAdviesVoorlopigRodHandler: re-reads the
 * SchoolAdvies, and when its voorlopig advice is still due for ROD asks
 * integriq for the bron-rod schooladvies exchange with the school advice
 * mapping (Advies1 from the voorlopig fields, Advies2 empty until
 * `definitief`), then stamps `voorlopigExchangeJobId` so it is sent once.
 *
 * @category BackgroundJob
 * @package  OCA\Learniq\BackgroundJob
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-the-voorlopig-school-advice-goes-to-rod-when-it-is-given
 */

declare(strict_types=1);

namespace OCA\Learniq\BackgroundJob;

use OCA\Learniq\Service\ExchangeDisclosure;
use OCA\Learniq\Service\IntegriqExchangeClient;
use OCA\Learniq\Service\SchoolAdviesRodTiming;
use OCA\OpenRegister\BackgroundJob\ActorForwardedJob;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends a voorlopig school advice to ROD once.
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-the-voorlopig-school-advice-goes-to-rod-when-it-is-given
 */
class SchoolAdviesVoorlopigRodJob extends ActorForwardedJob {

	private const LEARNIQ_REGISTER = 'learniq';
	private const SCHOOL_ADVIES_SCHEMA = 'school-advies';
	private const ROD_TARGET = 'bron-rod';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param IUserSession $userSession User session, for the actor.
	 * @param IUserManager $userManager User manager, for the actor.
	 * @param OrganisationService $organisation The actor's organisation.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param IntegriqExchangeClient $integriq Asks integriq for the exchange.
	 * @param SchoolAdviesRodTiming $timing Whether the advice is still due.
	 */
	public function __construct(
		ITimeFactory $time,
		IUserSession $userSession,
		IUserManager $userManager,
		OrganisationService $organisation,
		LoggerInterface $logger,
		private readonly ObjectService $objectService,
		private readonly IntegriqExchangeClient $integriq,
		private readonly SchoolAdviesRodTiming $timing,
	) {
		parent::__construct(
			time: $time,
			userSession: $userSession,
			userManager: $userManager,
			organisation: $organisation,
			logger: $logger
		);
	}//end __construct()

	/**
	 * Send each queued advice that is still due.
	 *
	 * @param DeferredListenerContext $context The queued entries and the actor.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-the-voorlopig-school-advice-goes-to-rod-when-it-is-given
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		foreach ($context->getEntries() as $entry) {
			$id = (string)($entry['schoolAdviesId'] ?? '');
			if ($id === '') {
				continue;
			}

			try {
				$this->send(id: $id);
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[SchoolAdviesVoorlopigRodJob] No ROD exchange for the voorlopig advice {id}: {error}',
					context: ['id' => $id, 'error' => $e->getMessage()]
				);
			}
		}
	}//end runDeferred()

	/**
	 * Re-read one advice and send it when still due.
	 *
	 * @param string $id The SchoolAdvies uuid.
	 *
	 * @return void
	 */
	private function send(string $id): void {
		$entity = $this->objectService->find(id: $id, register: self::LEARNIQ_REGISTER, schema: self::SCHOOL_ADVIES_SCHEMA);
		if ($entity === null) {
			return;
		}

		$advies = $entity->jsonSerialize();
		if ($this->timing->voorlopigDue(advies: $advies) === false) {
			return;
		}

		$jobId = $this->integriq->requestJob(
			target: self::ROD_TARGET,
			direction: 'export',
			ownerRef: self::SCHOOL_ADVIES_SCHEMA . '/' . $id,
			scope: [
				'schema' => self::SCHOOL_ADVIES_SCHEMA,
				'recordIds' => [$id],
				'tenantId' => (string)($advies['tenant_id'] ?? ''),
				'berichtsoort' => 'schooladvies',
			],
			mappingSlug: ExchangeDisclosure::ROD_SCHOOL_ADVICE_MAPPING,
			requestedBy: 'system',
			name: 'ROD voorlopig schooladvies'
		);

		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::SCHOOL_ADVIES_SCHEMA,
			object: array_merge($advies, ['voorlopigExchangeJobId' => $jobId]),
			uuid: $id
		);
	}//end send()
}//end class
