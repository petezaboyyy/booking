# Microsoft SSO for Booking

## Personal account demo enrollment

The local Compose service enables enrollment for the operator's demo account via
`BOOKING_MICROSOFT_DEMO_EMAIL`. With this set, OAuth uses `common`; token validation
still accepts only the configured university tenant or Microsoft's consumer tenant.
The App Registration must support organizational **and personal Microsoft accounts**.
The configured university tenant ID and allowed domains remain unchanged.

The first personal login displays its verified `oid` and an email hint, but grants
no application access. The operator must confirm the intended Microsoft account
and set `BOOKING_MICROSOFT_DEMO_OID` in the deployment environment (or local `.env`),
then recreate the web service. Email claims alone never grant access or admin rights.
Only that pinned consumer identity can subsequently sign in. It receives admin
status when first created; existing roles and later demotions are preserved.
Local email collisions are rejected, never automatically linked.

Before delivery remove both demo environment settings, revoke the demo account's
sessions and disable that account. Keep a working administrative login until the
customer's Microsoft administrator has been verified; disabling password and other
login methods is a separate remaining rollout step.

Server-side authorization code flow with OIDC, PKCE S256 and a single university tenant. Microsoft tokens remain on the server; Booking issues its own HttpOnly authentication cookie. This integration sends no welcome email or Microsoft notifications.

## Configure Entra

1. Register a **single tenant** application. Use a Web redirect URI of `https://YOUR-HOST/microsoft/callback.php` (include the Booking subdirectory if applicable).
2. Create a client secret; copy its **Value**, not its secret ID. Record its expiry for rotation.
3. Configure delegated scopes `openid`, `profile`, `email`, `User.Read`. Apply administrator consent if your university policy requires it. No application permissions or `offline_access` are needed.
4. In the Enterprise Application, set **Assignment required = Yes** and assign only eligible staff. This is required for staff-only access when students share the tenant/domain. The application checks tenant, exact email domain and Graph `userType=Member`; it does not infer employment from an email address. Guest accounts are denied.

## Configure Booking

For local development, copy `.env.example` to `.env` and fill in the Microsoft
values. Each developer keeps their own `.env`; Git ignores it. For an automated
deployment, add the same names to the hosting provider's environment variables.
`BOOKING_MICROSOFT_CLIENT_SECRET` must never be committed to the repository.

Run the additive migration against the target database before enabling sign-in:

```sh
docker compose exec web php scripts/migrate_microsoft.php
```

Without Docker, run `php scripts/migrate_microsoft.php` using the deployment's database environment. The migration can be repeated. The normal installer schema and upgrade flow also include the columns. Docker imports based on older database dumps still need this migration.

In **General Settings → Microsoft SSO**, enter:

- Application (client) ID: GUID.
- Directory (tenant) ID: GUID; `common`, `organizations` and personal accounts are not accepted.
- Allowed university email domains: exact domains separated by commas, e.g. `university.ac.th, staff.university.ac.th`. Subdomains must be listed explicitly.
- Redirect URI: exactly the URI registered in Entra, including scheme, host, port and path. Production requires HTTPS. Local development permits `http://localhost:8080/microsoft/callback.php` when registered in Entra.
- Client secret value: the value is stored on the server, never returned by settings/config APIs. An empty password field preserves the existing secret. Clear the client ID to disable sign-in.

The Microsoft sign-in button is always visible, including when public registration is disabled. Until all SSO fields are complete, it is disabled and displays an unavailable message. Store real credentials only in the deployment settings, not in version control. When TLS terminates at a reverse proxy, configure trusted HTTPS forwarding and strip client-supplied forwarding headers; the integration uses the framework's HTTPS detection for Secure cookies. PHP session storage must persist across callbacks; multiple web instances need shared sessions or sticky routing. PHP cURL and OpenSSL extensions and outbound HTTPS to Microsoft login/Graph endpoints are required.

## Account behavior

- New Microsoft users receive status `0` and no elevated permissions.
- Identity is matched by `(microsoft_tenant_id, microsoft_id)` with a unique database index; the ID is the validated token `oid` and must equal Graph `/me`'s `id`.
- An email already belonging to another local identity is rejected. There is no automatic email linking or public endpoint that accepts Microsoft identity fields. A user-facing account-linking workflow is outside this first implementation.
- Existing disabled or pending-verification accounts are denied.
- Email changes do not create another account when the Microsoft identity is already linked. The existing local username remains unchanged.
- Booking's existing session lifetime and logout remain in effect. Removing Entra assignment prevents subsequent Microsoft sign-ins; it does not instantly revoke an already-issued Booking token. This integration does not disable existing local/social sign-in methods.

## Security and verification

The start route stores a random state, nonce, PKCE verifier, configuration snapshot and internal return path in a browser-bound session for ten minutes. State is consumed once. The callback validates RS256 signature against the tenant's HTTPS JWKS, issuer, audience, tenant, token lifetime, version and nonce before requesting Graph. Unknown keys fail closed; keys are fetched each login to support rotation. Microsoft failures do not expose raw tokens or upstream responses.

```sh
docker compose exec web php scripts/verify_microsoft.php
docker compose exec web php scripts/verify_microsoft.php --database
docker compose exec web php scripts/verify_microsoft_http.php
```

The HTTP suite runs an isolated loopback server with in-memory fixture configuration and checks redirects, cookie flags, browser binding and state replay. The database suite creates fixtures inside a transaction and rolls them back. No real Entra account or network access is needed for these tests. Before rollout, separately test a real assigned staff member, an unassigned member, a student, a guest, cancellation, a disabled Booking account, and logout/login in the deployed HTTPS environment. Verify that wrong-tenant and wrong-domain accounts cannot enter and that the intended Booking page opens after login.

References: [Microsoft authorization code flow](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow), [ID token claims](https://learn.microsoft.com/en-us/entra/identity-platform/id-token-claims-reference), [Graph user profile and delegated permissions](https://learn.microsoft.com/en-us/graph/api/user-get?view=graph-rest-1.0), [Restrict assigned users](https://learn.microsoft.com/en-us/entra/identity-platform/howto-restrict-your-app-to-a-set-of-users).
