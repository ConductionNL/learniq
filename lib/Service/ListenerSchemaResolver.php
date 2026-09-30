<?php

/**
 * Resolves an OpenRegister object's schema **slug** for Learniq's listeners.
 *
 * OpenRegister's {@see \OCA\OpenRegister\Db\MagicMapper} stamps the numeric
 * **ids** of the register and schema onto every {@see
 * \OCA\OpenRegister\Db\ObjectEntity} it materialises:
 *
 *     $result->setSchema((string) $schema->getId());
 *     $result->setRegister((string) $register->getId());
 *
 * Learniq's listeners, however, compare that value against a schema **slug**
 * literal (`'xapi-statement'`, `'enrolment'`, `'session'`, ...). An id can never
 * equal a slug, so every one of those guards returned early on every event: the
 * handler bodies had never run once. There was no exception and no log line —
 * the listeners were still constructed and invoked on every object write
 * instance-wide, they simply did nothing.
 *
 * The guards that read the schema off the **entity**
 * (`$event->getObject()->getSchema()`) go through schemaSlug() and the gate
 * below. The guards that read it off an **ObjectTransitionedEvent** go through
 * eventRegisterSlug() and eventSchemaSlug(), which are NOT gated: OpenRegister
 * sends ids there unless its own `transition_event_slug_contract` is set, and
 * the decision of 2026-09-30 is that Learniq's transition listeners fire on
 * real transitions either way.
 *
 * This resolver turns the id back into a slug so the existing literals match.
 * Three properties matter:
 *
 * 1. **Register-scoped.** Matching on schema alone is not safe: this instance
 *    carries two distinct schemas both slugged `automation` (ids 71 and 5103),
 *    so a schema-only match fires on another app's objects. Callers therefore
 *    get `''` for anything outside Learniq's own register.
 * 2. **Container-resolved.** OpenRegister is a soft dependency; the mappers are
 *    pulled from the DI container at call time and every failure degrades to
 *    `''`, so Learniq still boots and runs with OpenRegister absent.
 * 3. **Gated.** Waking these listeners is a behaviour change, not a bug fix —
 *    see {@see ListenerSlugContract}. While the contract is disabled this
 *    returns the raw entity value (the id), which reproduces today's dead
 *    behaviour byte for byte.
 *
 * {@see \OCA\OpenRegister\Db\SchemaMapper::find()} and
 * {@see \OCA\OpenRegister\Db\RegisterMapper::find()} are request-cached by
 * OpenRegister, so the lookup does not add a query per event.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns an OpenRegister entity's schema id into its slug, scoped to Learniq's
 * own register.
 */
class ListenerSchemaResolver {

	/**
	 * The OpenRegister register slug owning Learniq's schemas.
	 *
	 * @var string
	 */
	public const REGISTER_SLUG = 'learniq';

	/**
	 * FQCN of OpenRegister's schema mapper.
	 *
	 * @var string
	 */
	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * FQCN of OpenRegister's register mapper.
	 *
	 * @var string
	 */
	private const REGISTER_MAPPER = 'OCA\\OpenRegister\\Db\\RegisterMapper';

