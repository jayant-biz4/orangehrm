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

namespace OrangeHRM\OAuth\Repository;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use OrangeHRM\Authentication\Dto\UserCredential;
use OrangeHRM\Authentication\Dto\UserCredentialInterface;
use OrangeHRM\Core\Dao\BaseDao;
use OrangeHRM\Core\Utility\PasswordHash;
use OrangeHRM\Entity\OAuthClient;
use OrangeHRM\Entity\User;
use OrangeHRM\OAuth\Dto\Entity\ClientEntity;

class ClientRepository extends BaseDao implements ClientRepositoryInterface
{
    /**
     * @param string $clientIdentifier
     * @return OAuthClient|null
     */
    private function getOAuthClientById(string $clientIdentifier): ?OAuthClient
    {
        return $this->getRepository(OAuthClient::class)
            ->findOneBy(['clientId' => $clientIdentifier, 'enabled' => true]);
    }

    /**
     * @inheritdoc
     */
    public function getClientEntity($clientIdentifier): ?ClientEntityInterface
    {
        $oauthClient = $this->getOAuthClientById($clientIdentifier);
        if (!$oauthClient instanceof OAuthClient) {
            return null;
        }

        return ClientEntity::createFromEntity($oauthClient);
    }

    /**
     * @inheritdoc
     */
    public function validateClient($clientIdentifier, $clientSecret, $grantType): bool
    {
        $oauthClient = $this->getOAuthClientById($clientIdentifier);
        if (!$oauthClient instanceof OAuthClient) {
            return false;
        }
        if ($oauthClient->isConfidential() === true && $oauthClient->getClientSecret() === null) {
            // Confidential client must have a secret
            return false;
        }

        if ($grantType === 'refresh_token' && $oauthClient->isConfidential() === false) {
            return true;
        }

        if (!$this->validateClientSecret($oauthClient, new UserCredential(null, $clientSecret))) {
            return false;
        }

        // Fork patch (client credentials grant): only for confidential clients linked to an active user
        if ($grantType === 'client_credentials') {
            return $oauthClient->isConfidential()
                && $this->getClientCredentialsUserId($oauthClient->getId()) !== null;
        }

        return true;
    }

    /**
     * Fork patch (client credentials grant)
     *
     * @param int $clientId `ohrm_oauth2_client.id`
     * @return int|null the linked user id, or null if no user is linked or the linked user can't sign in
     */
    public function getClientCredentialsUserId(int $clientId): ?int
    {
        $oauthClient = $this->getRepository(OAuthClient::class)->findOneBy(['id' => $clientId, 'enabled' => true]);
        if (!$oauthClient instanceof OAuthClient || $oauthClient->getClientCredentialsUserId() === null) {
            return null;
        }
        $user = $this->getRepository(User::class)->find($oauthClient->getClientCredentialsUserId());
        return $user instanceof User && self::isActiveUser($user) ? $user->getId() : null;
    }

    /**
     * Same conditions a sign-in needs (see AuthenticationService::setCredentialsForUser)
     *
     * @param User $user
     * @return bool
     */
    public static function isActiveUser(User $user): bool
    {
        return !$user->isDeleted()
            && $user->getStatus()
            && $user->getEmployee() !== null
            && $user->getEmployee()->getEmployeeTerminationRecord() === null;
    }

    /**
     * @param OAuthClient $client
     * @param UserCredentialInterface $givenCred
     * @return bool
     */
    private function validateClientSecret(OAuthClient $client, UserCredentialInterface $givenCred): bool
    {
        // Fork patch: a request without client_secret is refused, not a TypeError (500)
        if ($client->getClientSecret() === null || $givenCred->getPassword() === null) {
            return false;
        }
        $passwordHasher = new PasswordHash();
        return $passwordHasher->verify($givenCred->getPassword(), $client->getClientSecret());
    }
}
