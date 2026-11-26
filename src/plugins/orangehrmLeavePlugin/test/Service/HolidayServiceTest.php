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

namespace OrangeHRM\Tests\Leave\Service;

use DateTime;
use OrangeHRM\Config\Config;
use OrangeHRM\Entity\Holiday;
use OrangeHRM\Framework\Cache\FilesystemAdapter;
use OrangeHRM\Framework\Services;
use OrangeHRM\Leave\Dao\HolidayDao;
use OrangeHRM\Leave\Dto\HolidaySearchFilterParams;
use OrangeHRM\Leave\Service\HolidayService;
use OrangeHRM\ORM\Doctrine;
use OrangeHRM\Pim\Service\EmployeeService;
use OrangeHRM\Tests\Util\KernelTestCase;
use OrangeHRM\Tests\Util\TestDataService;

/**
 * @group Leave
 * @group Service
 */
class HolidayServiceTest extends KernelTestCase
{
    private HolidayService $holidayService;
    private $fixture;

    protected function setUp(): void
    {
        $this->fixture = Config::get(Config::PLUGINS_DIR) . '/orangehrmLeavePlugin/test/fixtures/HolidayService.yml';
        $this->holidayService = new HolidayService();
        $cache = new FilesystemAdapter();
        $cache->clear();
        $this->createKernelWithMockServices([Services::CACHE => $cache, Services::EMPLOYEE_SERVICE => new EmployeeService()]);
    }

    public function testGetSetHolidayDao(): void
    {
        $this->assertTrue($this->holidayService->getHolidayDao() instanceof HolidayDao);
    }

    public function testSaveHoliday(): void
    {
        $holidays = TestDataService::loadObjectList(Holiday::class, $this->fixture, 'Holiday');
        $holiday = $holidays[0];

        $holidayDao = $this->getMockBuilder(HolidayDao::class)
            ->onlyMethods(['saveHoliday'])
            ->getMock();
        $holidayDao->expects($this->once())
            ->method('saveHoliday')
            ->with($holiday)
            ->will($this->returnValue($holiday));

        $holidayService = $this->getMockBuilder(HolidayService::class)
            ->onlyMethods(['getHolidayDao'])
            ->getMock();
        $holidayService->expects($this->once())
            ->method('getHolidayDao')
            ->willReturn($holidayDao);

        $this->assertTrue($holidayService->saveHoliday($holiday) instanceof Holiday);
    }

    private function newHoliday(string $date, bool $recurring, ?int $locationId): Holiday
    {
        $holiday = new Holiday();
        $holiday->setName('Test');
        $holiday->setDate(new DateTime($date));
        $holiday->setRecurring($recurring);
        $holiday->setLength(Holiday::HOLIDAY_FULL_DAY_LENGTH);
        $holiday->getDecorator()->setLocationById($locationId);
        return $holiday;
    }

    public function testIsHolidayForEmployee(): void
    {
        TestDataService::populate($this->fixture);
        $this->assertTrue($this->holidayService->isHoliday(new DateTime('2021-08-20'), 1));
        $this->assertFalse($this->holidayService->isHoliday(new DateTime('2021-08-20'), 2));
        $this->assertTrue($this->holidayService->isHoliday(new DateTime('2022-01-26'), 2));
        $this->assertFalse($this->holidayService->isHoliday(new DateTime('2021-08-20'), 999));
    }

    public function testIsHolidayWithoutEmployeeKeepsGlobalBehaviour(): void
    {
        TestDataService::populate($this->fixture);
        $this->assertTrue($this->holidayService->isHoliday(new DateTime('2021-08-20'), null));
        $this->assertTrue($this->holidayService->isHoliday(new DateTime('2021-08-20')));
    }

    public function testValidateOrgWideClashesWithLocationHoliday(): void
    {
        TestDataService::populate($this->fixture);
        $this->assertSame(
            HolidayService::VALIDATION_CLASH,
            $this->holidayService->validateHoliday($this->newHoliday('2021-08-20', false, null))
        );
    }

    public function testValidateLocationClashesWithOrgWide(): void
    {
        TestDataService::populate($this->fixture);
        $this->assertSame(
            HolidayService::VALIDATION_CLASH,
            $this->holidayService->validateHoliday($this->newHoliday('2027-01-26', false, 3))
        );
    }

