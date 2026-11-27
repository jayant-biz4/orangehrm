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

namespace OrangeHRM\Tests\Leave\Api;

use DateTime;
use OrangeHRM\Config\Config;
use OrangeHRM\Core\Api\CommonParams;
use OrangeHRM\Core\Api\V2\RequestParams;
use OrangeHRM\Core\Service\DateTimeHelperService;
use OrangeHRM\Entity\Holiday;
use OrangeHRM\Framework\Services;
use OrangeHRM\Leave\Api\HolidayAPI;
use OrangeHRM\Leave\Dao\HolidayDao;
use OrangeHRM\Leave\Dto\HolidaySearchFilterParams;
use OrangeHRM\Leave\Service\HolidayService;
use OrangeHRM\Tests\Util\EndpointTestCase;
use OrangeHRM\Tests\Util\MockObject;
use OrangeHRM\Tests\Util\TestDataService;

/**
 * @group Leave
 * @group APIv2
 */
class HolidayAPITest extends EndpointTestCase
{
    protected function loadFixtures(): void
    {
        $this->fixture = Config::get(
            Config::PLUGINS_DIR
        ) . '/orangehrmLeavePlugin/test/fixtures/HolidayDao.yml';
        TestDataService::populate($this->fixture);
    }

    public function testGetOne(): void
    {
        $holidayDao = $this->getMockBuilder(HolidayDao::class)
            ->onlyMethods(['getHolidayById'])
            ->getMock();

        $holiday = new Holiday();
        $holiday->setId(1);
        $holiday->setRecurring(true);
        $holiday->setName('Christmas');
        $holiday->setDate(new DateTime('2021-12-25'));
        $holiday->setLength(0);

        $holidayDao->expects($this->exactly(1))
            ->method('getHolidayById')
            ->with(1)
            ->will($this->returnValue($holiday));

        $holidayService = $this->getMockBuilder(HolidayService::class)
            ->onlyMethods(['getHolidayDao'])
            ->getMock();

        $holidayService->expects($this->exactly(1))
            ->method('getHolidayDao')
            ->willReturn($holidayDao);

        $this->createKernelWithMockServices(
            [
                Services::DATETIME_HELPER_SERVICE => new DateTimeHelperService(),
            ]
        );

        /** @var MockObject&HolidayAPI $api */
        $api = $this->getApiEndpointMockBuilder(
            HolidayAPI::class,
            [
                RequestParams::PARAM_TYPE_ATTRIBUTE => [
                    CommonParams::PARAMETER_ID => 1
                ]
            ]
        )->onlyMethods(['getHolidayService'])
            ->getMock();
        $api->expects($this->once())
            ->method('getHolidayService')
            ->will($this->returnValue($holidayService));

        $result = $api->getOne();
        $this->assertEquals(
            [
                "id" => 1,
                "name" => "Christmas",
                "date" => "2021-12-25",
                "recurring" => true,
                "length" => 0,
                "lengthName" => "Full Day",
                "location" => null
            ],
            $result->normalize()
        );
    }

    public function testGetValidationRuleForGetOne(): void
    {
        $api = new HolidayAPI($this->getRequest());
        $rules = $api->getValidationRuleForGetOne();
        $this->assertTrue(
            $this->validate(
                [CommonParams::PARAMETER_ID => 1],
                $rules
            )
        );
    }

