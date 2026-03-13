<?php 

namespace Stormlabs\EUVATGuard;

if (!defined('ABSPATH')) {
    exit;
}

class VAT_Guard_Helper
{
    /**
     * Validate EU VAT number structure and optionally VIES check
     * @param string $vat The VAT number (with country code)
     * @param bool $require_vies Whether to require VIES validation
     * @param array &$error_message If invalid, set to error message string
     * @return bool
     */
    public static function is_valid_eu_vat_number($vat, &$error_message = null)
    {
        $vat = strtoupper(str_replace([' ', '-', '.'], '', $vat));
        $require_vies = get_option('eu_vat_guard_require_vies', 0);
        $eu_countries = [
            'AT',
            'BE',
            'BG',
            'CY',
            'CZ',
            'DE',
            'DK',
            'EE',
            'EL',
            'ES',
            'FI',
            'FR',
            'HR',
            'HU',
            'IE',
            'IT',
            'LT',
            'LU',
            'LV',
            'MT',
            'NL',
            'PL',
            'PT',
            'RO',
            'SE',
            'SI',
            'SK'
        ];
        $country = substr($vat, 0, 2);
        if (!in_array($country, $eu_countries) || strlen($vat) < 8 || strlen($vat) > 14) {
            $error_message = __('Please enter a valid EU VAT number.', 'eu-vat-guard-for-woocommerce');
            return false;
        }
        // VAT number regex patterns for all EU countries
        $patterns = [
            'AT' => '/^ATU\d{8}$/',                  // Austria
            'BE' => '/^BE0?\d{9,10}$/',              // Belgium - 9 or 10 digits, optional leading 0
            'BG' => '/^BG\d{9,10}$/',                // Bulgaria - 9 or 10 digits
            'CY' => '/^CY\d{8}[A-Z]$/',              // Cyprus - 8 digits + 1 letter
            'CZ' => '/^CZ\d{8,10}$/',                // Czech Republic - 8, 9, or 10 digits
            'DE' => '/^DE\d{9}$/',                   // Germany - 9 digits
            'DK' => '/^DK\d{8}$/',                   // Denmark - 8 digits
            'EE' => '/^EE\d{9}$/',                   // Estonia - 9 digits
            'EL' => '/^EL\d{9}$/',                   // Greece - 9 digits
            'ES' => '/^ES[A-Z0-9]\d{7}[A-Z0-9]$/',  // Spain - letter/digit + 7 digits + letter/digit
            'FI' => '/^FI\d{8}$/',                   // Finland - 8 digits
            'FR' => '/^FR[A-HJ-NP-Z0-9]{2}\d{9}$/', // France - 2 chars + 9 digits
            'HR' => '/^HR\d{11}$/',                  // Croatia - 11 digits
            'HU' => '/^HU\d{8}$/',                   // Hungary - 8 digits
            'IE' => '/^IE(\d{7}[A-W]|\d[A-Z]\d{5}[A-W])[A-I]?$/', // Ireland - multiple formats
            'IT' => '/^IT\d{11}$/',                  // Italy - 11 digits
            'LT' => '/^LT(\d{9}|\d{12})$/',          // Lithuania - 9 or 12 digits
            'LU' => '/^LU\d{8}$/',                   // Luxembourg - 8 digits
            'LV' => '/^LV\d{11}$/',                  // Latvia - 11 digits
            'MT' => '/^MT\d{8}$/',                   // Malta - 8 digits
            'NL' => '/^NL\d{9}B\d{2}$/',             // Netherlands - 9 digits + B + 2 digits
            'PL' => '/^PL\d{10}$/',                  // Poland - 10 digits
            'PT' => '/^PT\d{9}$/',                   // Portugal - 9 digits
            'RO' => '/^RO\d{2,10}$/',                // Romania - 2 to 10 digits
            'SE' => '/^SE\d{12}$/',                  // Sweden - 12 digits (not ending in 01)
            'SI' => '/^SI\d{8}$/',                   // Slovenia - 8 digits
            'SK' => '/^SK\d{10}$/',                  // Slovakia - 10 digits
        ];
        if (isset($patterns[$country]) && preg_match($patterns[$country], $vat) !== 1) {
            $error_message = __('Please enter a valid EU VAT number.', 'eu-vat-guard-for-woocommerce');
            return false;
        }
        // Short-circuit hook — allows Pro plugin (or others) to return a cached result
        $pre = apply_filters('eu_vat_guard_pre_validate_vat_number', null, $vat);
        if ($pre !== null) {
            return (bool) $pre;
        }

        // VIES check if required
        if ($require_vies) {
            $ignore_vies_error = get_option('eu_vat_guard_ignore_vies_error', 0);
            $number = substr($vat, 2);
            $vies_result = VAT_Guard_VIES::check_vat($country, $number);
            if ($vies_result === false) {
                $error_message = __('This VAT number is not valid according to the VIES service.', 'eu-vat-guard-for-woocommerce');
                return false;
            } elseif ($vies_result === null) {
                if ($ignore_vies_error) {
                    // Allow checkout if VIES is unavailable and option is enabled
                    return true;
                }
                $error_message = __('VAT number validation is currently unavailable. Please try again later or contact the website owner.', 'eu-vat-guard-for-woocommerce');
                return false;
            }
        }

        // Allow Pro plugin to enhance VAT validation
        $enhanced_result = apply_filters('eu_vat_guard_validate_vat_number', true, $vat, $country);

        return $enhanced_result;
    }

