<?php

/**
 * Contract of ListenerSchemaResolver
 *
 * The public surface of ListenerSchemaResolver that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;

/**
 * Contract of ListenerSchemaResolver.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ListenerSchemaResolverInterface {

	/**
	 * The register slug of an ObjectTransitionedEvent; see eventRegisterSlug().
	 *
	 * @param ObjectTransitionedEvent $event The event.
	 *
	 * @return string The register slug, or the raw value when it cannot be resolved.
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
	 */
	public function eventRegister(ObjectTransitionedEvent $event): string;

	/**
	 * The schema slug of an ObjectTransitionedEvent; see eventSchemaSlug().
	 *
	 * @param ObjectTransitionedEvent $event The event.
	 *
	 * @return string The schema slug, or the raw value when it is not Learniq's or cannot be resolved.
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
	 */
	public function eventSchema(ObjectTransitionedEvent $event): string;

	/**
	 * Resolve an entity's schema slug for a SECURITY guard, regardless of the
	 * listener slug contract.
	 *
	 * The contract exists so that waking long-dead side-effect listeners is an
	 * explicit per-instance decision. A security guard is the opposite case: a
	 * guard that only runs when an admin opts in is a guard that does not run.
	 * So this always resolves the real slug, and still returns '' for an entity
	 * outside Learniq's own register.
	 *
	 * @param object|null $entity The OpenRegister object entity.
	 *
	 * @return string The schema slug, or '' when unresolvable or foreign.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-an-attempt-starts-only-inside-the-availability-window-and-with-the-access-code
	 */
	public function guardSchemaSlug(?object $entity): string;

	/**
	 * Resolve the register slug of an OpenRegister object entity.
	 *
	 * Listeners guard on register and schema together; this returns the value
	 * their `REGISTER` literal is compared against. While the contract is
	 * disabled it returns the raw (id) value, preserving today's behaviour.
	 *
	 * @param object|null $entity The OpenRegister ObjectEntity from the event.
	 *
	 * @return string The register slug, or '' when unresolvable.
	 */
	public function registerSlug(?object $entity): string;

	/**
	 * Resolve the schema slug of an OpenRegister object entity.
	 *
	 * Returns `''` when the object does not belong to Learniq's register, when
	 * the schema cannot be resolved, or when OpenRegister is unavailable — every
	 * caller treats `''` as "not my object" and returns early, so an
	 * unresolvable entity is never mistaken for a match.
	 *
	 * While {@see ListenerSlugContract} is disabled this deliberately returns
	 * the entity's raw schema value (an id), preserving the pre-fix behaviour.
	 *
	 * @param object|null $entity The OpenRegister ObjectEntity from the event.
	 *
	 * @return string The schema slug, or '' when this is not a Learniq object.
	 *
	 * @spec exclude Listener plumbing, not a behavioural requirement: it answers
	 *       "is this event about one of our objects" and returns '' for every
	 *       negative case so callers bail out. The register it matches against
	 *       is the app id, which the Scholiq->Learniq rename changed — which is
	 *       why the rename's diff surfaced this method to gate-16 at all. No
	 *       canonical spec governs the resolution itself; the behaviour that
	 *       DOES carry requirements lives in the listeners that call it.
	 */
	public function schemaSlug(?object $entity): string;
}//end interface
