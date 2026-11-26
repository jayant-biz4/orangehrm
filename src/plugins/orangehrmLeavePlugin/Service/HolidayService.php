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

namespace OrangeHRM\Leave\Service;

use DateTime;
use OrangeHRM\Core\Traits\CacheTrait;
use OrangeHRM\Core\Traits\Service\ConfigServiceTrait;
use OrangeHRM\Entity\Holiday;
use OrangeHRM\Entity\WorkWeek;
use OrangeHRM\Leave\Dao\HolidayDao;
use OrangeHRM\Leave\Dto\HolidaySearchFilterParams;
use OrangeHRM\Pim\Traits\Service\EmployeeServiceTrait;

class HolidayService
{
    use CacheTrait;
    use ConfigServiceTrait;
    use EmployeeServiceTrait;

    public const LEAVE_HOLIDAYS_CACHE_KEY_PREFIX = 'leave.holidays';
    public const PARAMETER_DATA = 'data';
    public const PARAMETER_TOTAL = 'total';
    public const VALIDATION_CLASH = 'clash';
    public const VALIDATION_ALL_LOCATIONS = 'allLocations';

    /**
     * @var HolidayDao|null
     */
    private ?HolidayDao $holidayDao = null;

    /**
     * @var array<int, int|null> empNumber => locationId (null when the employee has no location)
     */
    private array $employeeLocationIds = [];

    /**
     * @return HolidayDao
     */
    public function getHolidayDao(): HolidayDao
    {
        if (is_null($this->holidayDao)) {
            $this->holidayDao = new HolidayDao();
        }
        return $this->holidayDao;
    }

    /**
     * @param Holiday $holiday
     * @return Holiday
     */
    public function saveHoliday(Holiday $holiday): Holiday
    {
        $this->getCache()->clear(self::LEAVE_HOLIDAYS_CACHE_KEY_PREFIX);
        return $this->getHolidayDao()->saveHoliday($holiday);
    }

    /**
     * @param DateTime $fromDate
     * @param DateTime $toDate
     * @param string|null $locationFilter
     * @param int|null $locationId
     * @return string
     */
    protected function getHolidaysCacheKey(
        DateTime $fromDate,
        DateTime $toDate,
        ?string $locationFilter = null,
        ?int $locationId = null
    ): string {
        return self::LEAVE_HOLIDAYS_CACHE_KEY_PREFIX .
            '.' . $fromDate->format('Y_m_d') . '.' . $toDate->format('Y_m_d') .
            '.' . ($locationFilter ?? 'all') . '.' . ($locationId ?? 'none');
    }

    /**
     * Search Holidays within a given leave period
     * @param HolidaySearchFilterParams $holidaySearchFilterParams
     * @return Holiday[]
     */
    public function searchHolidays(HolidaySearchFilterParams $holidaySearchFilterParams): array
    {
        $offset = $holidaySearchFilterParams->getOffset();
        $limit = $holidaySearchFilterParams->getLimit();
        if ($limit == 0) {  // when check uniqueness
            return $this->searchHolidaysAlongWithCache(
                $holidaySearchFilterParams
            )[self::PARAMETER_DATA];
        }
        return array_slice($this->searchHolidaysAlongWithCache(
            $holidaySearchFilterParams
        )[self::PARAMETER_DATA], $offset, $limit);
    }

    /**
     * @param HolidaySearchFilterParams $holidaySearchFilterParams
     * @return int
     */
    public function searchHolidaysCount(HolidaySearchFilterParams $holidaySearchFilterParams): int
    {
        return $this->searchHolidaysAlongWithCache(
            $holidaySearchFilterParams
        )[self::PARAMETER_TOTAL];
    }

    /**
     * @param HolidaySearchFilterParams $filterParams
     * @return array
     */
    protected function searchHolidaysAlongWithCache(HolidaySearchFilterParams $filterParams): array
    {
        $fromDate = $filterParams->getFromDate();
        $toDate = $filterParams->getToDate();
        return $this->getCache()->get(
            $this->getHolidaysCacheKey(
                $fromDate,
                $toDate,
                $filterParams->getLocationFilter(),
                $filterParams->getLocationId()
            ),
            function () use ($filterParams) {
                $holidays = $this->getCalculatedHolidays($filterParams);
                return [self::PARAMETER_DATA => $holidays, self::PARAMETER_TOTAL => count($holidays)];
            }
        );
    }

