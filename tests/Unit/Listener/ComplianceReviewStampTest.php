<?php

/**
 * Tests for ComplianceReviewStamp.
 *
 * The admin page used to write privacyconvenantSignedAt as "now" on every
 * save, so the real signing date was lost, and it wrote lastReviewedBy/At
 * itself. The server now writes all three.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#scenario-a-compliance-officer-records-the-signed-privacyconvenant-agreement
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Listener\ComplianceReviewStamp;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ComplianceReviewStamp::handle().
 */
class ComplianceReviewStampTest extends TestCase {

	private const SIGNED_AT = '2026-03-01T09:00:00+00:00';

	/**
	 * Build the stamp for a schema slug, with the privacy officer signed in.
	 *
	 * @param string $schemaSlug The slug the resolver answers.
	 *
	 * @return ComplianceReviewStamp
	 */
	private function stamp(string $schemaSlug = 'compliance'): ComplianceReviewStamp {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn($schemaSlug);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('privacy-officer');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ComplianceReviewStamp(schemaResolver: $resolver, userSession: $session);
	}//end stamp()

	/**
	 * Re-saving a signed agreement keeps the date it was signed, whatever the page sent.
	 *
	 * @return void
	 */
	public function testResavingASignedAgreementKeepsItsSigningDate(): void {
		$old = OrEntityFactory::make(['privacyconvenantSigned' => true, 'privacyconvenantSignedAt' => self::SIGNED_AT], 'compliance');
		$new = OrEntityFactory::make(['privacyconvenantSigned' => true, 'privacyconvenantSignedAt' => '2026-10-21T10:00:00+00:00', 'privacybijsluiterVersion' => '2'], 'compliance');
		$event = new ObjectUpdatingEvent($new, $old);

		$this->stamp()->handle($event);

		$data = $event->getModifiedData();
		self::assertSame(self::SIGNED_AT, $data['privacyconvenantSignedAt']);
		self::assertSame('privacy-officer', $data['lastReviewedBy']);
		self::assertNotEmpty($data['lastReviewedAt']);
	}//end testResavingASignedAgreementKeepsItsSigningDate()

	/**
	 * Marking the agreement signed stamps the moment; unmarking clears it.
	 *
	 * @return void
	 */
	public function testSigningStampsTheMomentAndUnsigningClearsIt(): void {
		$unsigned = OrEntityFactory::make(['privacyconvenantSigned' => false], 'compliance');
		$signing = new ObjectUpdatingEvent(OrEntityFactory::make(['privacyconvenantSigned' => true], 'compliance'), $unsigned);
		$this->stamp()->handle($signing);
		$signedAt = $signing->getModifiedData()['privacyconvenantSignedAt'];
		self::assertIsString($signedAt);
		self::assertNotSame(self::SIGNED_AT, $signedAt);

		$signed = OrEntityFactory::make(['privacyconvenantSigned' => true, 'privacyconvenantSignedAt' => self::SIGNED_AT], 'compliance');
		$unsigning = new ObjectUpdatingEvent(OrEntityFactory::make(['privacyconvenantSigned' => false, 'privacyconvenantSignedAt' => self::SIGNED_AT], 'compliance'), $signed);
		$this->stamp()->handle($unsigning);
		self::assertNull($unsigning->getModifiedData()['privacyconvenantSignedAt']);
	}//end testSigningStampsTheMomentAndUnsigningClearsIt()

	/**
	 * A first record that is signed gets the moment and the reviewer, not the page's values.
	 *
	 * @return void
	 */
	public function testACreateIsStampedFromTheServer(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['privacyconvenantSigned' => true, 'lastReviewedBy' => 'someone-else', 'privacyconvenantSignedAt' => '1999-01-01T00:00:00+00:00'], 'compliance'));

		$this->stamp()->handle($event);

		$data = $event->getModifiedData();
		self::assertSame('privacy-officer', $data['lastReviewedBy']);
		self::assertNotSame('1999-01-01T00:00:00+00:00', $data['privacyconvenantSignedAt']);
	}//end testACreateIsStampedFromTheServer()

	/**
	 * Another schema is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['privacyconvenantSigned' => true], 'course'));

		$this->stamp(schemaSlug: 'course')->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * The stamp is wired for create and update.
	 *
	 * @return void
	 */
	public function testTheStampIsRegisteredForCreateAndUpdate(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = $event . ' => ' . $listener;
			}
		);

		(new IntegrityListenerRegistrar())->register(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . ComplianceReviewStamp::class, $registered);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . ComplianceReviewStamp::class, $registered);
	}//end testTheStampIsRegisteredForCreateAndUpdate()
}//end class