    public function testUpdate()
    {
        $holidayDao = $this->getMockBuilder(HolidayDao::class)
            ->onlyMethods(['saveHoliday', 'getHolidayById'])
            ->getMock();

        $holiday = new Holiday();
        $holiday->setId(1);
        $holiday->setRecurring(true);
        $holiday->setName('Christmas');
        $holiday->setDate(new DateTime('2021-12-25'));
        $holiday->setLength(0);

        $holidayDao->expects($this->exactly(1))
            ->method('getHolidayById')
            ->with(1)
            ->willReturn($holiday);

        $holidayDao->expects($this->exactly(1))
            ->method('saveHoliday')
            ->will(
                $this->returnCallback(
                    function (Holiday $holiday) {
                        return $holiday;
                    }
                )
            );

        $holidayService = $this->getMockBuilder(HolidayService::class)
            ->onlyMethods(['getHolidayDao', 'getCache', 'validateHoliday'])
            ->getMock();

        $holidayService->expects($this->exactly(1))
            ->method('getCache');

        $holidayService->method('validateHoliday')->willReturn(null);

        $holidayService->expects($this->exactly(2))
            ->method('getHolidayDao')
            ->willReturn($holidayDao);

        $this->createKernelWithMockServices(
            [
                Services::DATETIME_HELPER_SERVICE => new DateTimeHelperService(),
            ]
        );

        /** @var MockObject&HolidayAPI $api */
        $api = $this->getApiEndpointMockBuilder(
            HolidayAPI::class,
            [
                RequestParams::PARAM_TYPE_ATTRIBUTE => [
                    CommonParams::PARAMETER_ID => 1
                ],
                RequestParams::PARAM_TYPE_BODY => [
                    HolidayAPI::PARAMETER_NAME => 'Christmas',
                    HolidayAPI::PARAMETER_DATE => '2021-12-25',
                    HolidayAPI::PARAMETER_LENGTH => 0,
                    HolidayAPI::PARAMETER_RECURRING => true,
                ]
            ]
        )->onlyMethods(['getHolidayService'])
            ->getMock();
        $api->expects($this->exactly(3))
            ->method('getHolidayService')
            ->will($this->returnValue($holidayService));

        $result = $api->update();
        $this->assertEquals(
            [
                "id" => 1,
                "name" => "Christmas",
                "date" => "2021-12-25",
                "recurring" => true,
                "length" => 0,
                "lengthName" => "Full Day",
                "location" => null
            ],
            $result->normalize()
        );
    }

    public function testGetValidationRuleForUpdate(): void
    {
        $api = new HolidayAPI($this->getRequest());
        $rules = $api->getValidationRuleForUpdate();
        $this->assertTrue(
            $this->validate(
                [
                    CommonParams::PARAMETER_ID => 1,
                    HolidayAPI::PARAMETER_NAME => 'Christmas',
                    HolidayAPI::PARAMETER_DATE => '2021-12-25',
                    HolidayAPI::PARAMETER_LENGTH => 0,
                    HolidayAPI::PARAMETER_RECURRING => true,
                ],
                $rules
            )
        );
    }

    public function testDelete()
    {
        $holidayDao = $this->getMockBuilder(HolidayDao::class)
            ->onlyMethods(['deleteHolidays'])
            ->getMock();

        $holiday = new Holiday();
        $holiday->setId(1);
        $holiday->setRecurring(true);
        $holiday->setName('Christmas');
        $holiday->setDate(new DateTime('2021-12-25'));
        $holiday->setLength(0);

        $holidayDao->expects($this->exactly(0))
            ->method('deleteHolidays')
            ->with([1])
            ->willReturn(1);

        $holidayService = $this->getMockBuilder(HolidayService::class)
            ->onlyMethods(['getHolidayDao'])
            ->getMock();

        $holidayService->expects($this->exactly(0))
            ->method('getHolidayDao')
            ->willReturn($holidayDao);

        /** @var MockObject&HolidayAPI $api */
        $api = $this->getApiEndpointMockBuilder(
            HolidayAPI::class,
            [
                RequestParams::PARAM_TYPE_ATTRIBUTE => [],
                RequestParams::PARAM_TYPE_BODY => [
                    CommonParams::PARAMETER_IDS => [1],
                ]
            ]
        )->onlyMethods(['getHolidayService'])
            ->getMock();

        $this->expectRecordNotFoundException();
        $result = $api->delete();
    }

    public function testGetValidationRuleForDelete(): void
    {
        $api = new HolidayAPI($this->getRequest());
        $rules = $api->getValidationRuleForDelete();
        $this->assertTrue(
            $this->validate(
                [
                    CommonParams::PARAMETER_IDS => [1],
                ],
                $rules
            )
        );
    }

