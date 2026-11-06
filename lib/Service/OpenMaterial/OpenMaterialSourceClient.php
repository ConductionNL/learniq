<?php

/**
 * Learniq Open Material Source Client
 *
 * The one place learniq reaches integriq's learning material sources: search
 * and find one record. Duck-typed like
 * IntegriqExchangeClient: the event class is named by string, so learniq has
 * no class dependency on integriq and fails closed without it.
 *
 * @category Service
 * @package  OCA\Learniq\Service\OpenMaterial
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
 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\OpenMaterial;

use OCA\Learniq\Exception\IntegriqUnavailableException;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Asks integriq's learning material source for records.
 *
 * Contract (integriq openspec/specs/edurep-source, integriq#3024): dispatch
 * `LearningMaterialRequestedEvent(sourceApp, request)`, then read getAnswer()
 * or getRefusal() `{code, reason}` off the same instance. When nothing handles
 * the event, integriq is absent. The refusal reason is Dutch text meant for
 * integriq's own pages, so learniq names the code and never passes it on.
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
 */
class OpenMaterialSourceClient {

	public const INTEGRIQ_APP = 'integriq';
	public const OWNER_APP = 'learniq';
	public const SOURCE = 'edurep';
	public const REQUEST_EVENT = 'OCA\\Integriq\\Event\\LearningMaterialRequestedEvent';

	/**
	 * Results per page; integriq accepts at most 50.
	 */
	public const PAGE_SIZE = 10;

	/**
	 * What each refusal code means, in learniq's words.
	 */
	private const REFUSALS = [
		'source-unavailable'   => 'The source did not answer.',
		'source-disabled'      => 'The source is switched off in integriq.',
		'source-not-available' => 'The source is not available.',
		'unknown-source'       => 'Integriq does not know this source.',
		'unknown-operation'    => 'Integriq does not offer this operation.',
		'failed'               => 'Integriq could not reach the source.',
	];

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher Dispatches integriq's event.
	 * @param IAppManager      $appManager Tells whether integriq is enabled.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * Whether integriq is enabled and offers the source.
	 *
	 * @return bool True when a search can be made.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-sources-are-connections-an-administrator-switches
	 */
	public function isAvailable(): bool {
		return $this->appManager->isEnabledForUser(self::INTEGRIQ_APP) === true
			&& class_exists($this->eventClass()) === true;
	}//end isAvailable()

	/**
	 * Search the source.
	 *
	 * @param array{query: string, filters: array<string, array<int, string>>, page: int, facets?: bool} $request The search.
	 *
	 * @return array<string, mixed> The search result: total, page, pageSize, records, facets.
	 *
	 * @throws IntegriqUnavailableException When integriq or the source does not answer, or integriq refused.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
	 */
	public function search(array $request): array {
		$filters = $request['filters'];
		$filters['collection'] = ($filters['collections'] ?? []);
		$filters['pageSize']   = self::PAGE_SIZE;
		$filters['facets']     = ($request['facets'] ?? true);
		unset($filters['collections']);

		$answer = $this->ask(request: ['operation' => 'search', 'query' => $request['query'], 'filters' => $filters, 'page' => $request['page']]);

		return ($answer ?? []);
	}//end search()

	/**
	 * Find one record by its identifier at the source.
	 *
	 * @param string $sourceId The record identifier.
	 *
	 * @return array<string, mixed>|null The normalised record, or null when the source has none.
	 *
	 * @throws IntegriqUnavailableException When integriq or the source does not answer, or integriq refused.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-licence-rules-are-enforced-on-the-server
	 */
	public function find(string $sourceId): ?array {
		return $this->ask(request: ['operation' => 'find', 'id' => $sourceId]);
	}//end find()

	/**
	 * Dispatch one request and read its answer.
	 *
	 * @param array<string, mixed> $request The request, with its operation.
	 *
	 * @return array<string, mixed>|null The answer, null when the source has nothing.
	 *
	 * @throws IntegriqUnavailableException When nobody handled it, or integriq refused.
	 */
	private function ask(array $request): ?array {
		$event = $this->newEvent(request: array_merge(['source' => self::SOURCE], $request));
		$this->dispatcher->dispatchTyped($event);
		$this->throwRefusal(refusal: $this->read(event: $event, getter: 'getRefusal'));
		if ($this->read(event: $event, getter: 'isHandled') !== true) {
			throw new IntegriqUnavailableException(message: 'Integriq did not answer the ' . (string)$request['operation'] . ' request.');
		}

		$answer = $this->read(event: $event, getter: 'getAnswer');
		if (is_array($answer) === false) {
			return null;
		}

		return $answer;
	}//end ask()

	/**
	 * Instantiate integriq's request event, or fail closed.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return Event The event.
	 *
	 * @throws IntegriqUnavailableException When integriq or the event class is absent.
	 */
	private function newEvent(array $request): Event {
		if ($this->isAvailable() === false) {
			throw new IntegriqUnavailableException(
				message: 'Integriq is not installed, not enabled or too old to search open learning material.'
			);
		}

		$class = $this->eventClass();
		$event = new $class(self::OWNER_APP, $request);
		if (($event instanceof Event) === false) {
			throw new IntegriqUnavailableException(message: 'Integriq\'s ' . $class . ' is not an event.');
		}

		return $event;
	}//end newEvent()

	/**
	 * Turn a refusal into the source-down answer, in learniq's words for its code.
	 *
	 * The panel has one answer for a source it cannot use: the source-down state.
	 *
	 * @param mixed $refusal The refusal, `{code, reason, ...}`, or null.
	 *
	 * @return void
	 *
	 * @throws IntegriqUnavailableException When integriq refused or the source did not answer.
	 */
	private function throwRefusal(mixed $refusal): void {
		if (is_array($refusal) === false) {
			return;
		}

		$code = (string)($refusal['code'] ?? 'failed');
		throw new IntegriqUnavailableException(message: (self::REFUSALS[$code] ?? self::REFUSALS['failed']) . ' (' . $code . ')');
	}//end throwRefusal()

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
	 * Integriq's request event class, kept a plain string for static analysis.
	 *
	 * @return string The class name.
	 */
	private function eventClass(): string {
		return self::REQUEST_EVENT;
	}//end eventClass()
}//end class
