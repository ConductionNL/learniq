<?php

/**
 * Test stub for OCA\OpenRegister\Event\ObjectCreatingEvent.
 *
 * Concrete (not abstract) — EnrolmentPrerequisiteListenerTest instantiates a
 * real instance (not a mock) so it can assert `isPropagationStopped()`/
 * `getErrors()` after `handle()` runs, mirroring the real class's behaviour
 * exactly (StoppableEventInterface + setErrors/stopPropagation).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Stub for ObjectCreatingEvent.
 */
class ObjectCreatingEvent extends Event implements StoppableEventInterface {
	/**
	 * @var ObjectEntity
	 */
	private ObjectEntity $object;

	/**
	 * @var bool
	 */
	private bool $propagationStopped = false;

	/**
	 * @var array<string, mixed>
	 */
	private array $errors = [];

	/**
	 * @var array<string, mixed>
	 */
	private array $modifiedData = [];

	/**
	 * @param ObjectEntity $object The object entity being created.
	 */
	public function __construct(ObjectEntity $object) {
		parent::__construct();
		$this->object = $object;
	}//end __construct()

	/**
	 * @return ObjectEntity
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()

	/**
	 * @return bool
	 */
	public function isPropagationStopped(): bool {
		return $this->propagationStopped;
	}//end isPropagationStopped()

	/**
	 * @return void
	 */
	public function stopPropagation(): void {
		$this->propagationStopped = true;
	}//end stopPropagation()

	/**
	 * @param array<string, mixed> $errors The error details.
	 *
	 * @return void
	 */
	public function setErrors(array $errors): void {
		$this->errors = $errors;
	}//end setErrors()

	/**
	 * @return array<string, mixed>
	 */
	public function getErrors(): array {
		return $this->errors;
	}//end getErrors()

	/**
	 * @param array<string, mixed> $data Data merged into the object before insert.
	 *
	 * @return void
	 */
	public function setModifiedData(array $data): void {
		$this->modifiedData = $data;
	}//end setModifiedData()

	/**
	 * @return array<string, mixed>
	 */
	public function getModifiedData(): array {
		return $this->modifiedData;
	}//end getModifiedData()
}//end class
