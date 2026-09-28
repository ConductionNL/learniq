<?php

/**
 * Every findAll() filter key names a property the target schema declares.
 *
 * OpenRegister answers a filter on a property the schema does not declare
 * with `1 = 0` (`MagicSearchHandler::applyObjectFilters()`), so the read
 * returns nothing and says nothing. A guard that looks something up that way
 * refuses every transition; a listener that does skips its work. This test
 * reads every `->findAll(` config under lib/ whose `filters` it can resolve
 * statically, and fails on a key the register's target schema does not
 * declare.
 *
 * What the key is compared against is the shipped register
 * (`lib/Settings/learniq_register.json`), read through
 * {@see RegisterFaithfulStore::declaredProperties()}, the same source the
 * register-faithful fake uses. Keys are compared verbatim: on the internal
 * `findAll()` path OpenRegister does not split a key on underscores (that
 * split lives in `SearchQueryHandler::buildSearchQuery()`, which only the
 * REST controllers call), so `tenant_id` is a valid key wherever the schema
 * declares `tenant_id`.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Register
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
 *
 * @spec openspec/specs/nextcloud-app/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use PHPUnit\Framework\TestCase;

/**
 * Scans lib/ for findAll() filters on properties the target schema does not declare.
 */
class FindAllFilterKeysAreDeclaredTest extends TestCase {

	/**
	 * The register every learniq read names.
	 */
	private const REGISTER = 'learniq';

	/**
	 * Filter keys OpenRegister reads as query context, never as a property.
	 *
	 * @var string[]
	 */
	private const CONTEXT_KEYS = ['register', 'schema', 'registers', 'schemas', 'extend', '@self'];

	/**
	 * Fewer resolved findAll() calls than this means the scan is not reading lib/.
	 * Lowered from 150 by data-exchange-to-integriq, which deleted the classes that
	 * ran learniq's own exchange jobs and rejections (about 15 findAll calls).
	 */
	private const MIN_RESOLVED_CALLS = 130;

	/**
	 * Reads that already filtered on an undeclared property when this test landed.
	 *
	 * Each one returns nothing on a live instance. They are inherited, listed
	 * here so a NEW undeclared key fails at once while these are fixed in their
	 * own change. The list is a ratchet in both directions: an entry that no
	 * longer occurs fails the test too, so a fix has to delete its line here.
	 * The 29 reads that filtered on `id` or `uuid` moved the id into the
	 * config's `ids` (reads-that-filter-on-undeclared-ids), and
	 * FindAllConfigScopeTest now refuses those two keys for every schema.
	 * What remains needs a schema or query decision. Tracked in
	 * ConductionNL/learniq#1116.
	 *
	 * @var string[]
	 */
	private const KNOWN_UNDECLARED = [
		'lib/Lifecycle/AttestationSigningGuard.php: xapi-statement has no property "actor.id"',
		'lib/Lifecycle/AttestationSigningGuard.php: xapi-statement has no property "object.id"',
		'lib/Lifecycle/AttestationSigningGuard.php: xapi-statement has no property "verb.id"',
		'lib/Service/XapiEnrolmentCompletion.php: lesson has no property "xapiObjectId"',
		'lib/Service/LessonProgress.php: lesson has no property "xapiObjectId"',
		'lib/Timetabling/SessionWindowLoader.php: session has no property "sessionDayBucket"',
	];

	/**
	 * Resolved findAll() calls the last scan checked.
	 *
	 * @var int
	 */
	private int $resolvedCalls = 0;