    /**
     * @param HolidaySearchFilterParams $filterParams
     * @return array
     */
    protected function getCalculatedHolidays(HolidaySearchFilterParams $filterParams): array
    {
        $fromDate = $filterParams->getFromDate();
        $toDate = $filterParams->getToDate();
        $holidaySearchFilterParams = new HolidaySearchFilterParams();
        $holidaySearchFilterParams->setFromDate($fromDate);
        $holidaySearchFilterParams->setToDate($toDate);
        $holidaySearchFilterParams->setLocationFilter(
            $filterParams->getLocationFilter(),
            $filterParams->getLocationId()
        );
        $holidayList = $this->getHolidayDao()->searchHolidays($holidaySearchFilterParams);

        $startYear = $fromDate->format('Y');
        $endYear = $toDate->format('Y');
        $results = [];

        foreach ($holidayList as $holiday) {
            if ($holiday->isRecurring()) {
                $holidayDate = $holiday->getDate();

                for ($year = $startYear; $year <= $endYear; $year++) {
                    $recurringDateStr = "{$year}-{$holidayDate->format('m')}-{$holidayDate->format('d')}";
                    $recurringDate = new DateTime($recurringDateStr);

                    if ($recurringDate >= $fromDate && $recurringDate <= $toDate) {
                        $recurringHoliday = clone $holiday;
                        $recurringHoliday->setDate($recurringDate);
                        $recurringHoliday->setId($holiday->getId());

                        $results[] = $recurringHoliday;
                    }
                }
            } else {
                $results[] = $holiday;
            }
        }

        usort(
            $results,
            function (Holiday $a, Holiday $b) {
                $timeStampA = strtotime($a->getDate()->format('Y-m-d'));
                $timeStampB = strtotime($b->getDate()->format('Y-m-d'));
                if ($timeStampA === $timeStampB) {
                    return $a->getId() - $b->getId();
                } else {
                    return $timeStampA - $timeStampB;
                }
            }
        );

        return array_values($results);
    }

    /**
     * @param int $empNumber
     * @return int|null
     */
    public function getEmployeeLocationId(int $empNumber): ?int
    {
        if (!array_key_exists($empNumber, $this->employeeLocationIds)) {
            $employee = $this->getEmployeeService()->getEmployeeByEmpNumber($empNumber);
            $this->employeeLocationIds[$empNumber] = $employee?->getDecorator()->getLocation()?->getId();
        }
        return $this->employeeLocationIds[$empNumber];
    }

    /**
     * @param DateTime $day
     * @param int|null $empNumber null means the global (location independent) lookup
     * @return Holiday|null
     */
    private function getHolidayForDay(DateTime $day, ?int $empNumber): ?Holiday
    {
        if ($empNumber === null) {
            return $this->getHolidayDao()->getHolidayByDate($day);
        }
        return $this->getHolidayDao()->getHolidayForLocationByDate(
            $day,
            $this->getEmployeeLocationId($empNumber)
        );
    }

    /**
     * @param DateTime $day
     * @param int|null $empNumber
     * @return bool
     */
    public function isHoliday(DateTime $day, ?int $empNumber = null): bool
    {
        $holiday = $this->getHolidayForDay($day, $empNumber);
        if ($holiday != null && $holiday->getLength() == Holiday::HOLIDAY_FULL_DAY_LENGTH) {
            return true;
        }
        return false;
    }

    /**
     * @param DateTime $day
     * @param int|null $empNumber
     * @return bool
     */
    public function isHalfDay(DateTime $day, ?int $empNumber = null): bool
    {
        $holiday = $this->getHolidayForDay($day, $empNumber);
        if ($holiday != null &&
            $holiday->getLength() >= WorkWeek::WORKWEEK_LENGTH_HALF_DAY &&
            $holiday->getLength() < $this->getConfigService()->getDefaultWorkShiftLength()) {
            return true;
        }
        return false;
    }

    /**
     * @param DateTime $day
     * @param int|null $empNumber
     * @return bool
     */
    public function isHalfDayHoliday(DateTime $day, ?int $empNumber = null): bool
    {
        $holiday = $this->getHolidayForDay($day, $empNumber);
        if ($holiday != null && $holiday->getLength() == Holiday::HOLIDAY_HALF_DAY_LENGTH) {
            return true;
        }
        return false;
    }

    /**
     * @param Holiday $holiday
     * @return string|null null when valid, else self::VALIDATION_CLASH or self::VALIDATION_ALL_LOCATIONS
     */
    public function validateHoliday(Holiday $holiday): ?string
    {
        try {
            $holidayId = $holiday->getId();
        } catch (\Error) {
            $holidayId = null; // new holiday, id not assigned yet
        }
        $same = $this->getHolidayDao()->getSameDateHolidays($holiday->getDate(), $holiday->isRecurring(), $holidayId);
        if ($holiday->getDecorator()->isOrgWide()) {
            return empty($same) ? null : self::VALIDATION_CLASH;
        }

        $locationId = $holiday->getLocation()->getId();
        $otherLocationIds = [];
        foreach ($same as $existing) {
            if ($existing->getDecorator()->isOrgWide() || $existing->getLocation()->getId() === $locationId) {
                return self::VALIDATION_CLASH;
            }
            $otherLocationIds[$existing->getLocation()->getId()] = true;
        }
        if (count($otherLocationIds) + 1 >= $this->getHolidayDao()->getLocationCount()) {
            return self::VALIDATION_ALL_LOCATIONS;
        }
        return null;
    }

    /**
     * @param array $toDeleteIds
     * @return int
     */
    public function deleteHolidays(array $toDeleteIds): int
    {
        $this->getCache()->clear(self::LEAVE_HOLIDAYS_CACHE_KEY_PREFIX);
        return $this->getHolidayDao()->deleteHolidays($toDeleteIds);
    }
}
