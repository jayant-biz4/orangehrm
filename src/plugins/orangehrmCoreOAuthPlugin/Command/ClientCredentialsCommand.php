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

namespace OrangeHRM\OAuth\Command;

use OrangeHRM\Admin\Traits\Service\UserServiceTrait;
use OrangeHRM\Entity\OAuthClient;
use OrangeHRM\Entity\User;
use OrangeHRM\Framework\Console\Command;
use OrangeHRM\OAuth\Repository\AccessTokenRepository;
use OrangeHRM\OAuth\Repository\ClientRepository;
use OrangeHRM\OAuth\Traits\OAuthServiceTrait;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fork patch (client credentials grant): link an OAuth client to the user it acts as
 */
class ClientCredentialsCommand extends Command
{
    use OAuthServiceTrait;
    use UserServiceTrait;

    public const ARGUMENT_CLIENT_ID = 'client_id';
    public const ARGUMENT_USERNAME = 'username';
    public const OPTION_DISABLE = 'disable';

    /**
     * @inheritDoc
     */
    public function getCommandName(): string
    {
        return 'orangehrm:oauth:client-credentials';
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setDescription(
            'Allow an OAuth client to use the client credentials grant, acting as the given user'
        )
            ->addArgument(self::ARGUMENT_CLIENT_ID, InputArgument::REQUIRED, 'OAuth client id')
            ->addArgument(self::ARGUMENT_USERNAME, InputArgument::OPTIONAL, 'User the client acts as')
            ->addOption(self::OPTION_DISABLE, null, InputOption::VALUE_NONE, 'Refuse the grant for this client')
            ->setHelp('Without a username or --disable, shows the current link.');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $clientDao = $this->getOAuthService()->getOAuthClientDao();
        $client = $clientDao->getOAuthClientByClientId($input->getArgument(self::ARGUMENT_CLIENT_ID));
        if (!$client instanceof OAuthClient) {
            $this->getIO()->error('OAuth client not found');
            return self::FAILURE;
        }

        $username = $input->getArgument(self::ARGUMENT_USERNAME);
        $disable = $input->getOption(self::OPTION_DISABLE);
        if ($username !== null && $disable) {
            $this->getIO()->error('Give either a username or --disable, not both');
            return self::INVALID;
        }

        $currentUserId = $client->getClientCredentialsUserId();
        if ($username === null && !$disable) {
            $this->showLink($client, $currentUserId);
            return self::SUCCESS;
        }

        $newUserId = null;
        if ($username !== null) {
            $user = $this->getUserService()->geUserDao()->getUserByUserName($username);
            if (!$user instanceof User || !ClientRepository::isActiveUser($user)) {
                $this->getIO()->error(
                    'User not found, or not able to sign in (disabled, no employee, or employee terminated)'
                );
                return self::FAILURE;
            }
            if (!$client->isConfidential() || $client->getClientSecret() === null) {
                $this->getIO()->error('The client must be confidential, with a secret');
                return self::FAILURE;
            }
            $newUserId = $user->getId();
        }

        $client->setClientCredentialsUserId($newUserId);
        $clientDao->saveOAuthClient($client);

        if ($currentUserId !== null && $currentUserId !== $newUserId) {
            $revoked = (new AccessTokenRepository())
                ->revokeAccessTokensForClientUser($client->getId(), $currentUserId);
            $this->getIO()->note("Revoked $revoked access token(s) issued by this client to the previous user");
        }

        $newUserId === null
            ? $this->getIO()->success('Client credentials grant disabled for this client')
            : $this->getIO()->success("Client credentials grant enabled; tokens act as user `$username`");
        return self::SUCCESS;
    }

    /**
     * @param OAuthClient $client
     * @param int|null $userId
     */
    private function showLink(OAuthClient $client, ?int $userId): void
    {
        if ($userId === null) {
            $this->getIO()->writeln('Client credentials grant: disabled');
            return;
        }
        $user = $this->getUserService()->geUserDao()->getSystemUser($userId);
        $name = $user instanceof User ? $user->getUserName() : "(deleted user id $userId)";
        $active = $user instanceof User && ClientRepository::isActiveUser($user) ? 'active' : 'NOT active, grant refused';
        $this->getIO()->writeln("Client credentials grant: acts as `$name` ($active)");
        if (!$client->isEnabled()) {
            $this->getIO()->writeln('Client is disabled, grant refused');
        }
    }
}
