# Fork patch: location-based holidays

Not part of upstream OrangeHRM. Added on top of 5.9.1 (branch `feat/location-holidays`).

## What and why

Upstream holidays are global: every employee gets every holiday. The fork lets a holiday belong to
one location (Admin > Organization > Locations), so staff in different countries get their own
holidays. The employee's location is the one on their Job Details (`EmployeeDecorator::getLocation()`).

`ohrm_holiday.location_id` is a nullable column with a foreign key to `ohrm_location.id`
(ON DELETE CASCADE) and an index on `(location_id, date)`.

### Rules

| # | Rule |
|---|---|
| R1 | `location_id IS NULL` is an org-wide holiday. Otherwise the holiday applies only to employees of that location. |
| R2 | A date cannot hold both an org-wide holiday and a location holiday. |
| R3 | A date cannot hold two holidays for the same location. |
| R4 | A holiday on every location must be org-wide: a location holiday is rejected if, once saved, every row in `ohrm_location` would have a holiday on that date. Checked only when a holiday is saved. |
| R5 | Different locations may have holidays on the same date. |
| R6 | "Same date" means an equal date, or an equal month and day when either holiday is recurring. |
| R7 | An employee with no location gets org-wide holidays only. |
| R8 | Deleting a location deletes its holidays (foreign key cascade). |
| R9 | Leave that is already applied is never recalculated (holiday change, location change, location delete). |
| R10 | A new location inherits every org-wide holiday. There is no review step. |
| R11 | Existing holidays stay org-wide (`NULL`) on upgrade, so nothing changes until an admin assigns a location. |

R2 to R6 guarantee at most one holiday per employee per date, so `HolidayDao::fetchOne()` stays
correct once the location filter is applied.

### Documented behaviours

- **R9.** Changing a holiday, moving an employee to another location, or deleting a location does
  not touch leave already applied. Cancel and re-apply the leave if the days must change.
- **R10.** Adding a location does not copy or ask about holidays. It simply has the org-wide ones.
- **Work schedules.** `BasicWorkSchedule` passes the employee number, so it resolves per location.
  A custom `WorkScheduleInterface` implementation stays global (all holidays) until it passes
  `empNumber` to the holiday service.
- **Date pickers.** On the Holiday admin pages (list, add, edit) the date pickers shade all holidays
  (`DateInput` prop `allHolidays`). Every other date picker shades the signed-in user's effective
  holidays (`currentEmployee=true`). Assign Leave shades the selected employee's holidays.
- **Known limitation.** Pages that pick dates for another employee without passing an emp-number
  shade the viewer's holidays, not that employee's: Leave List filter, Time (AddTimesheetModal,
  EmployeeTimeReport) and Attendance (ViewEmployeeAttendanceDetailed, EditAttendance,
  AttendanceSummaryReport). This is only the shading. Leave calculation is unaffected.

Saving (including renaming) or deleting a location also clears the holiday cache, because cached
holiday lists embed the location name.

## Deploy (in this order)

1. **Pre-deploy check.** Run:

   ```sql
   SELECT emp_number, COUNT(*) AS n FROM hs_hr_emp_locations GROUP BY emp_number HAVING n > 1;
   ```

   It must return no rows. An employee listed has several locations, and holiday resolution would
   use only the first. Fix those rows in Job Details before deploying.
2. **Back up the database.**
3. **Schema must exist before the new code serves requests.** The new code maps `location_id`, so
   with the code live and the schema missing, holiday and leave calls fail with SQL errors.
   - Upgrading from 5.9 or earlier: run the upgrader. The fork step runs automatically, as the last
     step of `'5.9.1'`. This is the only path that can run the schema first.
   - Instance already at 5.9.1, or the fork step failed after upstream's 5.9.1 step (the upgrader
     will not retry it): `php bin/console orangehrm:leave:location-holidays-schema`. The command
     ships with the new code, so the new code is live before the schema exists on this path.
     **Maintenance mode is required** from deploying the code until the command has run and the
     cache is cleared (step 5). It adds the column, index, foreign key, lang strings and API
     permission if missing, and is safe to run any number of times. It needs a database user that
     can ALTER.
