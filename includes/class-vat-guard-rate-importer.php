<?php
/**
 * VAT Rate Importer Class
 *
 * @package Stormlabs\EUVATGuard
 * @since 1.2.0
 */

namespace Stormlabs\EUVATGuard;

use WC_Cache_Helper;
use WC_Tax;

if (!defined('ABSPATH')) {
    exit;
}

class VAT_Guard_Rate_Importer
{

    /**
     * Current EU VAT rates data
     * Updated as of 2024
     */
    private static $eu_vat_rates = array(
        'AT' => array( // Austria
            'country_name' => 'Austria',
            'standard' => 20,
            'reduced' => array(10, 13)
        ),
        'BE' => array( // Belgium
            'country_name' => 'Belgium',
            'standard' => 21,
            'reduced' => array(6, 12)
        ),
        'BG' => array( // Bulgaria
            'country_name' => 'Bulgaria',
            'standard' => 20,
            'reduced' => array(9)
        ),
        'HR' => array( // Croatia
            'country_name' => 'Croatia',
            'standard' => 25,
            'reduced' => array(5, 13)
        ),
        'CY' => array( // Cyprus
            'country_name' => 'Cyprus',
            'standard' => 19,
            'reduced' => array(5, 9)
        ),
        'CZ' => array( // Czech Republic
            'country_name' => 'Czech Republic',
            'standard' => 21,
            'reduced' => array(10, 15)
        ),
        'DK' => array( // Denmark
            'country_name' => 'Denmark',
            'standard' => 25,
            'reduced' => array()
        ),
        'EE' => array( // Estonia
            'country_name' => 'Estonia',
            'standard' => 20,
            'reduced' => array(9)
        ),
        'FI' => array( // Finland
            'country_name' => 'Finland',
            'standard' => 24,
            'reduced' => array(10, 14)
        ),
        'FR' => array( // France
            'country_name' => 'France',
            'standard' => 20,
            'reduced' => array(5.5, 10)
        ),
        'DE' => array( // Germany
            'country_name' => 'Germany',
            'standard' => 19,
            'reduced' => array(7)
        ),
        'GR' => array( // Greece
            'country_name' => 'Greece',
            'standard' => 24,
            'reduced' => array(6, 13)
        ),
        'HU' => array( // Hungary
            'country_name' => 'Hungary',
            'standard' => 27,
            'reduced' => array(5, 18)
        ),
        'IE' => array( // Ireland
            'country_name' => 'Ireland',
            'standard' => 23,
            'reduced' => array(9, 13.5)
        ),
        'IT' => array( // Italy
            'country_name' => 'Italy',
            'standard' => 22,
            'reduced' => array(4, 5, 10)
        ),
        'LV' => array( // Latvia
            'country_name' => 'Latvia',
            'standard' => 21,
            'reduced' => array(5, 12)
        ),
        'LT' => array( // Lithuania
            'country_name' => 'Lithuania',
            'standard' => 21,
            'reduced' => array(5, 9)
        ),
        'LU' => array( // Luxembourg
            'country_name' => 'Luxembourg',
            'standard' => 17,
            'reduced' => array(3, 8, 14)
        ),
        'MT' => array( // Malta
            'country_name' => 'Malta',
            'standard' => 18,
            'reduced' => array(5, 7)
        ),
        'NL' => array( // Netherlands
            'country_name' => 'Netherlands',
            'standard' => 21,
            'reduced' => array(9)
        ),
        'PL' => array( // Poland
            'country_name' => 'Poland',
            'standard' => 23,
            'reduced' => array(5, 8)
        ),
        'PT' => array( // Portugal
            'country_name' => 'Portugal',
            'standard' => 23,
            'reduced' => array(6, 13)
        ),
        'RO' => array( // Romania
            'country_name' => 'Romania',
            'standard' => 19,
            'reduced' => array(5, 9)
        ),
        'SK' => array( // Slovakia
            'country_name' => 'Slovakia',
            'standard' => 20,
            'reduced' => array(10)
        ),
        'SI' => array( // Slovenia
            'country_name' => 'Slovenia',
            'standard' => 22,
            'reduced' => array(5, 9.5)
        ),
        'ES' => array( // Spain
            'country_name' => 'Spain',
            'standard' => 21,
            'reduced' => array(4, 10)
        ),
        'SE' => array( // Sweden
            'country_name' => 'Sweden',
            'standard' => 25,
            'reduced' => array(6, 12)
        )
    );