    public function testCreate()
    {
        $this->loadFixtures();

        $holidayDao = $this->getMockBuilder(HolidayDao::class)
            ->onlyMethods(['saveHoliday', 'getHolidayById'])
            ->getMock();

        $holiday = new Holiday();
        $holiday->setId(1);
        $holiday->setRecurring(true);
        $holiday->setName('Christmas');
        $holiday->setDate(new DateTime('2021-12-25'));
        $holiday->setLength(0);

        $holidayDao->expects($this->never())
            ->method('getHolidayById')
            ->with(1, 1)
            ->willReturn($holiday);

        $holidayDao->expects($this->once())
            ->method('saveHoliday')
            ->will(
                $this->returnCallback(
                    function (Holiday $holiday) {
                        $holiday->setId(1);
                        return $holiday;
                    }
                )
            );

        $holidayService = $this->getMockBuilder(HolidayService::class)
            ->onlyMethods(['getHolidayDao', 'getCache', 'validateHoliday'])
            ->getMock();

        $holidayService->method('validateHoliday')->willReturn(null);

        $holidayService->expects($this->once())
            ->method('getHolidayDao')
            ->willReturn($holidayDao);

        $holidayService->expects($this->exactly(1))
            ->method('getCache');

        $this->createKernelWithMockServices(
            [
                Services::EMPLOYEE_SERVICE => $holidayService,
                Services::DATETIME_HELPER_SERVICE => new DateTimeHelperService(),
            ]
        );

        /** @var MockObject&HolidayAPI $api */
        $api = $this->getApiEndpointMockBuilder(
            HolidayAPI::class,
            [
                RequestParams::PARAM_TYPE_ATTRIBUTE => [],
                RequestParams::PARAM_TYPE_BODY => [
                    HolidayAPI::PARAMETER_NAME => 'Christmas',
                    HolidayAPI::PARAMETER_DATE => '2021-12-25',
                    HolidayAPI::PARAMETER_LENGTH => 0,
                    HolidayAPI::PARAMETER_RECURRING => true,
                ]
            ]
        )->onlyMethods(['getHolidayService'])
            ->getMock();
        $api->expects($this->exactly(2))
            ->method('getHolidayService')
            ->will($this->returnValue($holidayService));

        $result = $api->create();
        $this->assertEquals(
            [
                "id" => 1,
                "name" => "Christmas",
                "date" => "2021-12-25",
                "recurring" => true,
                "length" => 0,
                "lengthName" => "Full Day",
                "location" => null
            ],
            $result->normalize()
        );
    }

    public function testGetValidationRuleForCreate(): void
    {
        $api = new HolidayAPI($this->getRequest());
        $rules = $api->getValidationRuleForCreate();
        $this->assertTrue(
            $this->validate(
                [
                    HolidayAPI::PARAMETER_NAME => 'Christmas',
                    HolidayAPI::PARAMETER_DATE => '2021-12-25',
                    HolidayAPI::PARAMETER_LENGTH => 0,
                    HolidayAPI::PARAMETER_RECURRING => true,
                ],
                $rules
            )
        );
    }

    public function testGetAll()
    {
        $holidayService = $this->getMockBuilder(HolidayService::class)
            ->onlyMethods(['searchHolidays', 'searchHolidaysCount'])
            ->getMock();

        $holiday1 = new Holiday();
        $holiday1->setId(1);
        $holiday1->setRecurring(true);
        $holiday1->setName('Christmas');
        $holiday1->setDate(new DateTime('2021-12-25'));
        $holiday1->setLength(0);

        $holiday2 = new Holiday();
        $holiday2->setId(2);
        $holiday2->setRecurring(false);
        $holiday2->setName('Special');
        $holiday2->setDate(new DateTime('2021-08-21'));
        $holiday2->setLength(4);

        $holidayService->expects($this->exactly(1))
            ->method('searchHolidays')
            ->willReturn([$holiday1, $holiday2]);

        $holidayService->expects($this->exactly(1))
            ->method('searchHolidaysCount')
            ->willReturn(2);


        /** @var MockObject&HolidayAPI $api */
        $api = $this->getApiEndpointMockBuilder(
            HolidayAPI::class,
            [
                RequestParams::PARAM_TYPE_QUERY => [
                    HolidayAPI::FILTER_FROM_DATE => '2021-01-01',
                    HolidayAPI::FILTER_TO_DATE => '2021-12-31',
                ]
            ]
        )->onlyMethods(['getHolidayService'])
            ->getMock();
        $api->expects($this->exactly(2))
            ->method('getHolidayService')
            ->will($this->returnValue($holidayService));

        $this->createKernelWithMockServices(
            [
                Services::DATETIME_HELPER_SERVICE => new DateTimeHelperService(),
            ]
        );
        $result = $api->getAll();
        $this->assertEquals(
            [
                [
                    "id" => 1,
                    "name" => "Christmas",
                    "length" => 0,
                    "recurring" => true,
                    "date" => "2021-12-25",
                    'lengthName' => 'Full Day',
                    'location' => null
                ],
                [
                    "id" => 2,
                    "name" => "Special",
                    "length" => 4,
                    "recurring" => false,
                    "date" => "2021-08-21",
                    'lengthName' => 'Half Day',
                    'location' => null
                ]
            ],
            $result->normalize()
        );
    }

