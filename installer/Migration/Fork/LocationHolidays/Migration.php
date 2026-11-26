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

namespace OrangeHRM\Installer\Migration\Fork\LocationHolidays;

use OrangeHRM\Installer\Util\V1\AbstractMigration;

/**
 * Fork patch (location-based holidays), not part of upstream OrangeHRM.
 *
 * Runs as a further step of the 5.9.1 entry in AppSetupUtility::MIGRATIONS_MAP and reports
 * the upstream version, so `instance.version` stays an upstream value and later upstream
 * upgrades are detected as usual. Idempotent piece by piece (see SchemaUpdate); to re-run it
 * after a partial failure: `php bin/console orangehrm:leave:location-holidays-schema`.
 */
class Migration extends AbstractMigration
{
    /**
     * @inheritDoc
     */
    public function up(): void
    {
        (new SchemaUpdate($this->getConnection()))->apply();
    }

    /**
     * @inheritDoc
     */
    public function getVersion(): string
    {
        return '5.9.1';
    }
}
