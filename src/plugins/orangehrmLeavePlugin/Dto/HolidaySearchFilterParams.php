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

namespace OrangeHRM\Leave\Dto;

class HolidaySearchFilterParams extends DateRangeSearchFilterParams
{
    public const ALLOWED_SORT_FIELDS = ['holiday.date'];
    public const LOCATION_FILTER_LOCATION = 'location';
    public const LOCATION_FILTER_ORG_WIDE = 'orgWide';
    public const LOCATION_FILTER_EFFECTIVE = 'effective';

    /**
     * @var bool
     */
    private bool $excludeRecurring = false;

    /**
     * @var string|null
     */
    private ?string $locationFilter = null;

    /**
     * @var int|null
     */
    private ?int $locationId = null;

    public function __construct()
    {
        $this->setSortField('holiday.date');
    }

    /**
     * @return bool
     */
    public function isExcludeRecurring(): bool
    {
        return $this->excludeRecurring;
    }

    /**
     * @param bool $excludeRecurring
     */
    public function setExcludeRecurring(bool $excludeRecurring): void
    {
        $this->excludeRecurring = $excludeRecurring;
    }

    /**
     * @param string|null $filter one of the LOCATION_FILTER_* constants, null for no location condition
     * @param int|null $locationId
     */
    public function setLocationFilter(?string $filter, ?int $locationId = null): void
    {
        $this->locationFilter = $filter;
        $this->locationId = $locationId;
    }

    /**
     * @return string|null
     */
    public function getLocationFilter(): ?string
    {
        return $this->locationFilter;
    }

    /**
     * @return int|null
     */
    public function getLocationId(): ?int
    {
        return $this->locationId;
    }
}
