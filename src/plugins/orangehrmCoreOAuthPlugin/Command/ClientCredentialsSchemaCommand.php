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

use OrangeHRM\Core\Traits\ORM\EntityManagerHelperTrait;
use OrangeHRM\Framework\Console\Command;
use OrangeHRM\Installer\Migration\Fork\ClientCredentials\SchemaUpdate;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fork patch (client credentials grant): (re-)apply the fork's schema changes, e.g. when the
 * instance is already at 5.9.1 or the fork migration step failed after upstream's 5.9.1 step
 */
class ClientCredentialsSchemaCommand extends Command
{
    use EntityManagerHelperTrait;

    /**
     * @inheritDoc
     */
    public function getCommandName(): string
    {
        return 'orangehrm:oauth:client-credentials-schema';
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setDescription('Add the client credentials grant columns, index and foreign key if missing');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        (new SchemaUpdate($this->getEntityManager()->getConnection()))->apply();
        $this->getIO()->success('Client credentials schema is complete. Now run `php bin/console cache:clear`.');
        return self::SUCCESS;
    }
}
