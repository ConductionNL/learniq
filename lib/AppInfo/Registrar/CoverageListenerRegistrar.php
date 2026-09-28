<?php

/**
 * Learniq Coverage Listener Registrar
 *
 * Wires CurriculumCoverageRollupHandler (curriculum-coverage-rollup) on
 * OpenRegister's ObjectCreatedEvent, ObjectUpdatedEvent and ObjectDeletedEvent,
 * narrowed through ObjectEventSubscription to the learniq register and the six
 * schemas whose writes change coverage. It reuses BootListenerRegistrar's
 * narrowing helper, and lives in its own class so BootListenerRegistrar stays
 * under phpmd's coupling threshold.
 *
 * @category AppInfo
 * @package  OCA\Learniq\AppInfo\Registrar
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
 * @spec openspec/specs/competency/spec.md#requirement-coverage-is-recomputed-on-save-for-the-touched-frameworks-only-never-by-a-timedjob
 */

declare(strict_types=1);

namespace OCA\Learniq\AppInfo\Registrar;

use OCA\Learniq\Listener\CurriculumCoverageRollupHandler;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Registers the curriculum coverage recompute trigger.
 *
 * @spec openspec/specs/competency/spec.md#requirement-coverage-is-recomputed-on-save-for-the-touched-frameworks-only-never-by-a-timedjob
 */
class CoverageListenerRegistrar {

	/**
	 * The schemas whose writes change a framework's coverage. Assessment's
	 * slug is `exam`.
	 */
	public const SCHEMAS = ['lesson', 'course', 'assignment', 'exam', 'competency', 'competency-framework'];

	/**
	 * Register the handler for create, update and delete.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 * @param string           $appId      The Learniq app id (log context only).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-coverage-is-recomputed-on-save-for-the-touched-frameworks-only-never-by-a-timedjob
	 */
	public function register(IEventDispatcher $dispatcher, string $appId): void {
		$boot = new BootListenerRegistrar();
		foreach ([ObjectCreatedEvent::class, ObjectUpdatedEvent::class, ObjectDeletedEvent::class] as $event) {
			$boot->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				appId: $appId,
				event: $event,
				listener: CurriculumCoverageRollupHandler::class,
				registers: ['learniq'],
				schemas: self::SCHEMAS
			);
		}
	}//end register()
}//end class
