# Changelog

All notable changes are documented here.

## Unreleased (since 1.0.1)

Summary based on commits after `1.0.1`:

- Added HMAC request signing with keyring credentials, replay protection, and key usage tracking.
- Added Better Auth user sync endpoints with WooCommerce-aware billing and shipping sync.
- Added comprehensive unit tests for HMAC verification and user/address sync flows.

## 1.0.1

Summary of changes from `1.0.0` to `1.0.1`:

- Refactored Better Auth schema table names to use `ba_` prefix.
- Shipped release/version bump for the prefixed schema migration.

## 1.0.0

Initial public release:

- Initial Better Auth WordPress plugin implementation.
- Added activation migrations for Better Auth core tables.
- Added uninstall cleanup behavior and foundational unit tests.