    public function testValidateRecurringLocationClashesWithDatedOrgWide(): void
    {
        TestDataService::populate($this->fixture);
        Doctrine::getEntityManager()->persist($this->newHoliday('2028-05-01', false, null));
        Doctrine::getEntityManager()->flush();
        $this->assertSame(
            HolidayService::VALIDATION_CLASH,
            $this->holidayService->validateHoliday($this->newHoliday('2020-05-01', true, 1))
        );
    }

    public function testValidateSameLocationClash(): void
    {
        TestDataService::populate($this->fixture);
        $this->assertSame(
            HolidayService::VALIDATION_CLASH,
            $this->holidayService->validateHoliday($this->newHoliday('2021-08-20', false, 1))
        );
    }

    public function testValidateAllLocationsRejected(): void
    {
        TestDataService::populate($this->fixture);
        $this->assertSame(
            HolidayService::VALIDATION_ALL_LOCATIONS,
            $this->holidayService->validateHoliday($this->newHoliday('2021-08-20', false, 3))
        );
    }

    public function testValidateTwoLocationsAllowed(): void
    {
        TestDataService::populate($this->fixture);
        Doctrine::getEntityManager()->remove(Doctrine::getEntityManager()->getReference(Holiday::class, 7));
        Doctrine::getEntityManager()->flush();
        $this->assertNull($this->holidayService->validateHoliday($this->newHoliday('2021-08-20', false, 3)));
    }

    public function testValidateIgnoresItselfOnUpdate(): void
    {
        TestDataService::populate($this->fixture);
        $holiday = Doctrine::getEntityManager()->getRepository(Holiday::class)->find(6);
        $this->assertNull($this->holidayService->validateHoliday($holiday));
    }

    public function testCacheKeyDiffersByLocation(): void
    {
        TestDataService::populate($this->fixture);
        $search = function (int $locationId): array {
            $params = new HolidaySearchFilterParams();
            $params->setFromDate(new DateTime('2021-08-01'));
            $params->setToDate(new DateTime('2021-08-31'));
            $params->setLocationFilter(HolidaySearchFilterParams::LOCATION_FILTER_EFFECTIVE, $locationId);
            return array_map(fn (Holiday $h) => $h->getId(), $this->holidayService->searchHolidays($params));
        };
        $this->assertSame([6], $search(1));
        $this->assertSame([7], $search(2));
    }

    public function testSearchHolidaysNoneRecurring(): void
    {
        $fixture = [
            [
                'id' => 1,
                'recurring' => 0,
                'name' => 'Public Holiday',
                'date' => new DateTime('2009-03-22'),
                'length' => 4
            ],
            [
                'id' => 2,
                'recurring' => 0,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-05-22'),
                'length' => 8
            ],
            [
                'id' => 3,
                'recurring' => 0,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-05-27'),
                'length' => 4
            ],
            [
                'id' => 4,
                'recurring' => 0,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-06-27'),
                'length' => 8
            ]
        ];

        $holidays = TestDataService::loadObjectListFromArray(Holiday::class, $fixture);

        $holidayDao = $this->getMockBuilder(HolidayDao::class)
            ->onlyMethods(['searchHolidays'])
            ->getMock();
        $holidayDao->expects($this->once())
            ->method('searchHolidays')
            ->will($this->returnValue($holidays));

        $holidayService = $this->getMockBuilder(HolidayService::class)
            ->onlyMethods(['getHolidayDao'])
            ->getMock();
        $holidayService->expects($this->once())
            ->method('getHolidayDao')
            ->willReturn($holidayDao);

        $holidaySearchFilterParams = new HolidaySearchFilterParams();
        $holidaySearchFilterParams->setFromDate(new DateTime("2009-01-01"));
        $holidaySearchFilterParams->setToDate(new DateTime("2010-12-31"));
        $result = $holidayService->searchHolidays($holidaySearchFilterParams);

        $this->compareHolidays($holidays, $result);
    }

    public function testSearchHolidaysRecurringCurrentYear(): void
    {
        $fixture = [
            [
                'id' => 1,
                'recurring' => 0,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-03-22'),
                'length' => 4
            ],
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-04-01'),
                'length' => 8
            ],
            [
                'id' => 3,
                'recurring' => 0,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-05-27'),
                'length' => 4
            ],
            [
                'id' => 4,
                'recurring' => 0,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-06-27'),
                'length' => 8
            ]
        ];

        $holidays = TestDataService::loadObjectListFromArray(Holiday::class, $fixture);

        $holidayDao = $this->getMockBuilder(HolidayDao::class)
            ->onlyMethods(['searchHolidays'])
            ->getMock();
        $holidayDao->expects($this->once())
            ->method('searchHolidays')
            ->will($this->returnValue($holidays));

