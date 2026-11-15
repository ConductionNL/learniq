<?php

/**
 * TEST STUB: a verbatim copy of integriq's LearningMaterialRequestedEvent (ConductionNL/integriq development,
 * 1a8d91fe), so learniq's unit tests construct the real contract, never a double, and a wrong getter fails.
 * The code is verbatim; the @spec tags point at the learniq requirement that consumes it,
 * because integriq's spec path does not exist in this repository.
 *
 * Integriq LearningMaterialRequested Event.
 *
 * ADR-041 typed command for sibling apps (edurep-source). A consumer such as
 * learniq asks integriq to search a learning material source, find one
 * record, report the connection status or save its settings, and reads the
 * answer or the refusal off the same instance after dispatchTyped().
 *
 * Request shape, by `operation`:
 * - `search`: `source`, `query` (string), `filters` (array), `page` (int)
 * - `find`: `source`, `id` (string)
 * - `status`: `source`
 * - `saveSettings`: `source`, `settings` (`enabled`, `disabledCollections`, `language`)
 *
 * @category Event
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * Typed cross-app question: "search, find or report this learning material source".
 *
 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
 */
class LearningMaterialRequestedEvent extends Event {
	/**
	 * The handled.
	 *
	 * @var bool
	 */
	private bool $handled = false;

	/**
	 * The answer.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $answer = null;

	/**
	 * The refusal, `code` and `reason`.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $refusal = null;

	/**
	 * Constructor.
	 *
	 * @param string $sourceApp The asking app's id.
	 * @param array<string,mixed> $request The request: `operation`, `source` and the operation's fields.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly array $request,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The asking app's id.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The request.
	 *
	 * @return array<string,mixed> The request.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
	 */
	public function getRequest(): array {
		return $this->request;
	}//end getRequest()

	/**
	 * Answer the request.
	 *
	 * @param array<string,mixed>|null $answer The answer; null when `find` found nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
	 */
	public function answer(?array $answer): void {
		$this->answer = $answer;
		$this->refusal = null;
		$this->handled = true;
	}//end answer()

	/**
	 * Refuse the request.
	 *
	 * @param array<string,mixed> $refusal `code`, `reason` and, for `source-unavailable`, `source` and `lastSuccess`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
	 */
	public function refuse(array $refusal): void {
		$this->refusal = $refusal;
		$this->answer = null;
		$this->handled = true;
	}//end refuse()

	/**
	 * Whether integriq handled the request.
	 *
	 * @return bool True once answered or refused.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * The answer.
	 *
	 * @return array<string,mixed>|null The answer, or null.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
	 */
	public function getAnswer(): ?array {
		return $this->answer;
	}//end getAnswer()

	/**
	 * The refusal.
	 *
	 * @return array<string,mixed>|null The refusal, or null.
	 *
	 * @spec openspec/specs/open-learning-material/spec.md#requirement-the-search-panel-shows-results-with-facets-and-three-states
	 */
	public function getRefusal(): ?array {
		return $this->refusal;
	}//end getRefusal()
}//end class