    private function createLocationServiceMock(?string $validation, array $methods = [])
    {
        $holidayDao = $this->getMockBuilder(HolidayDao::class)
            ->onlyMethods(['saveHoliday', 'getHolidayById'])
            ->getMock();
        $holidayDao->method('saveHoliday')->willReturnCallback(
            function (Holiday $holiday) {
                $holiday->setId(1);
                return $holiday;
            }
        );
        $holiday = new Holiday();
        $holiday->setId(1);
        $holiday->setRecurring(false);
        $holiday->setName('Colombo Day');
        $holiday->setDate(new DateTime('2021-09-10'));
        $holiday->setLength(0);
        $holiday->getDecorator()->setLocationById(1);
        $holidayDao->method('getHolidayById')->willReturn($holiday);

        $holidayService = $this->getMockBuilder(HolidayService::class)
            ->onlyMethods(array_merge(['getHolidayDao', 'getCache', 'validateHoliday'], $methods))
            ->getMock();
        $holidayService->method('getHolidayDao')->willReturn($holidayDao);
        $holidayService->method('validateHoliday')->willReturn($validation);
        return $holidayService;
    }

    private function getLocationApi(array $body, $service, array $query = [], array $extra = [])
    {
        $this->createKernelWithMockServices([Services::DATETIME_HELPER_SERVICE => new DateTimeHelperService()]);
        $api = $this->getApiEndpointMockBuilder(
            HolidayAPI::class,
            [
                RequestParams::PARAM_TYPE_ATTRIBUTE => [CommonParams::PARAMETER_ID => 1],
                RequestParams::PARAM_TYPE_BODY => $body,
                RequestParams::PARAM_TYPE_QUERY => array_merge(
                    [HolidayAPI::FILTER_FROM_DATE => '2021-01-01', HolidayAPI::FILTER_TO_DATE => '2021-12-31'],
                    $query
                ),
            ]
        )->onlyMethods(array_merge(['getHolidayService'], array_keys($extra)))
            ->getMock();
        $api->method('getHolidayService')->willReturn($service);
        foreach ($extra as $method => $value) {
            $api->method($method)->willReturn($value);
        }
        return $api;
    }

    private function baseBody(array $more = []): array
    {
        return array_merge(
            [
                HolidayAPI::PARAMETER_NAME => 'Colombo Day',
                HolidayAPI::PARAMETER_DATE => '2021-09-10',
                HolidayAPI::PARAMETER_LENGTH => 0,
                HolidayAPI::PARAMETER_RECURRING => false,
            ],
            $more
        );
    }

    public function testCreateLocationHoliday(): void
    {
        $this->loadFixtures();
        $api = $this->getLocationApi(
            $this->baseBody([HolidayAPI::PARAMETER_LOCATION_ID => 1]),
            $this->createLocationServiceMock(null)
        );
        $result = $api->create()->normalize();
        $this->assertEquals(['id' => 1, 'name' => 'Colombo'], $result['location']);
    }

    public function testCreateWithoutLocationIdIsOrgWide(): void
    {
        $this->loadFixtures();
        $api = $this->getLocationApi($this->baseBody(), $this->createLocationServiceMock(null));
        $this->assertNull($api->create()->normalize()['location']);
    }

