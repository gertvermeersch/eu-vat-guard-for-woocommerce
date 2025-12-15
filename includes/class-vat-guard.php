<?php
/**
 * VAT Guard for WooCommerce Main Class
 *
 * @package Stormlabs\EUVATGuard
 */

namespace Stormlabs\EUVATGuard;

if (!defined('ABSPATH')) {
    exit;
}

class VAT_Guard
{
    /**
     * Show a VAT exempt notice in the order review totals if VAT is removed.
     */

    private static $instance = null;

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        require_once('class-vat-guard-helper.php');
        // Load block integration early if enabled (needs to be before woocommerce_init)
        // Only load on frontend or during AJAX calls (but not in admin dashboard)
        if (!is_admin() || (wp_doing_ajax() && !VAT_Guard_Helper::is_admin_dashboard_ajax())) {
            if (get_option('eu_vat_guard_enable_block_checkout', 0)) {
                add_action('plugins_loaded', array($this, 'init_block_checkout_support'), 50);
            }
        }

        // Hook into WordPress init to set up the plugin after all plugins are loaded
        add_action('init', array($this, 'init'), 10);
    }

    /**
     * Initialize the plugin - called on 'init' hook
     */
    public function init()
    {
        // Only proceed if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Load dependencies based on context
        $this->load_dependencies();

        // Set up hooks based on current request context
        $this->setup_hooks();


    }

    /**
     * Load required dependencies based on context
     */
    private function load_dependencies()
    {
        // Always load VIES for validation (lightweight)
        if (!class_exists(__NAMESPACE__ . '\VAT_Guard_VIES')) {
            require_once plugin_dir_path(__FILE__) . 'class-vat-guard-vies.php';
        }

        if (!class_exists(__NAMESPACE__ . '\VAT_Guard_Account')) {
            require_once plugin_dir_path(__FILE__) . 'class-vat-guard-account.php';
            VAT_Guard_Account::instance();
        }

        // Load admin functionality only in admin
        if (is_admin()) {
            require_once plugin_dir_path(__FILE__) . 'class-vat-guard-admin.php';
            VAT_Guard_Admin::instance();
        }

        // Block integration is loaded earlier in maybe_init_block_support()
    }

    /**
     * Set up hooks based on current request context
     */
    private function setup_hooks()
    {
        // Frontend-specific hooks
        if (!is_admin() || wp_doing_ajax()) {
            $this->setup_frontend_hooks();

        }

        // Email hooks (needed for both frontend and admin)
        add_action('woocommerce_email_customer_details', array($this, 'show_vat_in_emails'), 20, 4);
    }

    /**
     * Set up frontend-specific hooks
     */
    private function setup_frontend_hooks()
    {
        //register hooks for account and registration forms
        VAT_Guard_Account::instance()->setup_hooks();

        // Apply fixed pricing option if enabled
        if (get_option('eu_vat_guard_fixed_prices', false)) {
            add_filter('woocommerce_adjust_non_base_location_prices', '__return_false');
        }

        // Order display hooks - always active regardless of block support setting
        add_action('woocommerce_order_details_after_customer_details', array($this, 'show_vat_in_order_details'), 10, 1);
       
        // Checkout hooks - only load when actually needed
        add_action('wp', array($this, 'maybe_setup_checkout_hooks'));

        // Critical: Override B2B plugin's VAT exemption filter with higher priority
        add_filter('woocommerce_order_is_vat_exempt', array($this, 'override_order_vat_exempt_status'), 999, 2);
        
        // Override B2B plugin's init-based VAT exemption (only if enabled in settings)
        if (get_option('eu_vat_guard_override_b2b_plugins', '0') === '1') {
            add_action('init', array($this, 'override_b2b_vat_exemption'), 999);
        }

        // Order recalculation protection hooks - run with very high priority to override B2B plugins
        //add_action('woocommerce_before_calculate_totals', array($this, 'restore_vat_exemption_from_order'), 999);
       
        // Additional hooks to catch different recalculation scenarios from B2B plugins
        //add_action('woocommerce_cart_calculate_fees', array($this, 'restore_vat_exemption_from_order'), 999);
        //add_action('woocommerce_after_calculate_totals', array($this, 'ensure_vat_exemption_persists'), 999);
        
        // Hook into order status changes (PayPal and B2B plugins often trigger these)
        //add_action('woocommerce_order_status_changed', array($this, 'restore_vat_exemption_on_status_change'), 999, 3);
        //add_action('woocommerce_payment_complete', array($this, 'restore_vat_exemption_after_payment'), 999);
        
        // Hook into order save events (B2B plugins may trigger these)
        //add_action('woocommerce_process_shop_order_meta', array($this, 'restore_vat_exemption_on_order_save'), 999, 2);
        //add_action('save_post_shop_order', array($this, 'restore_vat_exemption_on_post_save'), 999, 2);
    }


    /**
     * Conditionally set up checkout hooks only when on checkout page or processing checkout
     */
    public function maybe_setup_checkout_hooks()
    {
        if (is_checkout() || wp_doing_ajax()) {
            // Classic checkout hooks
            add_filter('woocommerce_checkout_get_value', array($this, 'preload_checkout_fields'), 10, 2);
            //add_filter('woocommerce_default_address_fields', array($this, 'default_billing_company'));
            add_filter('woocommerce_checkout_fields', array($this, 'add_checkout_vat_field'), 99);

            // Order saving hooks
            add_action('woocommerce_checkout_update_order_meta', array($this, 'save_checkout_vat_field'));

            // Validation hooks
            add_action('woocommerce_checkout_update_order_review', array($this, 'ajax_validate_and_exempt_vat'), 999);
            add_action('woocommerce_after_checkout_validation', array($this, 'on_checkout_vat_field'), 999, 2);

            // VAT exempt notice
            add_action('woocommerce_review_order_before_shipping', array($this, 'show_vat_exempt_notice_checkout'), 5);
        }
    }

    /**
     * Enqueue checkout scripts only when needed
     */
    public function enqueue_checkout_scripts()
    {
        if (is_checkout()) {
            wp_enqueue_script(
                'vat-guard-checkout',
                plugin_dir_url(dirname(__FILE__)) . '/assets/js/vat-guard-checkout.js',
                array('jquery'),
                '1.0',
                true
            );
        }
    }



    /**
     * Check if VAT exemption is disabled
     */
    public function is_exemption_disabled()
    {
        return (bool) get_option('eu_vat_guard_disable_exemption', false);
    }



   

    /**
     * Initialize block-based checkout support
     * @return void
     */
    public function init_block_checkout_support()
    {
        // Load and initialize the block integration
        if (!class_exists('VAT_Guard_Block_Integration')) {
            require_once plugin_dir_path(__FILE__) . 'class-vat-guard-block-integration.php';
        }

        // Initialize the block integration with access to main class methods
        $block_integration = new VAT_Guard_Block_Integration($this);
        $block_integration->init();
    }



    /**
     * Preload checkout fields with user meta data if available
     * @param mixed $value Current value of the field
     * @param string $input Name of the input field
     * @return mixed
     */
    public function preload_checkout_fields($value, $input)
    {
        if ($input == EU_VAT_GUARD_META_ORDER_VAT && is_user_logged_in()) {
            $vat = get_user_meta(get_current_user_id(), EU_VAT_GUARD_META_VAT_NUMBER, true);
            if (!empty($vat)) {
                $value = $vat;
            }
        } else if ($input == 'billing_company' && is_user_logged_in()) {
            $company = get_user_meta(get_current_user_id(), EU_VAT_GUARD_META_COMPANY_NAME, true);
            if (!empty($company)) {
                $value = $company;
            }
        } else if ($input == 'billing_email' && is_user_logged_in()) {
            $email = get_user_meta(get_current_user_id(), 'email', true);
            if (!empty($email)) {
                $value = $email;
            }
        }
        return $value;
    }

    /**
     * Preload billing company field with user meta data if available
     * @param array $fields Current billing fields
     * @return array Modified billing fields
     * TODO: might be not required
     */
    public function default_billing_company($fields)
    {
        if (is_user_logged_in()) {
            $company_name = get_user_meta(get_current_user_id(), EU_VAT_GUARD_META_COMPANY_NAME, true);
            if (!empty($company_name)) {
                $fields['company']['default'] = $company_name;
            }
        }
        return $fields;
    }

    /**
     * Add VAT number field to checkout
     * @param array $fields Current checkout fields
     * @return array Modified checkout fields
     */
    public function add_checkout_vat_field($fields)
    {

        $require_vat = get_option('eu_vat_guard_require_vat', 1);
        $require_company = get_option('eu_vat_guard_require_company', 1);

        $fields['billing']['billing_company'] = array(
            'type' => 'text',
            'label' => VAT_Guard_Helper::get_company_label(),
            'placeholder' => VAT_Guard_Helper::get_company_label(),
            'required' => (bool) $require_company,
            'class' => array('form-row-wide'),
            'priority' => 25,
            'default' => '',
        );

        $fields['billing'][EU_VAT_GUARD_META_ORDER_VAT] = array(
            'type' => 'text',
            'label' => VAT_Guard_Helper::get_vat_label(),
            'placeholder' => VAT_Guard_Helper::get_vat_label(),
            'required' => (bool) $require_vat,
            'class' => array('form-row-wide', 'update_totals_on_change'),
            'priority' => 26,
            'default' => '',
        );
        return $fields;
    }


    /**
     * Save VAT number and exemption status to order meta during checkout
     * Not triggered on block based checkout
     * @param int $order_id The ID of the order being created
     */
    public function save_checkout_vat_field($order_id)
    {
        // Try to get VAT number from POST data
        $vat_number = '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification for checkout process
        if (isset($_POST[EU_VAT_GUARD_META_ORDER_VAT])) {
            $vat_number = sanitize_text_field(wp_unslash($_POST[EU_VAT_GUARD_META_ORDER_VAT]));
        }

        if (!empty($vat_number)) {


            // Use both post meta and order meta for compatibility
            update_post_meta($order_id, EU_VAT_GUARD_META_ORDER_VAT, $vat_number);

            // Also try to update using WC_Order object if available
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_meta_data(EU_VAT_GUARD_META_ORDER_VAT, $vat_number);
                $order->save_meta_data();
            }

            // Save VAT exemption status as order meta using WC()->customer->get_is_vat_exempt()
            $is_exempt = (WC()->customer && WC()->customer->get_is_vat_exempt());
            update_post_meta($order_id, 'billing_is_vat_exempt', $is_exempt ? 'yes' : 'no');

            if ($order) {
                $order->update_meta_data(EU_VAT_GUARD_META_ORDER_EXEMPT, $is_exempt ? 'yes' : 'no');

                // Notify Pro plugin when VAT exemption is applied to an actual order
                if ($is_exempt) {
                    $vat_country = substr(strtoupper(str_replace([' ', '-', '.'], '', $vat_number)), 0, 2);
                    $shop_base_country = wc_get_base_location()['country'];
                    $billing_country = $order->get_billing_country();
                    $shipping_country = $order->get_shipping_country();

                    do_action('eu_vat_guard_vat_exemption_applied', $order_id, array(
                        'vat_number' => $vat_number,
                        'vat_country' => $vat_country,
                        'billing_country' => $billing_country,
                        'shipping_country' => $shipping_country,
                        'shop_base_country' => $shop_base_country
                    ));
                }

                // Allow Pro plugin to add additional order data
                $order_data = array(
                    'vat_number' => $vat_number,
                    'is_vat_exempt' => $is_exempt,
                    'vat_country' => substr($vat_number, 0, 2)
                );
                $enhanced_order_data = apply_filters('eu_vat_guard_order_data', $order_data, $order);

                // Save any additional data from Pro plugin
                if (is_array($enhanced_order_data) && $enhanced_order_data !== $order_data) {
                    foreach ($enhanced_order_data as $key => $value) {
                        if (!in_array($key, array('vat_number', 'is_vat_exempt', 'vat_country'))) {
                            $order->update_meta_data('_vat_guard_' . $key, $value);
                        }
                    }
                }

                $order->save_meta_data();
            }

            // Update customer account VAT number if different
            if (is_user_logged_in()) {
                $user_id = get_current_user_id();
                $current_vat = get_user_meta($user_id, EU_VAT_GUARD_META_VAT_NUMBER, true);
                if ($vat_number !== $current_vat) {
                    update_user_meta($user_id, EU_VAT_GUARD_META_VAT_NUMBER, $vat_number);

                    // Notify Pro plugin when customer VAT is updated
                    do_action('eu_vat_guard_customer_vat_updated', $user_id, array(
                        'vat_number' => $vat_number,
                        'previous_vat' => $current_vat,
                        'order_id' => $order_id
                    ));
                }
            }
        }
    }




    /**
     * Validate VAT number during checkout and set VAT exemption status.
     * This runs after the default WooCommerce validation.
     * Uses the centralized validation function for consistency.
     */
    public function on_checkout_vat_field($data, $errors)
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification for checkout process
        $vat = isset($_POST[EU_VAT_GUARD_META_ORDER_VAT]) ? sanitize_text_field(wp_unslash($_POST[EU_VAT_GUARD_META_ORDER_VAT])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification for checkout process
        $ship_to_different_address = isset($_POST['ship_to_different_address']) && sanitize_text_field(wp_unslash($_POST['ship_to_different_address'])) === '1';

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification for checkout process
        $shipping_country = $ship_to_different_address && isset($_POST['shipping_country']) ?
            sanitize_text_field(wp_unslash($_POST['shipping_country'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification for checkout process
        $billing_country = isset($_POST['billing_country']) ? sanitize_text_field(wp_unslash($_POST['billing_country'])) : '';

        // Use the centralized validation function
        $error_messages = [];
        $this->validate_and_set_vat_exemption(
            $vat,
            $billing_country,
            $shipping_country,
            $error_messages
        );

        // Add any error messages to the WooCommerce errors object
        foreach ($error_messages as $error_message) {
            $errors->add('vat_number_error', $error_message);
        }
    }
    /**
     * Comprehensive VAT exemption validation and status setting
     * This is the centralized function that handles all VAT validation and exemption logic
     * 
     * @param string $vat VAT number to validate
     * @param string $billing_country Billing country code (2 letters)
     * @param string $shipping_country Shipping country code (2 letters)
     * @param array &$error_messages Array to collect error messages
     * @return bool True if VAT exempt, false otherwise
     */
    public function validate_and_set_vat_exemption($vat, $billing_country = '', $shipping_country = '', &$error_messages = [])
    {
        $require_vat = get_option('eu_vat_guard_require_vat', 1);

        // Initialize error messages array if not provided
        if (!is_array($error_messages)) {
            $error_messages = [];
        }

        // Check if VAT exemption is disabled
        if ($this->is_exemption_disabled()) {
            $this->set_customer_vat_exempt_status(false);
            // Still validate VAT number if provided, but don't apply exemption
            if (!empty($vat)) {
                $vat_error_message = '';
                if (!VAT_Guard_Helper::is_valid_eu_vat_number($vat, $vat_error_message)) {
                    $error_messages[] = $vat_error_message;
                    return false;
                }
            }
            return false; // No exemption applied
        }

        // Step 1: Check if VAT is required but empty
        if ($require_vat && empty($vat)) {
            $error_messages[] = __('Please enter your VAT number.', 'eu-vat-guard-for-woocommerce');
            $this->set_customer_vat_exempt_status(false);
            return false;
        }

        // Step 2: If no VAT number provided (and not required), no exemption
        if (empty($vat)) {
            $this->set_customer_vat_exempt_status(false);
            return false;
        }

        // Step 3: Validate VAT number format and VIES (if enabled)
        $vat_error_message = '';
        if (!VAT_Guard_Helper::is_valid_eu_vat_number($vat, $vat_error_message)) {
            $error_messages[] = $vat_error_message;
            $this->set_customer_vat_exempt_status(false);
            return false;
        }

        // Step 4: Extract VAT country and validate country matching
        $vat_country = substr(strtoupper(str_replace([' ', '-', '.'], '', $vat)), 0, 2);

        // Check billing country matches VAT country
        if (!empty($billing_country) && strtoupper($billing_country) !== $vat_country) {
            $error_messages[] = __('The billing country must match the country of the VAT number.', 'eu-vat-guard-for-woocommerce');
            $this->set_customer_vat_exempt_status(false);
            return false;
        }

        // Check shipping country matches VAT country (use shipping if different from billing)
        $country_to_check = !empty($shipping_country) ? strtoupper($shipping_country) : strtoupper($billing_country);
        if (!empty($country_to_check) && $country_to_check !== $vat_country) {
            $error_messages[] = __('The shipping country must match the country of the VAT number.', 'eu-vat-guard-for-woocommerce');
            $this->set_customer_vat_exempt_status(false);
            return false;
        }

        // Step 5: Check shipping method - no exemption for local pickup
        $chosen_methods = $this->get_current_shipping_methods();
        $local_pickup_methods = apply_filters('woocommerce_local_pickup_methods', ['local_pickup']);

        if (count(array_intersect($chosen_methods, $local_pickup_methods)) > 0) {
            $this->set_customer_vat_exempt_status(false);
            return false;
        }

        // Step 6: Check if this is a cross-border transaction (different from shop base country)
        $shop_base_country = wc_get_base_location()['country'];
        $is_cross_border = !empty($vat) && $vat_country && $vat_country !== $shop_base_country;

        $this->set_customer_vat_exempt_status($is_cross_border);

        return $is_cross_border;
    }

    /**
     * Set VAT exempt status on the customer (simplified version)
     * @param bool $is_exempt Whether customer should be VAT exempt
     */
    private function set_customer_vat_exempt_status($is_exempt)
    {
        if (WC()->customer) {
            WC()->customer->set_is_vat_exempt($is_exempt);
        }
    }

    /**
     * Clear VAT exempt status (used when VAT validation fails or is incomplete)
     * This is a lightweight function for cases where we don't have complete address info
     */
    public function clear_vat_exempt_status()
    {
        $this->set_customer_vat_exempt_status(false);
    }

    /**
     * Set VAT exempt status on the customer based on VAT number, shop base country and selected shipping method
     * This method does expect that basic checks on shipping/billing country have already been carried out
     * It will not show any errors but just apply the exemption rules:
     *      - if local pickup is selected, no exemption will occur
     *      - if the VAT number provided is from a different country than the store, VAT exemption occurs
     * @param string $vat
     * @deprecated Use validate_and_set_vat_exemption() instead
     */
    public function set_vat_exempt_status($vat)
    {
        if (empty($vat)) {
            WC()->customer->set_is_vat_exempt(false); //no VAT so no exemption
            return;
        }

        // Check if local pickup is selected - never exempt VAT for local pickup
        $chosen_methods = $this->get_current_shipping_methods();
        $local_pickup_methods = apply_filters('woocommerce_local_pickup_methods', array('local_pickup'));

        if (count(array_intersect($chosen_methods, $local_pickup_methods)) > 0) {
            WC()->customer->set_is_vat_exempt(false);
            return;
        }

        $vat_country = substr($vat, 0, 2);
        $shop_base_country = wc_get_base_location()['country'];
        if (!empty($vat) && $vat_country && $vat_country !== $shop_base_country) {
            WC()->customer->set_is_vat_exempt(true);
        } else {
            WC()->customer->set_is_vat_exempt(false);
        }
    }

    /* Validate VAT number and set VAT exemption after editing the field
     * Uses the centralized validation function for consistency
     */
    public function ajax_validate_and_exempt_vat($post_data)
    {
        parse_str($post_data, $data);

        $vat = isset($data[EU_VAT_GUARD_META_ORDER_VAT]) ? trim($data[EU_VAT_GUARD_META_ORDER_VAT]) : '';
        $ship_to_different_address = isset($data['ship_to_different_address']) && $data['ship_to_different_address'] === '1';

        // Get shipping country from the data
        // If shipping address is different, use that, otherwise use billing country
        $shipping_country = $ship_to_different_address && isset($data['shipping_country']) ?
            trim($data['shipping_country']) : '';
        $billing_country = isset($data['billing_country']) ? trim($data['billing_country']) : '';

        // Use the centralized validation function
        $error_messages = [];
        $this->validate_and_set_vat_exemption(
            $vat,
            $billing_country,
            $shipping_country,
            $error_messages
        );

        // Display any error messages
        foreach ($error_messages as $error_message) {
            wc_add_notice($error_message, 'error');
        }

        // Store VAT number in customer session for later retrieval
        if (!empty($vat) && WC()->customer) {
            WC()->session->set(EU_VAT_GUARD_META_ORDER_VAT, $vat);
        }
    }


    /**
     * Show VAT number in order emails
     */
    public function show_vat_in_emails($order, $sent_to_admin, $plain_text, $email)
    {
        $vat = VAT_Guard_Helper::get_order_vat_number($order);

        if ($vat) {
            echo '<p><strong>' . esc_html__('VAT Number', 'eu-vat-guard-for-woocommerce') . ':</strong> ' . esc_html($vat) . '</p>';
        }
    }






    /**
     * Show VAT exempt notice in checkout totals for classic checkout
     */
    public function show_vat_exempt_notice_checkout()
    {
        if (WC()->customer && WC()->customer->get_is_vat_exempt()) {
            echo '<tr class="vat-exempt-notice">';
            echo '<th colspan="2" style="color: #00a32a; font-weight: bold; text-align: center; padding: 10px;">';
            echo '✓ ' . esc_html(VAT_Guard_Helper::get_exemption_message());
            echo '</th>';
            echo '</tr>';
        }
    }

    /**
     * Get current shipping methods, checking POST data first for AJAX updates
     * This ensures we get the newly selected shipping method during checkout updates
     * Extracts method types from full IDs (e.g., 'flat_rate:2' -> 'flat_rate')
     * 
     * @return array Array of chosen shipping method IDs and method types
     */
    public function get_current_shipping_methods()
    {
        $chosen_methods = array();

        // Try direct POST data first (for AJAX contexts)
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification for shipping method updates
        if (isset($_POST['shipping_method']) && is_array($_POST['shipping_method'])) {
            $chosen_methods = array_map('sanitize_text_field', wp_unslash($_POST['shipping_method']));
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification for shipping method updates
        } elseif (isset($_POST['shipping_method'])) {
            $chosen_methods = array(sanitize_text_field(wp_unslash($_POST['shipping_method'])));
        }

        // If no POST data, fall back to session data
        if (empty($chosen_methods) && function_exists('wc_get_chosen_shipping_method_ids')) {
            $chosen_methods = wc_get_chosen_shipping_method_ids();
        }

        // Extract method types from full method IDs (e.g., 'flat_rate:2' -> 'flat_rate')
        // Also keep the full IDs for backward compatibility
        $method_types = array();
        foreach ($chosen_methods as $method_id) {
            $method_types[] = $method_id; // Keep full ID
            if (strpos($method_id, ':') !== false) {
                $method_types[] = substr($method_id, 0, strpos($method_id, ':')); // Add method type
            }
        }

        return array_unique($method_types);
    }

    /**
     * Display VAT information in order details section
     * Shows on order received page and my account order view
     * When block checkout is enabled, only shows exemption status (VAT number is shown by WooCommerce)
     * When block checkout is disabled, shows both VAT number and exemption status
     * 
     * @param WC_Order $order
     */
    public function show_vat_in_order_details($order)
    {
        if (!$order) {
            return;
        }

        $vat_number = VAT_Guard_Helper::get_order_vat_number($order);
        $is_exempt = $order->get_meta(EU_VAT_GUARD_META_ORDER_EXEMPT);

        if (empty($vat_number)) {
            return;
        }

        $block_checkout_enabled = get_option('eu_vat_guard_enable_block_checkout', 1);

        // If block checkout is enabled and VAT is exempt, only show exemption status
        if ($block_checkout_enabled && $is_exempt === 'yes') {
            echo '<div class="woocommerce-order-vat-exemption" style="margin: 20px 0; padding: 15px; background: #e7f7e7; border: 1px solid #46b450; border-radius: 4px; display: inline-block;">';
            echo '<p style="color: #00a32a; font-weight: bold; margin: 0;">✓ ' . esc_html(VAT_Guard_Helper::get_exemption_message()) . '</p>';
            echo '</div>';
            return;
        }

        // If block checkout is disabled, show full VAT information section
        if (!$block_checkout_enabled) {
            echo '<section class="woocommerce-customer-details" style="margin-top:32px">';
            echo '<h2 class="woocommerce-column__title">' . esc_html__('VAT Information', 'eu-vat-guard-for-woocommerce') . '</h2>';
            echo '<table class="woocommerce-table woocommerce-table--customer-details shop_table customer_details">';
            echo '<tbody>';
            echo '<tr>';
            echo '<th>' . esc_html(VAT_Guard_Helper::get_vat_label()) . ':</th>';
            echo '<td>' . esc_html($vat_number) . '</td>';
            echo '</tr>';

            if ($is_exempt === 'yes') {
                echo '<tr>';
                echo '<th>' . esc_html__('VAT Status', 'eu-vat-guard-for-woocommerce') . ':</th>';
                echo '<td style="color: #00a32a; font-weight: bold;">✓ ' . esc_html(VAT_Guard_Helper::get_exemption_message()) . '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
            echo '</section>';
        }
    }

    /**
     * Display VAT information on thank you page
     * Alternative hook that fires earlier on the thank you page
     * @deprecated 
     * 
     * @param int $order_id
     */
    public function show_vat_on_thankyou_page($order_id)
    {
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $vat_number = VAT_Guard_Helper::get_order_vat_number($order);
        $is_exempt = $order->get_meta(EU_VAT_GUARD_META_ORDER_EXEMPT);

        if (empty($vat_number)) {
            return;
        }

        echo '<div class="woocommerce-order-vat-details" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px;">';
        echo '<h3 style="margin-top: 0;">' . esc_html__('VAT Information', 'eu-vat-guard-for-woocommerce') . '</h3>';
        echo '<p><strong>' . esc_html(VAT_Guard_Helper::get_vat_label()) . ':</strong> ' . esc_html($vat_number) . '</p>';

        if ($is_exempt === 'yes') {
            echo '<p style="color: #00a32a; font-weight: bold; margin: 0;">✓ ' . esc_html(VAT_Guard_Helper::get_exemption_message()) . '</p>';
        }

        echo '</div>';
    }

    /**
     * Restore VAT exemption status during cart recalculation
     * This prevents VAT from being re-added after payment processing or B2B plugin interference
     * 
     * @param WC_Cart $cart
     */
    public function restore_vat_exemption_from_order($cart)
    {
        // Only run during checkout or order processing
        if (!is_checkout() && !wp_doing_ajax()) {
            return;
        }

        // Skip if exemption is disabled
        if ($this->is_exemption_disabled()) {
            return;
        }

        // First, try to get order ID for existing orders (payment processing scenario)
        $order_id = $this->get_current_order_id();
        
        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                // Check if this order was previously VAT exempt
                $is_exempt = $order->get_meta(EU_VAT_GUARD_META_ORDER_EXEMPT);
                $vat_number = VAT_Guard_Helper::get_order_vat_number($order);

                if ($is_exempt === 'yes' && !empty($vat_number)) {
                    // Restore VAT exemption status from order data
                    if (WC()->customer) {
                        WC()->customer->set_is_vat_exempt(true);
                    }
                    return;
                }
            }
        }

        // If no order exists yet, check session data and re-validate VAT exemption
        // This handles the checkout process before order creation
        $this->restore_vat_exemption_from_session();
    }

    /**
     * Restore VAT exemption from session data during checkout
     * This handles cases where order doesn't exist yet but VAT exemption should be maintained
     */
    private function restore_vat_exemption_from_session()
    {
        if (!WC()->session || !WC()->customer) {
            return;
        }

        // Get VAT number from session
        $vat_number = WC()->session->get(EU_VAT_GUARD_META_ORDER_VAT);
        
        if (empty($vat_number)) {
            // Try to get from user meta if logged in
            if (is_user_logged_in()) {
                $vat_number = get_user_meta(get_current_user_id(), EU_VAT_GUARD_META_VAT_NUMBER, true);
            }
        }

        if (empty($vat_number)) {
            return;
        }

        // Get current customer countries
        $billing_country = WC()->customer->get_billing_country();
        $shipping_country = WC()->customer->get_shipping_country();

        // Re-validate VAT exemption with current data (no error messages needed here)
        $error_messages = [];
        $is_exempt = $this->validate_and_set_vat_exemption(
            $vat_number,
            $billing_country,
            $shipping_country,
            $error_messages
        );

        // If validation determined exemption should apply, ensure it's set
        if ($is_exempt && !WC()->customer->get_is_vat_exempt()) {
            WC()->customer->set_is_vat_exempt(true);
        }
    }

    /**
     * Restore VAT exemption status during order recalculation
     * This prevents VAT from being re-added after payment processing or B2B plugin interference
     * Specifically handles order-level recalculation scenarios
     * 
     * @param bool $and_taxes Whether to calculate taxes (WooCommerce parameter)
     * @param WC_Order $order The order object being recalculated
     */
    public function restore_vat_exemption_from_order_object($and_taxes, $order)
    {
        // Skip if exemption is disabled
        if ($this->is_exemption_disabled()) {
            return;
        }

        // Validate order object
        if (!$order || !is_a($order, 'WC_Order')) {
            return;
        }

        // Check if this order was previously VAT exempt
        $is_exempt = $order->get_meta(EU_VAT_GUARD_META_ORDER_EXEMPT);
        $vat_number = VAT_Guard_Helper::get_order_vat_number($order);

        if ($is_exempt === 'yes' && !empty($vat_number)) {
            // Restore VAT exemption status from order data
            if (WC()->customer) {
                WC()->customer->set_is_vat_exempt(true);
            }
        }
    }

   

    /**
     * Get current order ID from various sources during checkout/payment processing
     * 
     * @return int|null Order ID or null if not found
     */
    private function get_current_order_id()
    {
        // Method 1: Check session for order awaiting payment
        if (WC()->session) {
            $order_id = WC()->session->get('order_awaiting_payment');
            if ($order_id) {
                return $order_id;
            }
        }

        // Method 2: Check POST data for order ID (PayPal and other gateways)
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Order ID verification happens in WooCommerce
        if (isset($_POST['order_id'])) {
            return absint($_POST['order_id']);
        }

        // Method 3: Check GET parameters (some payment gateways use this)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Order ID verification happens in WooCommerce
        if (isset($_GET['order_id'])) {
            return absint($_GET['order_id']);
        }

        // Method 4: Check if we're on order-pay page
        global $wp;
        if (isset($wp->query_vars['order-pay'])) {
            return absint($wp->query_vars['order-pay']);
        }

        return null;
    }

    /**
     * Ensure VAT exemption persists after cart calculation
     * This runs after calculate_totals to verify exemption wasn't removed
     * 
     * @param WC_Cart $cart
     */
    public function ensure_vat_exemption_persists($cart)
    {
        // Only run during checkout or order processing
        if (!is_checkout() && !wp_doing_ajax()) {
            return;
        }

        // Skip if exemption is disabled
        if ($this->is_exemption_disabled()) {
            return;
        }

        // First check if we have an existing order
        $order_id = $this->get_current_order_id();
        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                // Check if order should be VAT exempt but customer isn't marked as exempt
                $is_exempt = $order->get_meta(EU_VAT_GUARD_META_ORDER_EXEMPT);
                $vat_number = VAT_Guard_Helper::get_order_vat_number($order);

                if ($is_exempt === 'yes' && !empty($vat_number)) {
                    if (WC()->customer && !WC()->customer->get_is_vat_exempt()) {
                        // Force restore exemption status
                        WC()->customer->set_is_vat_exempt(true);
                        
                        // Prevent infinite loop by checking if we need recalculation
                        static $recalculating = false;
                        if (!$recalculating && WC()->cart) {
                            $recalculating = true;
                            WC()->cart->calculate_totals();
                            $recalculating = false;
                        }
                    }
                }
                return;
            }
        }

        // If no order exists, check session-based exemption
        if (!WC()->session || !WC()->customer) {
            return;
        }

        // Get VAT number from session or user meta
        $vat_number = WC()->session->get(EU_VAT_GUARD_META_ORDER_VAT);
        if (empty($vat_number) && is_user_logged_in()) {
            $vat_number = get_user_meta(get_current_user_id(), EU_VAT_GUARD_META_VAT_NUMBER, true);
        }

        if (!empty($vat_number)) {
            // Check if VAT exemption should be applied but isn't
            $billing_country = WC()->customer->get_billing_country();
            $shipping_country = WC()->customer->get_shipping_country();
            
            // Quick check for cross-border transaction
            $vat_country = substr(strtoupper(str_replace([' ', '-', '.'], '', $vat_number)), 0, 2);
            $shop_base_country = wc_get_base_location()['country'];
            $should_be_exempt = !empty($vat_number) && $vat_country && $vat_country !== $shop_base_country;

            if ($should_be_exempt && !WC()->customer->get_is_vat_exempt()) {
                // Re-validate and set exemption
                $error_messages = [];
                $this->validate_and_set_vat_exemption(
                    $vat_number,
                    $billing_country,
                    $shipping_country,
                    $error_messages
                );
            }
        }
    }

    /**
     * Restore VAT exemption when order status changes (PayPal completion, etc.)
     * 
     * @param int $order_id
     * @param string $old_status
     * @param string $new_status
     */
    public function restore_vat_exemption_on_status_change($order_id, $old_status, $new_status)
    {
        // Skip if exemption is disabled
        if ($this->is_exemption_disabled()) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Check if this order was VAT exempt
        $is_exempt = $order->get_meta(EU_VAT_GUARD_META_ORDER_EXEMPT);
        $vat_number = VAT_Guard_Helper::get_order_vat_number($order);

        if ($is_exempt === 'yes' && !empty($vat_number)) {
            // Restore VAT exemption status
            if (WC()->customer) {
                WC()->customer->set_is_vat_exempt(true);
            }
        }
    }

    /**
     * Restore VAT exemption after payment completion
     * 
     * @param int $order_id
     */
    public function restore_vat_exemption_after_payment($order_id)
    {
        // Skip if exemption is disabled
        if ($this->is_exemption_disabled()) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Check if this order was VAT exempt
        $is_exempt = $order->get_meta(EU_VAT_GUARD_META_ORDER_EXEMPT);
        $vat_number = VAT_Guard_Helper::get_order_vat_number($order);

        if ($is_exempt === 'yes' && !empty($vat_number)) {
            // Restore VAT exemption status
            if (WC()->customer) {
                WC()->customer->set_is_vat_exempt(true);
            }
        }
    }

    /**
     * Restore VAT exemption when order is saved (B2B plugins may trigger this)
     * 
     * @param int $post_id
     * @param WP_Post $post
     */
    public function restore_vat_exemption_on_order_save($post_id, $post)
    {
        // Skip if exemption is disabled
        if ($this->is_exemption_disabled()) {
            return;
        }

        // Only handle shop_order post type
        if (!$post || $post->post_type !== 'shop_order') {
            return;
        }

        $order = wc_get_order($post_id);
        if (!$order) {
            return;
        }

        // Check if this order was VAT exempt
        $is_exempt = $order->get_meta(EU_VAT_GUARD_META_ORDER_EXEMPT);
        $vat_number = VAT_Guard_Helper::get_order_vat_number($order);

        if ($is_exempt === 'yes' && !empty($vat_number)) {
            // Restore VAT exemption status
            if (WC()->customer) {
                WC()->customer->set_is_vat_exempt(true);
            }
        }
    }

    /**
     * Restore VAT exemption when post is saved (additional safety net)
     * 
     * @param int $post_id
     * @param WP_Post $post
     */
    public function restore_vat_exemption_on_post_save($post_id, $post)
    {
        // Delegate to order save method
        $this->restore_vat_exemption_on_order_save($post_id, $post);
    }

    /**
     * Override order VAT exempt status to prevent B2B plugins from interfering
     * This filter runs when WooCommerce checks if an order should be VAT exempt
     * 
     * @param bool $is_vat_exempt Current VAT exempt status
     * @param WC_Order $order Order object
     * @return bool Final VAT exempt status
     */
    public function override_order_vat_exempt_status($is_vat_exempt, $order)
    {
        // Skip if exemption is disabled in our plugin
        if ($this->is_exemption_disabled()) {
            return $is_vat_exempt;
        }

        if (!$order) {
            return $is_vat_exempt;
        }

        // Check if EU VAT Guard has determined this order should be VAT exempt
        $eu_vat_guard_exempt = $order->get_meta(EU_VAT_GUARD_META_ORDER_EXEMPT);
        $vat_number = VAT_Guard_Helper::get_order_vat_number($order);

        // If EU VAT Guard has explicitly set exemption status, use that
        if ($eu_vat_guard_exempt === 'yes' && !empty($vat_number)) {
            return true;
        } elseif ($eu_vat_guard_exempt === 'no') {
            return false;
        }

        // If EU VAT Guard hasn't set a status, fall back to current status
        return $is_vat_exempt;
    }

    /**
     * Override B2B plugin's VAT exemption logic that runs on init
     * This prevents B2B plugin from overriding EU VAT Guard's exemption decisions
     * Only applies during checkout/cart contexts to avoid interfering with price display
     */
    public function override_b2b_vat_exemption()
    {
        // Skip if exemption is disabled
        if ($this->is_exemption_disabled()) {
            return;
        }

        // Only run on frontend and AJAX calls
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }

        // Only proceed if WooCommerce customer exists
        if (!WC()->customer) {
            return;
        }

        // Only override during checkout/cart contexts to avoid price display issues
        if (!$this->is_checkout_or_cart_context()) {
            return;
        }

        // Check if we have a VAT number that should grant exemption
        $vat_number = '';
        
        // Try to get VAT number from session first
        if (WC()->session) {
            $vat_number = WC()->session->get(EU_VAT_GUARD_META_ORDER_VAT);
        }
        
        // If no session VAT, try user meta for logged-in users
        if (empty($vat_number) && is_user_logged_in()) {
            $vat_number = get_user_meta(get_current_user_id(), EU_VAT_GUARD_META_VAT_NUMBER, true);
        }

        // If we have a VAT number, check if it should grant exemption
        if (!empty($vat_number)) {
            // Get current customer countries
            $billing_country = WC()->customer->get_billing_country();
            $shipping_country = WC()->customer->get_shipping_country();

            // Use our centralized validation to determine exemption
            $error_messages = [];
            $should_be_exempt = $this->validate_and_set_vat_exemption(
                $vat_number,
                $billing_country,
                $shipping_country,
                $error_messages
            );

            // If our validation says exempt, ensure it stays exempt
            // This overrides any B2B plugin decision
            if ($should_be_exempt) {
                WC()->customer->set_is_vat_exempt(true);
            }
        }
    }

    /**
     * Check if we're in a checkout or cart context where VAT exemption should be applied
     * This prevents interference with general product price display
     * 
     * @return bool True if in checkout/cart context
     */
    private function is_checkout_or_cart_context()
    {
        // Direct page checks
        if (is_checkout() || is_cart()) {
            return true;
        }

        // Check if we're on order-pay page (payment processing)
        if (is_wc_endpoint_url('order-pay')) {
            return true;
        }

        // AJAX checks for checkout/cart operations
        if (wp_doing_ajax()) {
            $ajax_actions = [
                'woocommerce_update_order_review',
                'woocommerce_checkout',
                'woocommerce_apply_coupon',
                'woocommerce_remove_coupon',
                'woocommerce_update_shipping_method',
                'wc_ajax_update_order_review',
                'wc_ajax_checkout',
                'wc_ajax_apply_coupon',
                'wc_ajax_remove_coupon'
            ];

            $current_action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';
            if (in_array($current_action, $ajax_actions, true)) {
                return true;
            }
        }

        // Check if we're processing an order (payment gateways)
        if ($this->get_current_order_id()) {
            return true;
        }

        // Check if we have a VAT number in session (indicates active checkout process)
        if (WC()->session && WC()->session->get(EU_VAT_GUARD_META_ORDER_VAT)) {
            return true;
        }

        // Check for payment gateway callbacks (PayPal, Stripe, etc.)
        if ($this->is_payment_gateway_callback()) {
            return true;
        }

        return false;
    }

    /**
     * Check if current request is a payment gateway callback
     * Payment gateways often process orders outside of normal checkout context
     * 
     * @return bool True if this appears to be a payment gateway callback
     */
    private function is_payment_gateway_callback()
    {
        // Check for common payment gateway parameters
        $gateway_params = [
            'wc-api',           // WooCommerce API callbacks
            'paypal',           // PayPal callbacks
            'stripe',           // Stripe callbacks
            'payment_method',   // Generic payment method
            'order_id',         // Order processing
            'key',              // Order key (payment processing)
        ];

        foreach ($gateway_params as $param) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Payment gateway callbacks don't use nonces
            if (isset($_GET[$param]) || isset($_POST[$param])) {
                return true;
            }
        }

        // Check for PayPal specific patterns
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Payment gateway callbacks don't use nonces
        if (isset($_GET['PayerID']) || isset($_GET['token'])) {
            return true;
        }

        // Check if we're in a WooCommerce API context
        if (defined('WC_API_REQUEST') && WC_API_REQUEST) {
            return true;
        }

        return false;
    }


}
