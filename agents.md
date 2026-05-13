# EU VAT Guard for WooCommerce — CLAUDE.md

## Overview

EU VAT Guard for WooCommerce is a free WordPress plugin that handles B2B EU VAT number validation, WooCommerce checkout integration, and reverse-charge VAT exemption. It is the required dependency for the companion Pro plugin (`eu-vat-guard-for-woocommerce-pro`).

**Current version:** 1.4.4
**Text domain:** `eu-vat-guard-for-woocommerce`
**Main plugin file:** `vat-guard-woocommerce.php`
**Plugin constant prefix:** `EU_VAT_GUARD_`
**Option/hook prefix:** `eu_vat_guard_`

---

## Directory Structure

```
eu-vat-guard-for-woocommerce/
├── includes/
│   ├── class-vat-guard.php                  # Main orchestrator (singleton)
│   ├── class-vat-guard-admin.php            # Admin pages and settings
│   ├── class-vat-guard-admin-ui.php         # Reusable admin UI components
│   ├── class-vat-guard-account.php          # Registration/My Account fields
│   ├── class-vat-guard-vies.php             # VIES SOAP API client
│   ├── class-vat-guard-helper.php           # Validation utilities (shared)
│   ├── class-vat-guard-block-integration.php# WooCommerce Blocks Store API
│   ├── class-vat-guard-pdf-integration.php  # PDF invoice plugin support
│   ├── class-vat-guard-rate-importer.php    # EU VAT rate data + WC import
│   ├── pdf-template-helpers.php             # Functions for custom PDF templates
│   └── index.php                            # Empty security file
├── assets/
│   ├── js/
│   │   ├── vat-guard-checkout.js            # Classic checkout validation
│   │   ├── vat-guard-block-checkout.js      # Blocks checkout integration
│   │   ├── vat-guard-block-editor.js        # Block editor support
│   │   └── admin-rate-importer.js           # VAT rate import UI
│   └── css/
│       ├── admin-ui.css                     # Admin styles
│       └── admin-vat-importer.css           # Rate importer styles
├── languages/                               # .pot/.po/.mo translation files
├── vat-guard-woocommerce.php                # Plugin entry point
├── NAMING-CONVENTIONS.md                    # Internal naming docs
├── NAMESPACE-CHANGES.md                     # Namespace migration history
├── VAT-RATES-INFO.md                        # EU VAT rate data notes
└── README.md
```

---

## Architecture

### Class Loading

`vat-guard-woocommerce.php` bootstraps the plugin:
1. Defines constants (`EU_VAT_GUARD_VERSION`, `EU_VAT_GUARD_PLUGIN_DIR`, etc.)
2. Requires `class-vat-guard.php` and `class-vat-guard-rate-importer.php`
3. Initializes `VAT_Guard::instance()` on `plugins_loaded` at priority 20

All other classes are loaded by `VAT_Guard::init()` on the `init` hook (priority 10), with admin-only classes guarded by `is_admin()`.

### Singleton Pattern

Every major class follows the same pattern:
```php
class VAT_Guard_Foo {
    private static $instance = null;
    private function __construct() { /* setup */ }
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}
```

### Dependency Graph

```
VAT_Guard (main)
├── VAT_Guard_Helper        (always loaded — validation utilities)
├── VAT_Guard_Admin         (admin only)
│   └── VAT_Guard_Admin_UI  (admin UI components)
│   └── VAT_Guard_PDF_Integration (if PDF plugin active)
├── VAT_Guard_Account       (registration/my account)
└── VAT_Guard_Block_Integration (if block checkout enabled in settings)

VAT_Guard_Helper
└── VAT_Guard_VIES          (instantiated on demand for SOAP calls)

VAT_Guard_Rate_Importer     (loaded independently from main entry point)
```

---

## Key Classes

### `VAT_Guard` — `includes/class-vat-guard.php`
Main orchestrator. Manages all hooks for checkout, account, and email integration.

Key methods:
- `validate_vat_at_checkout()` — main checkout validation hook
- `maybe_apply_vat_exemption()` — applies/removes WC tax exemption
- `show_vat_in_emails()` — appends VAT info to order emails

