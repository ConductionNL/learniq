<?php
/**
 * CallerOrganisations test.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-guardian-invitation-letter/specs/portal-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\CallerOrganisations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Security review L5: the portal organisations a staff user may invite into
 * are the OpenRegister organisations they belong to, and nothing else.
 */
class CallerOrganisationsTest extends TestCase {

	/**
	 * A member of one school belongs to that school only; an empty slug and
	 * another school's are refused.
	 *
	 * @return void
	 */
	public function testOnlyTheCallersOwnOrganisationsAreIncluded(): void {
		$organisations = $this->organisations(service: new FakeOrganisationService(['de-wilgenboom', 'default-organisation']));

		$this->assertTrue(condition: $organisations->includes('de-wilgenboom'));
		$this->assertTrue(condition: $organisations->includes(' default-organisation '));
		$this->assertFalse(condition: $organisations->includes('vaartveld-college'));
		$this->assertFalse(condition: $organisations->includes(''));

	}//end testOnlyTheCallersOwnOrganisationsAreIncluded()

	/**
	 * Without OpenRegister the caller belongs to nothing: every slug is
	 * refused, so no invitation goes out into an organisation nobody checked.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsIncluded(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no openregister'));

		$this->assertFalse(condition: (new CallerOrganisations(container: $container, logger: $this->createMock(originalClassName: LoggerInterface::class)))->includes('de-wilgenboom'));

	}//end testWithoutOpenRegisterNothingIsIncluded()

	/**
	 * The service under test over a container answering one service.
	 *
	 * @param object $service What the container answers for OpenRegister's service.
	 *
	 * @return CallerOrganisations
	 */
	private function organisations(object $service): CallerOrganisations {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->with(CallerOrganisations::ORGANISATION_SERVICE)->willReturn($service);

		return new CallerOrganisations(container: $container, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end organisations()
}//end class

/**
 * Stands in for OpenRegister's OrganisationService: getUserOrganisations()
 * answers entities with a slug, as OpenRegister's Organisation does.
 */
class FakeOrganisationService {

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $slugs The slugs of the user's organisations.
	 */
	public function __construct(private readonly array $slugs) {
	}//end __construct()

	/**
	 * The user's organisations.
	 *
	 * @return array<int, object>
	 */
	public function getUserOrganisations(): array {
		return array_map(
			static fn (string $slug): object => new class($slug) {
				/**
				 * @param string $slug The slug.
				 */
				public function __construct(private readonly string $slug) {
				}

				/**
				 * @return string
				 */
				public function getSlug(): string {
					return $this->slug;
				}
			},
			$this->slugs
		);
	}//end getUserOrganisations()
}//end class
