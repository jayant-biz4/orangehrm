# Fork patch: client credentials grant

Not part of upstream OrangeHRM. Added on top of 5.9.1 (branch `feat/client-credentials`).

## What and why

The fork adds the OAuth 2.0 client credentials grant (`grant_type=client_credentials`) to the
existing League OAuth server. A server-side app (our HR automation) can then call the REST API v2
without a browser sign-in. It can approve or reject leave, add comments, and so on.

Every API permission check in OrangeHRM runs against a user. So a client is allowed this grant only
when an admin links it to an OrangeHRM user. The tokens it gets then act as that user. The user's
role, the data groups, the supervisor checks and the leave workflow all apply as they would to that
user signed in through the browser. There is no other way to reach the API.

- `ohrm_oauth2_client.client_credentials_user_id` is a nullable column, with a foreign key to
  `ohrm_user.id` (ON DELETE SET NULL). NULL means the grant is refused, so existing clients are
  unaffected.
- The client must be confidential and must send its secret. The secret is checked as before, with
  bcrypt via `password_verify`.
- To get a token, the linked user must be able to sign in: not deleted, enabled, linked to an
  employee, and that employee not terminated. The same checks run on every API call with the token,
  so an existing token stops working when the user is disabled, deleted or terminated.
- The access token lifetime is `oauth.access_token_ttl`, the same as for the other grants. No
  refresh token is issued, so the client asks for a new token when the old one expires.
- `--disable`, or linking the client to another user, also revokes the access tokens that client
  holds for the previous user.

## How to enable

1. Admin > Configuration > Register OAuth Client: add a client and keep it **Confidential**. The
   redirect URI is required by the form but not used. Copy the client secret, because it is shown
   only once.
2. Pick the user the app acts as. Use a dedicated user, with its own employee record that never
   applies for leave, and give it the role the app needs. For example, Admin can approve or reject
   anyone's leave, and a Supervisor can do so only for their subordinates.
3. Link it:

   ```bash
   php bin/console orangehrm:oauth:client-credentials <client_id> <username>   # enable
   php bin/console orangehrm:oauth:client-credentials <client_id>              # show
   php bin/console orangehrm:oauth:client-credentials <client_id> --disable    # disable + revoke
   ```

4. Get a token. Send the credentials in the body, or with HTTP Basic:

   ```bash
   curl -s https://hr.example.com/index.php/oauth2/token \
     -d grant_type=client_credentials -d client_id=$ID -d client_secret=$SECRET
   # {"token_type":"Bearer","expires_in":1800,"access_token":"def50200..."}
   ```

5. Call the API with `Authorization: Bearer <token>` and `Accept: application/json`. The calls need
   no session cookie and no CSRF token:

   ```
   PUT  /index.php/api/v2/leave/employees/leave-requests/{id}       {"action": "APPROVE"} or {"action": "REJECT"}
   POST /index.php/api/v2/leave/leave-requests/{id}/leave-comments  {"comment": "..."}   (max 255 chars)
   ```

## Database migration

`installer/Migration/Fork/ClientCredentials/Migration.php` is the second step of the `'5.9.1'`
entry in `AppSetupUtility::MIGRATIONS_MAP`. Its `getVersion()` returns `5.9.1`, so `instance.version`
stays an upstream value and the upgrader still recognises the instance. A fresh install, or an
upgrade from 5.9 or earlier, runs it automatically. It is idempotent.

**The code needs the column.** Doctrine maps the column, so every OAuth client query fails without
it. That includes the mobile app and the admin OAuth screen. Run the migration before you deploy the
code. An instance that is **already at 5.9.1** without the column does not run it on upgrade. Add
the column by hand on such an instance:

```sql
ALTER TABLE ohrm_oauth2_client ADD client_credentials_user_id INT NULL DEFAULT NULL,
  ADD CONSTRAINT oauth2_client_client_credentials_user_id FOREIGN KEY (client_credentials_user_id)
  REFERENCES ohrm_user (id) ON DELETE SET NULL;
```

You can also run
`php devTools/core/console.php migration:up "\OrangeHRM\Installer\Migration\Fork\ClientCredentials\Migration"`.

## Other changes in the same patch

- `OAuthSubscriber` evaluates only `Bearer` Authorization headers. Upstream tried to evaluate any
  Authorization header as a bearer token, which broke HTTP Basic client authentication on
  `/oauth2/token`.
- `OAuthSubscriber` refuses a token whose user is deleted. Upstream later failed such a request with
  "no user found", so this only refuses it earlier.
- `ClientRepository::validateClientSecret` refuses a request without `client_secret`. Upstream threw
  a TypeError, which the token endpoint returned as a 500 error.

## Files

| File | Change |
|---|---|
| `src/plugins/orangehrmCoreOAuthPlugin/Server/ClientCredentialsGrant.php` | new: League grant issuing the token to the linked user |
| `src/plugins/orangehrmCoreOAuthPlugin/Server/OAuthServer.php` | enable the grant |
| `src/plugins/orangehrmCoreOAuthPlugin/Repository/ClientRepository.php` | refuse unlinked or inactive links; `getClientCredentialsUserId()` |
| `src/plugins/orangehrmCoreOAuthPlugin/Repository/AccessTokenRepository.php` | `revokeAccessTokensForClientUser()` |
| `src/plugins/orangehrmCoreOAuthPlugin/entity/OAuthClient.php` | new column |
| `src/plugins/orangehrmCoreOAuthPlugin/Subscriber/OAuthSubscriber.php` | Bearer-only; refuse deleted users |
| `src/plugins/orangehrmCoreOAuthPlugin/Command/ClientCredentialsCommand.php` | new console command |
| `src/plugins/orangehrmCoreOAuthPlugin/config/CoreOAuthPluginConfiguration.php` | register the command |
| `installer/Migration/Fork/ClientCredentials/Migration.php` | new migration |
| `installer/Util/AppSetupUtility.php` | add it to the `'5.9.1'` entry |
| `src/plugins/orangehrmCoreOAuthPlugin/test/...ClientCredentials*` | tests and fixture |

Every edit to an upstream file is marked with a `Fork patch` comment (`git grep "Fork patch"`).

## Re-applying on an upstream upgrade

1. Merge or rebase upstream. Conflicts can only occur in the files listed above, and they are small.
2. `AppSetupUtility::MIGRATIONS_MAP`: keep the fork migration inside the `'5.9.1'` array. Upstream
   adds new versions after it. Do not move the fork migration to a newer version, and do not drop
   it, because a fresh install needs it.
3. If upstream changes League (`league/oauth2-server`), check that
   `AbstractGrant::issueAccessToken()` keeps its signature. `ClientCredentialsGrant` overrides it.
4. Run `./src/vendor/bin/phpunit --testsuite OAuth`.
5. Note that the repository `.gitignore` ignores `*.md`, so this file is tracked with `git add -f`.
