<?php

/**
 * OrangeHRM is a comprehensive Human Resource Management (HRM) System that captures
 * all the essential functionalities required for any enterprise.
 * Copyright (C) 2006 OrangeHRM Inc., http://www.orangehrm.com
 *
 * OrangeHRM is free software: you can redistribute it and/or modify it under the terms of
 * the GNU General Public License as published by the Free Software Foundation, either
 * version 3 of the License, or (at your option) any later version.
 *
 * OrangeHRM is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with OrangeHRM.
 * If not, see <https://www.gnu.org/licenses/>.
 */

namespace OrangeHRM\Tests\OAuth\Command;

use OrangeHRM\Admin\Service\UserService;
use OrangeHRM\Config\Config;
use OrangeHRM\Entity\OAuthAccessToken;
use OrangeHRM\Entity\OAuthClient;
use OrangeHRM\Framework\Services;
use OrangeHRM\OAuth\Command\ClientCredentialsCommand;
use OrangeHRM\OAuth\Service\OAuthService;
use OrangeHRM\Tests\Util\KernelTestCase;
use OrangeHRM\Tests\Util\TestDataService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Fork patch (client credentials grant)
 *
 * @group OAuth
 * @group Command
 */
class ClientCredentialsCommandTest extends KernelTestCase
{
    protected function setUp(): void
    {
        TestDataService::populate(
            Config::get(Config::PLUGINS_DIR)
            . '/orangehrmCoreOAuthPlugin/test/fixtures/ClientCredentialsGrantTest.yaml'
        );
        $this->createKernelWithMockServices([
            Services::OAUTH_SERVICE => new OAuthService(),
            Services::USER_SERVICE => new UserService(),
        ]);
    }

    private function runCommand(array $input): CommandTester
    {
        $tester = new CommandTester(new ClientCredentialsCommand());
        $tester->execute($input);
        $this->getEntityManager()->clear();
        return $tester;
    }

    private function getLinkedUserId(int $id): ?int
    {
        return $this->getEntityManager()->find(OAuthClient::class, $id)->getClientCredentialsUserId();
    }

    private function isRevoked(int $tokenId): bool
    {
        return $this->getEntityManager()->find(OAuthAccessToken::class, $tokenId)->isRevoked();
    }

    public function testLink(): void
    {
        $tester = $this->runCommand(['client_id' => 'unlinked', 'username' => 'hrbot']);
        $this->assertEquals(Command::SUCCESS, $tester->getStatusCode());
        $this->assertEquals(1, $this->getLinkedUserId(3));
    }

    public function testShowLink(): void
    {
        $tester = $this->runCommand(['client_id' => 'linked']);
        $this->assertEquals(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('acts as `hrbot` (active)', $tester->getDisplay());
        $this->assertEquals(1, $this->getLinkedUserId(2));
    }

    public function testDisableRevokesTokensOfLinkedUser(): void
    {
        $tester = $this->runCommand(['client_id' => 'linked', '--disable' => true]);
        $this->assertEquals(Command::SUCCESS, $tester->getStatusCode());
        $this->assertNull($this->getLinkedUserId(2));
        $this->assertTrue($this->isRevoked(1)); // token of hrbot issued by this client
        $this->assertFalse($this->isRevoked(2)); // another user's token
    }

    public function testRelinkRevokesTokensOfPreviousUser(): void
    {
        $tester = $this->runCommand(['client_id' => 'linked-disabled', 'username' => 'hrbot']);
        $this->assertEquals(Command::SUCCESS, $tester->getStatusCode());
        $this->assertEquals(1, $this->getLinkedUserId(4));
        $this->assertTrue($this->isRevoked(3)); // previous user's token issued by this client
        $this->assertFalse($this->isRevoked(2)); // same user, other client
    }

    /**
     * @dataProvider refusedDataProvider
     */
    public function testRefused(array $input, int $status): void
    {
        $before = $this->getLinkedUserId(3);
        $tester = $this->runCommand($input);
        $this->assertEquals($status, $tester->getStatusCode());
        $this->assertEquals($before, $this->getLinkedUserId(3));
    }

    public function refusedDataProvider(): array
    {
        return [
            'unknown client' => [['client_id' => 'no-such-client', 'username' => 'hrbot'], Command::FAILURE],
            'unknown user' => [['client_id' => 'unlinked', 'username' => 'nobody'], Command::FAILURE],
            'disabled user' => [['client_id' => 'unlinked', 'username' => 'disabled'], Command::FAILURE],
            'deleted user' => [['client_id' => 'unlinked', 'username' => 'deleted'], Command::FAILURE],
            'terminated employee' => [['client_id' => 'unlinked', 'username' => 'terminated'], Command::FAILURE],
            'both username and --disable' => [
                ['client_id' => 'unlinked', 'username' => 'hrbot', '--disable' => true],
                Command::INVALID
            ],
        ];
    }

    public function testRefusedForPublicClient(): void
    {
        $tester = $this->runCommand(['client_id' => 'orangehrm_mobile_app', 'username' => 'hrbot']);
        $this->assertEquals(Command::FAILURE, $tester->getStatusCode());
        $this->assertNull($this->getLinkedUserId(1));
    }
}
