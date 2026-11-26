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

namespace OrangeHRM\Leave\Command;

use OrangeHRM\Core\Traits\ORM\EntityManagerHelperTrait;
use OrangeHRM\Framework\Console\Command;
use OrangeHRM\Installer\Migration\Fork\LocationHolidays\SchemaUpdate;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fork patch (location-based holidays): (re-)apply the fork's schema changes, e.g. when the
 * instance is already at 5.9.1 or the fork migration step failed after upstream's 5.9.1 step
 */
class LocationHolidaysSchemaCommand extends Command
{
    use EntityManagerHelperTrait;

    /**
     * @inheritDoc
     */
    public function getCommandName(): string
    {
        return 'orangehrm:leave:location-holidays-schema';
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setDescription(
            'Add the holiday location column, index, foreign key, lang strings and API permission if missing'
        );
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        (new SchemaUpdate($this->getEntityManager()->getConnection()))->apply();
        $this->getIO()->success('Location holidays schema is complete. Now run `php bin/console cache:clear`.');
        return self::SUCCESS;
    }
}
