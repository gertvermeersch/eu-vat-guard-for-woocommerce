<?php
/**
 * VAT Guard for WooCommerce Admin Page
 *
 * @package Stormlabs\EUVATGuard
 */

namespace Stormlabs\EUVATGuard;

if (!defined('ABSPATH')) {
    exit;
}

class VAT_Guard_Admin
{

    private static $instance;

    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new VAT_Guard_Admin();
        }
        return self::$instance;
    }
    public function __construct()
    {
        if (is_admin() && !wp_doing_ajax()) {
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('admin_init', [$this, 'register_settings']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);
            
            // Add VAT field to admin order billing address editing
            add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'add_vat_field_to_admin_order'));

            // Save VAT field when order is updated in admin
            add_action('woocommerce_process_shop_order_meta', array($this, 'save_admin_order_vat_field'));

            // Display admin notices for VAT validation errors
            add_action('admin_notices', array($this, 'display_vat_validation_notices'));
        } else if(is_admin() && wp_doing_ajax()) {
              // Initialize PDF integration
            $this->init_pdf_integration();
        }
        require_once('class-vat-guard-helper.php');
        require_once('class-vat-guard-admin-ui.php');
    }

    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles($hook)
    {
        // Only load on VAT Guard admin pages
        if (strpos($hook, 'eu-vat-guard') !== false) {
            VAT_Guard_Admin_UI::enqueue_styles();
        }
    }

     /**
     * Initialize PDF integration
     */
    private function init_pdf_integration()
    {
        // Load PDF integration class
        if (!class_exists(__NAMESPACE__ . '\VAT_Guard_PDF_Integration')) {
            require_once plugin_dir_path(__FILE__) . 'class-vat-guard-pdf-integration.php';
        }

        // Initialize PDF integration
        VAT_Guard_PDF_Integration::instance();
    }

    /**
     * Add VAT field to admin order billing address section
     */
    public function add_vat_field_to_admin_order($order)
    {
        // Get current VAT number
        $vat_number = '';
        if ($order && $order->get_id()) {
            $vat_number = VAT_Guard_Helper::get_order_vat_number($order);
        }

        // Check if we're in edit mode by looking for the edit action in the request
        $is_edit_mode = isset($_GET['action']) && $_GET['action'] === 'edit';

        // If not in edit mode and VAT number exists, show it as read-only text
        if (!$is_edit_mode && !empty($vat_number)) {
            echo '<p class="form-field form-field-wide">';
            echo '<strong>' . esc_html(VAT_Guard_Helper::get_vat_label()) . ':</strong> ';
            echo '<span>' . esc_html($vat_number) . '</span>';
            echo '</p>';
        } else {
            // Add the VAT field using WooCommerce's woocommerce_wp_text_input function
            woocommerce_wp_text_input(array(
                'id' => EU_VAT_GUARD_META_ORDER_VAT,
                'label' => VAT_Guard_Helper::get_vat_label(),
                'placeholder' => __('Enter VAT number', 'eu-vat-guard-for-woocommerce'),
                'value' => $vat_number,
                'wrapper_class' => 'form-field-wide'
            ));
        }

        // Show current exemption status if applicable
        $is_exempt = $order && $order->get_id() ? $order->get_meta(EU_VAT_GUARD_META_ORDER_EXEMPT) : '';
        if ($is_exempt === 'yes') {
            echo '<p style="color: #00a32a; font-weight: bold; margin: 5px 0;">✓ ' . esc_html(VAT_Guard_Helper::get_exemption_message()) . '</p>';
        }
    }


    /**
     * Save VAT field when order is updated in admin
     */
    public function save_admin_order_vat_field($post_id)
    {
        if (isset($_POST[EU_VAT_GUARD_META_ORDER_VAT])) {
            $vat_number = sanitize_text_field(wp_unslash($_POST[EU_VAT_GUARD_META_ORDER_VAT]));

            // Get the order object
            $order = wc_get_order($post_id);
            if (!$order) {
                return;
            }

            // Validate VAT number if provided
            if (!empty($vat_number)) {
                $error = '';
                $validation_result = VAT_Guard_Helper::is_valid_eu_vat_number($vat_number, $error);

                if ($validation_result) {
                    // Save VAT number
                    $order->update_meta_data(EU_VAT_GUARD_META_ORDER_VAT, $vat_number);

                    // Set VAT exempt status if validation passed
                    $order->update_meta_data(EU_VAT_GUARD_META_ORDER_EXEMPT, 'yes');

                    // Save the order
                    $order->save();

                    // Add order note
                    $order->add_order_note(
                        /* translators: %s: VAT number */
                        sprintf(__('VAT number %s added and validated via admin.', 'eu-vat-guard-for-woocommerce'), $vat_number)
                    );
                } else {
                    // Store error message in transient for display on next page load
                    set_transient('eu_vat_guard_admin_error_' . get_current_user_id(), $error, 30);
                }
            } else {
                // Remove VAT number and exempt status if field is empty
                $order->delete_meta_data(EU_VAT_GUARD_META_ORDER_VAT);
                $order->delete_meta_data(EU_VAT_GUARD_META_ORDER_EXEMPT);
                $order->save();

                $order->add_order_note(__('VAT number removed via admin.', 'eu-vat-guard-for-woocommerce'));
            }
        }
    }

    /**
     * Display VAT validation error notices stored in transients
     */
    public function display_vat_validation_notices()
    {
        $error_message = get_transient('eu_vat_guard_admin_error_' . get_current_user_id());
        if ($error_message) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error_message) . '</p></div>';
            delete_transient('eu_vat_guard_admin_error_' . get_current_user_id());
        }
    }

    public function register_settings()
    {
        // Basic settings group
        register_setting('eu_vat_guard_basic_options', 'eu_vat_guard_require_company', [
            'type' => 'string',
            'default' => '1',
            'sanitize_callback' => function($value) {
                return $value ? '1' : '0';
            }
        ]);
        register_setting('eu_vat_guard_basic_options', 'eu_vat_guard_require_vat', [
            'type' => 'string',
            'default' => '1',
            'sanitize_callback' => function($value) {
                return $value ? '1' : '0';
            }
        ]);
        register_setting('eu_vat_guard_basic_options', 'eu_vat_guard_require_vies', [
            'type' => 'string',
            'default' => '0',
            'sanitize_callback' => function($value) {
                return $value ? '1' : '0';
            }
        ]);
        register_setting('eu_vat_guard_basic_options', 'eu_vat_guard_ignore_vies_error', [
            'type' => 'string',
            'default' => '0',
            'sanitize_callback' => function($value) {
                return $value ? '1' : '0';
            }
        ]);
        register_setting('eu_vat_guard_basic_options', 'eu_vat_guard_enable_block_checkout', [
            'type' => 'string',
            'default' => '1',
            'sanitize_callback' => function($value) {
                return $value ? '1' : '0';
            }
        ]);
        register_setting('eu_vat_guard_basic_options', 'eu_vat_guard_hide_registration_fields', [
            'type' => 'string',
            'default' => '0',
            'sanitize_callback' => function($value) {
                return $value ? '1' : '0';
            }
        ]);

        // Advanced settings group
        register_setting('eu_vat_guard_advanced_options', 'eu_vat_guard_disable_exemption', [
            'type' => 'string',
            'default' => '0',
            'sanitize_callback' => function($value) {
                return $value ? '1' : '0';
            }
        ]);
        register_setting('eu_vat_guard_advanced_options', 'eu_vat_guard_fixed_prices', [
            'type' => 'string',
            'default' => '0',
            'sanitize_callback' => function($value) {
                return $value ? '1' : '0';
            }
        ]);
        register_setting('eu_vat_guard_advanced_options', 'eu_vat_guard_company_label', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        register_setting('eu_vat_guard_advanced_options', 'eu_vat_guard_vat_label', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        register_setting('eu_vat_guard_advanced_options', 'eu_vat_guard_exemption_message', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        register_setting('eu_vat_guard_advanced_options', 'eu_vat_guard_override_b2b_plugins', [
            'type' => 'string',
            'default' => '0',
            'sanitize_callback' => function($value) {
                return $value ? '1' : '0';
            }
        ]);

        // Hook to register WPML strings when settings are saved
        add_action('update_option_eu_vat_guard_company_label', [$this, 'register_wpml_string'], 10, 3);
        add_action('update_option_eu_vat_guard_vat_label', [$this, 'register_wpml_string'], 10, 3);
        add_action('update_option_eu_vat_guard_exemption_message', [$this, 'register_wpml_string'], 10, 3);
    }

    /**
     * Register custom strings with WPML when they're saved
     */
    public function register_wpml_string($old_value, $new_value, $option_name)
    {
        // Only register if WPML is active and string is not empty
        if (!function_exists('icl_register_string') || empty($new_value)) {
            return;
        }

        // Map option names to WPML string names
        $string_names = [
            'eu_vat_guard_company_label' => 'Company Label',
            'eu_vat_guard_vat_label' => 'VAT Label',
            'eu_vat_guard_exemption_message' => 'Exemption Message'
        ];

        if (isset($string_names[$option_name])) {
            icl_register_string('EU VAT Guard', $string_names[$option_name], $new_value);
        }
    }

    public function add_admin_menu()
    {
        // Add main menu page
        add_menu_page(
            __('EU VAT Guard', 'eu-vat-guard-for-woocommerce'),
            __('EU VAT Guard', 'eu-vat-guard-for-woocommerce'),
            'manage_woocommerce',
            'eu-vat-guard',
            array($this, 'admin_page'),
            'dashicons-shield-alt',
            56
        );

        // Add settings submenu (duplicate main menu item)
        add_submenu_page(
            'eu-vat-guard',
            __('VAT Guard Settings', 'eu-vat-guard-for-woocommerce'),
            __('Settings', 'eu-vat-guard-for-woocommerce'),
            'manage_woocommerce',
            'eu-vat-guard',
            array($this, 'admin_page')
        );
        //load menu items from the pro plugin
        do_action('eu_vat_guard_admin_page_content');
    }



    public function admin_page()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Simple tab navigation, no data modification
        $active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';
        ?>
        <div class="wrap">
            <?php
            VAT_Guard_Admin_UI::page_header(
                __('EU VAT Guard for WooCommerce', 'eu-vat-guard-for-woocommerce'),
                '🛡️'
            );

            VAT_Guard_Admin_UI::support_banner(
                __('Thank you for using VAT Guard for WooCommerce! Your support helps us keep improving.', 'eu-vat-guard-for-woocommerce'),
                array(
                    array(
                        'url' => 'https://wordpress.org/support/plugin/eu-vat-guard-for-woocommerce/',
                        'label' => __('Get Support', 'eu-vat-guard-for-woocommerce'),
                        'target' => '_blank'
                    ),
                    array(
                        'url' => 'https://wordpress.org/plugins/eu-vat-guard-for-woocommerce/#reviews',
                        'label' => __('Leave a Review', 'eu-vat-guard-for-woocommerce'),
                        'target' => '_blank'
                    )
                )
            );

            $tabs = array(
                array('id' => 'settings', 'label' => __('Settings', 'eu-vat-guard-for-woocommerce')),
                array('id' => 'advanced', 'label' => __('Advanced', 'eu-vat-guard-for-woocommerce')),
                array('id' => 'documentation', 'label' => __('How It Works', 'eu-vat-guard-for-woocommerce')),
                array('id' => 'help', 'label' => __('Help', 'eu-vat-guard-for-woocommerce')),
            );

            VAT_Guard_Admin_UI::tab_navigation($tabs, $active_tab);
            ?>

            <?php if ($active_tab == 'settings'): ?>
                <?php $this->render_settings_tab(); ?>
            <?php elseif ($active_tab == 'advanced'): ?>
                <?php $this->render_advanced_tab(); ?>
            <?php elseif ($active_tab == 'documentation'): ?>
                <?php $this->render_documentation_tab(); ?>
            <?php elseif ($active_tab == 'help'): ?>
                <?php $this->render_help_tab(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_settings_tab()
    {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('eu_vat_guard_basic_options'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Block-based Checkout Support', 'eu-vat-guard-for-woocommerce'); ?>
                    </th>
                    <td>
                        <input type="checkbox" name="eu_vat_guard_enable_block_checkout" value="1" <?php checked(1, get_option('eu_vat_guard_enable_block_checkout', 0)); ?> />
                        <label
                            for="eu_vat_guard_enable_block_checkout"><?php esc_html_e('Enable support for WooCommerce Block-based Checkout (Cart & Checkout Blocks)', 'eu-vat-guard-for-woocommerce'); ?></label>
                        <p class="description">
                            <?php esc_html_e('Disable when using classic checkout or Cartflows', 'eu-vat-guard-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Hide Registration Fields', 'eu-vat-guard-for-woocommerce'); ?></th>
                    <td>
                        <input type="checkbox" name="eu_vat_guard_hide_registration_fields" value="1" <?php checked(1, get_option('eu_vat_guard_hide_registration_fields', 0)); ?> />
                        <label for="eu_vat_guard_hide_registration_fields"><?php esc_html_e('Hide VAT and company fields on registration form', 'eu-vat-guard-for-woocommerce'); ?></label>
                        <p class="description">
                            <?php esc_html_e('When enabled, VAT number and company name fields will not appear on the registration form. Customers can still add this information in their account area or during checkout.', 'eu-vat-guard-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Require Company Name', 'eu-vat-guard-for-woocommerce'); ?></th>
                    <td>
                        <input type="checkbox" name="eu_vat_guard_require_company" value="1" <?php checked(1, get_option('eu_vat_guard_require_company', 1)); ?> />
                        <label
                            for="eu_vat_guard_require_company"><?php esc_html_e('Make company name a required field', 'eu-vat-guard-for-woocommerce'); ?></label>
                        <p class="description">
                            <?php esc_html_e('Warning: This does not have effect on the block based checkout. You need to manually enable the Company name field in the block editor.', 'eu-vat-guard-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Require VAT Number - only sell B2B', 'eu-vat-guard-for-woocommerce'); ?>
                    </th>
                    <td>
                        <input type="checkbox" name="eu_vat_guard_require_vat" value="1" <?php checked(1, get_option('eu_vat_guard_require_vat', 1)); ?> />
                        <label
                            for="eu_vat_guard_require_vat"><?php esc_html_e('Make VAT number a required field', 'eu-vat-guard-for-woocommerce'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Validate VAT Number with VIES', 'eu-vat-guard-for-woocommerce'); ?></th>
                    <td>
                        <input type="checkbox" name="eu_vat_guard_require_vies" value="1" <?php checked(1, get_option('eu_vat_guard_require_vies', 0)); ?> />
                        <label
                            for="eu_vat_guard_require_vies"><?php esc_html_e('Check VAT number validity with the official VIES service', 'eu-vat-guard-for-woocommerce'); ?></label>
                        <p class="description">
                            <?php esc_html_e('VIES (VAT Information Exchange System) is a search engine (not a database) owned by the European Commission', 'eu-vat-guard-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Ignore VIES errors', 'eu-vat-guard-for-woocommerce'); ?></th>
                    <td>
                        <input type="checkbox" name="eu_vat_guard_ignore_vies_error" value="1" <?php checked(1, get_option('eu_vat_guard_ignore_vies_error', 0)); ?> />
                        <label
                            for="eu_vat_guard_ignore_vies_error"><?php esc_html_e('Allow checkout if VIES is unavailable or returns an error', 'eu-vat-guard-for-woocommerce'); ?></label>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
    }

    private function render_advanced_tab()
    {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('eu_vat_guard_advanced_options'); ?>

            <?php VAT_Guard_Admin_UI::section_header(__('Exemption Rules', 'eu-vat-guard-for-woocommerce')); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Disable VAT Exemption', 'eu-vat-guard-for-woocommerce'); ?></th>
                    <td>
                        <input type="checkbox" name="eu_vat_guard_disable_exemption" value="1" <?php checked(1, get_option('eu_vat_guard_disable_exemption', 0)); ?> />
                        <label
                            for="eu_vat_guard_disable_exemption"><?php esc_html_e('Skip VAT exemption entirely and just register VAT numbers', 'eu-vat-guard-for-woocommerce'); ?></label>
                        <p class="description">
                            <?php esc_html_e('When enabled, VAT numbers will be collected and validated but no tax exemption will be applied.', 'eu-vat-guard-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php VAT_Guard_Admin_UI::section_header(__('Pricing Options', 'eu-vat-guard-for-woocommerce')); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Fixed Prices Including VAT', 'eu-vat-guard-for-woocommerce'); ?></th>
                    <td>
                        <input type="checkbox" name="eu_vat_guard_fixed_prices" value="1" <?php checked(1, get_option('eu_vat_guard_fixed_prices', 0)); ?> />
                        <label
                            for="eu_vat_guard_fixed_prices"><?php esc_html_e('Show the same prices including VAT regardless of customer location', 'eu-vat-guard-for-woocommerce'); ?></label>
                        <p class="description">
                            <?php esc_html_e('When enabled, all customers will see the same prices including VAT, regardless of their country. This prevents WooCommerce from adjusting prices based on location.', 'eu-vat-guard-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php VAT_Guard_Admin_UI::section_header(__('Plugin Compatibility', 'eu-vat-guard-for-woocommerce')); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Override B2B Plugin VAT Settings', 'eu-vat-guard-for-woocommerce'); ?></th>
                    <td>
                        <input type="checkbox" name="eu_vat_guard_override_b2b_plugins" value="1" <?php checked(1, get_option('eu_vat_guard_override_b2b_plugins', 0)); ?> />
                        <label for="eu_vat_guard_override_b2b_plugins"><?php esc_html_e('Enable compatibility mode for B2B plugins', 'eu-vat-guard-for-woocommerce'); ?></label>
                        <p class="description">
                            <?php esc_html_e('Enable this if you use WooCommerce B2B or similar plugins that interfere with VAT exemption. This ensures EU VAT Guard\'s exemption decisions take priority.', 'eu-vat-guard-for-woocommerce'); ?>
                            <br><strong><?php esc_html_e('Note:', 'eu-vat-guard-for-woocommerce'); ?></strong> <?php esc_html_e('Only enable if you experience issues with VAT being re-added after checkout.', 'eu-vat-guard-for-woocommerce'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php VAT_Guard_Admin_UI::section_header(__('Custom Labels & Messages', 'eu-vat-guard-for-woocommerce')); ?>
            <p><?php esc_html_e('These labels and messages will be displayed on the checkout page. Can be translated (e.g. WPML String Translations)', 'eu-vat-guard-for-woocommerce'); ?>
            </p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label
                            for="eu_vat_guard_company_label"><?php esc_html_e('Company Name Label', 'eu-vat-guard-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="eu_vat_guard_company_label" name="eu_vat_guard_company_label"
                            value="<?php echo esc_attr(get_option('eu_vat_guard_company_label', '')); ?>" class="regular-text"
                            placeholder="<?php esc_attr_e('Company Name', 'eu-vat-guard-for-woocommerce'); ?>" />
                        <p class="description">
                            <?php esc_html_e('Custom label for the company name field. Leave empty to use default.', 'eu-vat-guard-for-woocommerce'); ?>
                            <?php if (function_exists('icl_register_string')): ?>
                                <br><em><?php esc_html_e('This string will be registered with WPML for translation.', 'eu-vat-guard-for-woocommerce'); ?></em>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label
                            for="eu_vat_guard_vat_label"><?php esc_html_e('VAT Number Label', 'eu-vat-guard-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="eu_vat_guard_vat_label" name="eu_vat_guard_vat_label"
                            value="<?php echo esc_attr(get_option('eu_vat_guard_vat_label', '')); ?>" class="regular-text"
                            placeholder="<?php esc_attr_e('VAT Number', 'eu-vat-guard-for-woocommerce'); ?>" />
                        <p class="description">
                            <?php esc_html_e('Custom label for the VAT number field. Leave empty to use default.', 'eu-vat-guard-for-woocommerce'); ?>
                            <?php if (function_exists('icl_register_string')): ?>
                                <br><em><?php esc_html_e('This string will be registered with WPML for translation.', 'eu-vat-guard-for-woocommerce'); ?></em>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label
                            for="eu_vat_guard_exemption_message"><?php esc_html_e('VAT Exemption Message', 'eu-vat-guard-for-woocommerce'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="eu_vat_guard_exemption_message" name="eu_vat_guard_exemption_message"
                            value="<?php echo esc_attr(get_option('eu_vat_guard_exemption_message', '')); ?>"
                            class="regular-text"
                            placeholder="<?php esc_attr_e('VAT exempt for this order', 'eu-vat-guard-for-woocommerce'); ?>" />
                        <p class="description">
                            <?php esc_html_e('Custom message shown when VAT exemption is applied. Leave empty to use default.', 'eu-vat-guard-for-woocommerce'); ?>
                            <?php if (function_exists('icl_register_string')): ?>
                                <br><em><?php esc_html_e('This string will be registered with WPML for translation.', 'eu-vat-guard-for-woocommerce'); ?></em>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <?php
        VAT_Guard_Admin_UI::info_box(
            'warning',
            '<ul style="margin-left: 20px;">
                <li><strong>' . esc_html__('Disable VAT Exemption:', 'eu-vat-guard-for-woocommerce') . '</strong> ' .
                    esc_html__('Useful if you only want to collect VAT numbers for record-keeping without applying tax exemptions.', 'eu-vat-guard-for-woocommerce') . '</li>
                <li><strong>' . esc_html__('Fixed Prices Including VAT:', 'eu-vat-guard-for-woocommerce') . '</strong> ' .
                    esc_html__('Prevents WooCommerce from adjusting prices based on customer location. All customers see the same prices including VAT.', 'eu-vat-guard-for-woocommerce') . '</li>
                <li><strong>' . esc_html__('Custom Labels:', 'eu-vat-guard-for-woocommerce') . '</strong> ' .
                    esc_html__('Override default field labels to match your store\'s terminology or language preferences.', 'eu-vat-guard-for-woocommerce') . '</li>
                <li><strong>' . esc_html__('Custom Messages:', 'eu-vat-guard-for-woocommerce') . '</strong> ' .
                    esc_html__('Customize the VAT exemption message shown to customers during checkout.', 'eu-vat-guard-for-woocommerce') . '</li>
                <li><strong>' . esc_html__('WPML Compatibility:', 'eu-vat-guard-for-woocommerce') . '</strong> ' .
                    esc_html__('Custom strings are automatically registered with WPML for translation when saved.', 'eu-vat-guard-for-woocommerce') . '</li>
            </ul>',
            __('⚙️ Advanced Settings Info', 'eu-vat-guard-for-woocommerce')
        );

        if (function_exists('icl_register_string')) {
            VAT_Guard_Admin_UI::info_box(
                'info',
                '<p>' . esc_html__('WPML is active! Custom strings will be automatically registered for translation when you save them. You can translate them in:', 'eu-vat-guard-for-woocommerce') . '</p>
                <p><strong>WPML → String Translation → EU VAT Guard</strong></p>',
                __('🌍 WPML Translation', 'eu-vat-guard-for-woocommerce')
            );
        }
    }

    private function render_documentation_tab()
    {
        ?>
        <div style="max-width: 800px;">
            <h2><?php esc_html_e('How VAT Guard Works', 'eu-vat-guard-for-woocommerce'); ?></h2>

            <div style="background: #f9f9f9; border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 5px;">
                <h3 style="margin-top: 0;"><?php esc_html_e('🎯 Purpose', 'eu-vat-guard-for-woocommerce'); ?></h3>
                <p><?php esc_html_e('VAT Guard helps you comply with EU VAT regulations by automatically managing VAT exemptions for valid B2B transactions between EU member states.', 'eu-vat-guard-for-woocommerce'); ?>
                </p>
            </div>

            <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 20px; margin: 20px 0; border-radius: 5px;">
                <h3 style="margin-top: 0;"><?php esc_html_e('⚖️ VAT Exemption Rules', 'eu-vat-guard-for-woocommerce'); ?></h3>
                <p><?php esc_html_e('VAT exemption is automatically applied when ALL of the following conditions are met:', 'eu-vat-guard-for-woocommerce'); ?>
                </p>
                <ul style="margin-left: 20px;">
                    <li><?php esc_html_e('Customer provides a valid EU VAT number', 'eu-vat-guard-for-woocommerce'); ?></li>
                    <li><?php esc_html_e('VAT number country is different from your store\'s base country', 'eu-vat-guard-for-woocommerce'); ?>
                    </li>
                    <li><?php esc_html_e('Shipping method is NOT local pickup', 'eu-vat-guard-for-woocommerce'); ?></li>
                    <li><?php esc_html_e('Billing and shipping countries match the VAT number country', 'eu-vat-guard-for-woocommerce'); ?>
                    </li>
                </ul>
                <p><strong><?php esc_html_e('Important:', 'eu-vat-guard-for-woocommerce'); ?></strong>
                    <?php esc_html_e('If local pickup is selected, VAT is always charged regardless of the VAT number.', 'eu-vat-guard-for-woocommerce'); ?>
                </p>
            </div>

            <div style="background: #e8f4fd; border: 1px solid #bee5eb; padding: 20px; margin: 20px 0; border-radius: 5px;">
                <h3 style="margin-top: 0;"><?php esc_html_e('🔍 VAT Number Validation', 'eu-vat-guard-for-woocommerce'); ?></h3>
                <p><?php esc_html_e('The plugin validates VAT numbers in two stages:', 'eu-vat-guard-for-woocommerce'); ?></p>
                <ol style="margin-left: 20px;">
                    <li><strong><?php esc_html_e('Format Validation:', 'eu-vat-guard-for-woocommerce'); ?></strong>
                        <?php esc_html_e('Checks if the VAT number matches the correct format for each EU country', 'eu-vat-guard-for-woocommerce'); ?>
                    </li>
                    <li><strong><?php esc_html_e('VIES Validation (Optional):', 'eu-vat-guard-for-woocommerce'); ?></strong>
                        <?php esc_html_e('Verifies the VAT number exists in the official EU VIES database', 'eu-vat-guard-for-woocommerce'); ?>
                    </li>
                </ol>
            </div>

            <div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 20px; margin: 20px 0; border-radius: 5px;">
                <h3 style="margin-top: 0;"><?php esc_html_e('🚫 When VAT is NOT Exempted', 'eu-vat-guard-for-woocommerce'); ?>
                </h3>
                <ul style="margin-left: 20px;">
                    <li><?php esc_html_e('No VAT number provided', 'eu-vat-guard-for-woocommerce'); ?></li>
                    <li><?php esc_html_e('Invalid VAT number format', 'eu-vat-guard-for-woocommerce'); ?></li>
                    <li><?php esc_html_e('VAT number country same as store base country', 'eu-vat-guard-for-woocommerce'); ?>
                    </li>
                    <li><?php esc_html_e('Local pickup shipping method selected', 'eu-vat-guard-for-woocommerce'); ?></li>
                    <li><?php esc_html_e('Billing/shipping country doesn\'t match VAT number country', 'eu-vat-guard-for-woocommerce'); ?>
                    </li>
                    <li><?php esc_html_e('VIES validation fails (if enabled)', 'eu-vat-guard-for-woocommerce'); ?></li>
                </ul>
            </div>

            <div style="background: #d1ecf1; border: 1px solid #bee5eb; padding: 20px; margin: 20px 0; border-radius: 5px;">
                <h3 style="margin-top: 0;"><?php esc_html_e('📋 Supported Features', 'eu-vat-guard-for-woocommerce'); ?></h3>
                <ul style="margin-left: 20px;">
                    <li><?php esc_html_e('Classic WooCommerce checkout', 'eu-vat-guard-for-woocommerce'); ?></li>
                    <li><?php esc_html_e('Block-based checkout (Cart & Checkout blocks)', 'eu-vat-guard-for-woocommerce'); ?>
                    </li>
                    <li><?php esc_html_e('Customer registration and account pages', 'eu-vat-guard-for-woocommerce'); ?></li>
                    <li><?php esc_html_e('Admin order management', 'eu-vat-guard-for-woocommerce'); ?></li>
                    <li><?php esc_html_e('Email notifications', 'eu-vat-guard-for-woocommerce'); ?></li>
                    <li><?php esc_html_e('All 27 EU member states VAT formats', 'eu-vat-guard-for-woocommerce'); ?></li>
                </ul>
            </div>

            <div style="background: #fff2cc; border: 1px solid #ffeaa7; padding: 20px; margin: 20px 0; border-radius: 5px;">
                <h3 style="margin-top: 0;"><?php esc_html_e('⚙️ Configuration Tips', 'eu-vat-guard-for-woocommerce'); ?></h3>
                <ul style="margin-left: 20px;">
                    <li><strong><?php esc_html_e('B2B Only:', 'eu-vat-guard-for-woocommerce'); ?></strong>
                        <?php esc_html_e('Enable "Require VAT Number" to sell only to businesses', 'eu-vat-guard-for-woocommerce'); ?>
                    </li>
                    <li><strong><?php esc_html_e('VIES Validation:', 'eu-vat-guard-for-woocommerce'); ?></strong>
                        <?php esc_html_e('Enable for stricter validation, but consider enabling "Ignore VIES errors" for better user experience when the service is down', 'eu-vat-guard-for-woocommerce'); ?>
                    </li>
                    <li><strong><?php esc_html_e('Block Checkout:', 'eu-vat-guard-for-woocommerce'); ?></strong>
                        <?php esc_html_e('Enable if you\'re using WooCommerce\'s new block-based checkout', 'eu-vat-guard-for-woocommerce'); ?>
                    </li>
                </ul>
            </div>

            <div style="background: #e2e3e5; border: 1px solid #d6d8db; padding: 20px; margin: 20px 0; border-radius: 5px;">
                <h3 style="margin-top: 0;"><?php esc_html_e('📞 Need Help?', 'eu-vat-guard-for-woocommerce'); ?></h3>
                <p><?php esc_html_e('If you encounter any issues or need assistance with VAT compliance, please contact our support team. We\'re here to help ensure your store meets all EU VAT requirements.', 'eu-vat-guard-for-woocommerce'); ?>
                </p>
                <p><strong><?php esc_html_e('Email:', 'eu-vat-guard-for-woocommerce'); ?></strong>
                    <a href="mailto:dev@stormlabs.be" style="color: #2271b1;">dev@stormlabs.be</a>
                </p>
                <p><strong><?php esc_html_e('Website:', 'eu-vat-guard-for-woocommerce'); ?></strong>
                    <a href="https://stormlabs.be/" target="_blank" style="color: #2271b1;">stormlabs.be</a>
                </p>
            </div>

        </div>
        <?php
    }

    private function render_help_tab()
    {
        ?>
        <div style="max-width: 800px;">
            <h2><?php esc_html_e('Help & Support', 'eu-vat-guard-for-woocommerce'); ?></h2>

            <div style="background: #fff; border: 1px solid #ddd; padding: 30px; margin: 20px 0; border-radius: 5px;">
                <h3 style="margin-top: 0; display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 1.5em;">💬</span>
                    <?php esc_html_e('Get Support', 'eu-vat-guard-for-woocommerce'); ?>
                </h3>
                <p><?php esc_html_e('Need help with the plugin? Have questions about VAT compliance or configuration?', 'eu-vat-guard-for-woocommerce'); ?></p>
                <p><?php esc_html_e('Visit our support forum on WordPress.org where our team and community can help you:', 'eu-vat-guard-for-woocommerce'); ?></p>
                <p style="margin: 20px 0;">
                    <a href="https://wordpress.org/support/plugin/eu-vat-guard-for-woocommerce/" 
                       target="_blank" 
                       class="button button-primary button-hero"
                       style="text-decoration: none;">
                        <?php esc_html_e('Visit Support Forum', 'eu-vat-guard-for-woocommerce'); ?>
                    </a>
                </p>
                <p class="description">
                    <?php esc_html_e('Before posting, please search existing topics - your question might already be answered!', 'eu-vat-guard-for-woocommerce'); ?>
                </p>
            </div>

            <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 30px; margin: 20px 0; border-radius: 5px;">
                <h3 style="margin-top: 0; display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 1.5em;">⭐</span>
                    <?php esc_html_e('Love This Plugin?', 'eu-vat-guard-for-woocommerce'); ?>
                </h3>
                <p><?php esc_html_e('If EU VAT Guard has helped your business, please consider leaving a review. Your feedback helps us improve and helps other store owners discover the plugin.', 'eu-vat-guard-for-woocommerce'); ?></p>
                <p style="margin: 20px 0;">
                    <a href="https://wordpress.org/plugins/eu-vat-guard-for-woocommerce/#reviews" 
                       target="_blank" 
                       class="button button-secondary button-hero"
                       style="text-decoration: none;">
                        <?php esc_html_e('Leave a Review', 'eu-vat-guard-for-woocommerce'); ?>
                    </a>
                </p>
                <p class="description">
                    <?php esc_html_e('It only takes a minute and means the world to us! 🙏', 'eu-vat-guard-for-woocommerce'); ?>
                </p>
            </div>

            <div style="background: #e7f7e7; border: 1px solid #46b450; padding: 30px; margin: 20px 0; border-radius: 5px;">
                <h3 style="margin-top: 0; display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 1.5em;">📚</span>
                    <?php esc_html_e('Documentation', 'eu-vat-guard-for-woocommerce'); ?>
                </h3>
                <p><?php esc_html_e('Learn more about how VAT Guard works and how to configure it for your store:', 'eu-vat-guard-for-woocommerce'); ?></p>
                <ul style="margin-left: 20px; line-height: 1.8;">
                    <li>
                        <a href="?page=eu-vat-guard&tab=documentation" style="color: #2271b1; text-decoration: none;">
                            <?php esc_html_e('How It Works - VAT Exemption Rules', 'eu-vat-guard-for-woocommerce'); ?>
                        </a>
                    </li>
                    <li>
                        <a href="?page=eu-vat-guard&tab=settings" style="color: #2271b1; text-decoration: none;">
                            <?php esc_html_e('Basic Settings - Configure Required Fields', 'eu-vat-guard-for-woocommerce'); ?>
                        </a>
                    </li>
                    <li>
                        <a href="?page=eu-vat-guard&tab=advanced" style="color: #2271b1; text-decoration: none;">
                            <?php esc_html_e('Advanced Settings - Custom Labels & Exemption Rules', 'eu-vat-guard-for-woocommerce'); ?>
                        </a>
                    </li>
                </ul>
            </div>

            <div style="background: #e8f4fd; border: 1px solid #bee5eb; padding: 30px; margin: 20px 0; border-radius: 5px;">
                <h3 style="margin-top: 0; display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 1.5em;">🚀</span>
                    <?php esc_html_e('Common Questions', 'eu-vat-guard-for-woocommerce'); ?>
                </h3>
                <div style="margin-top: 20px;">
                    <h4><?php esc_html_e('Does this work with WooCommerce blocks?', 'eu-vat-guard-for-woocommerce'); ?></h4>
                    <p><?php esc_html_e('Yes! The plugin fully supports both classic checkout and the new block-based checkout. Enable "Block-based Checkout Support" in Settings.', 'eu-vat-guard-for-woocommerce'); ?></p>

                    <h4><?php esc_html_e('What VAT number formats are supported?', 'eu-vat-guard-for-woocommerce'); ?></h4>
                    <p><?php esc_html_e('All 27 EU member states VAT number formats are supported with proper validation.', 'eu-vat-guard-for-woocommerce'); ?></p>

                    <h4><?php esc_html_e('Is VIES validation required?', 'eu-vat-guard-for-woocommerce'); ?></h4>
                    <p><?php esc_html_e('No, VIES validation is optional. You can use format-only validation or enable real-time VIES checking in Settings.', 'eu-vat-guard-for-woocommerce'); ?></p>

                    <h4><?php esc_html_e('Does this handle VAT exemptions automatically?', 'eu-vat-guard-for-woocommerce'); ?></h4>
                    <p><?php esc_html_e('Yes, the plugin automatically applies VAT exemptions for valid B2B transactions between different EU countries. See the How It Works tab for details.', 'eu-vat-guard-for-woocommerce'); ?></p>
                </div>
            </div>

            <div style="background: #f9f9f9; border: 1px solid #ddd; padding: 30px; margin: 20px 0; border-radius: 5px;">
                <h3 style="margin-top: 0; display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 1.5em;">🔗</span>
                    <?php esc_html_e('Useful Links', 'eu-vat-guard-for-woocommerce'); ?>
                </h3>
                <ul style="margin-left: 20px; line-height: 1.8;">
                    <li>
                        <a href="https://wordpress.org/plugins/eu-vat-guard-for-woocommerce/" target="_blank" style="color: #2271b1; text-decoration: none;">
                            <?php esc_html_e('Plugin Page on WordPress.org', 'eu-vat-guard-for-woocommerce'); ?>
                        </a>
                    </li>
                    <li>
                        <a href="https://wordpress.org/plugins/eu-vat-guard-for-woocommerce/#faq" target="_blank" style="color: #2271b1; text-decoration: none;">
                            <?php esc_html_e('Frequently Asked Questions', 'eu-vat-guard-for-woocommerce'); ?>
                        </a>
                    </li>
                    <li>
                        <a href="https://stormlabs.be/" target="_blank" style="color: #2271b1; text-decoration: none;">
                            <?php esc_html_e('Stormlabs Website', 'eu-vat-guard-for-woocommerce'); ?>
                        </a>
                    </li>
                    <li>
                        <a href="https://ec.europa.eu/taxation_customs/vies/" target="_blank" style="color: #2271b1; text-decoration: none;">
                            <?php esc_html_e('EU VIES VAT Number Validation', 'eu-vat-guard-for-woocommerce'); ?>
                        </a>
                    </li>
                </ul>
            </div>

            <div style="background: #fff; border: 2px solid #2271b1; padding: 30px; margin: 20px 0; border-radius: 5px; text-align: center;">
                <h3 style="margin-top: 0; color: #2271b1;">
                    <?php esc_html_e('Thank You for Using EU VAT Guard!', 'eu-vat-guard-for-woocommerce'); ?>
                </h3>
                <p><?php esc_html_e('We\'re committed to helping your business comply with EU VAT regulations. If you have any suggestions for improvements, we\'d love to hear from you.', 'eu-vat-guard-for-woocommerce'); ?></p>
                <p style="margin-top: 20px;">
                    <a href="https://wordpress.org/support/plugin/eu-vat-guard-for-woocommerce/" 
                       target="_blank" 
                       class="button button-primary"
                       style="text-decoration: none; margin-right: 10px;">
                        <?php esc_html_e('Get Support', 'eu-vat-guard-for-woocommerce'); ?>
                    </a>
                    <a href="https://wordpress.org/plugins/eu-vat-guard-for-woocommerce/#reviews" 
                       target="_blank" 
                       class="button button-secondary"
                       style="text-decoration: none;">
                        <?php esc_html_e('Leave a Review', 'eu-vat-guard-for-woocommerce'); ?>
                    </a>
                </p>
            </div>

        </div>
        <?php
    }
}