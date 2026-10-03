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
	 * @param ObjectEntity $object    Object after the transition.
	 * @param string       $action    Action name.
	 * @param string       $from      State before.
	 * @param string       $to        State after.
	 * @param string|null  $userId    Caller uid (null for system-applied transitions).
	 * @param string       $register  Register slug.
	 * @param string       $schema    Schema slug.
	 * @param bool         $automatic True when a transition's `autoWhen` fired this move.
	 */
	public function __construct(
		ObjectEntity $object,
		string $action,
		string $from,
		string $to,
		?string $userId,
		string $register,
		string $schema,
		bool $automatic = false,
	) {
		parent::__construct();
		$this->object = $object;
		$this->action = $action;
		$this->from = $from;
		$this->to = $to;
		$this->userId = $userId;
		$this->register = $register;
		$this->schema = $schema;
		$this->automatic = $automatic;
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