	/**
	 * Slugs resolved in this request, keyed by container, mapper and id. A
	 * transition fans out to dozens of listeners; each asks once.
	 *
	 * @var array<string, string>
	 */
	private static array $slugs = [];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container — OpenRegister mappers are resolved
	 *                                      lazily so Learniq boots without OpenRegister.
	 * @param ListenerSlugContract $contract Default-off gate for the corrected matching.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ListenerSlugContract $contract,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

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
	public function schemaSlug(?object $entity): string {
		// `is_callable()`, NOT `method_exists()`. OpenRegister's ObjectEntity
		// gets getSchema()/getRegister()/getUuid() from
		// OCP\AppFramework\Db\Entity::__call, so `method_exists()` returns
		// FALSE for them on a real entity — measured, not assumed. This guard
		// therefore used to reject every genuine ObjectEntity and return '',
		// which every caller reads as "not my object" and returns early: all of
		// Learniq's OpenRegister listeners silently did nothing in production.
		// The unit suite did not catch it because the old tests/Stubs entity
		// declared those accessors concretely, so method_exists() was true
		// there and only there.
		if ($entity === null || is_callable([$entity, 'getSchema']) === false) {
			return '';
		}

		$rawSchema = (string)($entity->getSchema() ?? '');

		// Gate closed: reproduce the pre-fix comparison exactly.
		if ($this->contract->isEnabled() === false) {
			return $rawSchema;
		}

		if ($rawSchema === '' || $this->isOwnRegister(entity: $entity) === false) {
			return '';
		}

		return $this->resolveSlug(service: self::SCHEMA_MAPPER, id: $rawSchema);
	}//end schemaSlug()

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
	public function guardSchemaSlug(?object $entity): string {
		// `is_callable()`, not `method_exists()` — see schemaSlug().
		if ($entity === null || is_callable([$entity, 'getSchema']) === false) {
			return '';
		}

		$rawSchema = (string)($entity->getSchema() ?? '');
		if ($rawSchema === '' || $this->isOwnRegister(entity: $entity) === false) {
			return '';
		}

		return $this->resolveSlug(service: self::SCHEMA_MAPPER, id: $rawSchema);
	}//end guardSchemaSlug()

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
	public function registerSlug(?object $entity): string {
		// `is_callable()`, not `method_exists()` — see schemaSlug().
		if ($entity === null || is_callable([$entity, 'getRegister']) === false) {
			return '';
		}

		$rawRegister = (string)($entity->getRegister() ?? '');

		if ($this->contract->isEnabled() === false) {
			return $rawRegister;
		}

		if ($rawRegister === '') {
			return '';
		}

		if (strcasecmp($rawRegister, self::REGISTER_SLUG) === 0) {
			return self::REGISTER_SLUG;
		}

		return $this->resolveSlug(service: self::REGISTER_MAPPER, id: $rawRegister);
	}//end registerSlug()

	/**
	 * Whether the entity belongs to Learniq's own OpenRegister register.
	 *
	 * This is the guard that keeps a schema-only literal (for example the two
	 * distinct schemas both slugged `automation`) from firing on another app's
	 * objects.
	 *
	 * @param object|null $entity The OpenRegister ObjectEntity from the event.
	 *
	 * @return bool True when the entity sits in Learniq's register.
	 */
	public function isOwnRegister(?object $entity): bool {
		// `is_callable()`, not `method_exists()` — see schemaSlug().
		if ($entity === null || is_callable([$entity, 'getRegister']) === false) {
			return false;
		}

		$rawRegister = (string)($entity->getRegister() ?? '');
		if ($rawRegister === '') {
			return false;
		}

		// Tolerate an entity that already carries a slug (a hand-built entity in
		// a test, or a future OpenRegister that stops stamping ids).
		if (strcasecmp($rawRegister, self::REGISTER_SLUG) === 0) {
			return true;
		}

		return strcasecmp(
			$this->resolveSlug(service: self::REGISTER_MAPPER, id: $rawRegister),
			self::REGISTER_SLUG
		) === 0;

	}//end isOwnRegister()

	/**
	 * The register slug an ObjectTransitionedEvent names, whatever form it
	 * carries it in.
	 *
	 * OpenRegister's TransitionEngine hands the event the register and schema
	 * slugs only when the instance opts into its `transition_event_slug_contract`
	 * app config; by default the event carries their numeric ids. Every Learniq
	 * transition listener compares against slug literals, so on a default
	 * instance none of them ever fired: a completed enrolment issued no
	 * certificate. This answers the slug in both cases, and deliberately does
	 * NOT consult {@see ListenerSlugContract}: that gate covers the
	 * entity-based guards above, and the decision (Ruben, 2026-09-30) is that
	 * transition listeners fire on real transitions.
	 *
	 * @param string $register The event's register: a slug or a numeric id.
	 *
	 * @return string The register slug, or the raw value when it cannot be resolved.
	 *
	 * @spec openspec/changes/archive/2026-09-29-credentials-europass-edci-export/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
	 */
	public function eventRegisterSlug(string $register): string {
		if (strcasecmp($register, self::REGISTER_SLUG) === 0) {
			return self::REGISTER_SLUG;
		}

		if (ctype_digit($register) === false) {
			return $register;
		}

		$slug = $this->resolveSlug(service: self::REGISTER_MAPPER, id: $register);
		if ($slug === '') {
			return $register;
		}

		return $slug;
	}//end eventRegisterSlug()