4. **Deploy the code.** The compiled frontend (`web/dist`) is committed (commit "build: rebuild
   frontend for location holidays"), so no frontend build is needed.
5. **Clear the cache:** `php bin/console cache:clear`, then `php bin/console orm:generate-proxies`.
   With stale Doctrine metadata, `location_id` is saved as NULL (seen in dev).

## Rollback

Restore the code first, then the schema. Take a backup before doing either.

**If any location holidays exist, restore the database from backup instead.** Dropping
`location_id` removes the distinction: every location holiday becomes an org-wide holiday and
applies to all employees.

Manual schema rollback:

```sql
ALTER TABLE ohrm_holiday DROP FOREIGN KEY holiday_location_id;
ALTER TABLE ohrm_holiday DROP INDEX holiday_location_id_date;
ALTER TABLE ohrm_holiday DROP COLUMN location_id;

DELETE urdg FROM ohrm_user_role_data_group urdg
  JOIN ohrm_data_group dg ON dg.id = urdg.data_group_id
  WHERE dg.name = 'apiv2_leave_holiday_date_validation';
DELETE ap FROM ohrm_api_permission ap
  JOIN ohrm_data_group dg ON dg.id = ap.data_group_id
  WHERE dg.name = 'apiv2_leave_holiday_date_validation';
DELETE FROM ohrm_data_group WHERE name = 'apiv2_leave_holiday_date_validation';
```

The lang strings may stay; they are unused. Then clear the cache (`php bin/console cache:clear`).

## API changes (`/api/v2/leave/holidays`)

| Call | New parameter | Meaning |
|---|---|---|
| POST, PUT `/{id}` body | `locationId: int\|null` | `null` or absent on POST = org-wide. On PUT, absent = unchanged and `null` = org-wide. Must be an existing location id. R2 to R4 give 422 on `date`. |
| GET list query | `locationId` | Only that location's holidays. |
| GET list query | `orgWideOnly=true` | Only org-wide holidays. |
| GET list query | `empNumber` | Holidays effective for that employee (org-wide plus own location). Must be accessible to the caller, else 403. |
| GET list query | `currentEmployee=true` | Same as `empNumber`, for the signed-in user. |
| Response | `location: {id, name} \| null` | `null` = org-wide. |

When several filters are sent: `empNumber` > `currentEmployee` > `orgWideOnly` > `locationId`. With
none, all holidays are returned, as before.

New endpoint: `GET /api/v2/leave/validation/holiday-date?date=&recurring=&locationId=&holidayId=`
returns `{"valid": bool, "reason": null|"clash"|"allLocations"}`. Admin read only (API permission
seeded by the fork step). It runs the same service check as save and powers live form validation.

## Database migration

`installer/Migration/Fork/LocationHolidays/Migration.php` is the last step of the `'5.9.1'` entry in
`AppSetupUtility::MIGRATIONS_MAP`. Its `getVersion()` returns `5.9.1`, so `instance.version` stays an
upstream value. It delegates to `SchemaUpdate::apply()` (idempotent), which the console command also
uses. The lang strings (`lang-string/leave.yaml`) and the API permission (`permission/api.yaml`) are
seeded there too.

## Files

| File | Change |
|---|---|
| `installer/Migration/Fork/LocationHolidays/*` | migration, `SchemaUpdate`, lang strings, API permission |
| `installer/Util/AppSetupUtility.php` | add the step to `'5.9.1'` |
| `src/plugins/orangehrmLeavePlugin/entity/Holiday.php`, `entity/Decorator/HolidayDecorator.php` | `location` mapping, same-date and location helpers |
| `src/plugins/orangehrmLeavePlugin/Dao/HolidayDao.php` | location filters, per-employee resolution, clash queries |
| `src/plugins/orangehrmLeavePlugin/Service/HolidayService.php` | R2 to R4 check, per-employee holidays |
| `src/plugins/orangehrmLeavePlugin/WorkSchedule/BasicWorkSchedule.php` | pass `empNumber` |
| `src/plugins/orangehrmLeavePlugin/Api/HolidayAPI.php`, `Api/Model/HolidayModel.php`, `Dto/HolidaySearchFilterParams.php` | location in the API |
| `src/plugins/orangehrmLeavePlugin/Api/HolidayDateValidationAPI.php`, `config/routes.yaml` | validation endpoint |
| `src/plugins/orangehrmLeavePlugin/Command/LocationHolidaysSchemaCommand.php`, `config/LeavePluginConfiguration.php` | schema command |
| `src/plugins/orangehrmLeavePlugin/Controller/HolidayController.php`, `SaveHolidayController.php` | page props |
| `src/plugins/orangehrmAdminPlugin/Service/LocationService.php` | clear the holiday cache on location save and delete |
| `src/client/src/core/components/inputs/DateInput.vue`, `core/util/helper/holidayQuery.ts` | shading per employee |
| `src/client/src/orangehrmLeavePlugin/pages/configure/holiday/*`, `pages/LeaveAssign.vue` | location field, live validation |
| `web/dist/*` | rebuilt frontend |
| tests and fixtures under `orangehrmLeavePlugin/test` and `orangehrmAdminPlugin/test` | coverage |

## Re-applying on an upstream upgrade

1. Merge or rebase upstream. Likely conflicts: `HolidayDao`, `HolidayService`, `HolidayAPI`,
   `DateInput.vue` and the holiday pages.
2. `AppSetupUtility::MIGRATIONS_MAP`: keep the fork migration inside the `'5.9.1'` array, after the
   client credentials step. Do not move or drop it, because a fresh install needs it.
3. Run `./src/vendor/bin/phpunit --testsuite Leave` and `--testsuite Admin`, then rebuild and commit
   `web/dist`.
4. The repository `.gitignore` ignores `*.md`, so this file is tracked with `git add -f`.
