<?php

/**
 * Learniq EmployerPortalInvitation read tests
 *
 * Pins how one company is read and checked before its contact person is
 * invited: which row counts as the company, when it answers like an unknown
 * one, which address is invited, and how a failing read is reported.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-identity/spec.md#requirement-the-institute-invites-a-companys-contact-person-as-its-employer
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\EmployerPortalInvitation;
use OCA\Learniq\Portal\PortalTenant;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__.'/EmployerSitePagesTest.php';

/**
 * The company read and checks of EmployerPortalInvitation.
 */
class EmployerPortalInvitationReadTest extends TestCase {

	/**
	 * Every read: the ref asked for and the rbac / multitenancy flags.
	 *
	 * @var list<array{0: mixed, 1: bool, 2: bool}>
	 */
	private array $reads = [];

	/**
	 * The addresses portaliq was asked to provision.
	 *
	 * @var list<string>
	 */
	private array $invited = [];

	/**
	 * Warnings logged.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	/**
	 * An invitation over a store answering these objects for any read.
	 *
	 * @param array<int, mixed> $answer The objects a read returns, or null to fail.
	 *
	 * @return EmployerPortalInvitation
	 */
	private function invitation(?array $answer): EmployerPortalInvitation {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($answer): array {
				$this->reads[] = [$config, $_rbac, $_multitenancy];
				if ($answer === null) {
					throw new RuntimeException('store down');
				}

				return $answer;
			}
		);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				if ($event instanceof FakeEmployerProvisionEvent) {
					$this->invited[] = $event->email;
				}
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context): void {
				$this->warnings[] = strtr($message, ['{ref}' => (string)$context['ref'], '{msg}' => $context['msg']]);
			}
		);

		return new EmployerPortalInvitation(
			objectService: $objects,
			dispatcher: $dispatcher,
			logger: $logger,
			provisionEventClass: FakeEmployerProvisionEvent::class,
			claimEventClass: FakeEmployerClaimEvent::class
		);
	}//end invitation()

	/**
	 * A company of the portal's organisation.
	 *
	 * @param array<string, mixed> $fields Fields to set or replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function company(array $fields): array {
		return array_merge(
			['id' => 'co-1', 'name' => 'Eigen BV', 'contactEmail' => 'linda@eigen.example', '@self' => ['organisation' => 'uuid-a']],
			$fields
		);
	}//end company()

	/**
	 * Which row counts as the company: matching id or uuid, active or without
	 * a lifecycle, array or entity; the read is one id in the company schema.
	 *
	 * @return void
	 */
	public function testOnlyTheActiveCompanyWithThisRefCounts(): void {
		$tenant = new PortalTenant(slug: 'org', uuid: 'uuid-a');
		$invited = ['status' => 'invited', 'subjectRef' => 'linda-account'];
		$unknown = ['status' => 'refused', 'reason' => 'company-unknown'];

		$cases = [
			'array'          => [[self::company([])], $invited],
			'no lifecycle'   => [[self::company(['lifecycle' => null])], $invited],
			'archived'       => [[self::company(['lifecycle' => 'archived'])], $unknown],
			'other id'       => [[self::company(['id' => 'co-2'])], $unknown],
			'by uuid'        => [[self::company(['id' => null, 'uuid' => 'co-1'])], $invited],
			'other uuid'     => [[self::company(['id' => null, 'uuid' => 'co-2'])], $unknown],
			'second row'     => [[self::company(['id' => 'co-2']), self::company(['lifecycle' => 'active'])], $invited],
			'not a row'      => [['co-1', 7], $unknown],
			'none'           => [[], $unknown],
			'other tenant'   => [[self::company(['@self' => ['organisation' => 'uuid-b']])], $unknown],
		];
		foreach ($cases as $name => [$answer, $expected]) {
			self::assertSame($expected, $this->invitation(answer: $answer)->invite(organisationRef: ' co-1 ', tenant: $tenant), $name);
		}

		$uuidOnly = array_diff_key(self::company([]), ['id' => true]) + ['uuid' => 'co-1'];
		self::assertSame($invited, $this->invitation(answer: [$uuidOnly])->invite(organisationRef: 'co-1', tenant: $tenant));

		$entity = OrEntityFactory::make(['name' => 'Eigen BV', 'contactEmail' => 'linda@eigen.example'], 'client-organisation', 'learniq', 'co-1');
		$entityResult = $this->invitation(answer: [$entity])->invite(organisationRef: 'co-1', tenant: $tenant);
		self::assertSame('company-unknown', ($entityResult['reason'] ?? ''), 'an entity row carries no portal organisation');

		self::assertSame(
			[['filters' => ['register' => 'learniq', 'schema' => 'client-organisation'], 'ids' => ['co-1'], 'limit' => 1], false, false],
			$this->reads[0]
		);
	}//end testOnlyTheActiveCompanyWithThisRefCounts()

	/**
	 * An empty ref is never read; a failing read is logged and reported as
	 * the portal being unavailable; the caller path reads with the caller's rights.
	 *
	 * @return void
	 */
	public function testEmptyRefsFailingReadsAndTheCallerPath(): void {
		$tenant = new PortalTenant(slug: 'org', uuid: 'uuid-a');

		self::assertSame(
			['status' => 'refused', 'reason' => 'company-unknown'],
			$this->invitation(answer: [self::company([])])->invite(organisationRef: '  ', tenant: $tenant)
		);
		self::assertSame([], $this->reads);

		self::assertSame(
			['status' => 'refused', 'reason' => 'portal-unavailable'],
			$this->invitation(answer: null)->invite(organisationRef: ' co-1', tenant: $tenant)
		);
		self::assertSame(['[EmployerPortalInvitation] Could not read company  co-1: store down'], $this->warnings);

		$this->invitation(answer: [self::company([])])->inviteAsCaller(organisationRef: 'co-1', tenant: $tenant);
		self::assertSame([true, true], array_slice(end($this->reads), 1));
	}//end testEmptyRefsFailingReadsAndTheCallerPath()

	/**
	 * The address given wins (trimmed); without one the company's contact
	 * address is used (trimmed); an invalid address is refused.
	 *
	 * @return void
	 */
	public function testTheInvitedAddress(): void {
		$tenant = new PortalTenant(slug: 'org', uuid: 'uuid-a');
		$invalid = ['status' => 'refused', 'reason' => 'email-invalid'];

		$this->invitation(answer: [self::company([])])->invite(organisationRef: 'co-1', tenant: $tenant, email: ' hr@eigen.example ');
		$this->invitation(answer: [self::company(['contactEmail' => ' piet@eigen.example '])])->invite(organisationRef: 'co-1', tenant: $tenant, email: '   ');
		self::assertSame(['hr@eigen.example', 'piet@eigen.example'], $this->invited);

		self::assertSame($invalid, $this->invitation(answer: [self::company(['contactEmail' => null])])->invite(organisationRef: 'co-1', tenant: $tenant));
		self::assertSame($invalid, $this->invitation(answer: [self::company([])])->invite(organisationRef: 'co-1', tenant: $tenant, email: 'not-an-address'));
		self::assertSame(['hr@eigen.example', 'piet@eigen.example'], $this->invited);
	}//end testTheInvitedAddress()
}//end class