    /**
     * Initialize the importer
     */
    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'), 20);
        add_action('admin_init', array($this, 'handle_import_request'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'eu-vat-guard',
            esc_html__('VAT Rate Importer', 'eu-vat-guard-for-woocommerce'),
            esc_html__('VAT Rate Importer', 'eu-vat-guard-for-woocommerce'),
            'manage_woocommerce',
            'eu-vat-guard-rate-importer',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_scripts($hook)
    {
        if ('eu-vat-guard_page_eu-vat-guard-rate-importer' !== $hook) {
            return;
        }

        wp_enqueue_script('jquery');

        // Enqueue the admin JavaScript file
        wp_enqueue_script(
            'vat-importer-admin-js',
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/admin-rate-importer.js',
            array('jquery'),
            EU_VAT_GUARD_VERSION,
            true
        );

        wp_enqueue_style(
            'vat-importer-admin',
            plugin_dir_url(dirname(__FILE__)) . 'assets/admin-vat-importer.css',
            array(),
            EU_VAT_GUARD_VERSION
        );
    }

    /**
     * Handle import request
     */
    public function handle_import_request()
    {
        if (!isset($_POST['eu_vat_guard_import_rates']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eu_vat_guard_import_nonce'])), 'eu_vat_guard_import_rates')) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'eu-vat-guard-for-woocommerce'));
        }

        $selected_countries = isset($_POST['selected_countries']) ? array_map('sanitize_text_field', wp_unslash($_POST['selected_countries'])) : array();
        $include_reduced = isset($_POST['include_reduced_rates']) ? sanitize_text_field(wp_unslash($_POST['include_reduced_rates'])) : 'no';

        if (empty($selected_countries)) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>' . esc_html__('Please select at least one country to import.', 'eu-vat-guard-for-woocommerce') . '</p></div>';
            });
            return;
        }

        $imported_count = $this->import_vat_rates($selected_countries, $include_reduced === 'yes');

        add_action('admin_notices', function () use ($imported_count) {
            $message = sprintf(
                /* translators: %d: number of VAT rates imported */
                esc_html__('Successfully imported %d VAT rates.', 'eu-vat-guard-for-woocommerce'),
                $imported_count
            );
            echo wp_kses_post('<div class="notice notice-success"><p>' . $message . '</p></div>');
        });
    }

    /**
     * Import VAT rates for selected countries
     */
    private function import_vat_rates($selected_countries, $include_reduced = false)
    {
        $imported_count = 0;

        foreach ($selected_countries as $country_code) {
            if (!isset(self::$eu_vat_rates[$country_code])) {
                continue;
            }

            $country_data = self::$eu_vat_rates[$country_code];

            // Import standard rate
            $this->create_or_update_tax_rate($country_code, 'standard', $country_data['standard'], $country_data['country_name']);
            $imported_count++;

            // Import one reduced rate if requested. Additional special reduced tiers are skipped for now.
            if ($include_reduced && !empty($country_data['reduced'])) {
                $primary_reduced_rate = (float) $country_data['reduced'][0];
                $this->create_or_update_tax_rate($country_code, 'reduced', $primary_reduced_rate, $country_data['country_name']);
                $imported_count++;
            }
        }

        return $imported_count;
    }

