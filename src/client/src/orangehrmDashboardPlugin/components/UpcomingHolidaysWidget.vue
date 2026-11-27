<!--
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
 -->

<template>
  <base-widget
    icon="calendar-event-fill"
    :empty="rows.length === 0"
    :empty-text="$t('leave.no_upcoming_holidays')"
    :loading="isLoading"
    :title="$t('leave.upcoming_holidays')"
  >
    <div v-for="row in rows" :key="row.date + row.name" class="holiday-row">
      <oxd-text tag="p" class="holiday-row-date">
        {{ row.date }}
        <span class="holiday-row-day">{{ row.day }}</span>
      </oxd-text>
      <oxd-text tag="p" class="holiday-row-name">
        {{ row.name }}{{ row.isLocation ? '*' : ''
        }}{{ row.halfDay ? ` (${$t('leave.half_day')})` : '' }}
      </oxd-text>
    </div>
    <oxd-text v-if="locationName" tag="p" class="holiday-footnote">
      * {{ $t('leave.holiday_for_location_only', {location: locationName}) }}
    </oxd-text>
    <a class="holiday-view-all" href="#" @click.prevent="onClickViewAll">
      {{ $t('leave.view_all') }}
    </a>
  </base-widget>
</template>

<script>
import {ref} from 'vue';
import {OxdText} from '@ohrm/oxd';
import {APIService} from '@/core/util/services/api.service';
import {navigate} from '@/core/util/helper/navigation';
import {freshDate, formatDate, parseDate} from '@ohrm/core/util/helper/datefns';
import useDateFormat from '@/core/util/composable/useDateFormat';
import useLocale from '@/core/util/composable/useLocale';
import BaseWidget from '@/orangehrmDashboardPlugin/components/BaseWidget.vue';
import {toUpcomingHolidays} from '@/orangehrmDashboardPlugin/util/upcomingHolidays';

export default {
  name: 'UpcomingHolidaysWidget',

  components: {
    'oxd-text': OxdText,
    'base-widget': BaseWidget,
  },

  setup() {
    const http = new APIService(
      window.appGlobal.baseUrl,
      '/api/v2/leave/holidays',
    );
    const {jsDateFormat} = useDateFormat();
    const {locale} = useLocale();
    const rows = ref([]);
    const locationName = ref(null);
    const isLoading = ref(false);

    return {http, jsDateFormat, locale, rows, locationName, isLoading};
  },

  beforeMount() {
    this.isLoading = true;
    const today = freshDate();
    const nextYear = freshDate();
    nextYear.setFullYear(today.getFullYear() + 1);
    this.http
      .getAll({
        currentEmployee: true,
        fromDate: formatDate(today, 'yyyy-MM-dd'),
        toDate: formatDate(nextYear, 'yyyy-MM-dd'),
        limit: 0,
      })
      .then((response) => {
        const result = toUpcomingHolidays(response.data.data);
        this.locationName = result.locationName;
        this.rows = result.rows.map((row) => {
          const date = parseDate(row.date);
          return {
            ...row,
            date: formatDate(date, this.jsDateFormat, {locale: this.locale}),
            day: formatDate(date, 'EEEE', {locale: this.locale}),
          };
        });
      })
      .finally(() => {
        this.isLoading = false;
      });
  },

  methods: {
    onClickViewAll() {
      navigate('/leave/holidayList');
    },
  },
};
</script>

<style lang="scss" scoped>
.holiday-row {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.25rem 0;
  &-day {
    opacity: 0.7;
    margin-left: 0.25rem;
  }
  &-name {
    font-weight: 700;
    text-align: right;
  }
}
.holiday-footnote {
  font-size: 0.75rem;
  margin-top: 0.5rem;
}
.holiday-view-all {
  display: inline-block;
  margin-top: 0.5rem;
  font-size: 0.8rem;
}
</style>
