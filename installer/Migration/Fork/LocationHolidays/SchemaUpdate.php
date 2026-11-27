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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Types\Types;
use OrangeHRM\Installer\Util\V1\DataGroupHelper;
use OrangeHRM\Installer\Util\V1\LangStringHelper;
use OrangeHRM\Installer\Util\V1\SchemaHelper;

/**
 * Fork patch (location-based holidays): schema, lang string and API permission changes, each
 * piece checked on its own, so it is safe to run again after a partial failure (MySQL DDL is not
 * transactional). Used by the installer migration and by
 * `bin/console orangehrm:leave:location-holidays-schema`. See docs/fork-location-holidays.md
 */
class SchemaUpdate
{
    public const TABLE = 'ohrm_holiday';
    public const COLUMN = 'location_id';
    public const INDEX = 'holiday_location_id_date';
    public const FOREIGN_KEY = 'holiday_location_id';
    public const DATA_GROUP = 'apiv2_leave_holiday_date_validation';
    public const SCREEN_URL = 'holidayList';
    public const MENU_TITLE = 'Holiday List';
    public const WIDGET_DATA_GROUP = 'dashboard_holiday_widget';

    private Connection $connection;
    private SchemaHelper $schemaHelper;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->schemaHelper = new SchemaHelper($connection);
    }

    public function apply(): void
    {
        if (!$this->schemaHelper->columnExists(self::TABLE, self::COLUMN)) {
            $this->schemaHelper->addColumn(
                self::TABLE,
                self::COLUMN,
                Types::INTEGER,
                ['Notnull' => false, 'Default' => null]
            );
        }

        $table = $this->connection->createSchemaManager()->introspectTable(self::TABLE);
        if (!$table->hasIndex(self::INDEX)) {
            $this->connection->createSchemaManager()->createIndex(
                new Index(self::INDEX, [self::COLUMN, 'date']),
                self::TABLE
            );
        }

        if (!$table->hasForeignKey(self::FOREIGN_KEY)) {
            $this->schemaHelper->addForeignKey(
                self::TABLE,
                new ForeignKeyConstraint(
                    [self::COLUMN],
                    'ohrm_location',
                    ['id'],
                    self::FOREIGN_KEY,
                    ['onDelete' => 'CASCADE']
                )
            );
        }

        (new LangStringHelper($this->connection))->insertOrUpdateLangStrings(__DIR__, 'leave');

        // DataGroupHelper::insertApiPermissions is not idempotent
        $exists = $this->connection->createQueryBuilder()
            ->select('dataGroup.id')
            ->from('ohrm_data_group', 'dataGroup')
            ->where('dataGroup.name = :name')
            ->setParameter('name', self::DATA_GROUP)
            ->executeQuery()
            ->fetchOne();
        if ($exists === false) {
            (new DataGroupHelper($this->connection))->insertApiPermissions(__DIR__ . '/permission/api.yaml');
        }

        $leaveModuleId = $this->connection->createQueryBuilder()
            ->select('module.id')
            ->from('ohrm_module', 'module')
            ->where('module.name = :name')
            ->setParameter('name', 'leave')
            ->executeQuery()
            ->fetchOne();
        $screenId = $this->findScreenId($leaveModuleId);
        if ($screenId === false) {
            (new DataGroupHelper($this->connection))->insertScreenPermissions(__DIR__ . '/permission/screen.yaml');
            $screenId = $this->findScreenId($leaveModuleId);
        }

        $widgetExists = $this->connection->createQueryBuilder()
            ->select('dataGroup.id')
            ->from('ohrm_data_group', 'dataGroup')
            ->where('dataGroup.name = :name')
            ->setParameter('name', self::WIDGET_DATA_GROUP)
            ->executeQuery()
            ->fetchOne();
        if ($widgetExists === false) {
            (new DataGroupHelper($this->connection))->insertDataGroupPermissions(
                __DIR__ . '/permission/data_group.yaml'
            );
        }

        $leaveMenuId = $this->connection->createQueryBuilder()
            ->select('menuItem.id')
            ->from('ohrm_menu_item', 'menuItem')
            ->where('menuItem.menu_title = :menuTitle')
            ->setParameter('menuTitle', 'Leave')
            ->andWhere('menuItem.level = :level')
            ->setParameter('level', 1)
            ->executeQuery()
            ->fetchOne();
        $menuExists = $this->connection->createQueryBuilder()
            ->select('menuItem.id')
            ->from('ohrm_menu_item', 'menuItem')
            ->where('menuItem.menu_title = :menuTitle')
            ->setParameter('menuTitle', self::MENU_TITLE)
            ->andWhere('menuItem.parent_id = :parentId')
            ->setParameter('parentId', $leaveMenuId)
            ->executeQuery()
            ->fetchOne();
        if ($menuExists === false) {
            $this->connection->createQueryBuilder()
                ->insert('ohrm_menu_item')
                ->values(
                    [
                        'menu_title' => ':menuTitle',
                        'screen_id' => ':screenId',
                        'parent_id' => ':parentId',
                        'level' => ':level',
                        'order_hint' => ':orderHint',
                        'status' => ':status',
                    ]
                )
                ->setParameter('menuTitle', self::MENU_TITLE)
                ->setParameter('screenId', $screenId)
                ->setParameter('parentId', $leaveMenuId)
                ->setParameter('level', 2)
                ->setParameter('orderHint', 800)
                ->setParameter('status', 1)
                ->executeStatement();
        }
    }

    /**
     * @param int|string $leaveModuleId
     * @return int|string|false
     */
    private function findScreenId($leaveModuleId)
    {
        return $this->connection->createQueryBuilder()
            ->select('screen.id')
            ->from('ohrm_screen', 'screen')
            ->where('screen.module_id = :moduleId')
            ->setParameter('moduleId', $leaveModuleId)
            ->andWhere('screen.action_url = :url')
            ->setParameter('url', self::SCREEN_URL)
            ->executeQuery()
            ->fetchOne();
    }
}