       /**
     * Get VAT number from existing order, checking both block and classic checkout sources
     * @param \WC_Order $order
     * @return string VAT number or empty string
     */
    public static function get_order_vat_number($order)
    {
        $vat = $order->get_meta(EU_VAT_GUARD_META_ORDER_VAT);
        if (empty($vat)) {
            $vat = get_post_meta($order->get_id(), EU_VAT_GUARD_META_ORDER_VAT, true);
        }

        return $vat;
    }

     /**
     * Get custom company label or default (WPML compatible)
     */
    public static function get_company_label()
    {
        $custom_label = get_option('eu_vat_guard_company_label', '');
        if (!empty($custom_label)) {
            // Make custom string translatable with WPML
            return self::translate_custom_string($custom_label, 'Company Label');
        }
        return __('Company Name', 'eu-vat-guard-for-woocommerce');
    }

    /**
     * Get custom VAT label or default (WPML compatible)
     */
    public static function get_vat_label()
    {
        $custom_label = get_option('eu_vat_guard_vat_label', '');
        if (!empty($custom_label)) {
            // Make custom string translatable with WPML
            return self::translate_custom_string($custom_label, 'VAT Label');
        }
        return __('VAT Number', 'eu-vat-guard-for-woocommerce');
    }

    /**
     * Get custom exemption message or default (WPML compatible)
     */
    public static function get_exemption_message()
    {
        $custom_message = get_option('eu_vat_guard_exemption_message', '');
        if (!empty($custom_message)) {
            // Make custom string translatable with WPML
            return self::translate_custom_string($custom_message, 'Exemption Message');
        }
        return __('VAT exempt for this order', 'eu-vat-guard-for-woocommerce');
    }

    /**
     * Translate custom string with WPML if available
     */
    private static function translate_custom_string($string, $name)
    {
        // Check if WPML is active and has string translation
        if (function_exists('icl_t')) {
            // Register string for translation if not already registered
            if (function_exists('icl_register_string')) {
                icl_register_string('EU VAT Guard', $name, $string);
            }
            // Return translated string
            return icl_t('EU VAT Guard', $name, $string);
        }

        // Fallback: return original string if WPML not available
        return $string;
    }

     /**
     * Check if current AJAX call is from admin dashboard (not checkout)
     * This helps distinguish between checkout AJAX calls and admin dashboard AJAX calls
     * 
     * @return bool True if this is an admin dashboard AJAX call
     */
    public static function is_admin_dashboard_ajax()
    {
        if (!wp_doing_ajax()) {
            return false; //no ajax call
        }

        // Check for checkout-related AJAX actions that should be allowed
        $checkout_ajax_actions = array(
            'woocommerce_update_order_review',
            'woocommerce_checkout',
            'woocommerce_apply_coupon',
            'woocommerce_remove_coupon',
            'woocommerce_update_shipping_method',
            'wc_ajax_update_order_review',
            'wc_ajax_checkout',
            'wc_ajax_apply_coupon',
            'wc_ajax_remove_coupon'
        );

        $action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';

        // If it's a checkout-related AJAX action, it's not an admin dashboard call
        if (in_array($action, $checkout_ajax_actions, true)) {
            return false; //customer facing ajax call
        }

        // Check if we're in admin context with non-checkout AJAX
        // This catches admin dashboard AJAX calls that we want to exclude
        return is_admin() && !in_array($action, $checkout_ajax_actions, true); //different call on admin section
    }


        /**
     * Sanitize VAT field input
     * Removes dots, spaces, and other non-alphanumeric characters
     * Converts to uppercase
     * @param string $value The VAT number to sanitize
     * @return string The sanitized VAT number
     */
    public static function sanitize_vat_field($value)
    {
        if (empty($value)) {
            return '';
        }

        // Remove dots, spaces, dashes, and other non-alphanumeric characters
        // Keep only letters and numbers
        $sanitized = preg_replace('/[^A-Za-z0-9]/', '', $value);

        // Convert to uppercase
        return strtoupper($sanitized);
    }
}