### `VAT_Guard_Helper` — `includes/class-vat-guard-helper.php`
Shared validation and data-retrieval utilities. Used by both free and Pro plugins.

Key methods:
- `is_valid_eu_vat_number($vat, $country)` — format + optional VIES check
- `get_order_vat_number($order)` — retrieves from order meta (handles both classic and block checkout meta keys)
- `sanitize_vat_field($vat)` — strips special chars, uppercases

### `VAT_Guard_VIES` — `includes/class-vat-guard-vies.php`
Thin wrapper around the EU Commission VIES SOAP API.
- `check_vat($country, $vat)` → `true` | `false` | `null` (null = VIES unreachable)
- WSDL: `https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl`

### `VAT_Guard_Admin` — `includes/class-vat-guard-admin.php`
Settings pages, order meta box, and integration with WooCommerce admin.

### `VAT_Guard_Admin_UI` — `includes/class-vat-guard-admin-ui.php`
Stateless helper for rendering consistent admin UI components:
- `page_header()`, `info_box()`, `tab_navigation()`, `stats_table()`, `status_badge()`

### `VAT_Guard_Block_Integration` — `includes/class-vat-guard-block-integration.php`
Implements WooCommerce Blocks `IntegrationInterface`. Registers the Store API REST endpoint and enqueues block scripts.
- REST endpoint: `POST /vat-guard/v1/validate`
- Registration name: `'eu-vat-guard'`

### `VAT_Guard_Account` — `includes/class-vat-guard-account.php`
Adds company and VAT fields to registration form, My Account, and classic checkout billing.

### `VAT_Guard_Rate_Importer` — `includes/class-vat-guard-rate-importer.php`
Contains hardcoded EU VAT rate data. Admin UI to bulk-create WooCommerce tax rates.

---

## VAT Validation Workflow

```
1. Customer enters VAT number at checkout / registration
2. JavaScript (vat-guard-checkout.js) sanitizes input
3. Server-side: VAT_Guard_Helper::is_valid_eu_vat_number()
   a. Country-specific regex validation (all 27 EU members)
   b. apply_filters('eu_vat_guard_pre_validate_vat_number') — allows short-circuit (Pro caches)
   c. If VIES required: VAT_Guard_VIES::check_vat()
   d. apply_filters('eu_vat_guard_validate_vat_number') — post-validation
4. If valid and VAT country ≠ shop country and not local pickup:
   a. WC customer set as VAT exempt
   b. All taxes removed from cart
   c. Order meta: _eu_vat_guard_order_vat_exempt = 'yes'
   d. do_action('eu_vat_guard_vat_exemption_applied', $order_id, $data)
5. Order saved with VAT meta
6. Email sent with VAT number and exemption status
```

---

## Custom Hooks

### Actions
| Hook | Args | When |
|---|---|---|
| `eu_vat_guard_vat_exemption_applied` | `$order_id, $data[]` | After exemption applied at checkout |
| `eu_vat_guard_customer_vat_updated` | `$customer_id, $data[]` | After VAT saved on account/registration |
| `eu_vat_guard_admin_page_content` | `$tab` | In admin settings (Pro plugin extends via this) |

### Filters
| Filter | Args | Purpose |
|---|---|---|
| `eu_vat_guard_pre_validate_vat_number` | `$result, $vat, $country` | Short-circuit validation (return bool to skip VIES) |
| `eu_vat_guard_validate_vat_number` | `$result, $vat, $country` | Post-validation override |
| `eu_vat_guard_vat_exempt_countries` | `$countries[]` | Modify exempt country list |
| `eu_vat_guard_order_data` | `$data, $order` | Enhance order data before processing |
| `eu_vat_guard_version_info` | `$info[]` | Add version info (Pro uses this) |

---

## WordPress Options

All options use `get_option()` / `update_option()` with these keys:

