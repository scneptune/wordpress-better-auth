# Better Auth WordPress Plugin

[![Tests (Latest Tag)](https://img.shields.io/github/actions/workflow/status/scneptune/wordpress-better-auth/phpunit.yml?event=push&label=tests%20(latest%20tag))](https://github.com/scneptune/wordpress-better-auth/actions/workflows/phpunit.yml)
[![Latest Tag](https://img.shields.io/github/v/tag/scneptune/wordpress-better-auth?sort=semver)](https://github.com/scneptune/wordpress-better-auth/releases)
[![Codecov](https://img.shields.io/codecov/c/gh/scneptune/wordpress-better-auth)](https://app.codecov.io/gh/scneptune/wordpress-better-auth)

This plugin helps you run Better Auth with a headless WordPress stack by:

- Creating and maintaining Better Auth core schema tables inside your WordPress database.
- Exposing REST API routes to create/link WordPress users from Better Auth users.
- Exposing WooCommerce-aware profile sync routes for billing and shipping.
- Protecting sync routes with replay-safe HMAC request signing.

## Important Scope (Read This First)

**This plugin does not provide login credentials, password validation, session issuance, or user authentication UI.**

**Better Auth is the authentication system for sign up and sign in.**

This plugin acts as a **post-signup/post-signin sync sidecar** for WordPress and WooCommerce data:

- It creates the database tables Better Auth needs in WordPress.
- It links Better Auth users to WordPress users.
- It stores WordPress/WooCommerce profile data related to that user link.

### Authentication vs Sync Flow (ASCII)

```text
[User]
  |
  | 1) Sign up / Sign in
  v
[Your App + Better Auth]
  |  (Better Auth validates credentials, creates sessions/tokens)
  |
  | 2) Server-to-server sync call (HMAC signed)
  v
[WordPress Better Auth Plugin]
  |-- ensures Better Auth schema tables exist (ba_user/ba_session/ba_account/ba_verification)
  |-- creates/links WP user (and Woo customer when available)
  |-- stores WordPress-side profile/address metadata
  v
[WordPress / WooCommerce data sidecar]
```

In short: **Better Auth authenticates. This plugin synchronizes and stores related WordPress-side data.**

## What This Plugin Does

At activation, the plugin creates Better Auth schema tables (if missing) via `dbDelta()`:

- `{wp_prefix}ba_user`
- `{wp_prefix}ba_session`
- `{wp_prefix}ba_account`
- `{wp_prefix}ba_verification`

During runtime, it provides sync endpoints for Better Auth -> WordPress/WooCommerce user data flows.

## Key Management

Under `Settings -> General -> Better Auth` you can:

- Generate credentials (key id + secret)
- Revoke active keys
- Rotate active credentials

Secrets are shown one-time at generation and then hidden.

## HMAC Signing Guide (ALL API ROUTES MUST BE HMAC SIGNED)

Each request must include:

- `X-BA-Key-Id`
- `X-BA-Timestamp` (unix seconds)
- `X-BA-Nonce` (unique per request)
- `X-BA-Signature`

Signature payload format:

```text
METHOD + "\n" + ROUTE + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + SHA256(RAW_BODY)
```

Then:

```text
X-BA-Signature = HMAC_SHA256(payload, client_secret)
```

Important:

- `ROUTE` must match WordPress route string exactly (example: `/better-auth/v1/create-user`).
- `RAW_BODY` must be exactly the JSON you send on the wire.
- Nonces are one-time-use (replay protected).
- Timestamp drift tolerance is 300 seconds.

### Node.js Example of HMAC-Signed REST Requests

```js
import crypto from "node:crypto";

const keyId = process.env.BA_KEY_ID;
const secret = process.env.BA_CLIENT_SECRET;
const wpBaseUrl = process.env.WP_BASE_URL;

function signRequest({ method, route, rawBody, secret }) {
  const timestamp = String(Math.floor(Date.now() / 1000));
  const nonce = crypto.randomUUID();
  const bodyHash = crypto.createHash("sha256").update(rawBody, "utf8").digest("hex");
  const payload = [method, route, timestamp, nonce, bodyHash].join("\n");
  const signature = crypto.createHmac("sha256", secret).update(payload, "utf8").digest("hex");

  return { timestamp, nonce, signature };
}

async function signedFetch({ baseUrl, route, method, body, keyId, secret }) {
  const rawBody = JSON.stringify(body);
  const { timestamp, nonce, signature } = signRequest({
    method,
    route,
    rawBody,
    secret
  });

  return fetch(`${baseUrl}/wp-json${route}`, {
    method,
    headers: {
      "Content-Type": "application/json",
      "X-BA-Key-Id": keyId,
      "X-BA-Timestamp": timestamp,
      "X-BA-Nonce": nonce,
      "X-BA-Signature": signature
    },
    body: rawBody
  });
}

await signedFetch({
  baseUrl: wpBaseUrl,
  route: "/better-auth/v1/sync/billing",
  method: "PATCH",
  keyId,
  secret,
  body: {
    ba_user_id: "ba_123",
    billing_address: {
      first_name: "Jane",
      last_name: "Doe",
      address_1: "123 Main St",
      city: "Austin",
      state: "TX",
      postcode: "78701",
      country: "US",
      email: "jane@example.com",
      phone: "555-1111"
    }
  }
});
```

## Available REST API Routes

Base namespace: `better-auth/v1`

### 1) `POST /create-user`

Creates or links a WordPress user from Better Auth user payload.

Authentication:

- HMAC headers (`X-BA-Key-Id`, `X-BA-Timestamp`, `X-BA-Nonce`, `X-BA-Signature`).

Body fields:

- `email` (required)
- `ba_user_id` (required)
- `name` (optional)
- `phone` (optional)
- `otp_method` (optional)

Typically you would use this like:

```typescript
// in your auth.ts file:
//....
export const auth = betterAuth({
  //...
  user: {
    // the tables created by the plugin follow this schema.
    modelName: "wp_ba_user",
  },
  databaseHooks: {
    user: {
      create: {
        after: async (user) => {
          await signedFetch({
            baseUrl: env.WP_BASE_URL,
            route: "/better-auth/v1/create-user",
            method: "POST",
            keyId: env.BA_KEY_ID,
            secret: env.BA_CLIENT_SECRET,
            body: {
              ba_user_id: user.id,
              email: user.email,
              name: user.name,
              phone: user.phoneNumber,
              otp_method: user.otp_method,
            },
          });
        }
      }
    }
  //...
});
```

### 2) `PATCH /sync/billing`

Syncs billing address data to WordPress user meta and WooCommerce customer data.

Authentication:

- HMAC headers.

Body fields:

- `ba_user_id` (required)
- `billing_address` (required object)

`billing_address` supports:

- `first_name`, `last_name`, `address_1`, `address_2`, `city`, `state`, `postcode`, `country`, `email`, `phone`

Notes:

- Only registered when WooCommerce (`WooCommerce` + `WC_Customer`) is available.

### 3) `PATCH /sync/shipping`

Syncs shipping address data to WordPress user meta and WooCommerce customer data.

Authentication:

- HMAC headers.

Body fields:

- `ba_user_id` (required)
- `shipping_address` (required object)

`shipping_address` supports:

- `first_name`, `last_name`, `address_1`, `address_2`, `city`, `state`, `postcode`, `country`

Notes:

- Only registered when WooCommerce (`WooCommerce` + `WC_Customer`) is available.

## Database Tables Added

On activation, the plugin creates:

- `{prefix}ba_user`
- `{prefix}ba_session`
- `{prefix}ba_account`
- `{prefix}ba_verification`

These tables map to Better Auth core model data and are created/updated via WordPress `dbDelta()` migrations.

Please review [Better Auth's Core Schema](https://better-auth.com/docs/concepts/database#core-schema) for individual table schema. Keep in mind in your database models in `auth.ts`
file will need to be prefixed with `wp_ba_` ; like:

```TypeScript
// in your auth.ts file:
 //....
 export const auth = betterAuth({
  database: prismaAdapter(prismaClient, { 
    // you can use any mysql adapter, I'm just using prisma for this example, 
    provider: "mysql",
  }),
  user: {
    // the tables created by the plugin follow this schema. 
    modelName: "wp_ba_user",
  },
  session: {
    modelName: "wp_ba_session",
  },
  verification: {
    modelName: "wp_ba_verification",
  },
  account: {
    modelName: "wp_ba_account",
  },
  //...
 });
```

## Better Auth Compatibility

Compatibility is schema-based (not runtime package-coupled in WordPress PHP).

- Plugin version: `2.0.3`
- Expected Better Auth schema family: v1.x-style core tables (`user`, `session`, `account`, `verification`) represented here as `ba_*`.

If your Better Auth app uses a different schema revision, validate table columns before production rollout.

## Known Gotchas

- Billing/shipping sync routes only register when WooCommerce classes are loaded.
- `X-BA-Nonce` values cannot be reused.
- Body hash is computed from raw request JSON string. Any serialization differences break signatures.
- Clock skew greater than 5 minutes is rejected.
- If no active key exists in `better_auth_api_keys`, HMAC requests are rejected.

## Development Setup (Contributing)

### Prerequisites

- PHP 8.0+
- Composer
- WordPress local environment (optional for unit tests, required for end-to-end plugin behavior)

### Local setup

```bash
git clone https://github.com/scneptune/wordpress-better-auth.git
cd better-auth-wp-plugin
composer install
```

### Run tests

```bash
./vendor/bin/phpunit --colors=always
```

### Contributing workflow

- Create a feature branch.
- Add or update tests for behavior changes.
- Keep WordPress coding standards and input sanitization/escaping practices.
- Open a PR with clear migration notes when schema/auth behavior changes.

## Open Source Notes

If badges do not resolve yet, add CI and coverage providers in your repository:

- GitHub Actions workflow for PHPUnit (for tests badge)
- Codecov (or equivalent) upload step (for coverage badge)

Release summaries are available in [CHANGELOG.md](CHANGELOG.md).
