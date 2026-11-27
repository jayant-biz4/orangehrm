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

const HALF_DAY_LENGTH = 4;

export interface HolidayItem {
  id: number;
  name: string;
  date: string;
  length: number | string;
  location: {id: number; name: string} | null;
}

export interface UpcomingHolidayRow {
  date: string;
  name: string;
  halfDay: boolean;
  isLocation: boolean;
}

export const toUpcomingHolidays = function (
  items: HolidayItem[],
  limit = 5,
): {rows: UpcomingHolidayRow[]; locationName: string | null} {
  const kept = [...items]
    .sort((a, b) => a.date.localeCompare(b.date))
    .slice(0, limit);
  return {
    rows: kept.map((item) => ({
      date: item.date,
      name: item.name,
      halfDay: Number(item.length) === HALF_DAY_LENGTH,
      isLocation: item.location !== null,
    })),
    locationName: kept.find((item) => item.location)?.location?.name ?? null,
  };
};
