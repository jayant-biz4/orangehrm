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

namespace OrangeHRM\Leave\Api;

use OrangeHRM\Core\Api\V2\CollectionEndpoint;
use OrangeHRM\Core\Api\V2\Endpoint;
use OrangeHRM\Core\Api\V2\EndpointResourceResult;
use OrangeHRM\Core\Api\V2\EndpointResult;
use OrangeHRM\Core\Api\V2\Model\ArrayModel;
use OrangeHRM\Core\Api\V2\RequestParams;
use OrangeHRM\Core\Api\V2\Validator\ParamRule;
use OrangeHRM\Core\Api\V2\Validator\ParamRuleCollection;
use OrangeHRM\Core\Api\V2\Validator\Rule;
use OrangeHRM\Core\Api\V2\Validator\Rules;
use OrangeHRM\Entity\Holiday;
use OrangeHRM\Entity\Location;
use OrangeHRM\Leave\Traits\Service\HolidayServiceTrait;

class HolidayDateValidationAPI extends Endpoint implements CollectionEndpoint
{
    use HolidayServiceTrait;

    public const PARAMETER_DATE = 'date';
    public const PARAMETER_RECURRING = 'recurring';
    public const PARAMETER_LOCATION_ID = 'locationId';
    public const PARAMETER_HOLIDAY_ID = 'holidayId';

    public const PARAMETER_VALID = 'valid';
    public const PARAMETER_REASON = 'reason';

    /**
     * @OA\Get(
     *     path="/api/v2/leave/validation/holiday-date",
     *     tags={"Leave/Validation"},
     *     summary="Validate Holiday Date",
     *     operationId="validate-holiday-date",
     *     @OA\Parameter(name="date", in="query", required=true, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="recurring", in="query", required=false, @OA\Schema(type="boolean")),
     *     @OA\Parameter(name="locationId", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="holidayId", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response="200",
     *         description="Success",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="valid", type="boolean"),
     *                 @OA\Property(property="reason", type="string", nullable=true, enum={"clash", "allLocations"}),
     *             ),
     *             @OA\Property(property="meta", type="object")
     *         ),
     *     )
     * )
     *
     * @inheritDoc
     */
    public function getAll(): EndpointResult
    {
        $query = RequestParams::PARAM_TYPE_QUERY;
        $holiday = new Holiday();
        $holidayId = $this->getRequestParams()->getIntOrNull($query, self::PARAMETER_HOLIDAY_ID);
        if ($holidayId !== null) {
            $holiday->setId($holidayId);
        }
        $holiday->setDate($this->getRequestParams()->getDateTime($query, self::PARAMETER_DATE));
        $holiday->setRecurring($this->getRequestParams()->getBoolean($query, self::PARAMETER_RECURRING, false));
        $holiday->getDecorator()->setLocationById(
            $this->getRequestParams()->getIntOrNull($query, self::PARAMETER_LOCATION_ID)
        );

        $reason = $this->getHolidayService()->validateHoliday($holiday);
        return new EndpointResourceResult(
            ArrayModel::class,
            [self::PARAMETER_VALID => $reason === null, self::PARAMETER_REASON => $reason]
        );
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForGetAll(): ParamRuleCollection
    {
        return new ParamRuleCollection(
            new ParamRule(self::PARAMETER_DATE, new Rule(Rules::API_DATE)),
            $this->getValidationDecorator()->notRequiredParamRule(
                new ParamRule(self::PARAMETER_RECURRING, new Rule(Rules::BOOL_VAL))
            ),
            $this->getValidationDecorator()->notRequiredParamRule(
                new ParamRule(self::PARAMETER_LOCATION_ID, new Rule(Rules::ENTITY_ID_EXISTS, [Location::class]))
            ),
            $this->getValidationDecorator()->notRequiredParamRule(
                new ParamRule(self::PARAMETER_HOLIDAY_ID, new Rule(Rules::ENTITY_ID_EXISTS, [Holiday::class]))
            ),
        );
    }

    /**
     * @inheritDoc
     */
    public function create(): EndpointResult
    {
        throw $this->getNotImplementedException();
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForCreate(): ParamRuleCollection
    {
        throw $this->getNotImplementedException();
    }

    /**
     * @inheritDoc
     */
    public function delete(): EndpointResult
    {
        throw $this->getNotImplementedException();
    }

    /**
     * @inheritDoc
     */
    public function getValidationRuleForDelete(): ParamRuleCollection
    {
        throw $this->getNotImplementedException();
    }
}
