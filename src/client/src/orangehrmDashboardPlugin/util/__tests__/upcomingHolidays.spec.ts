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

import {toUpcomingHolidays} from '../upcomingHolidays';

describe('orangehrmDashboardPlugin/util/upcomingHolidays', () => {
  const items = [
    {id: 3, name: 'C', date: '2027-01-26', length: 0, location: null},
    {
      id: 1,
      name: 'A',
      date: '2026-12-25',
      length: 0,
      location: {id: 1, name: 'Pune'},
    },
    {id: 2, name: 'B', date: '2027-01-01', length: 0, location: null},
  ];

  test('sorts by date, cuts to limit and picks location name', () => {
    expect(toUpcomingHolidays(items, 2)).toEqual({
      rows: [
        {date: '2026-12-25', name: 'A', halfDay: false, isLocation: true},
        {date: '2027-01-01', name: 'B', halfDay: false, isLocation: false},
      ],
      locationName: 'Pune',
    });
  });

  test('no location name when no location rows', () => {
    expect(toUpcomingHolidays([items[0]]).locationName).toBeNull();
  });

  test('empty input', () => {
    expect(toUpcomingHolidays([]).rows).toEqual([]);
  });

  test('half day when length is 4', () => {
    const result = toUpcomingHolidays([
      {id: 4, name: 'D', date: '2027-02-01', length: 4, location: null},
    ]);
    expect(result.rows[0].halfDay).toBe(true);
  });
});