    public function testCreateClashReturnsInvalidParam(): void
    {
        $this->loadFixtures();
        $api = $this->getLocationApi($this->baseBody(), $this->createLocationServiceMock(HolidayService::VALIDATION_CLASH));
        $this->expectInvalidParamException();
        $api->create();
    }

    public function testUpdateWithoutLocationIdKeepsLocation(): void
    {
        $this->loadFixtures();
        $api = $this->getLocationApi($this->baseBody(), $this->createLocationServiceMock(null));
        $this->assertEquals(['id' => 1, 'name' => 'Colombo'], $api->update()->normalize()['location']);
    }

    public function testUpdateWithNullLocationIdMakesOrgWide(): void
    {
        $this->loadFixtures();
        $api = $this->getLocationApi(
            $this->baseBody([HolidayAPI::PARAMETER_LOCATION_ID => null]),
            $this->createLocationServiceMock(null)
        );
        $this->assertNull($api->update()->normalize()['location']);
    }

    public function testGetValidationRuleForCreateRejectsUnknownLocation(): void
    {
        $this->loadFixtures();
        $api = new HolidayAPI($this->getRequest());
        $rules = $api->getValidationRuleForCreate();
        $this->assertTrue($this->validate($this->baseBody([HolidayAPI::PARAMETER_LOCATION_ID => 1]), $rules));
        $this->assertTrue($this->validate($this->baseBody([HolidayAPI::PARAMETER_LOCATION_ID => null]), $rules));
        $this->expectException(\OrangeHRM\Core\Api\V2\Exception\InvalidParamException::class);
        $this->validate($this->baseBody([HolidayAPI::PARAMETER_LOCATION_ID => 999]), $rules);
    }

    public function testGetAllLocationIdIncludeOrgWide(): void
    {
        $this->getAllWith(
            [HolidayAPI::FILTER_LOCATION_ID => 2, HolidayAPI::FILTER_INCLUDE_ORG_WIDE => 'true'],
            [],
            function (HolidaySearchFilterParams $p) {
                $this->assertEquals(HolidaySearchFilterParams::LOCATION_FILTER_EFFECTIVE, $p->getLocationFilter());
                $this->assertEquals(2, $p->getLocationId());
            }
        );
        $this->getAllWith(
            [HolidayAPI::FILTER_LOCATION_ID => 2, HolidayAPI::FILTER_INCLUDE_ORG_WIDE => 'false'],
            [],
            function (HolidaySearchFilterParams $p) {
                $this->assertEquals(HolidaySearchFilterParams::LOCATION_FILTER_LOCATION, $p->getLocationFilter());
            }
        );
    }

    private function getAllWith(array $query, array $extra, callable $expectFilter, $service = null): array
    {
        $service ??= $this->getMockBuilder(HolidayService::class)
            ->onlyMethods(['searchHolidays', 'searchHolidaysCount', 'getEmployeeLocationId'])
            ->getMock();
        $service->method('getEmployeeLocationId')->willReturn(1);
        $service->method('searchHolidaysCount')->willReturn(0);
        $service->expects($this->once())->method('searchHolidays')->with(
            $this->callback(
                function (HolidaySearchFilterParams $p) use ($expectFilter) {
                    $expectFilter($p);
                    return true;
                }
            )
        )->willReturn([]);
        $api = $this->getLocationApi([], $service, $query, $extra);
        return $api->getAll()->normalize();
    }

    public function testGetAllEffectiveForEmployee(): void
    {
        $userRoleManager = $this->getMockBuilder(\OrangeHRM\Core\Authorization\Helper\UserRoleManagerHelper::class)
            ->onlyMethods(['isEmployeeAccessible'])
            ->getMock();
        $userRoleManager->expects($this->once())->method('isEmployeeAccessible')
            ->with(1)->willReturn(true);
        $this->getAllWith(
            [HolidayAPI::FILTER_EMP_NUMBER => 1],
            ['getUserRoleManagerHelper' => $userRoleManager],
            function (HolidaySearchFilterParams $p) {
                $this->assertEquals(HolidaySearchFilterParams::LOCATION_FILTER_EFFECTIVE, $p->getLocationFilter());
                $this->assertEquals(1, $p->getLocationId());
            }
        );
    }

