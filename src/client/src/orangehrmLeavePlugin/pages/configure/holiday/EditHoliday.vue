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
    <div class="orangehrm-card-container">
      <oxd-text tag="h6" class="orangehrm-main-title">
        {{ $t('leave.edit_holiday') }}
      </oxd-text>

      <oxd-divider />

      <oxd-form :loading="isLoading" @submit-valid="onSave">
        <oxd-form-row>
          <oxd-grid :cols="3" class="orangehrm-full-width-grid">
            <oxd-grid-item>
              <oxd-input-field
                v-model="holiday.name"
                :label="$t('general.name')"
                :rules="rules.name"
                required
              />
            </oxd-grid-item>
            <oxd-grid-item>
              <date-input
                v-model="holiday.date"
                :label="$t('general.date')"
                :rules="rules.date"
                :years="yearArray"
                all-holidays
                required
              />
            </oxd-grid-item>
            <oxd-grid-item>
              <oxd-input-field
                v-model="holiday.length"
                type="select"
                :label="$t('leave.full_day_half_day')"
                :options="holidayLengthList"
                :rules="rules.length"
                required
              />
            </oxd-grid-item>
            <oxd-grid-item>
              <oxd-input-field
                v-model="holiday.location"
                type="select"
                :label="$t('general.location')"
                :options="locationOptions"
              />
            </oxd-grid-item>
          </oxd-grid>
        </oxd-form-row>
        <oxd-form-row>
          <oxd-grid :cols="3" class="orangehrm-full-width-grid">
            <oxd-grid-item>
              <oxd-input-group
                :classes="{wrapper: '--status-grouped-field'}"
                :label="$t('leave.repeats_annually')"
              >
                <oxd-input-field
                  v-model="holiday.recurring"
                  type="radio"
                  :option-label="$t('general.yes')"
                  :value="true"
                />
                <oxd-input-field
                  v-model="holiday.recurring"
                  type="radio"
                  :option-label="$t('general.no')"
                  :value="false"
                />
              </oxd-input-group>
            </oxd-grid-item>
          </oxd-grid>
        </oxd-form-row>

        <oxd-divider />

        <oxd-form-actions>
          <required-text />
          <oxd-button
            type="button"
            display-type="ghost"
            :label="$t('general.cancel')"
            @click="onCancel"
          />
          <submit-button />
        </oxd-form-actions>
      </oxd-form>
    </div>
  </div>
</template>

<script>
import {navigate} from '@ohrm/core/util/helper/navigation';
import {APIService} from '@ohrm/core/util/services/api.service';
import {
  required,
  shouldNotExceedCharLength,
  validDateFormat,
} from '@ohrm/core/util/validation/rules';
import {promiseDebounce} from '@ohrm/oxd';
import {yearRange} from '@/core/util/helper/year-range';
import useDateFormat from '@/core/util/composable/useDateFormat';

const holidayModel = {
  id: '',
  name: '',
  date: '',
  recurring: false,
  location: null,
  length: 0,
};

export default {
  props: {
    holidayId: {
      type: Number,
      required: true,
    },
    holidayLengthList: {
      type: Array,
      required: true,
    },
    locations: {
      type: Array,
      default: () => [],
    },
  },

  setup() {
    const http = new APIService(
      window.appGlobal.baseUrl,
      '/api/v2/leave/holidays',
    );
    const {userDateFormat} = useDateFormat();

    return {
      http,
      userDateFormat,
    };
  },

  data() {
    return {
      yearArray: [...yearRange(201)],
      isLoading: false,
      holiday: {...holidayModel},
      rules: {
        name: [required, shouldNotExceedCharLength(200)],
        date: [
          required,
          validDateFormat(this.userDateFormat),
          promiseDebounce(this.validateDate, 500),
        ],
        length: [required],
      },
    };
  },

  computed: {
    locationOptions() {
      return [
        {id: 0, label: this.$t('leave.all_locations')},
        ...this.locations.map(({id, name}) => ({id, label: name})),
      ];
    },
  },

  watch: {
    'holiday.location'() {
      if (!this.isLoading) this.revalidateDate();
    },
    'holiday.recurring'() {
      if (!this.isLoading) this.revalidateDate();
    },
  },
  created() {
    this.isLoading = true;
    this.http
      .get(this.holidayId)
      .then((response) => {
        const {data} = response.data;
        this.holiday.id = data.id;
        this.holiday.name = data.name;
        this.holiday.date = data.date;
        this.holiday.recurring = data.recurring;
        if (data.length !== '' && data.length !== null) {
          this.holiday.length = this.holidayLengthList.find((h) => {
            return h.id === data.length;
          });
        }
        const locationId = data.location?.id ?? 0;
        this.holiday.location = this.locationOptions.find(
          (l) => l.id === locationId,
        ) ?? {id: locationId, label: data.location.name};
      })
      .finally(() => {
        this.isLoading = false;
      });
  },

  methods: {
    validateDate(date) {
      return new Promise((resolve) => {
        this.http
          .request({
            method: 'GET',
            url: '/api/v2/leave/validation/holiday-date',
            params: {
              date,
              recurring: this.holiday.recurring,
              locationId: this.holiday.location?.id || undefined,
              holidayId: this.holidayId,
            },
          })
          .then(({data}) => {
            const {reason} = data.data;
            if (reason === 'clash') {
              resolve(this.$t('leave.holiday_date_clash'));
            } else if (reason === 'allLocations') {
              resolve(this.$t('leave.holiday_all_locations'));
            } else {
              resolve(true);
            }
          })
          .catch(() => resolve(true));
      });
    },
    revalidateDate() {
      const date = this.holiday.date;
      if (!date) return;
      // Field validation only runs when its value changes
      this.holiday.date = '';
      this.$nextTick(() => {
        this.holiday.date = date;
      });
    },
    onSave() {
      this.isLoading = true;
      this.http
        .update(this.holidayId, {
          name: this.holiday.name,
          date: this.holiday.date,
          recurring: this.holiday.recurring,
          length: this.holiday.length.id,
          locationId: this.holiday.location?.id || null,
        })
        .then(() => {
          return this.$toast.updateSuccess();
        })
        .then(() => {
          this.onCancel();
        })
        .catch(() => {
          this.isLoading = false;
        });
    },
    onCancel() {
      navigate('/leave/viewHolidayList');
    },
  },
};
</script>

<style src="./holiday.scss" lang="scss" scoped></style>
