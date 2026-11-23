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

namespace OrangeHRM\Tests\OAuth\Migration;

use Doctrine\DBAL\Connection;
use OrangeHRM\Installer\Migration\Fork\ClientCredentials\SchemaUpdate;
use OrangeHRM\Tests\Util\KernelTestCase;

/**
 * Fork patch (client credentials grant): the schema step is idempotent piece by piece
 *
 * @group OAuth
 * @group Migration
 */
class ClientCredentialsSchemaTest extends KernelTestCase
{
    private function connection(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }

    private function apply(): void
    {
        (new SchemaUpdate($this->connection()))->apply();
    }

    private function assertSchemaComplete(): void
    {
        $client = $this->connection()->createSchemaManager()->introspectTable('ohrm_oauth2_client');
        $this->assertTrue($client->hasColumn(SchemaUpdate::CLIENT_COLUMN));
        $this->assertTrue($client->hasForeignKey(SchemaUpdate::FOREIGN_KEY));
        $indexed = false;
        foreach ($client->getIndexes() as $index) {
            $indexed = $indexed || $index->getColumns() === [SchemaUpdate::CLIENT_COLUMN];
        }
        $this->assertTrue($indexed, 'client_credentials_user_id is not indexed');
        $token = $this->connection()->createSchemaManager()->introspectTable('ohrm_oauth2_access_token');
        $this->assertTrue($token->hasColumn(SchemaUpdate::TOKEN_COLUMN));
    }

    private function drop(bool $foreignKey, bool $index = false, bool $columns = false): void
    {
        $c = $this->connection();
        $foreignKey && $c->executeStatement(
            'ALTER TABLE ohrm_oauth2_client DROP FOREIGN KEY ' . SchemaUpdate::FOREIGN_KEY
        );
        $index && $c->executeStatement('ALTER TABLE ohrm_oauth2_client DROP INDEX ' . SchemaUpdate::INDEX);
        if ($columns) {
            $c->executeStatement('ALTER TABLE ohrm_oauth2_client DROP COLUMN ' . SchemaUpdate::CLIENT_COLUMN);
            $c->executeStatement('ALTER TABLE ohrm_oauth2_access_token DROP COLUMN ' . SchemaUpdate::TOKEN_COLUMN);
        }
    }

    public function testRunTwiceOnCompleteSchema(): void
    {
        $this->apply();
        $this->apply();
        $this->assertSchemaComplete();
    }

    public function testAddsMissingForeignKeyOnly(): void
    {
        $this->drop(true);
        $this->apply();
        $this->assertSchemaComplete();
    }

    public function testAddsMissingIndexAndForeignKey(): void
    {
        $this->drop(true, true);
        $this->apply();
        $this->assertSchemaComplete();
    }

    public function testFromScratchTwice(): void
    {
        $this->drop(true, true, true);
        $this->apply();
        $this->apply();
        $this->assertSchemaComplete();
    }
}
