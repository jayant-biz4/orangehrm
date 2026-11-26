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

namespace OrangeHRM\Tests\Leave\Entity;

use DateTime;
use OrangeHRM\Config\Config;
use OrangeHRM\Entity\Holiday;
use OrangeHRM\Tests\Util\EntityTestCase;
use OrangeHRM\Tests\Util\TestDataService;

/**
 * @group Leave
 * @group Entity
 */
class HolidayTest extends EntityTestCase
{
    protected function setUp(): void
    {
        TestDataService::truncateSpecificTables([Holiday::class]);
        TestDataService::populate(Config::get(Config::PLUGINS_DIR) . '/orangehrmLeavePlugin/test/fixtures/HolidayEntity.yml');
    }

    private function createHoliday(?int $locationId): Holiday
    {
        $holiday = new Holiday();
        $holiday->setName('Founders Day');
        $holiday->setDate(new DateTime('2026-03-01'));
        $holiday->setRecurring(false);
        $holiday->setLength(Holiday::HOLIDAY_FULL_DAY_LENGTH);
        $holiday->getDecorator()->setLocationById($locationId);
        $this->persist($holiday);
        $this->getEntityManager()->clear();
        return $this->getRepository(Holiday::class)->find($holiday->getId());
    }

    public function testOrgWideByDefault(): void
    {
        $holiday = new Holiday();
        $this->assertNull($holiday->getLocation());
        $this->assertTrue($holiday->getDecorator()->isOrgWide());
        $this->assertNull($holiday->getDecorator()->getLocationArray());
    }

    public function testLocationHolidayPersists(): void
    {
        $reloaded = $this->createHoliday(1);
        $this->assertEquals(1, $reloaded->getLocation()->getId());
        $this->assertFalse($reloaded->getDecorator()->isOrgWide());
        $this->assertEquals(
            ['id' => 1, 'name' => 'Colombo Office'],
            $reloaded->getDecorator()->getLocationArray()
        );
    }

    public function testSetLocationByIdNullMakesOrgWide(): void
    {
        $holiday = $this->createHoliday(1);
        $holiday->getDecorator()->setLocationById(null);
        $this->persist($holiday);
        $this->getEntityManager()->clear();
        $reloaded = $this->getRepository(Holiday::class)->find($holiday->getId());
        $this->assertNull($reloaded->getLocation());
        $this->assertTrue($reloaded->getDecorator()->isOrgWide());
    }
}