| Option | Type | Description |
|---|---|---|
| `eu_vat_guard_require_company` | bool | Require company name field |
| `eu_vat_guard_require_vat` | bool | Require VAT number field |
| `eu_vat_guard_require_vies` | bool | Require VIES API validation |
| `eu_vat_guard_ignore_vies_error` | bool | Allow checkout if VIES is down |
| `eu_vat_guard_enable_block_checkout` | bool | Enable WooCommerce Blocks support |
| `eu_vat_guard_disable_exemption` | bool | Disable reverse-charge exemption |
| `eu_vat_guard_company_label` | string | Custom label for company field |
| `eu_vat_guard_vat_label` | string | Custom label for VAT field |
| `eu_vat_guard_exemption_message` | string | Custom exemption notice message |
| `eu_vat_guard_override_b2b_plugins` | bool | Override other B2B plugins' exemption |
| `eu_vat_guard_hide_registration_fields` | bool | Hide fields on registration form |

---

## Meta Keys

### Order Meta
| Key | Description |
|---|---|
| `_eu_vat_guard_order_vat_number` | VAT number on order (classic checkout) |
| `_eu_vat_guard_order_vat_exempt` | `'yes'` if VAT exempt |
| `_wc_other/eu-vat-guard/vat_number` | VAT number (WooCommerce Blocks format) |

### User Meta
| Key | Description |
|---|---|
| `vat_number` | Customer's VAT number (no prefix — legacy BC) |
| `company_name` | Customer's company name (no prefix — legacy BC) |

---

## Admin Menu

**Menu slug:** `eu-vat-guard`
**Icon:** `dashicons-shield-alt`
**Capability:** `manage_options`

Tabs:
1. **Settings** — Basic and advanced options
2. **VAT Rates** — EU rate data + WooCommerce tax rate importer
3. **Help** — Documentation links

The Pro plugin extends this menu by hooking into `eu_vat_guard_admin_page_content` and registering its own submenus.

---

## Block Checkout

Block checkout support is opt-in via the `eu_vat_guard_enable_block_checkout` option.

When enabled:
- `VAT_Guard_Block_Integration` registers with WooCommerce Blocks
- Custom block fields added to Store API schema
- REST endpoint `POST /vat-guard/v1/validate` handles validation
- `vat-guard-block-checkout.js` handles frontend interaction
- `vat-guard-block-editor.js` handles block editor visibility

Order meta from block checkout uses the WC Blocks key format: `_wc_other/eu-vat-guard/vat_number`. The `get_order_vat_number()` helper checks both key formats.

---

## Security Conventions

- All PHP files start with: `if (!defined('ABSPATH')) exit;`
- All form submissions verified with `wp_verify_nonce()`
- Admin operations check `manage_woocommerce` or `manage_options` capability
- All inputs sanitized with `sanitize_text_field()`, `sanitize_email()`, etc.
- All dynamic output escaped with `esc_html()`, `esc_attr()`, `wp_kses_post()`
- AJAX handlers use `check_ajax_referer()` and `current_user_can()`

---

## Internationalization

- Text domain: `eu-vat-guard-for-woocommerce`
- Translation files: `languages/` directory
- WPML support: custom labels registered via `icl_register_string()` in `VAT_Guard_Helper`
- All user-facing strings use `__()`, `_e()`, `esc_html__()`, `esc_html_e()`

---

## Pro Plugin Integration

The Pro plugin (`eu-vat-guard-for-woocommerce-pro`) depends on this plugin (minimum v1.3.0) and integrates via:

1. **Dependency check:** Pro reads `EU_VAT_GUARD_VERSION` constant
2. **Hook listeners:** Pro's `Evidence_Tracker` listens to `eu_vat_guard_vat_exemption_applied`
3. **Filter hooks:** Pro uses `eu_vat_guard_pre_validate_vat_number` to provide VIES caching
4. **Admin extension:** Pro hooks into `eu_vat_guard_admin_page_content` and adds submenus
5. **Helper reuse:** Pro uses `VAT_Guard_Helper` static methods directly

Do not remove or rename any of the custom hooks without updating the Pro plugin.

---

## Countries Supported

All 27 EU member states: AT, BE, BG, CY, CZ, DE, DK, EE, ES, FI, FR, GR, HR, HU, IE, IT, LT, LU, LV, MT, NL, PL, PT, RO, SE, SI, SK

