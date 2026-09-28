<?php

/**
 * Learniq Integriq Exchange Client
 *
 * Asks integriq to carry a data exchange job, or to store one of learniq's
 * own mappings, through integriq's typed events (ADR-041). Learniq keeps no
 * job of its own (decision D7).
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
 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Exception\ExchangeRequestRefusedException;
use OCA\Learniq\Exception\IntegriqUnavailableException;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * The only way learniq reaches integriq's exchange jobs.
 *
 * Duck-typed: integriq's event classes are named by string and instantiated
 * only when they exist, so learniq has no class dependency on integriq and
 * fails closed without it (contract: integriq
 * openspec/changes/learniq-exchange-jobs-native/contract.md).
 *
 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
 */
class IntegriqExchangeClient {

	public const INTEGRIQ_APP = 'integriq';
	public const OWNER_APP = 'learniq';
	public const JOB_EVENT = 'OCA\\Integriq\\Event\\ExchangeJobRequestedEvent';
	public const MAPPING_EVENT = 'OCA\\Integriq\\Event\\ExchangeMappingRequestedEvent';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher Dispatches integriq's events.
	 * @param IAppManager      $appManager Tells whether integriq is enabled.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * Whether integriq is enabled and new enough to carry exchange jobs.
	 *
	 * @return bool True when a request can be made.
	 *
	 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
	 */
	public function isAvailable(): bool {
		return $this->appManager->isEnabledForUser(self::INTEGRIQ_APP) === true
			&& class_exists($this->eventClass(name: self::JOB_EVENT)) === true;
	}//end isAvailable()

	/**
	 * Ask integriq to carry one exchange job.
	 *
	 * @param string                    $target      The exchange target, such as `leerplicht`.
	 * @param string                    $direction   export, import or sync.
	 * @param string                    $ownerRef    The row that caused the job, as `<schema>/<uuid>`.
	 * @param array<string, mixed>      $scope       Selectors and target parameters, never personal data.
	 * @param string|null               $mappingSlug The integriq mapping, such as `learniq-leerplicht-export-melding`.
	 * @param string                    $requestedBy The requesting user, or `system`.
	 * @param string                    $name        A label for integriq's job list.
	 * @param array<string, mixed>|null $history     Migration only: a finished job's history.
	 *
	 * @return string The integriq job id.
	 *
	 * @throws IntegriqUnavailableException    When integriq cannot carry it.
	 * @throws ExchangeRequestRefusedException When integriq refused it.
	 *
	 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
	 */
	public function requestJob(
		string $target,
		string $direction,
		string $ownerRef,
		array $scope,
		?string $mappingSlug,
		string $requestedBy,
		string $name = '',
		?array $history = null,
	): string {
		$event = $this->newEvent(
			name: self::JOB_EVENT,
			arguments: [self::OWNER_APP, $target, $direction, $ownerRef, $scope, $mappingSlug, $requestedBy, $name, $history]
		);
		$this->dispatcher->dispatchTyped($event);

		return $this->answer(event: $event, idGetter: 'getJobId', what: 'exchange job');
	}//end requestJob()

	/**
	 * Ask integriq to store one of learniq's own mappings, upserted by slug.
	 *
	 * @param string               $slug        The slug, starting with `learniq-`.
	 * @param string               $name        A label.
	 * @param string               $description What the mapping does.
	 * @param array<string, mixed> $mapping     Output key to source path or Twig template.
	 *
	 * @return string The integriq mapping id.
	 *
	 * @throws IntegriqUnavailableException    When integriq cannot carry it.
	 * @throws ExchangeRequestRefusedException When integriq refused it.
	 *
	 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-existing-exchange-rows-are-moved-to-integriq-or-archived
	 */
	public function requestMapping(string $slug, string $name, string $description, array $mapping): string {
		$event = $this->newEvent(
			name: self::MAPPING_EVENT,
			arguments: [self::OWNER_APP, $slug, $name, $description, $mapping, [], [], false]
		);
		$this->dispatcher->dispatchTyped($event);

		return $this->answer(event: $event, idGetter: 'getMappingId', what: 'mapping');
	}//end requestMapping()

	/**
	 * Instantiate one of integriq's events, or fail closed.
	 *
	 * @param string            $name      The event's class name.
	 * @param array<int, mixed> $arguments Its constructor arguments, in order.
	 *
	 * @return Event The event.
	 *
	 * @throws IntegriqUnavailableException When integriq or the event class is absent.
	 */
	private function newEvent(string $name, array $arguments): Event {
		$class = $this->eventClass(name: $name);
		if ($this->appManager->isEnabledForUser(self::INTEGRIQ_APP) === false || class_exists($class) === false) {
			throw new IntegriqUnavailableException(
				message: 'Integriq is not installed, not enabled or too old to carry learniq\'s data exchanges.'
			);
		}

		$event = new $class(...$arguments);
		if (($event instanceof Event) === false) {
			throw new IntegriqUnavailableException(message: 'Integriq\'s ' . $name . ' is not an event.');
		}

		return $event;
	}//end newEvent()

	/**
	 * Read the result slot of a dispatched request.
	 *
	 * @param Event  $event    The dispatched event.
	 * @param string $idGetter The getter of the created id.
	 * @param string $what     What was requested, for the message.
	 *
	 * @return string The id integriq returned.
	 *
	 * @throws IntegriqUnavailableException    When nobody answered.
	 * @throws ExchangeRequestRefusedException When integriq refused.
	 */
	private function answer(Event $event, string $idGetter, string $what): string {
		$refusal = $this->read(event: $event, getter: 'getRefusal');
		if (is_array($refusal) === true) {
			throw new ExchangeRequestRefusedException(
				refusalCode: (string)($refusal['code'] ?? 'refused'),
				reason: (string)($refusal['reason'] ?? 'Integriq refused the ' . $what . '.')
			);
		}

		$id = $this->read(event: $event, getter: $idGetter);
		if (is_string($id) === false || $id === '') {
			throw new IntegriqUnavailableException(message: 'Integriq did not answer the ' . $what . ' request.');
		}

		return $id;
	}//end answer()

	/**
	 * Call a getter on an integriq event that learniq knows only by contract.
	 *
	 * @param Event  $event  The event.
	 * @param string $getter The getter's name.
	 *
	 * @return mixed The value, or null when the getter does not exist.
	 */
	private function read(Event $event, string $getter): mixed {
		if (method_exists($event, $getter) === false) {
			return null;
		}

		return $event->$getter();
	}//end read()

	/**
	 * An integriq class name, kept a plain string for static analysis.
	 *
	 * @param string $name The class name.
	 *
	 * @return string The class name.
	 */
	private function eventClass(string $name): string {
		return $name;
	}//end eventClass()
}//end class
