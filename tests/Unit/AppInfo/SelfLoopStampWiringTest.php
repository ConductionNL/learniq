<?php

/**
 * The self-loop stamp listeners are wired from the app's real registration path.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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

namespace OCA\Learniq\Tests\Unit\AppInfo;

use OCA\Learniq\AppInfo\Registrar\EventListenerWiring;
use OCA\Learniq\Listener\MunicipalityFeedbackStampListener;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * A listener with a full test suite and no registration never runs, so this
 * asserts the wiring from EventListenerWiring::registerAll(), the path
 * Application::register() takes (learniq#983).
 */
class SelfLoopStampWiringTest extends TestCase {

	/**
	 * MunicipalityFeedbackStampListener listens for ObjectTransitionedEvent.
	 *
	 * @return void
	 */
	public function testMunicipalityFeedbackStampListenerIsRegistered(): void {
		$registered = [];

		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = $event . ' => ' . $listener;
			}
		);

		(new EventListenerWiring())->registerAll(context: $context);

		self::assertContains(
			ObjectTransitionedEvent::class . ' => ' . MunicipalityFeedbackStampListener::class,
			$registered
		);
	}//end testMunicipalityFeedbackStampListenerIsRegistered()
}//end class
