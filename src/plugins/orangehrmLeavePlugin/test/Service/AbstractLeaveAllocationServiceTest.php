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
use Generator;
use OrangeHRM\Config\Config;
use OrangeHRM\Core\Service\ConfigService;
use OrangeHRM\Core\Service\DateTimeHelperService;
use OrangeHRM\Entity\Leave;
use OrangeHRM\Framework\Cache\FilesystemAdapter;
use OrangeHRM\Framework\Services;
use OrangeHRM\Leave\Dto\LeaveParameterObject;
use OrangeHRM\Leave\Service\AbstractLeaveAllocationService;
use OrangeHRM\Leave\Service\HolidayService;
use OrangeHRM\Leave\Service\WorkScheduleService;
use OrangeHRM\Leave\Service\WorkWeekService;
use OrangeHRM\Leave\WorkSchedule\BasicWorkSchedule;
use OrangeHRM\Pim\Service\EmployeeService;
use OrangeHRM\Tests\Util\KernelTestCase;
use OrangeHRM\Tests\Util\TestDataService;

/**
 * @group Leave
 * @group Service
 */
class AbstractLeaveAllocationServiceTest extends KernelTestCase
{
    /**
     * @dataProvider getAddHoursDurationDataProvider
     * @param DateTime $time
     * @param float $hoursToAdd
     * @param string $expected H:i format
     */
    public function testAddHoursDuration(DateTime $time, float $hoursToAdd, string $expected): void
    {
        $leaveAllocationService = $this->getMockBuilder(AbstractLeaveAllocationService::class)
            ->onlyMethods(
                [
                    'isWeekend',
                    'isHoliday',
                    'isHalfDay',
                    'isHalfdayHoliday',
                    'getApplicableLeaveDuration',
                    'updateLeaveDurationParameters',
                    'getLeaveRequestStatus'
                ]
            )
            ->getMockForAbstractClass();

        /** @var DateTime $result */
        $result = $this->invokeProtectedMethodOnMock(
            AbstractLeaveAllocationService::class,
            $leaveAllocationService,
            'addHoursDuration',
            [$time, $hoursToAdd]
        );
        $this->assertEquals($expected, $result->format('H:i'));
    }

    /**
     * @return Generator
     */
    public function getAddHoursDurationDataProvider(): Generator
    {
        yield [new DateTime('09:00'), 4, '13:00'];
        yield [new DateTime('09:00'), 4.5, '13:30'];
    }

    /**
     * @dataProvider getDurationInHoursDataProvider
     * @param DateTime $fromTime
     * @param DateTime $toTime
     * @param float $expected
     */
    public function testGetDurationInHours(DateTime $fromTime, DateTime $toTime, float $expected): void
    {
        $this->createKernelWithMockServices([Services::DATETIME_HELPER_SERVICE => new DateTimeHelperService()]);
        $leaveAllocationService = $this->getMockBuilder(AbstractLeaveAllocationService::class)
            ->onlyMethods([])
            ->getMockForAbstractClass();

        /** @var DateTime $result */
        $result = $this->invokeProtectedMethodOnMock(
            AbstractLeaveAllocationService::class,
            $leaveAllocationService,
            'getDurationInHours',
            [$fromTime, $toTime]
        );
        $this->assertEquals($expected, $result);
    }

    /**
     * @return Generator
     */
    public function getDurationInHoursDataProvider(): Generator
    {
        yield [new DateTime('09:00'), new DateTime('17:00'), 8];
        yield [new DateTime('09:00'), new DateTime('13:00'), 4];
        yield [new DateTime('09:00'), new DateTime('09:30'), 0.5];
        yield [new DateTime('09:00'), new DateTime('09:15'), 0.25];
        // TODO:: decimal points also need to handle
        //yield [new DateTime('09:00'), new DateTime('09:10'), 0.16];
    }

    public function testLeaveLengthDiffersByLocation(): void
    {
        TestDataService::populate(
            Config::get(Config::PLUGINS_DIR) . '/orangehrmLeavePlugin/test/fixtures/LeaveAllocationLocation.yml'
        );
        $cache = new FilesystemAdapter();
        $cache->clear();
        $workScheduleService = $this->getMockBuilder(WorkScheduleService::class)
            ->onlyMethods(['getWorkSchedule'])
            ->getMock();
        $workScheduleService->method('getWorkSchedule')->willReturnCallback(
            function (int $empNumber) {
                $workSchedule = new BasicWorkSchedule();
                $workSchedule->setEmpNumber($empNumber);
                return $workSchedule;
            }
        );
        $this->createKernelWithMockServices([
            Services::CACHE => $cache,
            Services::EMPLOYEE_SERVICE => new EmployeeService(),
            Services::HOLIDAY_SERVICE => new HolidayService(),
            Services::WORK_WEEK_SERVICE => new WorkWeekService(),
            Services::CONFIG_SERVICE => new ConfigService(),
            Services::DATETIME_HELPER_SERVICE => new DateTimeHelperService(),
            Services::WORK_SCHEDULE_SERVICE => $workScheduleService,
        ]);

        $leaveAllocationService = new class () extends AbstractLeaveAllocationService {
            protected function saveLeaveRequest(LeaveParameterObject $leaveAssignmentData)
            {
            }

            protected function getLeaveRequestStatus(
                bool $isWeekend,
                bool $isHoliday,
                DateTime $leaveDate,
                LeaveParameterObject $leaveAssignmentData
            ): int {
                return $isHoliday ? Leave::LEAVE_STATUS_LEAVE_HOLIDAY : Leave::LEAVE_STATUS_LEAVE_APPROVED;
            }

            protected function allowToExceedLeaveBalance(): bool
            {
                return true;
            }
        };

        $from = new DateTime('2021-08-19');
        $to = new DateTime('2021-08-20');

        $emp1Leaves = $leaveAllocationService->createLeaveObjectListForAppliedRange(
            new LeaveParameterObject(1, 1, $from, $to)
        );
        $this->assertEquals(Leave::LEAVE_STATUS_LEAVE_APPROVED, $emp1Leaves[0]->getStatus());
        $this->assertEquals(1, $emp1Leaves[0]->getLengthDays());
        $this->assertEquals(Leave::LEAVE_STATUS_LEAVE_HOLIDAY, $emp1Leaves[1]->getStatus());
        $this->assertEquals(0, $emp1Leaves[1]->getLengthDays());

        $emp2Leaves = $leaveAllocationService->createLeaveObjectListForAppliedRange(
            new LeaveParameterObject(2, 1, $from, $to)
        );
        foreach ($emp2Leaves as $leave) {
            $this->assertEquals(Leave::LEAVE_STATUS_LEAVE_APPROVED, $leave->getStatus());
            $this->assertEquals(1, $leave->getLengthDays());
        }
    }
}
