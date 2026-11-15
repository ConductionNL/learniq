<?php

/**
 * Test stub for OCA\OpenRegister\Event\ObjectTransitionedEvent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Lifecycle\TransitionActor;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use OCP\EventDispatcher\Event;

/**
 * Stub for ObjectTransitionedEvent.
 *
 * Kept in sync with the real class (openregister/lib/Event/ObjectTransitionedEvent.php,
 * development d611a36): the same constructor and the same accessors, and
 * nothing else. It used to declare an abstract `getContext()`, which the real
 * class does not have, so a double could configure a method production would
 * fatal on. Concrete, so RegisteredListenersHandleRealEventsTest can build the
 * event OpenRegister dispatches instead of a double.
 */
class ObjectTransitionedEvent extends Event {

	/**
	 * Object after the transition.
	 *
	 * @var ObjectEntity
	 */
	private ObjectEntity $object;

	/**
	 * Action name.
	 *
	 * @var string
	 */
	private string $action;

	/**
	 * State before.
	 *
	 * @var string
	 */
	private string $from;

	/**
	 * State after.
	 *
	 * @var string
	 */
	private string $to;

	/**
	 * Caller uid, null for system-applied transitions.
	 *
	 * @var string|null
	 */
	private ?string $userId;

	/**
	 * Register slug.
	 *
	 * @var string
	 */
	private string $register;

	/**
	 * Schema slug.
	 *
	 * @var string
	 */
	private string $schema;

	/**
	 * Whether a transition's `autoWhen` fired this move.
	 *
	 * @var bool
	 */
	private bool $automatic;

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object Object after the transition.
	 * @param TransitionMove $move The action applied and the states it moved between.
	 * @param RegisterSchemaRef $scope Register and schema slugs.
	 * @param TransitionActor|null $actor Who made the move; null for a system move.
	 */
	public function __construct(
		ObjectEntity $object,
		TransitionMove $move,
		RegisterSchemaRef $scope,
		?TransitionActor $actor = null,
	) {
		parent::__construct();
		$actor = $actor ?? new TransitionActor();
		$this->object = $object;
		$this->action = $move->action;
		$this->from = $move->from;
		$this->to = $move->to;
		$this->userId = $actor->userId;
		$this->register = (string) $scope->register;
		$this->schema = (string) $scope->schema;
		$this->automatic = $actor->automatic;
	}//end __construct()

	/**
	 * @return bool
	 */
	public function isAutomatic(): bool {
		return $this->automatic;
	}//end isAutomatic()

	/**
	 * @return ObjectEntity
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()

	/**
	 * @return string
	 */
	public function getAction(): string {
		return $this->action;
	}//end getAction()

	/**
	 * @return string
	 */
	public function getFrom(): string {
		return $this->from;
	}//end getFrom()

	/**
	 * @return string
	 */
	public function getTo(): string {
		return $this->to;
	}//end getTo()

	/**
	 * @return string|null
	 */
	public function getUserId(): ?string {
		return $this->userId;
	}//end getUserId()

	/**
	 * @return string
	 */
	public function getRegister(): string {
		return $this->register;
	}//end getRegister()

	/**
	 * @return string
	 */
	public function getSchema(): string {
		return $this->schema;
	}//end getSchema()
}//end class
