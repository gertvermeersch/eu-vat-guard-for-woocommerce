<?php
/*
Plugin Name: EU VAT Guard for WooCommerce
Description: Manage EU VAT numbers and company information for WooCommerce customers and B2B. Adds company and VAT fields to registration, account, and checkout, exempts VAT (reverse charge) where applicable and provides admin tools for VAT management.
Version: 1.3.10
Author: Stormlabs
Author URI: https://stormlabs.be/
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: eu-vat-guard-for-woocommerce
Domain Path: /languages
*/

namespace Stormlabs\EUVATGuard;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('EU_VAT_GUARD_VERSION', '1.3.10');
define('EU_VAT_GUARD_PLUGIN_FILE', __FILE__);
define('EU_VAT_GUARD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EU_VAT_GUARD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('EU_VAT_GUARD_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Define option name constants
define('EU_VAT_GUARD_OPTION_PREFIX', 'eu_vat_guard_');
define('EU_VAT_GUARD_OPTION_REQUIRE_COMPANY', 'eu_vat_guard_require_company');
define('EU_VAT_GUARD_OPTION_REQUIRE_VAT', 'eu_vat_guard_require_vat');
define('EU_VAT_GUARD_OPTION_REQUIRE_VIES', 'eu_vat_guard_require_vies');
define('EU_VAT_GUARD_OPTION_IGNORE_VIES_ERROR', 'eu_vat_guard_ignore_vies_error');
define('EU_VAT_GUARD_OPTION_ENABLE_BLOCK_CHECKOUT', 'eu_vat_guard_enable_block_checkout');
define('EU_VAT_GUARD_OPTION_DISABLE_EXEMPTION', 'eu_vat_guard_disable_exemption');
define('EU_VAT_GUARD_OPTION_COMPANY_LABEL', 'eu_vat_guard_company_label');
define('EU_VAT_GUARD_OPTION_VAT_LABEL', 'eu_vat_guard_vat_label');
define('EU_VAT_GUARD_OPTION_EXEMPTION_MESSAGE', 'eu_vat_guard_exemption_message');

// Define meta key constants with plugin prefix
define('EU_VAT_GUARD_META_VAT_NUMBER', '_eu_vat_guard_vat_number'); // User meta
define('EU_VAT_GUARD_META_COMPANY_NAME', '_eu_vat_guard_company_name'); // User meta
define('EU_VAT_GUARD_META_ORDER_VAT', '_eu_vat_guard_order_vat_number'); // Order meta
define('EU_VAT_GUARD_META_ORDER_EXEMPT', '_eu_vat_guard_order_vat_exempt'); // Order meta
define('EU_VAT_GUARD_META_BLOCK_VAT', '_wc_other/eu-vat-guard/vat_number'); // Block checkout meta

// Include main plugin class
require_once EU_VAT_GUARD_PLUGIN_DIR . 'includes/class-vat-guard.php';

// Include VAT rate importer (admin only)
if (is_admin()) {
    require_once EU_VAT_GUARD_PLUGIN_DIR . 'includes/class-vat-guard-rate-importer.php';
}

/**
 * Plugin activation hook - Initialize default options
 */
function eu_vat_guard_activate() {
    // Initialize options with defaults if they don't exist
    $default_options = array(
        'eu_vat_guard_require_company' => '1',
        'eu_vat_guard_require_vat' => '1',
        'eu_vat_guard_require_vies' => '0',
        'eu_vat_guard_ignore_vies_error' => '0',
        'eu_vat_guard_enable_block_checkout' => '1',
        'eu_vat_guard_disable_exemption' => '0',
        'eu_vat_guard_fixed_prices' => '0',
        'eu_vat_guard_company_label' => '',
        'eu_vat_guard_vat_label' => '',
        'eu_vat_guard_exemption_message' => ''
    );
    
    foreach ($default_options as $option_name => $default_value) {
        if (false === get_option($option_name, false)) {
            add_option($option_name, $default_value, '', false);
        }
    }
}
register_activation_hook(__FILE__, 'Stormlabs\EUVATGuard\eu_vat_guard_activate');

// Initialize the plugin
add_action('plugins_loaded', function () {
    if (class_exists('Stormlabs\EUVATGuard\VAT_Guard')) {
        VAT_Guard::instance();
    }
}, 20); // Load after text domain

// Backward compatibility - create alias for old class name
if (!class_exists('EU_VAT_Guard')) {
    class_alias('Stormlabs\EUVATGuard\VAT_Guard', 'EU_VAT_Guard');
}
