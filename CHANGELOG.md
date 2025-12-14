# Changelog

All notable changes to EU VAT Guard for WooCommerce will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).



## [1.3.10] - 2024-12-13

### Added
- Plugin activation hook that initializes default option values on first installation
- Default settings are now pre-configured for optimal out-of-the-box experience
- Existing installations also benefit from default options initialization on admin_init

### Changed
- Improved first-time installation experience with sensible defaults
- Options are only created if they don't already exist (preserves existing settings)
- Refactored activation code for better maintainability and reusability
- Increased priority for VAT hooks

### Technical
- Added `eu_vat_guard_get_default_options()` helper function that returns default option values
- Added `eu_vat_guard_init_options()` function that initializes options if they don't exist
- Modified `eu_vat_guard_activate()` to call `eu_vat_guard_init_options()`
- Added `admin_init` hook (priority 5) to run `eu_vat_guard_init_options()` for existing installations
- Default options include: require_company (enabled), require_vat (enabled), require_vies (disabled), ignore_vies_error (disabled), enable_block_checkout (enabled), disable_exemption (disabled), fixed_prices (disabled)
- Empty string defaults for custom labels (company_label, vat_label, exemption_message)
- Better code organization with separation of concerns between default values and initialization logic

## [1.3.9] - 2024-12-06

### Fixed
- **Checkbox Settings**: Fixed checkbox settings not saving properly on some server configurations
- **Option Initialization**: Options now properly initialize in database on plugin activation
- **Settings Persistence**: Resolved issue where `update_option()` would fail for non-existent options

### Added
- **Activation Hook**: Added plugin activation hook to initialize all options with default values

### Improved
- **Code Quality**: Refactored settings sanitization for better maintainability
- **Architecture**: Simplified checkbox sanitization using inline callbacks in `register_setting()`
- **Code Organization**: Streamlined settings registration by removing redundant filter hooks and methods

### Technical
- All checkbox settings use string type ('1' or '0') for WordPress Settings API compatibility
- Options are now created in database during activation, preventing update failures
- Enhanced form reliability with hidden fields for unchecked checkbox handling
- Replaced separate `sanitize_checkbox()` method with inline sanitization callbacks
- Removed redundant `admin_init` hook for checkbox handling
- Removed `sanitize_option_{$option}` filter hooks in favor of direct `sanitize_callback` in `register_setting()`
- Improved code readability and reduced complexity in `class-vat-guard-admin.php`

## [1.3.8] - 2024-12-05

### Fixed
- **Settings Compatibility**: Fixed checkbox settings not saving properly on some server configurations
- **Block Checkout Setting**: Block checkout option now properly saves across all WordPress environments
- **Settings Registration**: All checkbox settings now use string type ('1' or '0') for better compatibility

### Technical
- Changed all checkbox settings from boolean to string type for consistent behavior
- Updated sanitize callbacks to convert checkbox values to '1' or '0' strings
- Ensures reliable settings storage across different PHP/WordPress configurations

## [1.3.7] - 2024-12-04

### Enhanced
- **Help & Support Tab**: Completely redesigned with comprehensive documentation and resources
- **Admin Interface**: Better organized navigation and clearer section headers
- **User Guidance**: Added common questions section for quick answers to frequent issues
- **Documentation Access**: Direct links to all documentation tabs and external resources
- **Support Experience**: Improved prompts for getting help and leaving reviews

### Added
- Common questions section covering block checkout, VAT formats, VIES validation, and automatic exemptions
- Useful links section with direct access to WordPress.org plugin page, FAQ, and VIES validation
- Better visual organization with icons and color-coded information boxes
- Centralized thank you message with clear call-to-action buttons

### Technical
- Improved admin class structure with dedicated help tab rendering
- Enhanced HTML structure for better accessibility and readability
- Consistent styling across all admin tabs

## [1.3.6] - 2024-11-29

### Improved
- **Admin Interface**: Updated admin page with direct links to WordPress.org support and reviews
- **User Experience**: Replaced email contact with convenient support forum and review links
- **Accessibility**: Better access to help resources and feedback channels

### Technical
- Enhanced admin interface for better community engagement
- Improved user guidance for support and feedback

## [1.3.5] - 2024-11-28

### Compatibility
- **WordPress 6.9**: Tested and confirmed compatible with WordPress 6.9
- **Technical**: Updated version number for WordPress 6.9 release compatibility

## [1.3.4] - 2025-11-13

### Changed
- **Code Optimization**: Removed redundant PDF integration initialization from admin constructor
- **Improved Structure**: PDF integration now loads only during AJAX requests when needed
- **Performance**: Reduced unnecessary code execution in admin context

### Technical
- PDF integration initialization moved exclusively to AJAX context in admin class
- Cleaner separation of concerns between admin UI and AJAX handlers
- Improved code structure and maintainability

## [1.3.3] - 2025-10-27

