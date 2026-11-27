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
  <div class="orangehrm-background-container">
    <oxd-table-filter :filter-title="$t('leave.holiday_list')">
      <oxd-form @submit-valid="filterItems" @reset="onReset">
        <oxd-form-row>
          <oxd-grid :cols="4" class="orangehrm-full-width-grid">
            <oxd-grid-item>
              <date-input
                v-model="filters.fromDate"
                :label="$t('general.from')"
                :rules="rules.fromDate"
                :years="yearArray"
                all-holidays
              />
            </oxd-grid-item>
            <oxd-grid-item>
              <date-input
                v-model="filters.toDate"
                :label="$t('general.to')"
                :rules="rules.toDate"
                :years="yearArray"
                all-holidays
              />
            </oxd-grid-item>
            <oxd-grid-item>
              <oxd-input-field
                v-model="filters.location"
                type="select"
                :label="$t('general.location')"
                :options="locationOptions"
              />
            </oxd-grid-item>
          </oxd-grid>
        </oxd-form-row>

        <oxd-divider />

        <oxd-form-actions>
          <oxd-button
            display-type="ghost"
            :label="$t('general.reset')"
            type="reset"
          />
          <oxd-button
            class="orangehrm-left-space"
            display-type="secondary"
            :label="$t('general.search')"
            type="submit"
          />
        </oxd-form-actions>
      </oxd-form>
    </oxd-table-filter>
    <br />
    <div class="orangehrm-paper-container">
      <table-header :total="total" :loading="isLoading"></table-header>
      <div class="orangehrm-container">
        <oxd-card-table
          :headers="headers"
          :items="items?.data"
          :selectable="false"
          :clickable="false"
          :loading="isLoading"
          row-decorator="oxd-table-decorator-card"
        />
      </div>
    </div>
  </div>
</template>

<script>
import {computed, nextTick, ref} from 'vue';
import usePaginate from '@ohrm/core/util/composable/usePaginate';
import {APIService} from '@/core/util/services/api.service';
import {
  endDateShouldBeAfterStartDate,
  required,
  validDateFormat,
} from '@/core/util/validation/rules';
import {yearRange} from '@ohrm/core/util/helper/year-range';
import useDateFormat from '@/core/util/composable/useDateFormat';
import {formatDate, parseDate} from '@/core/util/helper/datefns';
import useLocale from '@/core/util/composable/useLocale';
import usei18n from '@/core/util/composable/usei18n';

export default {
  props: {
    leavePeriod: {
      type: Object,
      required: true,
    },
    locations: {
      type: Array,
      default: () => [],
    },
    employeeLocationId: {
      type: Number,
      default: null,
    },
  },

  setup(props) {
    const {$t: t} = usei18n();

    const locationOptions = computed(() => [
      {id: 0, label: t('leave.org_wide_holidays')},
      ...props.locations,
    ]);

    const defaultLocation = () =>
      locationOptions.value.find((o) => o.id === props.employeeLocationId) ??
      locationOptions.value[0];

    const filters = ref({
      fromDate: props.leavePeriod.startDate,
      toDate: props.leavePeriod.endDate,
      location: defaultLocation(),
    });

    const serializedFilters = computed(() => {
      const location = filters.value.location;
      const isOrgWide = !location || location.id === 0;
      return {
        fromDate: filters.value.fromDate,
        toDate: filters.value.toDate,
        // limit=0 is accepted by the API and means "do not limit"
        limit: 0,
        orgWideOnly: isOrgWide ? true : undefined,
        locationId: isOrgWide ? undefined : location.id,
        includeOrgWide: isOrgWide ? undefined : true,
      };
    });

    const {jsDateFormat, userDateFormat} = useDateFormat();
    const {locale} = useLocale();
    const dataNormalizer = (data) => {
      return data.map((item) => {
        const date = parseDate(item.date);
        return {
          id: item.id,
          name: item.name,
          date: formatDate(date, jsDateFormat, {locale}),
          day: formatDate(date, 'EEEE', {locale}),
          length: item.lengthName,
          appliesTo: item.location?.name ?? t('leave.org_wide'),
        };
      });
    };

    const http = new APIService(
      window.appGlobal.baseUrl,
      '/api/v2/leave/holidays',
    );
    const {total, response, isLoading, execQuery} = usePaginate(http, {
      query: serializedFilters,
      normalizer: dataNormalizer,
    });

    return {
      total,
      isLoading,
      execQuery,
      items: response,
      filters,
      locationOptions,
      defaultLocation,
      userDateFormat,
    };
  },

  data() {
    return {
      yearArray: [...yearRange(201)],
      rules: {
        fromDate: [required, validDateFormat(this.userDateFormat)],
        toDate: [
          required,
          validDateFormat(this.userDateFormat),
          endDateShouldBeAfterStartDate(
            () => this.filters.fromDate,
            this.$t('general.to_date_should_be_after_from_date'),
            {allowSameDate: true},
          ),
        ],
      },
      headers: [
        {name: 'date', title: this.$t('general.date'), style: {flex: 2}},
        {name: 'day', title: this.$t('leave.day'), style: {flex: 2}},
        {name: 'name', title: this.$t('general.name'), style: {flex: 3}},
        {
          name: 'length',
          title: this.$t('leave.full_day_half_day'),
          style: {flex: 2},
        },
        {
          name: 'appliesTo',
          title: this.$t('leave.applies_to'),
          style: {flex: 2},
        },
      ],
    };
  },

  methods: {
    async filterItems() {
      await this.execQuery();
    },
    async onReset() {
      // Wait for the form's own reset to settle, then restore the defaults
      await nextTick();
      this.filters.fromDate = this.leavePeriod.startDate;
      this.filters.toDate = this.leavePeriod.endDate;
      this.filters.location = this.defaultLocation();
      await nextTick();
      await this.execQuery();
    },
  },
};
</script>