	/**
	 * The schema slug an ObjectTransitionedEvent names, whatever form it
	 * carries it in. Only a schema in Learniq's own register is resolved, so a
	 * slug shared with another app's schema cannot match.
	 *
	 * @param string $register The event's register: a slug or a numeric id.
	 * @param string $schema   The event's schema: a slug or a numeric id.
	 *
	 * @return string The schema slug, or the raw value when it is not Learniq's or cannot be resolved.
	 *
	 * @spec openspec/changes/archive/2026-09-29-credentials-europass-edci-export/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
	 */
	public function eventSchemaSlug(string $register, string $schema): string {
		if (ctype_digit($schema) === false) {
			return $schema;
		}

		if ($this->eventRegisterSlug(register: $register) !== self::REGISTER_SLUG) {
			return $schema;
		}

		$slug = $this->resolveSlug(service: self::SCHEMA_MAPPER, id: $schema);
		if ($slug === '') {
			return $schema;
		}

		return $slug;
	}//end eventSchemaSlug()

	/**
	 * The register slug of an ObjectTransitionedEvent; see eventRegisterSlug().
	 *
	 * @param ObjectTransitionedEvent $event The event.
	 *
	 * @return string The register slug, or the raw value when it cannot be resolved.
	 *
	 * @spec openspec/changes/archive/2026-09-29-credentials-europass-edci-export/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
	 */
	public function eventRegister(ObjectTransitionedEvent $event): string {
		return $this->eventRegisterSlug(register: $event->getRegister());
	}//end eventRegister()

	/**
	 * The schema slug of an ObjectTransitionedEvent; see eventSchemaSlug().
	 *
	 * @param ObjectTransitionedEvent $event The event.
	 *
	 * @return string The schema slug, or the raw value when it is not Learniq's or cannot be resolved.
	 *
	 * @spec openspec/changes/archive/2026-09-29-credentials-europass-edci-export/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
	 */
	public function eventSchema(ObjectTransitionedEvent $event): string {
		return $this->eventSchemaSlug(register: $event->getRegister(), schema: $event->getSchema());
	}//end eventSchema()

	/**
	 * Look an OpenRegister entity's slug up by id through a mapper FQCN.
	 *
	 * @param string $service The mapper FQCN (SchemaMapper or RegisterMapper).
	 * @param string $id The id to resolve.
	 *
	 * @return string The slug, or '' when unresolvable / OpenRegister absent.
	 */
	private function resolveSlug(string $service, string $id): string {
		$key = spl_object_id($this->container) . '|' . $service . '|' . $id;
		if (array_key_exists($key, self::$slugs) === true) {
			return self::$slugs[$key];
		}

		$slug = $this->lookUpSlug(service: $service, id: $id);
		self::$slugs[$key] = $slug;

		return $slug;
	}//end resolveSlug()

	/**
	 * Ask the mapper for an entity's slug; the uncached half of resolveSlug().
	 *
	 * @param string $service The mapper FQCN (SchemaMapper or RegisterMapper).
	 * @param string $id The id to resolve.
	 *
	 * @return string The slug, or '' when unresolvable / OpenRegister absent.
	 */
	private function lookUpSlug(string $service, string $id): string {
		try {
			$entity = $this->container->get($service)->find($id);
			// `is_callable()`, NOT `method_exists()` — the same trap schemaSlug()
			// documents, one layer down. OpenRegister's Db\Schema and Db\Register
			// declare getSlug() as a `@method` docblock only; it is reached through
			// OCP\AppFramework\Db\Entity::__call. Measured on both classes:
			// method_exists(getSlug)=false, is_callable=true, while the concretely
			// declared Schema::setSlug reads true on both. With method_exists this
			// returned '' for every real entity, so isOwnRegister() compared ''
			// against 'scholiq' and every listener guard rejected every object.
			if (is_object($entity) === true && is_callable([$entity, 'getSlug']) === true) {
				return (string)($entity->getSlug() ?? '');
			}
		} catch (Throwable $e) {
			$this->logger->debug(
				'Learniq: could not resolve an OpenRegister slug for a listener guard',
				[
					'service' => $service,
					'id' => $id,
					'exception' => $e->getMessage(),
				]
			);
		}//end try

		return '';
	}//end lookUpSlug()
}//end class
