<?php

/**
 * TEST STUB: the contract learniq asks integriq to implement for its SBB source
 * (workplace-leerbedrijf-check-through-integriq, design D4). Integriq has no SBB
 * source yet, so this is the agreed shape, not a copy: it follows integriq's
 * LearningMaterialRequestedEvent (answer/refuse/isHandled/getAnswer/getRefusal),
 * so learniq's unit tests construct the contract, never a double.
 *
 * Request: `source` (`sbb`) and `number` (a KVK number or an SBB erkenningsnummer).
 * Answer: `found` (bool), `erkenningNumber` (string|null), `endDate` (Y-m-d|null).
 * Refusal: `code` and `reason`; `source-not-available` when integriq has no SBB source.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/workplace-leerbedrijf-check-through-integriq/specs/bpv/spec.md#requirement-the-check-stores-sbbs-answer-on-the-placement
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * Typed cross-app question: "look this training company up in the SBB source".
 *
 * @spec openspec/changes/workplace-leerbedrijf-check-through-integriq/specs/bpv/spec.md#requirement-the-check-stores-sbbs-answer-on-the-placement
 */
class TrainingCompanyLookupRequestedEvent extends Event {

	/**
	 * Whether integriq handled the request.
	 *
	 * @var boolean
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
	 * @param string              $sourceApp The asking app's id.
	 * @param array<string,mixed> $request   `source` and `number`.
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
	 * @return string
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The request.
	 *
	 * @return array<string,mixed>
	 */
	public function getRequest(): array {
		return $this->request;
	}//end getRequest()

	/**
	 * Answer the request.
	 *
	 * @param array<string,mixed>|null $answer `found`, `erkenningNumber`, `endDate`.
	 *
	 * @return void
	 */
	public function answer(?array $answer): void {
		$this->answer  = $answer;
		$this->refusal = null;
		$this->handled = true;
	}//end answer()

	/**
	 * Refuse the request.
	 *
	 * @param array<string,mixed> $refusal `code` and `reason`.
	 *
	 * @return void
	 */
	public function refuse(array $refusal): void {
		$this->refusal = $refusal;
		$this->answer  = null;
		$this->handled = true;
	}//end refuse()

	/**
	 * Whether integriq handled the request.
	 *
	 * @return bool
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * The answer.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getAnswer(): ?array {
		return $this->answer;
	}//end getAnswer()

	/**
	 * The refusal.
	 *
	 * @return array<string,mixed>|null
	 */
	public function getRefusal(): ?array {
		return $this->refusal;
	}//end getRefusal()
}//end class
