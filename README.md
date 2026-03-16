# Better Auth WordPress Plugin

![Tests](https://img.shields.io/github/actions/workflow/status/scneptune/wordpress-better-auth/phpunit.yml?branch=main&label=tests)
![Coverage](https://img.shields.io/codecov/c/github/scneptune/wordpress-better-auth?label=coverage)

This plugin helps you run Better Auth with a headless WordPress stack by:

- Creating and maintaining Better Auth core schema tables inside your WordPress database.
- Exposing REST API routes to create/link WordPress users from Better Auth users.
- Exposing WooCommerce-aware profile sync routes for billing and shipping.
- Protecting sync routes with replay-safe HMAC request signing.

## What This Plugin Does

At activation, the plugin creates Better Auth schema tables (if missing) via `dbDelta()`:

- `{wp_prefix}ba_user`
- `{wp_prefix}ba_session`
- `{wp_prefix}ba_account`
- `{wp_prefix}ba_verification`

During runtime, it provides sync endpoints for Better Auth -> WordPress/WooCommerce user data flows.

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

## HMAC Signing Guide

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

### Node.js Example

```js
import crypto from "node:crypto";

const keyId = process.env.BA_KEY_ID;
const secret = process.env.BA_CLIENT_SECRET;

const method = "PATCH";
const route = "/better-auth/v1/sync/billing";
const body = {
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
};

const rawBody = JSON.stringify(body);
const timestamp = String(Math.floor(Date.now() / 1000));
const nonce = crypto.randomUUID();
const bodyHash = crypto.createHash("sha256").update(rawBody, "utf8").digest("hex");
const payload = [method, route, timestamp, nonce, bodyHash].join("\n");
const signature = crypto.createHmac("sha256", secret).update(payload, "utf8").digest("hex");

await fetch(`https://example.com/wp-json${route}`, {
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
```

## Database Tables Added

On activation, the plugin creates:

- `{prefix}ba_user`
- `{prefix}ba_session`
- `{prefix}ba_account`
- `{prefix}ba_verification`

These tables map to Better Auth core model data and are created/updated via WordPress `dbDelta()` migrations.

## Better Auth Compatibility

Compatibility is schema-based (not runtime package-coupled in WordPress PHP).

- Plugin version: `2.0.1`
- Expected Better Auth schema family: v1.x-style core tables (`user`, `session`, `account`, `verification`) represented here as `ba_*`.

If your Better Auth app uses a different schema revision, validate table columns before production rollout.

## Known Gotchas

- Billing/shipping sync routes only register when WooCommerce classes are loaded.
- `X-BA-Nonce` values cannot be reused.
- Body hash is computed from raw request JSON string. Any serialization differences break signatures.
- Clock skew greater than 5 minutes is rejected.
- If no active key exists in `better_auth_api_keys`, HMAC requests are rejected.

## Key Management

Under `Settings -> General -> Better Auth` you can:

- Generate credentials (key id + secret)
- Revoke active keys
- Rotate active credentials

Secrets are shown one-time at generation and then hidden.

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
