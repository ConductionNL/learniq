<?php

/**
 * Resolves an OpenRegister object's schema **slug** for scholiq's listeners.
 *
 * OpenRegister's {@see \OCA\OpenRegister\Db\MagicMapper} stamps the numeric
 * **ids** of the register and schema onto every {@see
 * \OCA\OpenRegister\Db\ObjectEntity} it materialises:
 *
 *     $result->setSchema((string) $schema->getId());
 *     $result->setRegister((string) $register->getId());
 *
 * Scholiq's listeners, however, compare that value against a schema **slug**
 * literal (`'xapi-statement'`, `'enrolment'`, `'session'`, ...). An id can never
 * equal a slug, so every one of those guards returned early on every event: the
 * handler bodies had never run once. There was no exception and no log line —
 * the listeners were still constructed and invoked on every object write
 * instance-wide, they simply did nothing.
 *
 * Note this affects only the guards that read the schema off the **entity**
 * (`$event->getObject()->getSchema()`). The guards that read it off the
 * **event** (`ObjectTransitionedEvent::getSchema()`) are a separate defect with
 * its own gate and are deliberately untouched here.
 *
 * This resolver turns the id back into a slug so the existing literals match.
 * Three properties matter:
 *
 * 1. **Register-scoped.** Matching on schema alone is not safe: this instance
 *    carries two distinct schemas both slugged `automation` (ids 71 and 5103),
 *    so a schema-only match fires on another app's objects. Callers therefore
 *    get `''` for anything outside scholiq's own register.
 * 2. **Container-resolved.** OpenRegister is a soft dependency; the mappers are
 *    pulled from the DI container at call time and every failure degrades to
 *    `''`, so scholiq still boots and runs with OpenRegister absent.
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
 * @package  OCA\Scholiq\Service
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

namespace OCA\Scholiq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns an OpenRegister entity's schema id into its slug, scoped to scholiq's
 * own register.
 */
class ListenerSchemaResolver
{

    /**
     * The OpenRegister register slug owning scholiq's schemas.
     *
     * @var string
     */
    public const REGISTER_SLUG = 'scholiq';

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
     * Constructor.
     *
     * @param ContainerInterface   $container DI container — OpenRegister mappers are resolved
     *                                        lazily so scholiq boots without OpenRegister.
     * @param ListenerSlugContract $contract  Default-off gate for the corrected matching.
     * @param LoggerInterface      $logger    Logger for fail-soft diagnostics.
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
     * Returns `''` when the object does not belong to scholiq's register, when
     * the schema cannot be resolved, or when OpenRegister is unavailable — every
     * caller treats `''` as "not my object" and returns early, so an
     * unresolvable entity is never mistaken for a match.
     *
     * While {@see ListenerSlugContract} is disabled this deliberately returns
     * the entity's raw schema value (an id), preserving the pre-fix behaviour.
     *
     * @param object|null $entity The OpenRegister ObjectEntity from the event.
     *
     * @return string The schema slug, or '' when this is not a scholiq object.
     */
    public function schemaSlug(?object $entity): string
    {
        // `is_callable()`, NOT `method_exists()`. OpenRegister's ObjectEntity
        // gets getSchema()/getRegister()/getUuid() from
        // OCP\AppFramework\Db\Entity::__call, so `method_exists()` returns
        // FALSE for them on a real entity — measured, not assumed. This guard
        // therefore used to reject every genuine ObjectEntity and return '',
        // which every caller reads as "not my object" and returns early: all of
        // scholiq's OpenRegister listeners silently did nothing in production.
        // The unit suite did not catch it because the old tests/Stubs entity
        // declared those accessors concretely, so method_exists() was true
        // there and only there.
        if ($entity === null || is_callable([$entity, 'getSchema']) === false) {
            return '';
        }

        $rawSchema = (string) ($entity->getSchema() ?? '');

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
    public function registerSlug(?object $entity): string
    {
        // `is_callable()`, not `method_exists()` — see schemaSlug().
        if ($entity === null || is_callable([$entity, 'getRegister']) === false) {
            return '';
        }

        $rawRegister = (string) ($entity->getRegister() ?? '');

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
     * Whether the entity belongs to scholiq's own OpenRegister register.
     *
     * This is the guard that keeps a schema-only literal (for example the two
     * distinct schemas both slugged `automation`) from firing on another app's
     * objects.
     *
     * @param object|null $entity The OpenRegister ObjectEntity from the event.
     *
     * @return bool True when the entity sits in scholiq's register.
     */
    public function isOwnRegister(?object $entity): bool
    {
        // `is_callable()`, not `method_exists()` — see schemaSlug().
        if ($entity === null || is_callable([$entity, 'getRegister']) === false) {
            return false;
        }

        $rawRegister = (string) ($entity->getRegister() ?? '');
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
     * Look an OpenRegister entity's slug up by id through a mapper FQCN.
     *
     * @param string $service The mapper FQCN (SchemaMapper or RegisterMapper).
     * @param string $id      The id to resolve.
     *
     * @return string The slug, or '' when unresolvable / OpenRegister absent.
     */
    private function resolveSlug(string $service, string $id): string
    {
        try {
            $entity = $this->container->get($service)->find($id);
            if (is_object($entity) === true && method_exists($entity, 'getSlug') === true) {
                return (string) ($entity->getSlug() ?? '');
            }
        } catch (Throwable $e) {
            $this->logger->debug(
                'Scholiq: could not resolve an OpenRegister slug for a listener guard',
                [
                    'service'   => $service,
                    'id'        => $id,
                    'exception' => $e->getMessage(),
                ]
            );
        }//end try

        return '';

    }//end resolveSlug()
}//end class