	/**
	 * Every statically resolvable findAll() filter key under lib/ is declared on its schema.
	 *
	 * @return void
	 */
	public function testEveryFindAllFilterKeyInLibIsDeclaredOnTheTargetSchema(): void {
		$root = dirname(__DIR__, 3);
		$violations = [];
		$resolved = 0;
		foreach ($this->collectPhpFiles(dir: $root . '/lib') as $file) {
			$source = (string)file_get_contents($file);
			foreach ($this->findViolations(source: $source) as $violation) {
				$violations[] = substr($file, strlen($root) + 1) . ':' . $violation;
			}

			$resolved += $this->resolvedCalls;
		}

		self::assertGreaterThanOrEqual(
			self::MIN_RESOLVED_CALLS,
			$resolved,
			'The scan resolved too few findAll() filters; it is not reading lib/.'
		);

		$drift = $this->compareWithBaseline(violations: $violations, baseline: self::KNOWN_UNDECLARED);
		self::assertSame(
			[],
			$drift['new'],
			"These findAll() filters name a property the target schema does not declare,\n"
			. "so OpenRegister answers them with no rows:\n"
			. implode("\n", $drift['new'])
		);
		self::assertSame(
			[],
			$drift['gone'],
			"These KNOWN_UNDECLARED entries no longer occur; delete them from the list:\n"
			. implode("\n", $drift['gone'])
		);
	}//end testEveryFindAllFilterKeyInLibIsDeclaredOnTheTargetSchema()

	/**
	 * Control: the baseline comparison fails on a new violation AND on a fixed one.
	 *
	 * @return void
	 */
	public function testTheBaselineFailsInBothDirections(): void {
		$baseline = [
			'lib/A.php: cohort has no property "id"',
			'lib/B.php: room has no property "id"',
		];
		$violations = [
			'lib/A.php:10: cohort has no property "id"',
			'lib/A.php:20: cohort has no property "id"',
			'lib/C.php:5: lesson has no property "uuid"',
		];

		self::assertSame(
			[
				'new' => ['lib/A.php:20: cohort has no property "id"', 'lib/C.php:5: lesson has no property "uuid"'],
				'gone' => ['lib/B.php: room has no property "id" (listed 1, found 0)'],
			],
			$this->compareWithBaseline(violations: $violations, baseline: $baseline)
		);
	}//end testTheBaselineFailsInBothDirections()

	/**
	 * Split scan results into violations beyond the baseline and baseline entries that are gone.
	 *
	 * An entry is compared without its line number, so an unrelated edit that
	 * moves a known read does not fail the test; it is counted, so a second
	 * undeclared read of the same key in the same file does.
	 *
	 * @param string[] $violations "<path>:<line>: <reason>" entries from the scan.
	 * @param string[] $baseline   "<path>: <reason>" entries, one per known read.
	 *
	 * @return array{new: string[], gone: string[]}
	 */
	private function compareWithBaseline(array $violations, array $baseline): array {
		$found = [];
		foreach ($violations as $violation) {
			$key = (string)preg_replace('/^([^:]+):\d+: /', '$1: ', $violation);
			$found[$key][] = $violation;
		}

		$allowed = array_count_values($baseline);
		$new = [];
		foreach ($found as $key => $entries) {
			array_push($new, ...array_slice($entries, ($allowed[$key] ?? 0)));
		}

		$gone = [];
		foreach ($allowed as $key => $listed) {
			$have = count(($found[$key] ?? []));
			if ($have < $listed) {
				$gone[] = $key . ' (listed ' . $listed . ', found ' . $have . ')';
			}
		}

		return ['new' => $new, 'gone' => $gone];
	}//end compareWithBaseline()

	/**
	 * The shipped Lesson schema declares every key CoursePublishGuard filters on.
	 *
	 * Pinned on its own because issue #1109 suspected `tenant_id`: the key is
	 * valid on this path, and this is the property it is valid against.
	 *
	 * @return void
	 */
	public function testTheLessonSchemaDeclaresTheKeysTheCoursePublishGuardFiltersOn(): void {
		$declared = (RegisterFaithfulStore::declaredProperties()['lesson'] ?? []);

		foreach (['courseId', 'lifecycle', 'tenant_id'] as $key) {
			self::assertContains($key, $declared, 'Lesson does not declare ' . $key . '.');
		}
	}//end testTheLessonSchemaDeclaresTheKeysTheCoursePublishGuardFiltersOn()

