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

namespace OrangeHRM\Tests\OAuth\Api;

use OrangeHRM\Config\Config;
use OrangeHRM\Core\Api\V2\RequestParams;
use OrangeHRM\Entity\OAuthAccessToken;
use OrangeHRM\Entity\OAuthClient;
use OrangeHRM\Entity\OAuthRefreshToken;
use OrangeHRM\Framework\Services;
use OrangeHRM\OAuth\Api\OAuthClientAPI;
use OrangeHRM\OAuth\Service\OAuthService;
use OrangeHRM\Tests\Util\EndpointTestCase;
use OrangeHRM\Tests\Util\TestDataService;

/**
 * Fork patch (client credentials grant): admin edits of a client must not leave usable tokens
 * or a dormant client credentials link behind.
 *
 * @group OAuth
 * @group APIv2
 */
class OAuthClientCredentialsUpdateTest extends EndpointTestCase
{
    protected function setUp(): void
    {
        TestDataService::populate(
            Config::get(Config::PLUGINS_DIR)
            . '/orangehrmCoreOAuthPlugin/test/fixtures/ClientCredentialsGrantTest.yaml'
        );
        $this->createKernelWithMockServices([Services::OAUTH_SERVICE => new OAuthService()]);
    }

    private function update(int $id, bool $enabled, bool $confidential, string $name = 'Linked'): void
    {
        $api = $this->getApiEndpointMockBuilder(OAuthClientAPI::class, [
            RequestParams::PARAM_TYPE_ATTRIBUTE => ['id' => $id],
            RequestParams::PARAM_TYPE_BODY => [
                'name' => $name,
                'redirectUri' => 'https://example.com/oauth/callback',
                'enabled' => $enabled,
                'confidential' => $confidential,
            ],
        ])->onlyMethods(['generateSecret'])->getMock();
        $api->method('generateSecret')->willReturn('new-secret');
        $api->update();
        $this->getEntityManager()->clear();
    }

    private function linkOf(int $id): ?int
    {
        return $this->getEntityManager()->find(OAuthClient::class, $id)->getClientCredentialsUserId();
    }

    private function accessRevoked(int $id): bool
    {
        return $this->getEntityManager()->find(OAuthAccessToken::class, $id)->isRevoked();
    }

    private function refreshRevoked(int $id): bool
    {
        return $this->getEntityManager()->find(OAuthRefreshToken::class, $id)->isRevoked();
    }

    public function testSwitchToPublicClearsLinkAndRevokesTokens(): void
    {
        $this->update(2, true, false);
        $this->assertNull($this->linkOf(2));
        $this->assertTrue($this->accessRevoked(1));
        $this->assertTrue($this->accessRevoked(2));
        $this->assertTrue($this->refreshRevoked(1));
        $this->assertFalse($this->accessRevoked(3)); // other client
    }

    public function testSwitchBackToConfidentialDoesNotRestoreLink(): void
    {
        $this->update(2, true, false);
        $this->update(2, true, true);
        $this->assertNull($this->linkOf(2));
        $this->assertNotNull($this->getEntityManager()->find(OAuthClient::class, 2)->getClientSecret());
    }

    public function testDisableRevokesTokensKeepsLink(): void
    {
        $this->update(2, false, true);
        $this->assertEquals(1, $this->linkOf(2));
        $this->assertTrue($this->accessRevoked(1));
        $this->assertTrue($this->refreshRevoked(1));
    }

    public function testRenameKeepsTokensAndLink(): void
    {
        $this->update(2, true, true, 'Renamed');
        $this->assertEquals(1, $this->linkOf(2));
        $this->assertFalse($this->accessRevoked(1));
        $this->assertFalse($this->refreshRevoked(1));
    }
}
