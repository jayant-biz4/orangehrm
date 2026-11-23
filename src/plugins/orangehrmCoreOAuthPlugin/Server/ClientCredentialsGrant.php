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

namespace OrangeHRM\OAuth\Server;

use DateInterval;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\ClientCredentialsGrant as BaseClientCredentialsGrant;
use OrangeHRM\OAuth\Repository\ClientRepository;

/**
 * Fork patch (client credentials grant): League's grant, except the access token is issued
 * to the user linked to the client (`ohrm_oauth2_client.client_credentials_user_id`), so the
 * existing per-user API permission checks apply. The client secret is checked by League
 * through ClientRepository::validateClient before this runs. No refresh token is issued.
 */
class ClientCredentialsGrant extends BaseClientCredentialsGrant
{
    /**
     * @inheritDoc
     */
    protected function issueAccessToken(
        DateInterval $accessTokenTTL,
        ClientEntityInterface $client,
        $userIdentifier,
        array $scopes = []
    ) {
        $userId = $this->clientRepository instanceof ClientRepository
            ? $this->clientRepository->getClientCredentialsUserId($client->getIdentifier())
            : null;
        if ($userId === null) {
            throw OAuthServerException::accessDenied();
        }
        return parent::issueAccessToken($accessTokenTTL, $client, $userId, $scopes);
    }
}