        $holidayService = $this->getMockBuilder(HolidayService::class)
            ->onlyMethods(['getHolidayDao'])
            ->getMock();
        $holidayService->expects($this->once())
            ->method('getHolidayDao')
            ->willReturn($holidayDao);

        $holidaySearchFilterParams = new HolidaySearchFilterParams();
        $holidaySearchFilterParams->setFromDate(new DateTime("2010-01-01"));
        $holidaySearchFilterParams->setToDate(new DateTime("2010-12-31"));
        $result = $holidayService->searchHolidays($holidaySearchFilterParams);

        $this->compareHolidays($holidays, $result);
    }

    public function testSearchHolidaysRecurringOtherYear(): void
    {
        $fixture = [
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2008-04-01'),
                'length' => 8
            ],
            [
                'id' => 3,
                'recurring' => 0,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-05-27'),
                'length' => 4
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-06-27'),
                'length' => 8
            ],
            [
                'id' => 5,
                'recurring' => 0,
                'name' => 'Another Holiday',
                'date' => new DateTime('2010-09-13'),
                'length' => 8
            ],
            [
                'id' => 7,
                'recurring' => 1,
                'name' => 'Another Holiday',
                'date' => new DateTime('2012-09-13'),
                'length' => 8
            ]
        ];

        $holidays = TestDataService::loadObjectListFromArray(Holiday::class, $fixture);

        $holidayDao = $this->getMockBuilder(HolidayDao::class)
            ->onlyMethods(['searchHolidays'])
            ->getMock();
        $holidayDao->expects($this->once())
            ->method('searchHolidays')
            ->will($this->returnValue($holidays));

        $holidayService = $this->getMockBuilder(HolidayService::class)
            ->onlyMethods(['getHolidayDao'])
            ->getMock();
        $holidayService->expects($this->once())
            ->method('getHolidayDao')
            ->willReturn($holidayDao);

        $holidaySearchFilterParams = new HolidaySearchFilterParams();
        $holidaySearchFilterParams->setFromDate(new DateTime("2010-01-01"));
        $holidaySearchFilterParams->setToDate(new DateTime("2010-12-31"));
        $result = $holidayService->searchHolidays($holidaySearchFilterParams);

        $expectedArray = [
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-04-01'),
                'length' => 8
            ],
            [
                'id' => 3,
                'recurring' => 0,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-05-27'),
                'length' => 4
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-06-27'),
                'length' => 8
            ],
            [
                'id' => 5,
                'recurring' => 0,
                'name' => 'Another Holiday',
                'date' => new DateTime('2010-09-13'),
                'length' => 8
            ],
            [
                'id' => 7,
                'recurring' => 1,
                'name' => 'Another Holiday',
                'date' => new DateTime('2010-09-13'),
                'length' => 8
            ]
        ];
        $expected = TestDataService::loadObjectListFromArray(Holiday::class, $expectedArray);

        $this->compareHolidays($expected, $result);
    }

    public function testSearchHolidaysRecurringMultiYear(): void
    {
        $fixture = [
            [
                'id' => 1,
                'recurring' => 0,
                'name' => 'Public Holiday',
                'date' => new DateTime('2008-03-22'),
                'length' => 4
            ],
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2008-04-01'),
                'length' => 8
            ],
            [
                'id' => 3,
                'recurring' => 0,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-05-27'),
                'length' => 4
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-06-27'),
                'length' => 8
            ],
            [
                'id' => 5,
                'recurring' => 0,
                'name' => 'Another Holiday',
                'date' => new DateTime('2010-09-13'),
                'length' => 8
            ],
            [
                'id' => 6,
                'recurring' => 0,
                'name' => 'Another Holiday',
                'date' => new DateTime('2011-01-13'),
                'length' => 8
            ],
            [
                'id' => 7,
                'recurring' => 1,
                'name' => 'Another Holiday',
                'date' => new DateTime('2012-09-13'),
                'length' => 8
            ]
        ];

        $holidays = TestDataService::loadObjectListFromArray(Holiday::class, $fixture);

        $holidayDao = $this->getMockBuilder(HolidayDao::class)
            ->onlyMethods(['searchHolidays'])
            ->getMock();
        $holidayDao->expects($this->once())
            ->method('searchHolidays')
            ->will($this->returnValue($holidays));

        $holidayService = $this->getMockBuilder(HolidayService::class)
            ->onlyMethods(['getHolidayDao'])
            ->getMock();
        $holidayService->expects($this->once())
            ->method('getHolidayDao')
            ->willReturn($holidayDao);

        $holidaySearchFilterParams = new HolidaySearchFilterParams();
        $holidaySearchFilterParams->setFromDate(new DateTime("2005-01-01"));
        $holidaySearchFilterParams->setToDate(new DateTime("2013-07-01"));
        $result = $holidayService->searchHolidays($holidaySearchFilterParams);

        $expectedArray = [
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2005-04-01'),
                'length' => 8
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2005-06-27'),
                'length' => 8
            ],
            [
                'id' => 7,
                'recurring' => 1,
                'name' => 'Another Holiday',
                'date' => new DateTime('2005-09-13'),
                'length' => 8
            ],
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2006-04-01'),
                'length' => 8
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2006-06-27'),
                'length' => 8
            ],
            [
                'id' => 7,
                'recurring' => 1,
                'name' => 'Another Holiday',
                'date' => new DateTime('2006-09-13'),
                'length' => 8
            ],
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2007-04-01'),
                'length' => 8
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2007-06-27'),
                'length' => 8
            ],
            [
                'id' => 7,
                'recurring' => 1,
                'name' => 'Another Holiday',
                'date' => new DateTime('2007-09-13'),
                'length' => 8
            ],
            [
                'id' => 1,
                'recurring' => 0,
                'name' => 'Public Holiday',
                'date' => new DateTime('2008-03-22'),
                'length' => 4
            ],
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2008-04-01'),
                'length' => 8
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2008-06-27'),
                'length' => 8
            ],
            [
                'id' => 7,
                'recurring' => 1,
                'name' => 'Another Holiday',
                'date' => new DateTime('2008-09-13'),
                'length' => 8
            ],
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2009-04-01'),
                'length' => 8
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2009-06-27'),
                'length' => 8
            ],
            [
                'id' => 7,
                'recurring' => 1,
                'name' => 'Another Holiday',
                'date' => new DateTime('2009-09-13'),
                'length' => 8
            ],
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-04-01'),
                'length' => 8
            ],
            [
                'id' => 3,
                'recurring' => 0,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-05-27'),
                'length' => 4
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-06-27'),
                'length' => 8
            ],
            [
                'id' => 5,
                'recurring' => 0,
                'name' => 'Another Holiday',
                'date' => new DateTime('2010-09-13'),
                'length' => 8
            ],
            [
                'id' => 7,
                'recurring' => 1,
                'name' => 'Another Holiday',
                'date' => new DateTime('2010-09-13'),
                'length' => 8
            ],
            [
                'id' => 6,
                'recurring' => 0,
                'name' => 'Another Holiday',
                'date' => new DateTime('2011-01-13'),
                'length' => 8
            ],
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2011-04-01'),
                'length' => 8
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2011-06-27'),
                'length' => 8
            ],
            [
                'id' => 7,
                'recurring' => 1,
                'name' => 'Another Holiday',
                'date' => new DateTime('2011-09-13'),
                'length' => 8
            ],
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2012-04-01'),
                'length' => 8
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2012-06-27'),
                'length' => 8
            ],
            [
                'id' => 7,
                'recurring' => 1,
                'name' => 'Another Holiday',
                'date' => new DateTime('2012-09-13'),
                'length' => 8
            ],
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2013-04-01'),
                'length' => 8
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2013-06-27'),
                'length' => 8
            ]
        ];

        $expected = TestDataService::loadObjectListFromArray(Holiday::class, $expectedArray);
        $this->compareHolidays($expected, $result);
    }

    public function testSearchHolidaysRecurringPartYear(): void
    {
        $fixture = [
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2008-04-01'),
                'length' => 8
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-06-27'),
                'length' => 8
            ],
            [
                'id' => 5,
                'recurring' => 0,
                'name' => 'Another Holiday',
                'date' => new DateTime('2010-09-13'),
                'length' => 8
            ],
            [
                'id' => 7,
                'recurring' => 1,
                'name' => 'Another Holiday',
                'date' => new DateTime('2012-09-13'),
                'length' => 8
            ]
        ];

        $holidays = TestDataService::loadObjectListFromArray(Holiday::class, $fixture);

        $holidayDao = $this->getMockBuilder(HolidayDao::class)
            ->onlyMethods(['searchHolidays'])
            ->getMock();
        $holidayDao->expects($this->once())
            ->method('searchHolidays')
            ->will($this->returnValue($holidays));

        $holidayService = $this->getMockBuilder(HolidayService::class)
            ->onlyMethods(['getHolidayDao'])
            ->getMock();
        $holidayService->expects($this->once())
            ->method('getHolidayDao')
            ->willReturn($holidayDao);

        $holidaySearchFilterParams = new HolidaySearchFilterParams();
        $holidaySearchFilterParams->setFromDate(new DateTime("2010-06-11"));
        $holidaySearchFilterParams->setToDate(new DateTime("2010-11-02"));
        $result = $holidayService->searchHolidays($holidaySearchFilterParams);

        $expectedArray = [
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-06-27'),
                'length' => 8
            ],
            [
                'id' => 5,
                'recurring' => 0,
                'name' => 'Another Holiday',
                'date' => new DateTime('2010-09-13'),
                'length' => 8
            ],
            [
                'id' => 7,
                'recurring' => 1,
                'name' => 'Another Holiday',
                'date' => new DateTime('2010-09-13'),
                'length' => 8
            ]
        ];

        $expected = TestDataService::loadObjectListFromArray(Holiday::class, $expectedArray);
        $this->compareHolidays($expected, $result);
    }

    protected function compareHolidays($expected, $actual): void
    {
        $this->assertEquals(count($expected), count($actual));
        for ($i = 0; $i < count($expected); $i++) {
            $this->compareHoliday($expected[$i], $actual[$i]);
        }
    }

    protected function compareHoliday(Holiday $expected, Holiday $actual): void
    {
        $this->assertEquals($expected->isRecurring(), $actual->isRecurring());
        $this->assertEquals($expected->getName(), $actual->getName());
        $this->assertEquals($expected->getDate(), $actual->getDate());
        $this->assertEquals($expected->getLength(), $actual->getLength());
    }

    public function testSearchHolidaysCheckUniqueness()
    {
        $fixture = [
            [
                'id' => 1,
                'recurring' => 0,
                'name' => 'Public Holiday',
                'date' => new DateTime('2008-03-22'),
                'length' => 4
            ],
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2008-04-01'),
                'length' => 8
            ],
            [
                'id' => 3,
                'recurring' => 0,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-05-27'),
                'length' => 4
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-06-27'),
                'length' => 8
            ],
            [
                'id' => 5,
                'recurring' => 0,
                'name' => 'Another Holiday',
                'date' => new DateTime('2010-09-13'),
                'length' => 8
            ],
            [
                'id' => 6,
                'recurring' => 0,
                'name' => 'Another Holiday',
                'date' => new DateTime('2011-01-13'),
                'length' => 8
            ],
            [
                'id' => 7,
                'recurring' => 1,
                'name' => 'Another Holiday',
                'date' => new DateTime('2012-09-13'),
                'length' => 8
            ]
        ];

        $holidays = TestDataService::loadObjectListFromArray(Holiday::class, $fixture);

        $holidayDao = $this->getMockBuilder(HolidayDao::class)
            ->onlyMethods(['searchHolidays'])
            ->getMock();
        $holidayDao->expects($this->once())
            ->method('searchHolidays')
            ->will($this->returnValue($holidays));

        $holidayService = $this->getMockBuilder(HolidayService::class)
            ->onlyMethods(['getHolidayDao'])
            ->getMock();
        $holidayService->expects($this->once())
            ->method('getHolidayDao')
            ->willReturn($holidayDao);

        $expectedArray = [
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2005-04-01'),
                'length' => 8
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2005-06-27'),
                'length' => 8
            ],
            [
                'id' => 7,
                'recurring' => 1,
                'name' => 'Another Holiday',
                'date' => new DateTime('2005-09-13'),
                'length' => 8
            ],
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2006-04-01'),
                'length' => 8
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2006-06-27'),
                'length' => 8
            ],
            [
                'id' => 7,
                'recurring' => 1,
                'name' => 'Another Holiday',
                'date' => new DateTime('2006-09-13'),
                'length' => 8
            ],
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2007-04-01'),
                'length' => 8
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2007-06-27'),
                'length' => 8
            ],
            [
                'id' => 7,
                'recurring' => 1,
                'name' => 'Another Holiday',
                'date' => new DateTime('2007-09-13'),
                'length' => 8
            ],
            [
                'id' => 1,
                'recurring' => 0,
                'name' => 'Public Holiday',
                'date' => new DateTime('2008-03-22'),
                'length' => 4
            ],
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2008-04-01'),
                'length' => 8
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2008-06-27'),
                'length' => 8
            ],
            [
                'id' => 7,
                'recurring' => 1,
                'name' => 'Another Holiday',
                'date' => new DateTime('2008-09-13'),
                'length' => 8
            ],
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2009-04-01'),
                'length' => 8
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2009-06-27'),
                'length' => 8
            ],
            [
                'id' => 7,
                'recurring' => 1,
                'name' => 'Another Holiday',
                'date' => new DateTime('2009-09-13'),
                'length' => 8
            ],
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-04-01'),
                'length' => 8
            ],
            [
                'id' => 3,
                'recurring' => 0,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-05-27'),
                'length' => 4
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2010-06-27'),
                'length' => 8
            ],
            [
                'id' => 5,
                'recurring' => 0,
                'name' => 'Another Holiday',
                'date' => new DateTime('2010-09-13'),
                'length' => 8
            ],
            [
                'id' => 7,
                'recurring' => 1,
                'name' => 'Another Holiday',
                'date' => new DateTime('2010-09-13'),
                'length' => 8
            ],
            [
                'id' => 6,
                'recurring' => 0,
                'name' => 'Another Holiday',
                'date' => new DateTime('2011-01-13'),
                'length' => 8
            ],
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2011-04-01'),
                'length' => 8
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2011-06-27'),
                'length' => 8
            ],
            [
                'id' => 7,
                'recurring' => 1,
                'name' => 'Another Holiday',
                'date' => new DateTime('2011-09-13'),
                'length' => 8
            ],
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2012-04-01'),
                'length' => 8
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2012-06-27'),
                'length' => 8
            ],
            [
                'id' => 7,
                'recurring' => 1,
                'name' => 'Another Holiday',
                'date' => new DateTime('2012-09-13'),
                'length' => 8
            ],
            [
                'id' => 2,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2013-04-01'),
                'length' => 8
            ],
            [
                'id' => 4,
                'recurring' => 1,
                'name' => 'Public Holiday',
                'date' => new DateTime('2013-06-27'),
                'length' => 8
            ]
        ];

        // check offset = 0, limit = 5
        $holidaySearchFilterParams = new HolidaySearchFilterParams();
        $holidaySearchFilterParams->setFromDate(new DateTime("2005-01-01"));
        $holidaySearchFilterParams->setToDate(new DateTime("2013-07-01"));
        $holidaySearchFilterParams->setLimit(5);
        $holidaySearchFilterParams->setOffset(0);
        $result = $holidayService->searchHolidays($holidaySearchFilterParams);

        $expected = TestDataService::loadObjectListFromArray(Holiday::class, array_slice($expectedArray, 0, 5));
        $this->compareHolidays($expected, $result);

        // check offset = 1, limit = 5
        $holidaySearchFilterParams = new HolidaySearchFilterParams();
        $holidaySearchFilterParams->setFromDate(new DateTime("2005-01-01"));
        $holidaySearchFilterParams->setToDate(new DateTime("2013-07-01"));
        $holidaySearchFilterParams->setLimit(5);
        $holidaySearchFilterParams->setOffset(1);
        $result = $holidayService->searchHolidays($holidaySearchFilterParams);

        $expected = TestDataService::loadObjectListFromArray(Holiday::class, array_slice($expectedArray, 1, 5));
        $this->compareHolidays($expected, $result);

        // check offset = 3, limit = 5
        $holidaySearchFilterParams = new HolidaySearchFilterParams();
        $holidaySearchFilterParams->setFromDate(new DateTime("2005-01-01"));
        $holidaySearchFilterParams->setToDate(new DateTime("2013-07-01"));
        $holidaySearchFilterParams->setLimit(5);
        $holidaySearchFilterParams->setOffset(3);
        $result = $holidayService->searchHolidays($holidaySearchFilterParams);

        $expected = TestDataService::loadObjectListFromArray(Holiday::class, array_slice($expectedArray, 3, 5));
        $this->compareHolidays($expected, $result);

        // check uniqueness (limit = 0)
        $holidaySearchFilterParams = new HolidaySearchFilterParams();
        $holidaySearchFilterParams->setFromDate(new DateTime("2005-01-01"));
        $holidaySearchFilterParams->setToDate(new DateTime("2013-07-01"));
        $holidaySearchFilterParams->setLimit(0);
        $result = $holidayService->searchHolidays($holidaySearchFilterParams);

        $expected = TestDataService::loadObjectListFromArray(Holiday::class, $expectedArray);
        $this->compareHolidays($expected, $result);
    }
}
