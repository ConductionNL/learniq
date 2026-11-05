<?php

/**
 * TEST STUB: integriq's LearningMaterialRequestedEvent as its contract reads
 * (ConductionNL/integriq#3024): constructed with (sourceApp, request), answered
 * with an answer or a refusal on the same instance. Replace with a verbatim copy
 * of integriq's class once it is on development.
 *
 * @category Event
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * "Search or find at this learning material source", with an answer slot.
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
 */
class LearningMaterialRequestedEvent extends Event {

	/**
	 * Whether a handler took the request.
	 *
	 * @var boolean
	 */
	private bool $handled = false;

	/**
	 * The answer.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $answer = null;

	/**
	 * Why the request was refused.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $refusal = null;

	/**
	 * Constructor.
	 *
	 * @param string               $sourceApp The app asking.
	 * @param array<string, mixed> $request   The request, keyed by operation.
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly array $request,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * @return string The app asking.
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * @return array<string, mixed> The request.
	 */
	public function getRequest(): array {
		return $this->request;
	}//end getRequest()

	/**
	 * Answer the request.
	 *
	 * @param array<string, mixed>|null $answer The answer, null for nothing found.
	 *
	 * @return void
	 */
	public function setAnswer(?array $answer): void {
		$this->answer  = $answer;
		$this->handled = true;
	}//end setAnswer()

	/**
	 * Refuse the request.
	 *
	 * @param array<string, mixed> $refusal `{code, reason, ...}`.
	 *
	 * @return void
	 */
	public function refuse(array $refusal): void {
		$this->refusal = $refusal;
		$this->handled = true;
	}//end refuse()

	/**
	 * @return bool Whether a handler took the request.
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * @return array<string, mixed>|null The answer.
	 */
	public function getAnswer(): ?array {
		return $this->answer;
	}//end getAnswer()

	/**
	 * @return array<string, mixed>|null The refusal.
	 */
	public function getRefusal(): ?array {
		return $this->refusal;
	}//end getRefusal()
}//end class
