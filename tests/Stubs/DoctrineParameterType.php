<?php

/**
 * Guarded stub for Doctrine\DBAL\ParameterType.
 *
 * `OCP\DB\QueryBuilder\IQueryBuilder` references this class in its constant
 * declarations, so merely creating a PHPUnit double for `OCP\IDBConnection`
 * loads it. Where the app is tested against a full Nextcloud checkout the real
 * Doctrine package is present and this file does nothing; on a bare host
 * (Composer autoload + tests/Stubs only) it is absent, and every test that
 * doubles a database connection dies with "Class Doctrine\DBAL\ParameterType
 * not found" — an environment gap that reads as a code failure.
 *
 * The guard matters: defining this unconditionally would SHADOW the real
 * Doctrine class wherever it is installed, which is how a stub stops being a
 * shim and starts being a second, diverging definition.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Doctrine\DBAL;

if (class_exists(\Doctrine\DBAL\ParameterType::class, false) === false
	&& enum_exists(\Doctrine\DBAL\ParameterType::class, false) === false
) {
	/**
	 * Minimal stand-in carrying only the constants IQueryBuilder references.
	 *
	 * Values mirror Doctrine DBAL 3.x's PDO-derived integers. Nothing in this
	 * app compares them to anything but each other.
	 */
	class ParameterType {
		public const NULL = 0;
		public const INTEGER = 1;
		public const STRING = 2;
		public const LARGE_OBJECT = 3;
		public const BOOLEAN = 5;
		public const BINARY = 16;
		public const ASCII = 17;
	}//end class
}
