---
description: "Use when editing WordPress plugin PHP, composer.json, or plugin docs. Covers WordPress coding standards, security, and Composer installation best practices."
applyTo: ["**/*.php", "composer.json", "README.md", "README.txt", "**/*.md", "**/*.txt"]
---
# WordPress Plugin Best Practices (Preferred)

- Follow WordPress Coding Standards for PHP, including brace style and spacing.
- Keep a single plugin bootstrap file in the root that:
  - Blocks direct access with `defined('ABSPATH') || exit;`
  - Loads Composer autoload when present with a `file_exists` guard.
  - Registers activation/deactivation/uninstall hooks in the bootstrap only.
- Use a unique, consistent prefix or namespace for all functions, classes, constants, hooks, and options.
- Separate concerns: admin code in `admin/`, public code in `public/`, shared code in `includes/`.
- Sanitize all input and escape all output:
  - `sanitize_text_field`, `sanitize_key`, `sanitize_email`, `absint`, `wp_kses_post`, etc.
  - `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`, etc.
- Verify nonces and capabilities for any state-changing action.
- Internationalize all user-facing strings with `__()`, `_e()`, `esc_html__()`, and load text domain.
- Do not run plugin logic on load; hook into WordPress actions/filters.
- Use WordPress APIs for database, HTTP, files, and options.

## Composer and GitHub Distribution

- Provide a valid `composer.json` with:
  - `name` (vendor/plugin)
  - `type`: `wordpress-plugin`
  - `license`, `authors`, and `require` with PHP and WP constraints
  - `autoload` (prefer PSR-4) and `autoload-dev` for tests
- Ensure the plugin works when installed via Composer in a WordPress project:
  - Require `vendor/autoload.php` from the plugin bootstrap if present
  - Avoid hard-coded paths; use `plugin_dir_path(__FILE__)` and `plugin_dir_url(__FILE__)`
- Keep `vendor/` out of the repo unless a distribution channel requires it.
- Tag releases in GitHub using SemVer and keep `README.txt` compatible with WP.org format.

## Testing and Quality

- Add `phpunit` config if tests exist and keep runtime dependencies minimal.
- Lint with WordPress PHP Coding Standards when touching PHP files.
