# Changelog

All notable changes are documented here.

## Unreleased

- No changes yet.

## 2.0.3

- Maintenance release with workflow and documentation refinements.

## 2.0.2

- Version bump and release metadata alignment.

## 2.0.1

- Updated WordPress.org `README.txt` to follow official structure and consumer-facing tone.
- Aligned release metadata/version references across plugin files.

## 2.0.0

Summary based on commits after `1.0.1`:

- Added HMAC request signing with keyring credentials, replay protection, and key usage tracking.
- Added Better Auth user sync endpoints with WooCommerce-aware billing and shipping sync.
- Added comprehensive unit tests for HMAC verification and user/address sync flows.
- Removed legacy `POST /sync-user` endpoint and its bearer-secret auth flow.

## 1.0.1

Summary of changes from `1.0.0` to `1.0.1`:

- Refactored Better Auth schema table names to use `ba_` prefix.
- Shipped release/version bump for the prefixed schema migration.

## 1.0.0

Initial public release:

- Initial Better Auth WordPress plugin implementation.
- Added activation migrations for Better Auth core tables.
- Added uninstall cleanup behavior and foundational unit tests.
