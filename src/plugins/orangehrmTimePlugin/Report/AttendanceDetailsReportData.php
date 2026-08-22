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

namespace OrangeHRM\Time\Report;

use OrangeHRM\Attendance\Traits\Service\AttendanceServiceTrait;
use OrangeHRM\Core\Api\CommonParams;
use OrangeHRM\Core\Api\V2\ParameterBag;
use OrangeHRM\Core\Report\ReportData;
use OrangeHRM\Core\Traits\Service\DateTimeHelperTrait;
use OrangeHRM\Core\Traits\Service\NumberHelperTrait;
use OrangeHRM\Core\Traits\LoggerTrait;
use OrangeHRM\I18N\Traits\Service\I18NHelperTrait;
use OrangeHRM\Time\Dto\AttendanceDetailsReportSearchFilterParams;

class AttendanceDetailsReportData implements ReportData
{
    use AttendanceServiceTrait;
    use NumberHelperTrait;
    use DateTimeHelperTrait;
    use I18NHelperTrait;
    use LoggerTrait;

    /**
     * @var AttendanceDetailsReportSearchFilterParams
     */
    private AttendanceDetailsReportSearchFilterParams $filterParams;

    public function __construct(AttendanceDetailsReportSearchFilterParams $filterParams)
    {
        $this->filterParams = $filterParams;
    }

    /**
     * @inheritDoc
     */
    public function normalize(): array
    {
        $employeeAttendanceRecords = $this->getAttendanceService()
            ->getAttendanceDao()
            ->getAttendanceDetailsReportCriteriaList($this->filterParams);


        $result = [];
        foreach ($employeeAttendanceRecords as $employeeAttendanceRecord) {
            $termination = $employeeAttendanceRecord['terminationId'];
            $result[] = [
                AttendanceDetailsReport::PARAMETER_EMPLOYEE_NO => $employeeAttendanceRecord['employeeId'],
                AttendanceDetailsReport::PARAMETER_EMPLOYEE_NAME => $termination === null ? $employeeAttendanceRecord['fullName'] : $employeeAttendanceRecord['fullName'] . ' ' . $this->getI18NHelper()->transBySource('(Past Employee)'),
                AttendanceDetailsReport::PARAMETER_PUNCHIN_DATE => $this->getDateTimeHelper()
                    ->formatDate($employeeAttendanceRecord['punchInTime']),
                AttendanceDetailsReport::PARAMETER_PUNCHIN_TIME => $this->getDateTimeHelper()
                    ->formatDateTimeToTimeString($employeeAttendanceRecord['punchInTime']),
                AttendanceDetailsReport::PARAMETER_PUNCHOUT_TIME => $this->getDateTimeHelper()
                    ->formatDateTimeToTimeString($employeeAttendanceRecord['punchOutTime']),
                AttendanceDetailsReport::PARAMETER_WORKHOURS => $this->getNumberHelper()
                    ->numberFormat((float)$employeeAttendanceRecord['workHours'] / 3600, 2)
            ];
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getMeta(): ?ParameterBag
    {
        return new ParameterBag(
            [
                CommonParams::PARAMETER_TOTAL => $this->getAttendanceService()
                    ->getAttendanceDao()
                    ->getAttendanceDetailsReportCriteriaListCount($this->filterParams)
            ]
        );
    }
}