    /**
     * Create or update tax rate in WooCommerce
     */
    private function create_or_update_tax_rate($country_code, $type, $rate, $country_name)
    {
        global $wpdb;

        // Create clean tax rate name - just the percentage
        $tax_rate_name = $rate . '%';

        // Standard rates use an empty class. Reduced rates use WooCommerce's default reduced class.
        $tax_class_slug = $this->get_tax_class_slug_for_type($type);

        // Check if rate already exists for this country and class
        $existing_rate = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates 
             WHERE tax_rate_country = %s 
             AND tax_rate_class = %s
             AND tax_rate = %f",
            $country_code,
            $tax_class_slug,
            $rate
        ));

        if ($existing_rate) {
            // Update existing rate
            $wpdb->update(
                $wpdb->prefix . 'woocommerce_tax_rates',
                array(
                    'tax_rate_name' => $tax_rate_name,
                    'tax_rate_priority' => $type === 'standard' ? 1 : 2,
                    'tax_rate_compound' => 0,
                    'tax_rate_shipping' => $type === 'standard' ? 1 : 0,
                ),
                array('tax_rate_id' => $existing_rate->tax_rate_id),
                array('%s', '%d', '%d', '%d'),
                array('%d')
            );
        } else {
            // Create new rate
            $wpdb->insert(
                $wpdb->prefix . 'woocommerce_tax_rates',
                array(
                    'tax_rate_country' => $country_code,
                    'tax_rate_state' => '',
                    'tax_rate' => $rate,
                    'tax_rate_name' => $tax_rate_name,
                    'tax_rate_priority' => $type === 'standard' ? 1 : 2,
                    'tax_rate_compound' => 0,
                    'tax_rate_shipping' => $type === 'standard' ? 1 : 0,
                    'tax_rate_order' => 0,
                    'tax_rate_class' => $tax_class_slug
                ),
                array('%s', '%s', '%f', '%s', '%d', '%d', '%d', '%d', '%s')
            );
        }

        // Clear WooCommerce tax cache
        WC_Cache_Helper::get_transient_version('taxes', true);
    }

    /**
     * Resolve the WooCommerce tax class slug for a given importer rate type.
     */
    private function get_tax_class_slug_for_type($type)
    {
        if ($type === 'standard') {
            return '';
        }

        if ($type === 'reduced') {
            return $this->get_default_reduced_tax_class_slug();
        }

        // Fallback for unexpected custom types.
        return $this->get_default_reduced_tax_class_slug();
    }

    /**
     * Resolve the default WooCommerce reduced tax class slug in a translation-safe way.
     */
    private function get_default_reduced_tax_class_slug()
    {
        global $wpdb;

        // Prefer WooCommerce's first default tax class from the dedicated table.
        // On standard installs this is the reduced class and keeps the canonical slug.
        $default_class_slug = $wpdb->get_var(
            "SELECT slug FROM {$wpdb->prefix}wc_tax_rate_classes
             ORDER BY tax_rate_class_id ASC
             LIMIT 1"
        );

        if (!empty($default_class_slug)) {
            return $default_class_slug;
        }

        // Fallback to first configured class name (translation-aware).
        $tax_classes = WC_Tax::get_tax_classes();
        if (!empty($tax_classes) && !empty($tax_classes[0])) {
            return sanitize_title($tax_classes[0]);
        }

        // Final fallback for edge cases where no classes exist yet.
        return $this->ensure_tax_class('Reduced rate');
    }

    /**
     * Ensure a WooCommerce tax class exists and return its slug.
     */
    private function ensure_tax_class($tax_class_name)
    {
        $tax_classes = WC_Tax::get_tax_classes();
        $target_slug = sanitize_title($tax_class_name);

        // Check if class already exists by slug.
        foreach ($tax_classes as $existing_class) {
            if (sanitize_title($existing_class) === $target_slug) {
                return $target_slug;
            }
        }

        // Add class when missing.
        $tax_classes[] = $tax_class_name;
        update_option('woocommerce_tax_classes', implode("\n", $tax_classes));

        return $target_slug;
    }

    /**
     * Get EU countries that WooCommerce sells to
     */
    private function get_wc_eu_countries()
    {
        $wc_countries = WC()->countries->get_allowed_countries();
        $eu_countries = array();

        foreach (self::$eu_vat_rates as $code => $data) {
            if (isset($wc_countries[$code])) {
                $eu_countries[$code] = $data['country_name'];
            }
        }

        return $eu_countries;
    }

    /**
     * Render admin page
     */
    public function render_admin_page()
    {
        $eu_countries = $this->get_wc_eu_countries();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('EU VAT Rate Importer', 'eu-vat-guard-for-woocommerce'); ?></h1>

            <div class="vat-importer-notice">
                <p><?php esc_html_e('Import current EU VAT rates into your WooCommerce tax settings. This tool will create or update tax rates for the selected countries.', 'eu-vat-guard-for-woocommerce'); ?>
                </p>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('eu_vat_guard_import_rates', 'eu_vat_guard_import_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label><?php esc_html_e('Select Countries', 'eu-vat-guard-for-woocommerce'); ?></label>
                        </th>
                        <td>
                            <div class="vat-importer-countries">
                                <p>
                                    <label>
                                        <input type="checkbox" id="select-all-countries">
                                        <strong><?php esc_html_e('Select All', 'eu-vat-guard-for-woocommerce'); ?></strong>
                                    </label>
                                </p>
                                <hr>
                                <?php foreach ($eu_countries as $code => $name): ?>
                                    <p>
                                        <label>
                                            <input type="checkbox" name="selected_countries[]"
                                                value="<?php echo esc_attr($code); ?>" class="country-checkbox">
                                            <?php echo esc_html($name); ?> (<?php echo esc_html($code); ?>)
                                            <span class="description">
                                                - <?php echo esc_html(self::$eu_vat_rates[$code]['standard']); ?>%
                                                <?php esc_html_e('standard', 'eu-vat-guard-for-woocommerce'); ?>
                                                <?php if (!empty(self::$eu_vat_rates[$code]['reduced'])): ?>
                                                    , <?php echo esc_html(self::$eu_vat_rates[$code]['reduced'][0]); ?>%
                                                    <?php esc_html_e('reduced', 'eu-vat-guard-for-woocommerce'); ?>
                                                <?php endif; ?>
                                            </span>
                                        </label>
                                    </p>
                                <?php endforeach; ?>
                            </div>
                            <p class="description">
                                <?php esc_html_e('Only countries that are enabled in your WooCommerce selling locations are shown.', 'eu-vat-guard-for-woocommerce'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label
                                for="include_reduced_rates"><?php esc_html_e('Include Reduced Rates', 'eu-vat-guard-for-woocommerce'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="include_reduced_rates" value="yes" id="include_reduced_rates">
                                <?php esc_html_e('Import reduced VAT rates (lower rates for specific goods)', 'eu-vat-guard-for-woocommerce'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Only the primary reduced VAT rate per country is imported into WooCommerce\'s default Reduced rate tax class.', 'eu-vat-guard-for-woocommerce'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="eu_vat_guard_import_rates" class="button-primary vat-importer-submit"
                        value="<?php esc_attr_e('Import Selected VAT Rates', 'eu-vat-guard-for-woocommerce'); ?>">
                </p>
            </form>

            <div class="postbox vat-rates-overview">
                <h3 class="hndle"><?php esc_html_e('Current EU VAT Rates Overview', 'eu-vat-guard-for-woocommerce'); ?></h3>
                <div class="inside">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Country', 'eu-vat-guard-for-woocommerce'); ?></th>
                                <th><?php esc_html_e('Standard Rate', 'eu-vat-guard-for-woocommerce'); ?></th>
                                <th><?php esc_html_e('Reduced Rates', 'eu-vat-guard-for-woocommerce'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (self::$eu_vat_rates as $code => $data): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($data['country_name']); ?></strong>
                                        (<?php echo esc_html($code); ?>)</td>
                                    <td><?php echo esc_html($data['standard']); ?>%</td>
                                    <td>
                                        <?php if (!empty($data['reduced'])): ?>
                                            <?php echo esc_html(implode('%, ', $data['reduced'])); ?>%
                                        <?php else: ?>
                                            <em><?php esc_html_e('None', 'eu-vat-guard-for-woocommerce'); ?></em>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
}

// Initialize the importer
if (is_admin()) {
    new VAT_Guard_Rate_Importer();
}
