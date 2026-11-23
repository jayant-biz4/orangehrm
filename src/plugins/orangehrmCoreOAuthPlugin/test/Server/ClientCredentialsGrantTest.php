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

namespace OrangeHRM\Tests\OAuth\Server;

use DateTime;
use League\OAuth2\Server\CryptTrait;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use OrangeHRM\Admin\Service\UserService;
use OrangeHRM\Authentication\Auth\User as AuthUser;
use OrangeHRM\Authentication\Exception\UnauthorizedException;
use OrangeHRM\Config\Config;
use OrangeHRM\Core\Service\ConfigService;
use OrangeHRM\Core\Service\DateTimeHelperService;
use OrangeHRM\Entity\OAuthAccessToken;
use OrangeHRM\Entity\User;
use OrangeHRM\Framework\Http\Request;
use OrangeHRM\Framework\Http\Response;
use OrangeHRM\Framework\Http\Session\MemorySessionStorage;
use OrangeHRM\Framework\Http\Session\Session;
use OrangeHRM\Framework\Logger\Logger;
use OrangeHRM\Framework\Services;
use OrangeHRM\OAuth\Dto\Entity\ClientEntity;
use OrangeHRM\OAuth\Dto\Entity\UserEntity;
use OrangeHRM\OAuth\Repository\ClientRepository;
use OrangeHRM\OAuth\Server\OAuthServer;
use OrangeHRM\OAuth\Service\PsrHttpFactoryHelper;
use OrangeHRM\OAuth\Subscriber\OAuthSubscriber;
use OrangeHRM\Tests\Util\KernelTestCase;
use OrangeHRM\Tests\Util\TestDataService;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Fork patch (client credentials grant)
 *
 * @group OAuth
 * @group Server
 */
class ClientCredentialsGrantTest extends KernelTestCase
{
    use CryptTrait;

    private const SECRET = 'Im2eYEIIWp+sT+W9xVYYwzBlYXpC0pfHWA0GthvjrII=';
    private const TOKEN_ENCRYPTION_KEY = '9R8rdJTORQRWuSh3UbSAImWrFTU0Yd7RS+A4s+urPEM=';

    private PsrHttpFactoryHelper $psrHttpFactory;

    protected function setUp(): void
    {
        TestDataService::populate(
            Config::get(Config::PLUGINS_DIR)
            . '/orangehrmCoreOAuthPlugin/test/fixtures/ClientCredentialsGrantTest.yaml'
        );
        $this->encryptionKey = self::TOKEN_ENCRYPTION_KEY;
        $this->psrHttpFactory = new PsrHttpFactoryHelper();

        $dateTimeHelper = $this->getMockBuilder(DateTimeHelperService::class)
            ->onlyMethods(['getNow'])
            ->getMock();
        $dateTimeHelper->method('getNow')->willReturnCallback(fn () => new DateTime('2026-11-23 09:00:00'));
        $session = new Session(new MemorySessionStorage());
        $session->start();
        $this->createKernelWithMockServices([
            Services::DATETIME_HELPER_SERVICE => $dateTimeHelper,
            Services::OAUTH_SERVER => new OAuthServer(),
            Services::CONFIG_SERVICE => new ConfigService(),
            Services::USER_SERVICE => new UserService(),
            Services::PSR_HTTP_FACTORY_HELPER => $this->psrHttpFactory,
            Services::SESSION => $session,
            Services::AUTH_USER => AuthUser::getInstance(),
            Services::LOGGER => $this->getMockBuilder(Logger::class)->disableOriginalConstructor()->getMock(),
        ]);
    }

    /**
     * @return array{0: int, 1: array} HTTP status and decoded body of the token endpoint response
     */
    private function requestToken(array $body, array $server = []): array
    {
        $request = Request::create('/oauth2/token', 'POST', $body, [], [], $server);
        $psrResponse = $this->psrHttpFactory->createPsr7Response(new Response());
        try {
            $psrResponse = (new OAuthServer())->getServer()->respondToAccessTokenRequest(
                $this->psrHttpFactory->createPsr7Request($request),
                $psrResponse
            );
        } catch (OAuthServerException $e) {
            $psrResponse = $e->generateHttpResponse($psrResponse);
        }
        return [$psrResponse->getStatusCode(), json_decode((string)$psrResponse->getBody(), true)];
    }

    private function clientCredentials(string $clientId, ?string $secret = self::SECRET): array
    {
        $body = ['grant_type' => 'client_credentials', 'client_id' => $clientId];
        if ($secret !== null) {
            $body['client_secret'] = $secret;
        }
        return $this->requestToken($body);
    }

    private function getStoredToken(string $accessToken): OAuthAccessToken
    {
        $tokenId = $this->decrypt($accessToken);
        $this->getEntityManager()->clear();
        return $this->getEntityManager()->getRepository(OAuthAccessToken::class)->findOneBy(['accessToken' => $tokenId]);
    }