Each country has a specific regex pattern for VAT format validation in `VAT_Guard_Helper::is_valid_eu_vat_number()`.

---

## Coding Conventions

- **PHP version:** 7.2+ minimum; use type hints where possible
- **Namespace:** `Stormlabs\EUVATGuard` (see `NAMESPACE-CHANGES.md`)
- **Class naming:** `VAT_Guard_*` for all includes classes (underscore-separated)
- **Hook naming:** `eu_vat_guard_*` prefix for all custom hooks
- **Option naming:** `eu_vat_guard_*` prefix for all options
- **Meta naming:** `_eu_vat_guard_*` prefix for new meta keys
- **No build tools:** plain PHP/JS/CSS, no Composer, no npm, no webpack
- **Singleton for every class** — see pattern above
- **Admin-only code in `is_admin()` guards** or loaded via admin hooks only
- **Context-aware loading:** Block integration only loaded if setting enabled

---

## WordPress Coding Standards & Plugin Checker Compliance

This plugin must pass the [WordPress Plugin Check](https://wordpress.org/plugins/plugin-check/) (PCP) tool and comply with the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/) (WPCS). All new code must follow these rules. Violations will block a WordPress.org submission or review.

### PHP Style Rules (WPCS)

- **Indentation:** Tabs, not spaces.
- **Braces:** Opening brace on same line for control structures; on next line for class/function declarations.
- **Yoda conditions:** Use `if ( 'value' === $var )` not `if ( $var === 'value' )`.
- **Spaces inside parentheses:** `if ( $x )`, `function foo( $a, $b )`, `array( 1, 2 )`.
- **No closing PHP tag** at end of PHP-only files.
- **No short tags:** Always use `<?php`, never `<?` or `<?=`.

### Prefixing — Everything Must Be Prefixed

All functions, classes, constants, hooks, option names, and global variables must be prefixed with `eu_vat_guard_` (snake_case) or `EU_VAT_GUARD_` (constants) or `VAT_Guard` (classes). This prevents naming collisions with other plugins.

**Never add an unprefixed global function, constant, or class.**

### Sanitization, Escaping & Validation

Every piece of data that crosses a trust boundary must be handled correctly:

| Context | Rule |
|---|---|
| Reading `$_GET`, `$_POST`, `$_REQUEST`, `$_SERVER` | Always `sanitize_text_field()`, `sanitize_email()`, `absint()`, etc. immediately on read — never store or use raw superglobal values |
| Outputting to HTML | `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()` — **never echo unescaped variables** |
| Outputting to HTML attributes | `esc_attr()` |
| Outputting URLs | `esc_url()` |
| Outputting to JavaScript | `wp_json_encode()` or `esc_js()` |
| SQL queries | Always use `$wpdb->prepare()` — never interpolate variables into SQL strings |
| Nonces | All form submissions and AJAX handlers must verify a nonce with `wp_verify_nonce()` or `check_ajax_referer()` before processing |

**Plugin checker will flag any missing escape on output as an error.**

### Capability Checks

- Every admin action, settings save, and AJAX handler must check `current_user_can()` before doing anything.
- Use the most specific capability: `manage_woocommerce` for WooCommerce admin tasks, `manage_options` for general settings, `edit_users` for user meta changes.
- Capability check must come **before** nonce verification, not after.

### Enqueueing Scripts and Styles

- **Never** use `<script>` or `<style>` tags directly in PHP output.
- Always register with `wp_register_script()` / `wp_register_style()` and enqueue with `wp_enqueue_script()` / `wp_enqueue_style()` on the correct hook (`wp_enqueue_scripts` for frontend, `admin_enqueue_scripts` for admin).
- All scripts must declare dependencies (e.g. `['jquery']`) and a version string (use plugin version constant).
- Use `wp_localize_script()` or `wp_add_inline_script()` to pass PHP data to JS — **never inline PHP variables in `<script>` blocks**.
- Load scripts in footer where possible (`$in_footer = true`).

### HTTP Requests

- **Never** use `curl_*` functions or `file_get_contents()` with a URL.
- Use the WordPress HTTP API: `wp_remote_get()`, `wp_remote_post()`, `wp_remote_request()`.
- Check for `WP_Error` on the response before using it.

### File System Operations

- **Never** use `fopen()`, `file_put_contents()`, `mkdir()`, etc. directly.
- Use the WordPress Filesystem API (`WP_Filesystem`) for any file read/write operations.

### Database Operations

- Use WordPress meta APIs (`get_post_meta`, `update_post_meta`, `get_user_meta`, etc.) and options API (`get_option`, `update_option`) wherever possible.
- If raw `$wpdb` queries are unavoidable, always use `$wpdb->prepare()`.
- Never create custom database tables unless absolutely necessary and approved.

### Redirects

- Use `wp_safe_redirect()` instead of `wp_redirect()` for redirecting to user-supplied URLs.
- Use `wp_redirect()` for known safe URLs.
- **Never** use `header('Location: ...')` directly.

### Deprecated Functions

- Do not use deprecated WordPress or WooCommerce functions. Check the deprecation log when upgrading WP/WC versions.
- Common ones to avoid: `get_currentuserinfo()`, `wp_get_current_user()` before `init`, `WC()->cart` before cart is initialized.

### Practices That Will Fail Plugin Check

Avoid all of the following — the plugin checker flags these as errors:

- `eval()` — forbidden entirely
- `base64_decode()` on executable code — forbidden
- `extract()` — forbidden (creates unpredictable variable scope)
- `create_function()` — forbidden (deprecated in PHP 7.2, removed in 8.0)
- `shell_exec()`, `exec()`, `system()`, `passthru()` — forbidden
- `$_REQUEST` without sanitization
- Outputting any variable without escaping
- Calling `wp_enqueue_scripts` from inside a template file
- Registering post types or taxonomies outside of the `init` hook
- Storing serialized data from user input in options/meta (use JSON instead)
- Using `update_option()` with `autoload = true` for large data (use `'no'` for large/infrequently read options)
- Calling `session_start()` — WordPress does not use PHP sessions
- Direct file inclusion with user-controlled paths
- Echoing or printing debug output (`var_dump`, `print_r`, `error_log` left in committed code)
- Hardcoded plugin paths — always use `plugin_dir_path(__FILE__)`, `plugin_dir_url(__FILE__)`, or the defined constants

### i18n Requirements

- Every user-facing string must be wrapped in a translation function: `__()`, `_e()`, `_n()`, `esc_html__()`, `esc_html_e()`, etc.
- Always pass the correct text domain as the second argument: `'eu-vat-guard-for-woocommerce'`.
- **Never** use variable text domains (e.g. `__( 'text', $domain )`) — the checker cannot statically analyse them.
- Do not translate strings that are not user-facing (e.g. option keys, hook names, log entries).

### JavaScript Standards

- Use `strict mode` (`'use strict';`) inside function wrappers.
- Wrap all JS in an IIFE or use the module pattern to avoid polluting the global scope.
- Use `wp.i18n.__()` for translatable strings in JS (registered via `wp_set_script_translations()`).
- No `console.log()` or debugging statements in committed code.
- Avoid inline event handlers (`onclick="..."`) — use `addEventListener` or jQuery `.on()`.

### CSS Standards

- Namespace all CSS selectors with a plugin-specific prefix (e.g. `.vat-guard-*`) to avoid conflicts.
- Do not use `!important` unless there is no alternative.
- Do not use ID selectors (`#my-id`) for styling — use classes.

### General Bad Practices to Avoid

- Do not suppress errors with `@` (e.g. `@file_get_contents()`).
- Do not catch exceptions silently with empty `catch` blocks.
- Do not leave `TODO`, `FIXME`, or `HACK` comments in production code.
- Do not commit commented-out code blocks.
- Do not use `die()` or `exit()` except in the direct-access guard at the top of files and in `wp_die()` for unauthorized access.
- Do not define constants inside functions or conditionals — define them at the top level in the main plugin file.