    public function testGetAllForInaccessibleEmployeeForbidden(): void
    {
        $userRoleManager = $this->getMockBuilder(\OrangeHRM\Core\Authorization\Helper\UserRoleManagerHelper::class)
            ->onlyMethods(['isEmployeeAccessible'])
            ->getMock();
        $userRoleManager->method('isEmployeeAccessible')->willReturn(false);
        $service = $this->getMockBuilder(HolidayService::class)->onlyMethods(['searchHolidays'])->getMock();
        $service->expects($this->never())->method('searchHolidays');
        $api = $this->getLocationApi(
            [],
            $service,
            [HolidayAPI::FILTER_EMP_NUMBER => 5],
            ['getUserRoleManagerHelper' => $userRoleManager]
        );
        $this->expectForbiddenException();
        $api->getAll();
    }

    public function testGetAllSelfEmpNumberAllowedViaHelper(): void
    {
        // The helper (entity access OR self) is what lets ESS users query their own empNumber
        $helper = $this->getMockBuilder(\OrangeHRM\Core\Authorization\Helper\UserRoleManagerHelper::class)
            ->onlyMethods(['isEmployeeAccessible'])
            ->getMock();
        $helper->expects($this->once())->method('isEmployeeAccessible')->with(7)->willReturn(true);
        $this->getAllWith(
            [HolidayAPI::FILTER_EMP_NUMBER => 7],
            ['getUserRoleManagerHelper' => $helper],
            function (HolidaySearchFilterParams $p) {
                $this->assertEquals(HolidaySearchFilterParams::LOCATION_FILTER_EFFECTIVE, $p->getLocationFilter());
            }
        );
    }

    public function testGetAllCurrentEmployeeWithoutEmployeeRecordIsOrgWide(): void
    {
        $authUser = $this->getMockBuilder(\OrangeHRM\Authentication\Auth\User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEmpNumber'])
            ->getMock();
        $authUser->method('getEmpNumber')->willReturn(null);
        $this->getAllWith(
            [HolidayAPI::FILTER_CURRENT_EMPLOYEE => 'true'],
            ['getAuthUser' => $authUser],
            function (HolidaySearchFilterParams $p) {
                $this->assertEquals(HolidaySearchFilterParams::LOCATION_FILTER_ORG_WIDE, $p->getLocationFilter());
            }
        );
    }

    public function testGetAllNoFiltersUnchanged(): void
    {
        $this->getAllWith(
            [],
            [],
            function (HolidaySearchFilterParams $p) {
                $this->assertNull($p->getLocationFilter());
            }
        );
    }

    public function testGetAllOrgWideOnlyAndLocationId(): void
    {
        $this->getAllWith(
            [HolidayAPI::FILTER_ORG_WIDE_ONLY => 'true', HolidayAPI::FILTER_LOCATION_ID => 2],
            [],
            function (HolidaySearchFilterParams $p) {
                $this->assertEquals(HolidaySearchFilterParams::LOCATION_FILTER_ORG_WIDE, $p->getLocationFilter());
            }
        );
        $this->getAllWith(
            [HolidayAPI::FILTER_LOCATION_ID => 2],
            [],
            function (HolidaySearchFilterParams $p) {
                $this->assertEquals(HolidaySearchFilterParams::LOCATION_FILTER_LOCATION, $p->getLocationFilter());
                $this->assertEquals(2, $p->getLocationId());
            }
        );
    }

    public function testGetValidationRuleForGetAll(): void
    {
        $api = new HolidayAPI($this->getRequest());
        $rules = $api->getValidationRuleForGetAll();
        $this->assertTrue(
            $this->validate(
                [
                    HolidayAPI::FILTER_FROM_DATE => '2021-01-01',
                    HolidayAPI::FILTER_TO_DATE => '2021-12-31',
                ],
                $rules
            )
        );
        $this->assertTrue(
            $this->validate(
                [
                    HolidayAPI::FILTER_FROM_DATE => '2021-01-01',
                    HolidayAPI::FILTER_TO_DATE => '2021-12-31',
                    HolidayAPI::FILTER_LOCATION_ID => 2,
                    HolidayAPI::FILTER_INCLUDE_ORG_WIDE => true,
                ],
                $rules
            )
        );
    }
}
