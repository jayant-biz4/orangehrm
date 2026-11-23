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

namespace OrangeHRM\Installer\Migration\Fork\ClientCredentials;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Types\Types;
use OrangeHRM\Installer\Util\V1\SchemaHelper;

/**
 * Fork patch (client credentials grant): schema changes, each piece checked on its own, so it is
 * safe to run again after a partial failure (MySQL DDL is not transactional). Used by the
 * installer migration and by `bin/console orangehrm:oauth:client-credentials-schema`.
 */
class SchemaUpdate
{
    public const CLIENT_COLUMN = 'client_credentials_user_id';
    public const INDEX = 'oauth2_client_client_credentials_user_id';
    public const FOREIGN_KEY = 'oauth2_client_client_credentials_user_id';
    public const TOKEN_COLUMN = 'is_client_credentials';

    private Connection $connection;
    private SchemaHelper $schemaHelper;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->schemaHelper = new SchemaHelper($connection);
    }

    public function apply(): void
    {
        if (!$this->schemaHelper->columnExists('ohrm_oauth2_client', self::CLIENT_COLUMN)) {
            $this->schemaHelper->addColumn(
                'ohrm_oauth2_client',
                self::CLIENT_COLUMN,
                Types::INTEGER,
                ['Notnull' => false, 'Default' => null]
            );
        }

        $table = $this->connection->createSchemaManager()->introspectTable('ohrm_oauth2_client');
        $indexed = false;
        foreach ($table->getIndexes() as $index) {
            $indexed = $indexed || $index->getColumns() === [self::CLIENT_COLUMN];
        }
        if (!$indexed) {
            $this->connection->createSchemaManager()->createIndex(
                new Index(self::INDEX, [self::CLIENT_COLUMN]),
                'ohrm_oauth2_client'
            );
        }

        if (!$table->hasForeignKey(self::FOREIGN_KEY)) {
            $this->schemaHelper->addForeignKey(
                'ohrm_oauth2_client',
                new ForeignKeyConstraint(
                    [self::CLIENT_COLUMN],
                    'ohrm_user',
                    ['id'],
                    self::FOREIGN_KEY,
                    ['onDelete' => 'SET NULL']
                )
            );
        }

        if (!$this->schemaHelper->columnExists('ohrm_oauth2_access_token', self::TOKEN_COLUMN)) {
            $this->schemaHelper->addColumn(
                'ohrm_oauth2_access_token',
                self::TOKEN_COLUMN,
                Types::BOOLEAN,
                ['Notnull' => true, 'Default' => false]
            );
        }
    }
}