    public function testTokenIssuedForLinkedClientActsAsLinkedUser(): void
    {
        [$status, $body] = $this->clientCredentials('linked');

        $this->assertEquals(200, $status);
        $this->assertEquals('Bearer', $body['token_type']);
        $this->assertArrayHasKey('expires_in', $body);
        $this->assertArrayNotHasKey('refresh_token', $body);

        $token = $this->getStoredToken($body['access_token']);
        $this->assertEquals(1, $token->getUserId()); // hrbot
        $this->assertEquals(2, $token->getClientId());
        $this->assertFalse($token->isRevoked());
        $this->assertEquals('2026-11-23 09:15:00', $token->getExpiryDateTime()->format('Y-m-d H:i:s'));
    }

    public function testTokenIssuedWithHttpBasicClientAuthentication(): void
    {
        [$status, $body] = $this->requestToken(
            ['grant_type' => 'client_credentials'],
            ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('linked:' . self::SECRET)]
        );
        $this->assertEquals(200, $status);
        $this->assertEquals(1, $this->getStoredToken($body['access_token'])->getUserId());
    }

    /**
     * @dataProvider refusedDataProvider
     */
    public function testTokenRefused(string $clientId, ?string $secret): void
    {
        [$status, $body] = $this->clientCredentials($clientId, $secret);

        $this->assertEquals(401, $status);
        $this->assertEquals('invalid_client', $body['error']);
        $this->assertArrayNotHasKey('access_token', $body);
        $this->assertEquals(4, $this->getEntityManager()->getRepository(OAuthAccessToken::class)->count([])); // fixture tokens only
    }

    public function refusedDataProvider(): array
    {
        return [
            'unlinked client' => ['unlinked', self::SECRET],
            'wrong secret' => ['linked', 'wrong-secret'],
            'empty secret' => ['linked', ''],
            'no secret' => ['linked', null],
            'secret in wrong case' => ['linked', strtoupper(self::SECRET)],
            'linked user disabled' => ['linked-disabled', self::SECRET],
            'linked user deleted' => ['linked-deleted', self::SECRET],
            'linked employee terminated' => ['linked-terminated', self::SECRET],
            'client disabled' => ['disabled-client', self::SECRET],
            'public client' => ['public-linked', null],
            'unknown client' => ['no-such-client', self::SECRET],
        ];
    }

    public function testClientCredentialsUserId(): void
    {
        $repository = new ClientRepository();
        $this->assertEquals(1, $repository->getClientCredentialsUserId(2));
        $this->assertNull($repository->getClientCredentialsUserId(3)); // not linked
        $this->assertNull($repository->getClientCredentialsUserId(4)); // disabled user
        $this->assertNull($repository->getClientCredentialsUserId(5)); // deleted user
        $this->assertNull($repository->getClientCredentialsUserId(6)); // terminated employee
        $this->assertNull($repository->getClientCredentialsUserId(7)); // disabled client
        $this->assertNull($repository->getClientCredentialsUserId(99));

        // Other grants ignore the link
        $this->assertTrue($repository->validateClient('unlinked', self::SECRET, 'authorization_code'));
        $this->assertFalse($repository->validateClient('unlinked', self::SECRET, 'client_credentials'));
        $this->assertTrue($repository->validateClient('linked', self::SECRET, 'client_credentials'));
    }

    private function authenticateWithBearer(string $accessToken, string $scheme = 'Bearer'): void
    {
        AuthUser::getInstance()->setIsAuthenticated(false);
        $request = Request::create(
            '/api/v2/pim/employees',
            'GET',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => $scheme . ' ' . $accessToken, 'HTTP_ACCEPT' => 'application/json']
        );
        $this->getEntityManager()->clear();
        (new OAuthSubscriber())->onRequestEvent(
            new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST)
        );
    }

    private function updateUser(int $userId, array $fields): void
    {
        /** @var User $user */
        $user = $this->getEntityManager()->getRepository(User::class)->find($userId);
        isset($fields['status']) && $user->setStatus($fields['status']);
        isset($fields['deleted']) && $user->setDeleted($fields['deleted']);
        $this->getEntityManager()->flush();
    }

    public function testIssuedTokenAuthenticatesAsLinkedUser(): void
    {
        [, $body] = $this->clientCredentials('linked');
        $this->authenticateWithBearer($body['access_token']);

        $this->assertTrue(AuthUser::getInstance()->isAuthenticated());
        $this->assertEquals(1, AuthUser::getInstance()->getUserId());
        $this->assertEquals(1, AuthUser::getInstance()->getEmpNumber());
    }

    public function testBasicAuthorizationHeaderLeftToTokenEndpoint(): void
    {
        // e.g. client_secret_basic on /oauth2/token; must not be evaluated as a bearer token
        $this->authenticateWithBearer(base64_encode('linked:' . self::SECRET), 'Basic');
        $this->assertFalse(AuthUser::getInstance()->isAuthenticated());
    }

    public function testExistingTokenRefusedWhenLinkedUserDisabled(): void
    {
        [, $body] = $this->clientCredentials('linked');
        $this->updateUser(1, ['status' => false]);

        $this->expectException(UnauthorizedException::class);
        $this->authenticateWithBearer($body['access_token']);
    }

    public function testExistingTokenRefusedWhenLinkedUserDeleted(): void
    {
        [, $body] = $this->clientCredentials('linked');
        $this->updateUser(1, ['deleted' => true]);

        try {
            $this->authenticateWithBearer($body['access_token']);
            $this->fail();
        } catch (UnauthorizedException $e) {
            $this->assertFalse(AuthUser::getInstance()->isAuthenticated());
        }
    }

    private function sql(string $statement): void
    {
        $this->getEntityManager()->getConnection()->executeStatement($statement);
    }

    public function testIssuedTokenIsMarkedClientCredentials(): void
    {
        [, $body] = $this->clientCredentials('linked');
        $this->assertTrue($this->getStoredToken($body['access_token'])->isClientCredentials());
    }

    /**
     * @dataProvider changeAfterIssueDataProvider
     */
    public function testExistingTokenRefusedAfterClientOrLinkChange(array $statements): void
    {
        [, $body] = $this->clientCredentials('linked');
        $this->authenticateWithBearer($body['access_token']);
        $this->assertTrue(AuthUser::getInstance()->isAuthenticated());

        foreach ($statements as $statement) {
            $this->sql($statement);
        }
        try {
            $this->authenticateWithBearer($body['access_token']);
            $this->fail('token still accepted');
        } catch (UnauthorizedException $e) {
            $this->assertFalse(AuthUser::getInstance()->isAuthenticated());
        }
    }

    public function changeAfterIssueDataProvider(): array
    {
        return [
            'client disabled' => [['UPDATE ohrm_oauth2_client SET enabled = 0 WHERE id = 2']],
            'link removed out of band' => [
                ['UPDATE ohrm_oauth2_client SET client_credentials_user_id = NULL WHERE id = 2']
            ],
            'linked to another user' => [
                ['UPDATE ohrm_oauth2_client SET client_credentials_user_id = 4 WHERE id = 2']
            ],
            'client switched to public' => [
                ['UPDATE ohrm_oauth2_client SET is_confidential = 0, client_secret = NULL WHERE id = 2']
            ],
            'client deleted' => [['DELETE FROM ohrm_oauth2_client WHERE id = 2']],
            'linked employee terminated' => [[
                "INSERT INTO ohrm_emp_termination (id, reason_id, termination_date, emp_number) VALUES (2, 1, '2026-11-01', 1)",
                'UPDATE hs_hr_employee SET termination_id = 2 WHERE emp_number = 1',
            ]],
        ];
    }

    public function testLowercaseBearerSchemeAccepted(): void
    {
        [, $body] = $this->clientCredentials('linked');
        $this->authenticateWithBearer($body['access_token'], 'bearer');
        $this->assertEquals(1, AuthUser::getInstance()->getUserId());
    }

    public function testAuthCodeFlowUnchanged(): void
    {
        // The `unlinked` client can't use client credentials, but still does authorization code + refresh
        $authRequest = new AuthorizationRequest();
        $authRequest->setGrantTypeId('authorization_code');
        $authRequest->setClient(new ClientEntity(3, 'unlinked', 'https://example.com/oauth/callback', true, 'Unlinked'));
        $authRequest->setRedirectUri('https://example.com/oauth/callback');
        $authRequest->setUser(new UserEntity('1'));
        $authRequest->setAuthorizationApproved(true);
        $redirect = (new OAuthServer())->getServer()->completeAuthorizationRequest(
            $authRequest,
            $this->psrHttpFactory->createPsr7Response(new Response())
        );
        parse_str((string)parse_url($redirect->getHeaderLine('Location'), PHP_URL_QUERY), $query);

        [$status, $body] = $this->requestToken([
            'grant_type' => 'authorization_code',
            'client_id' => 'unlinked',
            'client_secret' => self::SECRET,
            'redirect_uri' => 'https://example.com/oauth/callback',
            'code' => $query['code'],
        ]);
        $this->assertEquals(200, $status);
        $this->assertArrayHasKey('refresh_token', $body);
        $this->assertEquals(1, $this->getStoredToken($body['access_token'])->getUserId());
        $this->assertFalse($this->getStoredToken($body['access_token'])->isClientCredentials());
        $this->authenticateWithBearer($body['access_token']); // not subject to the link checks
        $this->assertEquals(1, AuthUser::getInstance()->getUserId());

        [$status, $refreshed] = $this->requestToken([
            'grant_type' => 'refresh_token',
            'client_id' => 'unlinked',
            'client_secret' => self::SECRET,
            'refresh_token' => $body['refresh_token'],
        ]);
        $this->assertEquals(200, $status);
        $this->assertEquals(1, $this->getStoredToken($refreshed['access_token'])->getUserId());
    }
}