	/**
	 * Control: an undeclared key in a literal filter is flagged; declared and context keys pass.
	 *
	 * @return void
	 */
	public function testTheDetectorFlagsAnUndeclaredKeyInALiteralFilter(): void {
		$source = <<<'PHP'
<?php
class Probe {
	private const LEARNIQ_REGISTER = 'learniq';
	private const SCHEMA = 'lesson';
	public function run(): void {
		$this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::SCHEMA,
					'courseId' => $id,
					'tenant_id' => $tenant,
					'_rbac' => false,
					'uuid' => $id,
				],
				'limit' => 1,
			]
		);
	}
}
PHP;

		self::assertSame(['14: lesson has no property "uuid"'], $this->findViolations(source: $source));
		self::assertSame(1, $this->resolvedCalls);
	}//end testTheDetectorFlagsAnUndeclaredKeyInALiteralFilter()

	/**
	 * Control: keys reached through a variable, array_merge() and tenantScoped() are all checked.
	 *
	 * @return void
	 */
	public function testTheDetectorFollowsVariablesMergesAndTheTenantHelper(): void {
		$source = <<<'PHP'
<?php
class Probe {
	public function one(string $tenantId): void {
		$filters = ['courseId' => $id, 'lifecycle' => 'published'];
		if ($tenantId !== '') {
			$filters['tenantId'] = $tenantId;
		}

		$this->objectService->findAll(['filters' => array_merge($filters, ['register' => 'learniq', 'schema' => 'lesson'])]);
	}
	public function two(): void {
		$this->objectService->findAll(
			config: [
				'filters' => $this->tenantScoped(
					filters: ['register' => 'learniq', 'schema' => 'enrolment', 'learnerId' => $uid],
					tenantId: $tenantId
				),
			]
		);
	}
}
PHP;

		$violations = $this->findViolations(source: $source);

		self::assertSame(['6: lesson has no property "tenantId"'], $violations);
		self::assertSame(2, $this->resolvedCalls);
	}//end testTheDetectorFollowsVariablesMergesAndTheTenantHelper()

	/**
	 * Control: a schema slug the register does not carry is flagged, and a dynamic one is skipped.
	 *
	 * @return void
	 */
	public function testTheDetectorFlagsAnUnknownSchemaAndSkipsADynamicOne(): void {
		$source = <<<'PHP'
<?php
class Probe {
	public function run(string $schema): void {
		$this->objectService->findAll(['filters' => ['register' => 'learniq', 'schema' => 'no-such-schema', 'x' => 1]]);
		$this->objectService->findAll(['filters' => ['register' => 'learniq', 'schema' => $schema, 'x' => 1]]);
		$this->objectService->findAll(['filters' => ['register' => 'openregister', 'schema' => 'lesson', 'x' => 1]]);
	}
}
PHP;

		self::assertSame(
			['4: the learniq register carries no schema "no-such-schema"'],
			$this->findViolations(source: $source)
		);
		self::assertSame(1, $this->resolvedCalls);
	}//end testTheDetectorFlagsAnUnknownSchemaAndSkipsADynamicOne()

	/**
	 * Find every undeclared filter key in the findAll() calls of one PHP source.
	 *
	 * Sets $this->resolvedCalls to the number of calls whose register, schema
	 * and filter keys were all resolved; a call with any part the scan cannot
	 * resolve statically is skipped rather than guessed at.
	 *
	 * @param string $source PHP source code.
	 *
	 * @return string[] One "<line>: <reason>" entry per violation.
	 */
	private function findViolations(string $source): array {
		$tokens = $this->significantTokens(source: $source);
		$constants = $this->classConstants(tokens: $tokens);
		$declared = RegisterFaithfulStore::declaredProperties();
		$violations = [];
		$this->resolvedCalls = 0;

		$count = count($tokens);
		for ($i = 0; $i < ($count - 2); $i++) {
			if (in_array($tokens[$i]['type'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) === false
				|| $tokens[($i + 1)]['text'] !== 'findAll'
				|| $tokens[($i + 2)]['text'] !== '('
			) {
				continue;
			}

			$filters = $this->filtersOfCall(tokens: $tokens, open: ($i + 2), constants: $constants);
			if ($filters === null
				|| $filters['register'] !== self::REGISTER
				|| $filters['schema'] === null
			) {
				continue;
			}

			$this->resolvedCalls++;
			$schema = $filters['schema'];
			if (isset($declared[$schema]) === false) {
				$violations[] = $filters['schemaLine'] . ': the learniq register carries no schema "' . $schema . '"';
				continue;
			}

			foreach ($filters['keys'] as $key) {
				if ($this->isContextKey(key: $key['name']) === true
					|| in_array($key['name'], $declared[$schema], true) === true
				) {
					continue;
				}

				$violations[] = $key['line'] . ': ' . $schema . ' has no property "' . $key['name'] . '"';
			}
		}//end for

		return $violations;
	}//end findViolations()

	/**
	 * Resolve the `filters` of the findAll() call whose argument list opens at $open.
	 *
	 * @param array<int,array{type:int|string,text:string,line:int}> $tokens    Significant tokens.
	 * @param int                                                    $open      Index of the call's '('.
	 * @param array<string,string>                                   $constants Class constants of the file.
	 *
	 * @return array{keys:array<int,array{name:string,line:int}>,register:string|null,schema:string|null,schemaLine:int}|null
	 *         Null when any part of the filters cannot be resolved statically.
	 */
	private function filtersOfCall(array $tokens, int $open, array $constants): ?array {
		$close = $this->matchingClose(tokens: $tokens, open: $open);
		$args = $this->splitTopLevel(tokens: $tokens, start: ($open + 1), end: $close);
		if ($args === []) {
			return null;
		}

		$wrapped = $this->filtersOfWrapperCall(tokens: $tokens, args: $args, callAt: $open, constants: $constants);
		if ($wrapped !== null) {
			return $wrapped;
		}

		[$start, $end] = $this->stripNamedLabel(tokens: $tokens, range: $args[0]);
		$config = $this->resolveArray(tokens: $tokens, start: $start, end: $end);
		if ($config === null) {
			return null;
		}

		foreach ($this->entries(tokens: $tokens, start: $config[0], end: $config[1]) as $entry) {
			if ($entry['key'] === 'filters') {
				return $this->evaluate(
					tokens: $tokens,
					start: $entry['value'][0],
					end: $entry['value'][1],
					callAt: $open,
					constants: $constants
				);
			}
		}

		return null;
	}//end filtersOfCall()

	/**
	 * Resolve a call to a class's own `findAll(schema: ..., filters: ...)` wrapper.
	 *
	 * A service that reads many schemas often keeps a private `findAll()` that
	 * adds the register and forwards to ObjectService. Its call sites name the
	 * schema and the filter keys, so they are checked here; the register is the
	 * file's own `REGISTER` or `LEARNIQ_REGISTER` constant.
	 *
	 * @param array<int,array{type:int|string,text:string,line:int}> $tokens    Significant tokens.
	 * @param array<int,array{0:int,1:int}>                          $args      The call's argument ranges.
	 * @param int                                                    $callAt    Index of the call's '('.
	 * @param array<string,string>                                   $constants Class constants of the file.
	 *
	 * @return array{keys:array<int,array{name:string,line:int}>,register:string|null,schema:string|null,schemaLine:int}|null
	 */
	private function filtersOfWrapperCall(array $tokens, array $args, int $callAt, array $constants): ?array {
		$named = [];
		foreach ($args as [$start, $end]) {
			if (($end - $start) > 2 && $tokens[$start]['type'] === T_STRING && $tokens[($start + 1)]['text'] === ':') {
				$named[$tokens[$start]['text']] = [($start + 2), $end];
			}
		}

		if (isset($named['schema'], $named['filters']) === false) {
			return null;
		}

		$schema = $this->scalarValue(tokens: $tokens, range: $named['schema'], constants: $constants);
		$register = ($constants['REGISTER'] ?? ($constants['LEARNIQ_REGISTER'] ?? null));
		$filters = $this->evaluate(
			tokens: $tokens,
			start: $named['filters'][0],
			end: $named['filters'][1],
			callAt: $callAt,
			constants: $constants
		);
		if ($schema === null || $register === null || $filters === null) {
			return null;
		}

		return $this->merge(
			parts: [
				$filters,
				['keys' => [], 'register' => $register, 'schema' => $schema, 'schemaLine' => $tokens[$named['schema'][0]]['line']],
			]
		);
	}//end filtersOfWrapperCall()

	/**
	 * Evaluate a filters expression to its keys, register and schema.
	 *
	 * Understands an array literal, `array_merge(...)` of resolvable parts,
	 * `$this->tenantScoped(filters: ..., ...)` (which adds `tenant_id`), and a
	 * local variable assigned an array literal and then extended key by key.
	 *
	 * @param array<int,array{type:int|string,text:string,line:int}> $tokens    Significant tokens.
	 * @param int                                                    $start     First token of the expression.
	 * @param int                                                    $end       One past its last token.
	 * @param int                                                    $callAt    Index of the findAll() call.
	 * @param array<string,string>                                   $constants Class constants of the file.
	 *
	 * @return array{keys:array<int,array{name:string,line:int}>,register:string|null,schema:string|null,schemaLine:int}|null
	 */
	private function evaluate(array $tokens, int $start, int $end, int $callAt, array $constants): ?array {
		if ($start >= $end) {
			return null;
		}

		$first = $tokens[$start];
		if ($first['text'] === '[' && $this->matchingClose(tokens: $tokens, open: $start) === ($end - 1)) {
			return $this->evaluateLiteral(tokens: $tokens, start: $start, end: $end, constants: $constants);
		}

		if ($first['type'] === T_STRING && $first['text'] === 'array_merge' && $tokens[($start + 1)]['text'] === '(') {
			$close = $this->matchingClose(tokens: $tokens, open: ($start + 1));
			if ($close !== ($end - 1)) {
				return null;
			}

			$parts = [];
			foreach ($this->splitTopLevel(tokens: $tokens, start: ($start + 2), end: $close) as $range) {
				$parts[] = $this->evaluate(tokens: $tokens, start: $range[0], end: $range[1], callAt: $callAt, constants: $constants);
			}

			return $this->merge(parts: $parts);
		}

		if ($first['type'] === T_VARIABLE && $first['text'] === '$this'
			&& ($tokens[($start + 2)]['text'] ?? '') === 'tenantScoped'
			&& ($tokens[($start + 3)]['text'] ?? '') === '('
		) {
			$close = $this->matchingClose(tokens: $tokens, open: ($start + 3));
			$args = $this->splitTopLevel(tokens: $tokens, start: ($start + 4), end: $close);
			if ($close !== ($end - 1) || $args === []) {
				return null;
			}

			[$argStart, $argEnd] = $this->stripNamedLabel(tokens: $tokens, range: $args[0]);
			$inner = $this->evaluate(tokens: $tokens, start: $argStart, end: $argEnd, callAt: $callAt, constants: $constants);

			return $this->merge(
				parts: [
					$inner,
					['keys' => [['name' => 'tenant_id', 'line' => $first['line']]], 'register' => null, 'schema' => null, 'schemaLine' => 0],
				]
			);
		}

		if ($first['type'] === T_VARIABLE && $first['text'] !== '$this' && ($start + 1) === $end) {
			return $this->evaluateVariable(tokens: $tokens, variable: $first['text'], callAt: $callAt, constants: $constants);
		}

		return null;
	}//end evaluate()

	/**
	 * Evaluate an array literal's string keys, register and schema.
	 *
	 * @param array<int,array{type:int|string,text:string,line:int}> $tokens    Significant tokens.
	 * @param int                                                    $start     Index of the '['.
	 * @param int                                                    $end       One past the matching ']'.
	 * @param array<string,string>                                   $constants Class constants of the file.
	 *
	 * @return array{keys:array<int,array{name:string,line:int}>,register:string|null,schema:string|null,schemaLine:int}|null
	 */
	private function evaluateLiteral(array $tokens, int $start, int $end, array $constants): ?array {
		$result = ['keys' => [], 'register' => null, 'schema' => null, 'schemaLine' => 0];
		foreach ($this->entries(tokens: $tokens, start: $start, end: $end) as $entry) {
			if ($entry['key'] === null) {
				// A computed key or a spread: the key set is not knowable here.
				return null;
			}

			$result['keys'][] = ['name' => $entry['key'], 'line' => $entry['line']];
			if ($entry['key'] === 'register' || $entry['key'] === 'schema') {
				$value = $this->scalarValue(tokens: $tokens, range: $entry['value'], constants: $constants);
				if ($value === null) {
					return null;
				}

				$result[$entry['key']] = $value;
				if ($entry['key'] === 'schema') {
					$result['schemaLine'] = $entry['line'];
				}
			}
		}

		return $result;
	}//end evaluateLiteral()

	/**
	 * Evaluate a local variable: its last array-literal assignment before the call, plus `$var['k'] = ...` writes.
	 *
	 * @param array<int,array{type:int|string,text:string,line:int}> $tokens    Significant tokens.
	 * @param string                                                 $variable  The variable name, with `$`.
	 * @param int                                                    $callAt    Index of the findAll() call.
	 * @param array<string,string>                                   $constants Class constants of the file.
	 *
	 * @return array{keys:array<int,array{name:string,line:int}>,register:string|null,schema:string|null,schemaLine:int}|null
	 */
	private function evaluateVariable(array $tokens, string $variable, int $callAt, array $constants): ?array {
		$functionStart = 0;
		for ($i = $callAt; $i >= 0; $i--) {
			if ($tokens[$i]['type'] === T_FUNCTION) {
				$functionStart = $i;
				break;
			}
		}

		$assignment = null;
		for ($i = $callAt; $i > $functionStart; $i--) {
			if ($tokens[$i]['type'] === T_VARIABLE && $tokens[$i]['text'] === $variable
				&& ($tokens[($i + 1)]['text'] ?? '') === '='
			) {
				$assignment = $i;
				break;
			}
		}

		if ($assignment === null) {
			return null;
		}

		$valueEnd = $this->statementEnd(tokens: $tokens, start: ($assignment + 2));
		$base = $this->evaluate(tokens: $tokens, start: ($assignment + 2), end: $valueEnd, callAt: $assignment, constants: $constants);
		$parts = [$base];
		for ($i = $valueEnd; $i < $callAt; $i++) {
			if ($tokens[$i]['type'] === T_VARIABLE && $tokens[$i]['text'] === $variable
				&& ($tokens[($i + 1)]['text'] ?? '') === '['
				&& ($tokens[($i + 3)]['text'] ?? '') === ']'
				&& ($tokens[($i + 4)]['text'] ?? '') === '='
			) {
				if ($tokens[($i + 2)]['type'] !== T_CONSTANT_ENCAPSED_STRING) {
					return null;
				}

				$name = trim($tokens[($i + 2)]['text'], '\'"');
				$part = ['keys' => [['name' => $name, 'line' => $tokens[$i]['line']]], 'register' => null, 'schema' => null, 'schemaLine' => 0];
				if ($name === 'register' || $name === 'schema') {
					$writeEnd = $this->statementEnd(tokens: $tokens, start: ($i + 5));
					$value = $this->scalarValue(tokens: $tokens, range: [($i + 5), $writeEnd], constants: $constants);
					if ($value === null) {
						return null;
					}

					$part[$name] = $value;
					$part['schemaLine'] = $tokens[$i]['line'];
				}

				$parts[] = $part;
			}
		}//end for

		return $this->merge(parts: $parts);
	}//end evaluateVariable()

	/**
	 * Merge evaluated parts the way array_merge() does: later scalars win, keys accumulate.
	 *
	 * @param array<int,array{keys:array<int,array{name:string,line:int}>,register:string|null,schema:string|null,schemaLine:int}|null> $parts The parts.
	 *
	 * @return array{keys:array<int,array{name:string,line:int}>,register:string|null,schema:string|null,schemaLine:int}|null
	 */
	private function merge(array $parts): ?array {
		$result = ['keys' => [], 'register' => null, 'schema' => null, 'schemaLine' => 0];
		foreach ($parts as $part) {
			if ($part === null) {
				return null;
			}

			array_push($result['keys'], ...$part['keys']);
			if ($part['register'] !== null) {
				$result['register'] = $part['register'];
			}

			if ($part['schema'] !== null) {
				$result['schema'] = $part['schema'];
				$result['schemaLine'] = $part['schemaLine'];
			}
		}

		return $result;
	}//end merge()

	/**
	 * The value of a string literal or a `self::`/`static::` constant, or null.
	 *
	 * @param array<int,array{type:int|string,text:string,line:int}> $tokens    Significant tokens.
	 * @param array{0:int,1:int}                                     $range     The value's token range.
	 * @param array<string,string>                                   $constants Class constants of the file.
	 *
	 * @return string|null
	 */
	private function scalarValue(array $tokens, array $range, array $constants): ?string {
		[$start, $end] = $range;
		if (($end - $start) === 1 && $tokens[$start]['type'] === T_CONSTANT_ENCAPSED_STRING) {
			return trim($tokens[$start]['text'], '\'"');
		}

		if (($end - $start) === 3
			&& in_array($tokens[$start]['text'], ['self', 'static'], true) === true
			&& $tokens[($start + 1)]['type'] === T_DOUBLE_COLON
		) {
			return ($constants[$tokens[($start + 2)]['text']] ?? null);
		}

		return null;
	}//end scalarValue()

	/**
	 * Class constants of a file that hold a plain string.
	 *
	 * @param array<int,array{type:int|string,text:string,line:int}> $tokens Significant tokens.
	 *
	 * @return array<string,string>
	 */
	private function classConstants(array $tokens): array {
		$constants = [];
		$count = count($tokens);
		for ($i = 0; $i < ($count - 4); $i++) {
			if ($tokens[$i]['type'] !== T_CONST) {
				continue;
			}

			// `const NAME = 'value';`, optionally typed: `const string NAME = ...`.
			$name = ($i + 1);
			if (($tokens[($i + 2)]['text'] ?? '') !== '=') {
				$name = ($i + 2);
			}

			if (($tokens[($name + 1)]['text'] ?? '') === '='
				&& ($tokens[($name + 2)]['type'] ?? null) === T_CONSTANT_ENCAPSED_STRING
				&& ($tokens[($name + 3)]['text'] ?? '') === ';'
			) {
				$constants[$tokens[$name]['text']] = trim($tokens[($name + 2)]['text'], '\'"');
			}
		}

		return $constants;
	}//end classConstants()

	/**
	 * The array literal a config argument is, or null when it is not a literal.
	 *
	 * @param array<int,array{type:int|string,text:string,line:int}> $tokens Significant tokens.
	 * @param int                                                    $start  First token of the argument.
	 * @param int                                                    $end    One past its last token.
	 *
	 * @return array{0:int,1:int}|null Token range of the literal, brackets included.
	 */
	private function resolveArray(array $tokens, int $start, int $end): ?array {
		if ($tokens[$start]['text'] === '[' && $this->matchingClose(tokens: $tokens, open: $start) === ($end - 1)) {
			return [$start, $end];
		}

		return null;
	}//end resolveArray()

	/**
	 * The depth-one entries of an array literal.
	 *
	 * @param array<int,array{type:int|string,text:string,line:int}> $tokens Significant tokens.
	 * @param int                                                    $start  Index of the '['.
	 * @param int                                                    $end    One past the matching ']'.
	 *
	 * @return array<int,array{key:string|null,line:int,value:array{0:int,1:int}}>
	 */
	private function entries(array $tokens, int $start, int $end): array {
		$entries = [];
		foreach ($this->splitTopLevel(tokens: $tokens, start: ($start + 1), end: ($end - 1)) as [$from, $to]) {
			$arrow = null;
			$depth = 0;
			for ($j = $from; $j < $to; $j++) {
				$depth += $this->depthDelta(token: $tokens[$j]);
				if ($depth === 0 && $tokens[$j]['type'] === T_DOUBLE_ARROW) {
					$arrow = $j;
					break;
				}
			}

			if ($arrow === null) {
				// A list entry or a spread: no string key.
				$entries[] = ['key' => null, 'line' => $tokens[$from]['line'], 'value' => [$from, $to]];
				continue;
			}

			$key = null;
			if (($arrow - $from) === 1 && $tokens[$from]['type'] === T_CONSTANT_ENCAPSED_STRING) {
				$key = trim($tokens[$from]['text'], '\'"');
			}

			$entries[] = ['key' => $key, 'line' => $tokens[$from]['line'], 'value' => [($arrow + 1), $to]];
		}//end foreach

		return $entries;
	}//end entries()

	/**
	 * Split a token range on its depth-zero commas, dropping a trailing empty part.
	 *
	 * @param array<int,array{type:int|string,text:string,line:int}> $tokens Significant tokens.
	 * @param int                                                    $start  First token.
	 * @param int                                                    $end    One past the last token.
	 *
	 * @return array<int,array{0:int,1:int}>
	 */
	private function splitTopLevel(array $tokens, int $start, int $end): array {
		$parts = [];
		$from = $start;
		$depth = 0;
		for ($j = $start; $j < $end; $j++) {
			$depth += $this->depthDelta(token: $tokens[$j]);
			if ($depth === 0 && $tokens[$j]['text'] === ',') {
				$parts[] = [$from, $j];
				$from = ($j + 1);
			}
		}

		if ($from < $end) {
			$parts[] = [$from, $end];
		}

		return $parts;
	}//end splitTopLevel()

	/**
	 * Drop a named-argument label (`config:`, `filters:`) from an argument range.
	 *
	 * @param array<int,array{type:int|string,text:string,line:int}> $tokens Significant tokens.
	 * @param array{0:int,1:int}                                     $range  The argument's range.
	 *
	 * @return array{0:int,1:int}
	 */
	private function stripNamedLabel(array $tokens, array $range): array {
		[$start, $end] = $range;
		if (($end - $start) > 2 && $tokens[$start]['type'] === T_STRING && $tokens[($start + 1)]['text'] === ':') {
			return [($start + 2), $end];
		}

		return $range;
	}//end stripNamedLabel()

	/**
	 * Index one past the end of the statement that starts at $start (its ';' excluded).
	 *
	 * @param array<int,array{type:int|string,text:string,line:int}> $tokens Significant tokens.
	 * @param int                                                    $start  First token of the expression.
	 *
	 * @return int
	 */
	private function statementEnd(array $tokens, int $start): int {
		$depth = 0;
		$count = count($tokens);
		for ($j = $start; $j < $count; $j++) {
			$depth += $this->depthDelta(token: $tokens[$j]);
			if ($depth === 0 && $tokens[$j]['text'] === ';') {
				return $j;
			}
		}

		return $count;
	}//end statementEnd()

	/**
	 * Index of the bracket closing the one opened at $open.
	 *
	 * @param array<int,array{type:int|string,text:string,line:int}> $tokens Significant tokens.
	 * @param int                                                    $open   Index of an opening bracket.
	 *
	 * @return int
	 */
	private function matchingClose(array $tokens, int $open): int {
		$depth = 0;
		$count = count($tokens);
		for ($j = $open; $j < $count; $j++) {
			$depth += $this->depthDelta(token: $tokens[$j]);
			if ($depth === 0) {
				return $j;
			}
		}

		return $count;
	}//end matchingClose()

	/**
	 * How a token changes the bracket depth.
	 *
	 * @param array{type:int|string,text:string,line:int} $token The token.
	 *
	 * @return int
	 */
	private function depthDelta(array $token): int {
		if (in_array($token['text'], ['[', '(', '{'], true) === true
			|| in_array($token['type'], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) === true
		) {
			return 1;
		}

		if (in_array($token['text'], [']', ')', '}'], true) === true) {
			return -1;
		}

		return 0;
	}//end depthDelta()

	/**
	 * Whether a filter key is query context or a system flag, not a property.
	 *
	 * @param string $key The filter key.
	 *
	 * @return bool
	 */
	private function isContextKey(string $key): bool {
		return str_starts_with($key, '_') === true || in_array($key, self::CONTEXT_KEYS, true) === true;
	}//end isContextKey()

	/**
	 * Tokenise PHP source, dropping whitespace and comments.
	 *
	 * @param string $source PHP source code.
	 *
	 * @return array<int,array{type:int|string,text:string,line:int}>
	 */
	private function significantTokens(string $source): array {
		$out = [];
		$line = 1;
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === true) {
				$line = $token[2];
				if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true) === true) {
					continue;
				}

				$out[] = ['type' => $token[0], 'text' => $token[1], 'line' => $token[2]];
				continue;
			}

			$out[] = ['type' => $token, 'text' => $token, 'line' => $line];
		}

		return $out;
	}//end significantTokens()

	/**
	 * Recursively collect all .php files under a directory.
	 *
	 * @param string $dir Directory path to scan.
	 *
	 * @return string[] Absolute file paths.
	 */
	private function collectPhpFiles(string $dir): array {
		$files = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if ($file->isFile() === true && $file->getExtension() === 'php') {
				$files[] = $file->getPathname();
			}
		}

		sort($files);

		return $files;
	}//end collectPhpFiles()
}//end class
