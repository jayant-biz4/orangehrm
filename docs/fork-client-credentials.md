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
  employee, and that employee not terminated.
- The access token lifetime is `oauth.access_token_ttl`, the same as for the other grants. No
  refresh token is issued, so the client asks for a new token when the old one expires.

### When an issued token stops working

Tokens from this grant are flagged (`ohrm_oauth2_access_token.is_client_credentials`). On every
request, `OAuthSubscriber` re-checks the client of a flagged token: it must still exist, be enabled
and confidential (with a secret), and still be linked to the token's user. The user must still be
able to sign in. So a token is refused on the next request after any of these, however they are made
(admin UI, console or SQL):

- the client is disabled, deleted or switched to public;
- the link is removed or points to another user;
- the user is disabled or deleted, or the employee is terminated.

Tokens from the other grants (mobile app, authorization code) skip the client re-check, as upstream.

These actions also revoke tokens, including their refresh tokens:

- **Admin UI (OAuthClientAPI update).** Disabling a client, or changing whether it is confidential,
  revokes all of the client's tokens. A secret can only change through that confidential toggle.
  Switching a client to public also clears its link, so switching it back to confidential does not
  quietly turn the grant on again. Link it again with the console.
- **Console `--disable`.** Revokes all of the client's tokens, even if the link was already gone.
- **Console relink to another user.** Revokes the client's tokens for the previous user.

## Deploy (in this order)

1. **Back up the database.**
2. **Put the schema in place before the new code is live**, or put the site in maintenance mode for
   steps 2 to 4. The new code maps the new columns, so with the code live and the schema not yet in
   place, `/oauth2/token`, `/oauth2/authorize` (mobile sign-in), the admin OAuth screen and every
   bearer-token API call (the mobile app included) fail with SQL errors. Put the schema in place in
   one of these ways:
   - Upgrading from 5.9 or earlier: run the upgrader. The fork step runs automatically, as the
     second step of `'5.9.1'`.
   - Instance already at 5.9.1, or the fork step failed after upstream's 5.9.1 step (the upgrader
     will not retry it, because `instance.version` is already 5.9.1):
     `php bin/console orangehrm:oauth:client-credentials-schema`. This command ships with the new
     code, so on an instance already at 5.9.1 use maintenance mode: deploy the code, run the command,
     clear the cache (step 4), then leave maintenance mode.
     It checks each piece separately: the column, its index, the foreign key and the token flag
     column. It is safe to run any number of times. It needs a database user that can ALTER.
3. **Deploy the code.**
4. **Clear the cache:** `php bin/console cache:clear` (or `rm -rf src/cache/doctrine_metadata`),
   then `php bin/console orm:generate-proxies`. In prod, Doctrine metadata is cached on disk. With a
   stale cache the link command "succeeds" but never saves, and every client reads as unlinked.
5. **Link the client** (see below). The command must print `acts as \`<user>\` (active)`.
6. **Smoke test:** get a token, then call an API endpoint, for example
   `GET /index.php/api/v2/leave/leave-types`, and expect 200.

## How to enable

1. Admin > Configuration > Register OAuth Client: add a client and keep it **Confidential**. The
   redirect URI is required but not used. Set it to a URL you control, not a placeholder domain.
   Copy the client secret, because it is shown only once.
2. Pick the user the app acts as. Use a dedicated user, with its own employee record that never
   applies for leave, and give it the least role the app needs. For example, Admin can approve or
   reject anyone's leave, and a Supervisor can do so only for their subordinates.
3. Link it:

   ```bash
   php bin/console orangehrm:oauth:client-credentials <client_id> <username>   # enable
   php bin/console orangehrm:oauth:client-credentials <client_id>              # show
   php bin/console orangehrm:oauth:client-credentials <client_id> --disable    # disable + revoke all tokens
   ```

4. Get a token. Send the credentials in the body, or with HTTP Basic:

   ```bash
   curl -s https://hr.example.com/index.php/oauth2/token \
     -d grant_type=client_credentials -d client_id=$ID -d client_secret=$SECRET
   # {"token_type":"Bearer","expires_in":1800,"access_token":"def50200..."}
   ```

5. Call the API with `Authorization: Bearer <token>` and `Accept: application/json`. The calls need
   no session cookie and no CSRF token. Don't keep cookies between calls (see the notes below).

   ```
   PUT  /index.php/api/v2/leave/employees/leave-requests/{id}       {"action": "APPROVE"} or {"action": "REJECT"}
   POST /index.php/api/v2/leave/leave-requests/{id}/leave-comments  {"comment": "..."}   (max 255 chars)
   ```