### Added
- **Order Display**: VAT information now displays on order confirmation pages and My Account order views
- **Smart Display Logic**: When block checkout is enabled, only shows VAT exemption status (VAT number shown by WooCommerce)
- **Classic Checkout Display**: When block checkout is disabled, shows complete VAT information section

### Changed
- **Meta Key Prefixes**: All meta keys now use `_eu_vat_guard_` prefix for better organization and conflict prevention
  - User meta: `vat_number` → `_eu_vat_guard_vat_number`
  - User meta: `company_name` → `_eu_vat_guard_company_name`
  - Order meta: `billing_eu_vat_number` → `_eu_vat_guard_order_vat_number`
  - Order meta: `billing_is_vat_exempt` → `_eu_vat_guard_order_vat_exempt`
- **Constants Usage**: All code now uses defined constants instead of hardcoded meta key strings

### Fixed
- **Admin Notices**: Fixed admin error notices not displaying when VAT validation fails in order editing
- **Block Integration**: Fixed undefined constant error in block checkout sanitize callback
- **Order Confirmation**: VAT information now properly displays regardless of block support setting
- **VAT Rate Importer**: Fixed VAT rate database issue

### Technical
- Improved code maintainability with consistent meta key usage
- Enhanced error handling for admin VAT validation
- Better separation of concerns between classic and block checkout displays

## [1.3.2] - 2025-10-26

## [1.3.1] - 2025-10-24

### Fixed

- Plugin checker flagged unsafe output (false positive)
- Notes for translators added

## [1.3.0] - 2025-10-23

### Added
- **Namespacing**: Implemented proper PHP namespace `Stormlabs\EUVATGuard` for all classes
- **Constants**: Added comprehensive plugin constants for better code organization
  - Plugin constants: `EU_VAT_GUARD_VERSION`, `EU_VAT_GUARD_PLUGIN_FILE`, etc.
  - Option name constants for all WordPress options
  - Meta key constants for consistent data handling
- **Documentation**: Created comprehensive documentation files
  - `NAMESPACE-CHANGES.md` - Details about namespace implementation
  - `NAMING-CONVENTIONS.md` - Complete naming conventions guide
- **Translations**: Completed missing translations for Dutch (nl_NL), French (fr_FR), and German (de_DE)
- **Admin Enhancement**: VAT field now displays as read-only when not in edit mode

### Changed
- **Code Structure**: Refactored all classes to use proper namespacing
- **Class Names**: Updated class names within namespace (e.g., `EU_VAT_Guard` → `VAT_Guard`)
- **Use Statements**: Added proper use statements for WordPress and WooCommerce classes
- **Code Organization**: Improved overall code structure following WordPress best practices

### Fixed
- Admin order edit mode detection for VAT field display
- VAT field now correctly shows as read-only text when viewing order (not editing)
- Translation strings for admin interface in multiple languages

### Backward Compatibility
- Added class alias for `EU_VAT_Guard` to maintain backward compatibility
- All existing integrations continue to work without changes
- No database migrations required
- User meta keys kept unchanged for compatibility

### Security
- Enhanced code security with proper namespacing
- Improved code maintainability and reduced conflict potential

## [1.2.1] - 2025-10-22

### Fixed
- Minor bug fixes and improvements

## [1.2.0] - 2025-10-21

### Added
- VAT Rate Importer tool for importing current EU VAT rates
- Comprehensive database of all 27 EU member states' VAT rates
- Support for special VAT categories (food, books, pharmaceuticals, hotels)
- Country selection interface with "Select All" functionality
- Automatic WooCommerce tax rate creation and updating
- Complete VAT rates overview table

### Changed
- Admin menu structure to dedicated main menu
- Enhanced WooCommerce integration with smart country filtering

## [1.1.0] - 2025-10-19

### Added
- Advanced settings tab with exemption rules customization
- Custom field labels and exemption messages
- WPML compatibility for custom strings
- PDF invoice integration for WooCommerce PDF Invoices & Packing Slips
- PDF template helper functions

### Changed
- Improved admin interface with separate option groups

### Fixed
- Admin options not saving correctly between tabs
- Enhanced security with proper nonce verification suppression

### Updated
- Text domain for better WordPress.org compatibility

## [1.0.0] - 2025-10-16

### Added
- Initial release
- Company name and VAT number fields
- EU VAT number format validation
- Optional VIES real-time validation
- Automatic VAT exemption for B2B transactions
- WooCommerce block checkout support
- Admin settings interface

---

## Version Numbering

We use [Semantic Versioning](https://semver.org/):
- **MAJOR** version for incompatible API changes
- **MINOR** version for new functionality in a backward compatible manner
- **PATCH** version for backward compatible bug fixes

## Links

- [Plugin Homepage](https://stormlabs.be/)
- [WordPress.org Plugin Page](https://wordpress.org/plugins/eu-vat-guard-for-woocommerce/)
- [GitHub Repository](#) (if applicable)
- [Support Forum](https://wordpress.org/support/plugin/eu-vat-guard-for-woocommerce/)
