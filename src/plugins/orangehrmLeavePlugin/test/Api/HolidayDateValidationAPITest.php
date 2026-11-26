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

use OrangeHRM\Core\Api\V2\RequestParams;
use OrangeHRM\Core\Service\DateTimeHelperService;
use OrangeHRM\Entity\Holiday;
use OrangeHRM\Framework\Services;
use OrangeHRM\Leave\Api\HolidayDateValidationAPI;
use OrangeHRM\Leave\Service\HolidayService;
use OrangeHRM\Tests\Util\EndpointTestCase;
use OrangeHRM\Tests\Util\MockObject;

/**
 * @group Leave
 * @group APIv2
 */
class HolidayDateValidationAPITest extends EndpointTestCase
{
    private function getApi(?string $reason, array $query, ?callable $check = null): HolidayDateValidationAPI
    {
        $holidayService = $this->getMockBuilder(HolidayService::class)
            ->onlyMethods(['validateHoliday'])
            ->getMock();
        $holidayService->expects($this->once())
            ->method('validateHoliday')
            ->willReturnCallback(function (Holiday $holiday) use ($reason, $check) {
                if ($check) {
                    $check($holiday);
                }
                return $reason;
            });
        $this->createKernelWithMockServices([Services::DATETIME_HELPER_SERVICE => new DateTimeHelperService()]);

        /** @var MockObject&HolidayDateValidationAPI $api */
        $api = $this->getApiEndpointMockBuilder(
            HolidayDateValidationAPI::class,
            [RequestParams::PARAM_TYPE_QUERY => $query]
        )->onlyMethods(['getHolidayService'])->getMock();
        $api->method('getHolidayService')->willReturn($holidayService);
        return $api;
    }

    public function testValidDate(): void
    {
        $api = $this->getApi(null, ['date' => '2030-01-01']);
        $this->assertEquals(['valid' => true, 'reason' => null], $api->getAll()->normalize());
    }

    public function testClash(): void
    {
        $api = $this->getApi(HolidayService::VALIDATION_CLASH, ['date' => '2030-01-01', 'recurring' => 'true']);
        $this->assertEquals(['valid' => false, 'reason' => 'clash'], $api->getAll()->normalize());
    }

    public function testUpdateExcludesSelf(): void
    {
        $api = $this->getApi(null, ['date' => '2030-01-01', 'holidayId' => 9], function (Holiday $h) {
            $this->assertSame(9, $h->getId());
            $this->assertSame('2030-01-01', $h->getDate()->format('Y-m-d'));
            $this->assertFalse($h->isRecurring());
            $this->assertTrue($h->getDecorator()->isOrgWide());
        });
        $api->getAll();
    }

    public function testNotImplemented(): void
    {
        $api = new HolidayDateValidationAPI($this->getRequest());
        $this->expectNotImplementedException();
        $api->create();
    }
}
