<?php

/**
 * Builder for OpenRegister ObjectEntity instances in unit tests.
 *
 * OpenRegister's ObjectEntity gets `getRegister()` / `getSchema()` / `getUuid()`
 * from `OCP\AppFramework\Db\Entity::__call`, so PHPUnit cannot configure them on
 * a mock — `createMock(ObjectEntity::class)->method('getRegister')` throws
 * `MethodCannotBeConfiguredException`. Tests must therefore build real
 * instances. This factory does that in one place so the shape stays consistent
 * whether the resolved class is the real OpenRegister entity (CI, where the app
 * is loaded into a Nextcloud server tree) or the mirror in tests/Stubs.
 *
 * Deliberately NOT registered in composer `autoload-dev`: a dev-built vendor/
 * bakes autoload-dev classmaps into the runtime loader and can shadow real
 * app classes instance-wide (openregister#2036). It is required explicitly
 * from tests/bootstrap.php and tests/bootstrap-unit.php instead.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Support
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Support;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Builds ObjectEntity instances for unit tests.
 */
final class OrEntityFactory
{


    /**
     * Build an ObjectEntity carrying the given payload.
     *
     * `jsonSerialize()` on the result returns the payload merged with an
     * `@self` metadata block and a top-level `id`, exactly as OpenRegister
     * returns it over the wire — so production code that normalises an
     * ObjectEntity into an array sees the real shape.
     *
     * @param array<string,mixed> $data     Object payload.
     * @param string              $schema   Schema slug the object belongs to.
     * @param string              $register Register slug the object belongs to.
     * @param string|null         $uuid     Explicit uuid; defaults to $data['id'].
     *
     * @return ObjectEntity
     */
    public static function make(
        array $data,
        string $schema='',
        string $register='scholiq',
        ?string $uuid=null
    ): ObjectEntity {
        $entity = new ObjectEntity();

        $resolvedUuid = $uuid;
        if ($resolvedUuid === null && isset($data['id']) === true && is_scalar($data['id']) === true) {
            $resolvedUuid = (string) $data['id'];
        }

        if ($resolvedUuid !== null) {
            $entity->setUuid($resolvedUuid);
        }

        $entity->setRegister($register);
        $entity->setSchema($schema);
        $entity->setObject($data);

        return $entity;

    }//end make()


    /**
     * Build a list of ObjectEntity instances from a list of payloads.
     *
     * @param array<int,array<string,mixed>> $rows     Object payloads.
     * @param string                         $schema   Schema slug.
     * @param string                         $register Register slug.
     *
     * @return array<int,ObjectEntity>
     */
    public static function makeMany(array $rows, string $schema='', string $register='scholiq'): array
    {
        return array_map(
            static fn (array $row): ObjectEntity => self::make($row, $schema, $register),
            $rows
        );

    }//end makeMany()


}//end class