## Database migration

`installer/Migration/Fork/ClientCredentials/Migration.php` is the second step of the `'5.9.1'`
entry in `AppSetupUtility::MIGRATIONS_MAP`. Its `getVersion()` returns `5.9.1`, so `instance.version`
stays an upstream value and the upgrader still recognises the instance. A fresh install, or an
upgrade from 5.9 or earlier, runs it automatically. It delegates to `SchemaUpdate`, which the console
command `orangehrm:oauth:client-credentials-schema` also uses.

## Other changes in the same patch

- `OAuthSubscriber` evaluates only `Bearer` Authorization headers. Upstream tried to evaluate any
  Authorization header as a bearer token, which broke HTTP Basic client authentication on
  `/oauth2/token`. The scheme is case-insensitive in both the subscriber and `BearerTokenValidator`.
- `OAuthSubscriber` refuses a token whose user is deleted. Upstream later failed such a request with
  "no user found", so this only refuses it earlier.
- `ClientRepository::validateClientSecret` refuses a request without `client_secret`. Upstream threw
  a TypeError, which the token endpoint returned as a 500 error.

## Notes and hardening (not addressed in code)

- **Race when revoking.** The console saves the link first and revokes second. A token issued in
  that gap survives revocation, but the per-request re-check still refuses it, because the link has
  changed.
- **Auth-code tokens on the same client.** Revoking by client also revokes auth-code sessions on that
  client. Use a dedicated client for the bot.
- **Sessions (pre-existing upstream).** Bearer requests store the authenticated state in the PHP
  session, and `OAuthSubscriber` invalidates the session at response time. If a request dies before
  that (fatal error, timeout), a client that kept the session cookie stays authenticated by cookie
  until the idle timeout, and token revocation no longer applies to it. The user checks still apply.
  In the Python client, don't persist cookies: clear the jar, or use no `requests.Session` cookies.
- **Browser sign-in.** The bot user can also sign in through the browser if it has a usable
  password. Give it a long random password nobody keeps.
- **No admin re-verification.** An Admin-linked token gets the full admin REST API without
  re-verification, including user and OAuth client management. Prefer a Supervisor-scoped user.
- **Token endpoint load.** `/oauth2/token` runs a bcrypt verify (cost 12) for any known `client_id`
  without authentication. Rate-limit it at the web server.
- **No scopes.** OrangeHRM has none, so a token carries all of the linked user's permissions.

## Files

| File | Change |
|---|---|
| `src/plugins/orangehrmCoreOAuthPlugin/Server/ClientCredentialsGrant.php` | new: League grant issuing a flagged token to the linked user |
| `src/plugins/orangehrmCoreOAuthPlugin/Server/OAuthServer.php` | enable the grant |
| `src/plugins/orangehrmCoreOAuthPlugin/Server/BearerTokenValidator.php` | case-insensitive `Bearer` |
| `src/plugins/orangehrmCoreOAuthPlugin/Repository/ClientRepository.php` | refuse unlinked, public or inactive links; `getClientCredentialsUserId()` |
| `src/plugins/orangehrmCoreOAuthPlugin/Repository/AccessTokenRepository.php` | `revokeTokensForClient()` (access and refresh), `markAsClientCredentials()` |
| `src/plugins/orangehrmCoreOAuthPlugin/entity/OAuthClient.php` | link column |
| `src/plugins/orangehrmCoreOAuthPlugin/entity/OAuthAccessToken.php` | `is_client_credentials` flag |
| `src/plugins/orangehrmCoreOAuthPlugin/Subscriber/OAuthSubscriber.php` | Bearer-only; per-request client and link re-check; refuse deleted users |
| `src/plugins/orangehrmCoreOAuthPlugin/Api/OAuthClientAPI.php` | clear link when public; revoke on disable or confidentiality change |
| `src/plugins/orangehrmCoreOAuthPlugin/Command/ClientCredentialsCommand.php` | link command |
| `src/plugins/orangehrmCoreOAuthPlugin/Command/ClientCredentialsSchemaCommand.php` | schema (re-)apply command |
| `src/plugins/orangehrmCoreOAuthPlugin/config/CoreOAuthPluginConfiguration.php` | register the commands |
| `installer/Migration/Fork/ClientCredentials/Migration.php`, `SchemaUpdate.php` | migration |
| `installer/Util/AppSetupUtility.php` | add it to the `'5.9.1'` entry |
| `src/plugins/orangehrmCoreOAuthPlugin/test/...` (`ClientCredentials*`, `OAuthClientCredentialsUpdateTest`) | tests and fixture |

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
