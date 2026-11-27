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

namespace OrangeHRM\Leave\Controller;

use OrangeHRM\Admin\Service\LocationService;
use OrangeHRM\Core\Traits\Auth\AuthUserTrait;
use OrangeHRM\Core\Controller\AbstractVueController;
use OrangeHRM\Core\Vue\Component;
use OrangeHRM\Core\Vue\Prop;
use OrangeHRM\Framework\Http\Request;
use OrangeHRM\Leave\Traits\Service\HolidayServiceTrait;
use OrangeHRM\Leave\Traits\Service\LeavePeriodServiceTrait;

class HolidayEmployeeListController extends AbstractVueController
{
    use AuthUserTrait;
    use HolidayServiceTrait;
    use LeavePeriodServiceTrait;

    protected ?LocationService $locationService = null;

    /**
     * @return LocationService
     */
    protected function getLocationService(): LocationService
    {
        if (!$this->locationService instanceof LocationService) {
            $this->locationService = new LocationService();
        }
        return $this->locationService;
    }

    /**
     * @inheritDoc
     */
    public function preRender(Request $request): void
    {
        $component = new Component('holiday-employee-list');
        $component->addProp(
            new Prop(
                'leave-period',
                Prop::TYPE_OBJECT,
                $this->getLeavePeriodService()->getNormalizedCurrentLeavePeriod()
            )
        );
        $component->addProp(
            new Prop('locations', Prop::TYPE_ARRAY, $this->getLocationService()->getLocationsArray())
        );
        $component->addProp(
            new Prop(
                'employee-location-id',
                Prop::TYPE_NUMBER,
                $this->getHolidayService()->getLocationIdForUser($this->getAuthUser()->getEmpNumber())
            )
        );

        $this->setComponent($component);
    }
}
