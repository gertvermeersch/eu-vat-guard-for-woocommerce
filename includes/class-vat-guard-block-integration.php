<?php
/**
 * VAT Guard Block Integration for WooCommerce Store API
 *
 * @package Stormlabs\EUVATGuard
 */

namespace Stormlabs\EUVATGuard;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use WP_Error;
use WP_REST_Response;


if (!defined('ABSPATH')) {
    exit;
}



class VAT_Guard_Block_Integration implements IntegrationInterface
{
    /**
     * Reference to the main VAT Guard class
     * @var VAT_Guard
     */
    private $main_class;

    /**
     * Constructor
     * @param VAT_Guard $main_class
     */
    public function __construct($main_class = null)
    {
        // Load the helper class
        require_once plugin_dir_path(__FILE__) . 'class-vat-guard-helper.php';
        $this->main_class = $main_class ?: VAT_Guard::instance();
    }

    /**
     * Initialize block checkout functionality
     */
    public function init()
    {
        $this->setup_block_checkout_fields();
        $this->setup_block_checkout_hooks();
        $this->setup_block_checkout_scripts();
        $this->setup_rest_api();
        $this->setup_store_api_integration();

        // Also call the interface initialize method for Store API integration
        $this->initialize();
    }
    /**
     * The name of the integration.
     */
    public function get_name()
    {
        return 'eu-vat-guard';
    }

    /**
     * When called invokes any initialization/setup for the integration.
     */
    public function initialize()
    {
        // Register scripts on proper hooks instead of immediately
        add_action('wp_enqueue_scripts', [$this, 'register_block_frontend_scripts']);
        add_action('enqueue_block_editor_assets', [$this, 'register_block_editor_scripts']);

        $this->register_main_integration();
    }

    /**
     * Returns an array of script handles to enqueue in the frontend context.
     */
    public function get_script_handles()
    {
        return ['vat-guard-block-frontend'];
    }

    /**
     * Returns an array of script handles to enqueue in the editor context.
     */
    public function get_editor_script_handles()
    {
        return ['vat-guard-block-editor'];
    }

    /**
     * An array of key, value pairs of data made available to the block on the client side.
     */
    public function get_script_data()
    {
        return [
            'restUrl' => rest_url('vat-guard/v1/validate'),
            'nonce' => wp_create_nonce('wp_rest'),
            'messages' => [
                'validating' => __('Validating VAT number...', 'eu-vat-guard-for-woocommerce'),
                'valid' => __('VAT number is valid', 'eu-vat-guard-for-woocommerce'),
                'exempt' => VAT_Guard_Helper::get_exemption_message(),
                'invalid' => __('Invalid VAT number', 'eu-vat-guard-for-woocommerce')
            ]
        ];
    }

    /**
     * Register scripts for frontend.
     */
    public function register_block_frontend_scripts()
    {
        // Only register if we're on a page that might use blocks
        if (!has_block('woocommerce/checkout') && !has_block('woocommerce/cart') && !is_checkout() && !is_cart()) {
            return;
        }

        $script_path = plugin_dir_path(dirname(__FILE__)) . 'assets/js/vat-guard-block-frontend.js';
        $script_url = plugin_dir_url(dirname(__FILE__)) . 'assets/js/vat-guard-block-frontend.js';

        // Check if file exists before registering
        if (file_exists($script_path)) {
            wp_register_script(
                'vat-guard-block-frontend',
                $script_url,
                ['wp-element', 'wp-i18n', 'wp-data'],
                filemtime($script_path),
                true
            );
        }
    }

    /**
     * Register scripts for editor.
     */
    public function register_block_editor_scripts()
    {
        $script_path = plugin_dir_path(dirname(__FILE__)) . 'assets/js/vat-guard-block-editor.js';
        $script_url = plugin_dir_url(dirname(__FILE__)) . 'assets/js/vat-guard-block-editor.js';

        // Check if file exists before registering
        if (file_exists($script_path)) {
            wp_register_script(
                'vat-guard-block-editor',
                $script_url,
                ['wp-element', 'wp-i18n'],
                filemtime($script_path),
                true
            );
        }
    }

    /**
     * Register the main integration with Store API.
     */
    private function register_main_integration()
    {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }

        woocommerce_store_api_register_endpoint_data([
            'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
            'namespace' => $this->get_name(),
            'data_callback' => [$this, 'extend_cart_data'],
            'schema_callback' => [$this, 'extend_checkout_schema'],
        ]);

        woocommerce_store_api_register_endpoint_data([
            'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
            'namespace' => $this->get_name(),
            'data_callback' => [$this, 'extend_cart_data'],
            'schema_callback' => [$this, 'extend_cart_schema'],
        ]);

        // Also register for batch endpoint to ensure data is available everywhere
        //TODO: This stopped working, need to investigate but batch request keep having EU VAT data
        // woocommerce_store_api_register_endpoint_data([
        //     'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\BatchSchema::IDENTIFIER,
        //     'namespace' => $this->get_name(),
        //     'data_callback' => [$this, 'extend_cart_data'],
        //     'schema_callback' => [$this, 'extend_cart_schema'],
        // ]);
    }

    /**
     * Extend checkout data with VAT information.
     * TODO: Obsolete?
     */
    public function extend_checkout_data()
    {
        //$is_exempt = WC()->customer ? WC()->customer->get_is_vat_exempt() : false;
        $is_exempt = WC()->customer->get_is_vat_exempt();
        return [
            'vat_exempt' => $is_exempt,
            'vat_number' => $this->get_customer_vat_number(),
            'vat_exempt_message' => $is_exempt ? VAT_Guard_Helper::get_exemption_message() : '',
        ];
    }

    /**
     * Extend cart data with VAT information, used by the frontend to show the excemption notice
     * This method performs real-time validation to ensure accurate exemption status.
     * gets called on whatever field changes... 
     */
    public function extend_cart_data()
    {
        // Get current VAT number from various sources
        $vat = $this->get_current_vat_number();

        // Get current customer countries
        $billing_country = '';
        $shipping_country = '';

        if (WC()->customer) {
            $billing_country = WC()->customer->get_billing_country();
            $shipping_country = WC()->customer->get_shipping_country();
        }

        // Perform real-time validation to get accurate exemption status
        // because this method gets called on fields changes like country/shipping method
        $error_messages = [];
        $is_exempt = $this->main_class->validate_and_set_vat_exemption(
            $vat,
            $billing_country,
            $shipping_country,
            $error_messages
        );

        return [
            'vat_exempt' => $is_exempt,
            'vat_number' => $vat,
            'vat_exempt_message' => $is_exempt ? VAT_Guard_Helper::get_exemption_message() : '',
        ];
    }

    /**
     * Schema for checkout extension data.
     */
    public function extend_checkout_schema()
    {
        return [
            'vat_exempt' => [
                'description' => __('Whether the customer is VAT exempt', 'eu-vat-guard-for-woocommerce'),
                'type' => 'boolean',
                'readonly' => true,
            ],
            'vat_number' => [
                'description' => __('Customer VAT number', 'eu-vat-guard-for-woocommerce'),
                'type' => 'string',
                'readonly' => true,
            ],
            'vat_exempt_message' => [
                'description' => __('VAT exempt message to display', 'eu-vat-guard-for-woocommerce'),
                'type' => 'string',
                'readonly' => true,
            ],
        ];
    }

    /**
     * Schema for cart extension data.
     */
    public function extend_cart_schema()
    {
        return $this->extend_checkout_schema();
    }

    /**
     * Get customer VAT number from account data.
     */
    private function get_customer_vat_number()
    {
        if (is_user_logged_in()) {
            return get_user_meta(get_current_user_id(), EU_VAT_GUARD_META_VAT_NUMBER, true);
        }
        return '';
    }

    /**
     * Setup block checkout fields
     */
    private function setup_block_checkout_fields()
    {
        add_action('woocommerce_init', function () {
            if (!function_exists('woocommerce_register_additional_checkout_field')) {
                return;
            }
            woocommerce_register_additional_checkout_field(
                array(
                    'id' => 'eu-vat-guard/vat_number',
                    'label' => VAT_Guard_Helper::get_vat_label(),
                    'location' => 'contact',
                    'type' => 'text',
                    'required' => (bool) get_option('eu_vat_guard_require_vat', 1),
                    'sanitize_callback' => array('Stormlabs\EUVATGuard\VAT_Guard_Helper', 'sanitize_vat_field'),
                    'validate_callback' => array($this, 'validate_vat_field')
                )
            );
        });
    }

    /**
     * Setup block checkout hooks
     */
    private function setup_block_checkout_hooks()
    {
        // Handle VAT number updates and exemption status
        add_action(
            'woocommerce_set_additional_field_value',
            array($this, 'handle_vat_field_update'),
            10,
            4
        );

        // Hook into Store API shipping rate selection for block checkout
        add_action(
            'woocommerce_store_api_cart_select_shipping_rate',
            array($this, 'handle_shipping_method_change_store_api'),
            10,
            3
        );

        // Hook into Store API customer address updates for block checkout
        add_action(
            'woocommerce_store_api_cart_update_customer',
            array($this, 'handle_customer_address_update_store_api'),
            10,
            2
        );


        // Preload the VAT number field with user meta data if available
        add_filter(
            "woocommerce_get_default_value_for_eu-vat-guard/vat_number",
            array($this, 'preload_vat_field'),
            10,
            3
        );
    }

    /**
     * Setup block checkout scripts
     */
    private function setup_block_checkout_scripts()
    {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_block_checkout_scripts'));
    }

    /**
     * Setup REST API endpoints
     */
    private function setup_rest_api()
    {
        add_action('rest_api_init', array($this, 'register_vat_validation_endpoint'));
    }

    /**
     * Setup Store API integration
     */
    private function setup_store_api_integration()
    {
        add_action('woocommerce_blocks_loaded', array($this, 'register_block_checkout_integration'));
    }

    /**
     * Handle VAT field updates and re-evaluate exemption on any field change
     * Called on VAT number field update, after initial field validation validate_vat_field
     * Manual hooked on additional field value, because here we have the Order object
     * @param string $key
     * @param string $value
     * @param string $group
     * @param \Automattic\WooCommerce\Admin\Overrides\Order $wc_object //or customer
     */
    public function handle_vat_field_update($key, $value, $group, $wc_object)
    {
        // Handle VAT field specific updates, happens AFTER validation
        $vat = '';
        if ('eu-vat-guard/vat_number' === $key) {
            // Sanitize VAT number
            $vat = VAT_Guard_Helper::sanitize_vat_field($value);
            $wc_object->update_meta_data(EU_VAT_GUARD_META_ORDER_VAT, $vat, true);
            //also save in the session
            if (WC()->session) {
                if ($vat === '') {
                    WC()->session->set(EU_VAT_GUARD_META_ORDER_VAT, '');
                } else {
                    WC()->session->set(EU_VAT_GUARD_META_ORDER_VAT, $vat);
                }
            }
            //save customer object
            if (WC()->customer) {
                WC()->customer->update_meta_data('_wc_other/eu-vat-guard/vat_number', $vat);
                WC()->customer->save();
            }
        }


        // Get shipping and billing countries from customer object
        $billing_country = '';
        $shipping_country = '';

        if (WC()->customer) {
            $billing_country = WC()->customer->get_billing_country();
            $shipping_country = WC()->customer->get_shipping_country();
        }

        // Use the centralized validation function
        $error_messages = [];
        $is_exempt = $this->main_class->validate_and_set_vat_exemption(
            $vat,
            $billing_country,
            $shipping_country,
            $error_messages
        );

        if (function_exists('wc_clear_notices')) {
            wc_clear_notices();
        }
        // Display any error messages
        foreach ($error_messages as $error_message) {
            wc_add_notice($error_message, 'error');
        }

        // Update meta data
        if ($wc_object) {
            $wc_object->update_meta_data(EU_VAT_GUARD_META_ORDER_EXEMPT, $is_exempt ? 'yes' : 'no');
        }

        // Trigger frontend refresh
        $this->trigger_frontend_refresh();
    }

    /**
     * Handle shipping method changes for Store API (block checkout)
     * @param string $package_id
     * @param string $rate_id
     * @param array $request_data
     */
    public function handle_shipping_method_change_store_api($package_id, $rate_id, $request_data)
    {
        // Get current VAT number
        $vat = $this->get_current_vat_number();

        if (empty($vat)) {
            return;
        }

        // Get current customer countries
        $billing_country = '';
        $shipping_country = '';

        if (WC()->customer) {
            $billing_country = WC()->customer->get_billing_country();
            $shipping_country = WC()->customer->get_shipping_country();
        }

        // Use the centralized validation function (no error messages needed here)
        $error_messages = [];
        $this->main_class->validate_and_set_vat_exemption(
            $vat,
            $billing_country,
            $shipping_country,
            $error_messages
        );

        wc_clear_notices();
        foreach ($error_messages as $error_message) {
            wc_add_notice($error_message, 'error');
        }

        // Trigger cart recalculation
        if (WC()->cart) {
            WC()->cart->calculate_totals();
        }
    }

    /**
     * Handle customer address updates for Store API (block checkout)
     * Re-evaluates VAT exemption when customer changes their address
     * @param array $customer_data Updated customer data
     * @param array $request_data Full request data
     */
    public function handle_customer_address_update_store_api($customer_data, $request_data)
    {
        // Get current VAT number
        $vat = $this->get_current_vat_number();

        if (empty($vat)) {
            return;
        }

        // Get updated customer countries (should be already updated in WC()->customer)
        $billing_country = '';
        $shipping_country = '';

        if (WC()->customer) {
            $billing_country = WC()->customer->get_billing_country();
            $shipping_country = WC()->customer->get_shipping_country();
        }

        // Use the centralized validation function (no error messages needed here)
        $error_messages = [];
        $this->main_class->validate_and_set_vat_exemption(
            $vat,
            $billing_country,
            $shipping_country,
            $error_messages
        );

        wc_clear_notices();
        foreach ($error_messages as $error_message) {
            wc_add_notice($error_message, 'error');
        }

        // Trigger cart recalculation to reflect VAT changes
        if (WC()->cart) {
            WC()->cart->calculate_totals();
        }

        // Trigger frontend refresh to update VAT exempt status display
        $this->trigger_frontend_refresh();
    }



    /**
     * Preload VAT field with user data
     */
    public function preload_vat_field($value, $group, $wc_object)
    {
        if (is_user_logged_in()) {
            $vat = get_user_meta(get_current_user_id(), EU_VAT_GUARD_META_VAT_NUMBER, true);
            if (!empty($vat)) {
                return $vat;
            }
        }
        //$this->trigger_frontend_refresh();
        return $value;
    }


    /**
     * Validate VAT field for block checkout
     * Called when user changes the VAT number field
     * validation hook of field. supports error messages
     * But, we don't get info about the shipping country so we can only check validity of the VAT number itself
     * Not possible to determine exemption status here - that happens later in handle_vat_field_update
     */
    public function validate_vat_field($value, $fields = null)
    {
        $require_vat = get_option('eu_vat_guard_require_vat', 1);

        if ($require_vat && empty($value)) {
            $this->main_class->clear_vat_exempt_status();
            return new WP_Error('vat_number_error', __('Please enter your VAT number.', 'eu-vat-guard-for-woocommerce'));
        }

        if (!empty($value)) {
            $error_message = '';
            if (!VAT_Guard_Helper::is_valid_eu_vat_number($value, $error_message)) {
                $this->main_class->clear_vat_exempt_status();
                return new WP_Error('vat_number_error', $error_message);
            }
        }

        // Store the VAT in session to handle old values being passed in handle_vat_field_update
        if (WC()->session) {
            $sanitized = VAT_Guard_Helper::sanitize_vat_field($value);
            WC()->session->set(EU_VAT_GUARD_META_ORDER_VAT, $sanitized);
        }

        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $session_vat = (WC()->session) ? WC()->session->get(EU_VAT_GUARD_META_ORDER_VAT) : '';
            error_log(
                sprintf(
                    '[VAT Guard] validate_vat_field value=%s sanitized=%s session=%s',
                    is_scalar($value) ? (string) $value : '[non-scalar]',
                    isset($sanitized) ? $sanitized : '',
                    is_scalar($session_vat) ? (string) $session_vat : '[non-scalar]'
                )
            );
        }
        return true; // No errors - exemption status will be determined later with complete address info
    }

    /**
     * Enqueue scripts for block checkout
     */
    public function enqueue_block_checkout_scripts()
    {
        if (!has_block('woocommerce/checkout') && !has_block('woocommerce/cart')) {
            return;
        }

        // Fallback script for when Store API integration is not available
        wp_enqueue_script(
            'vat-guard-block-checkout-fallback',
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/vat-guard-block-checkout.js',
            array('wp-element', 'wp-hooks', 'wp-data'),
            '1.0.0',
            true
        );

        wp_localize_script('vat-guard-block-checkout-fallback', 'vatGuardBlock', array(
            'restUrl' => rest_url('vat-guard/v1/validate'),
            'nonce' => wp_create_nonce('wp_rest'),
            'messages' => array(
                'validating' => __('Validating VAT number...', 'eu-vat-guard-for-woocommerce'),
                'valid' => __('VAT number is valid', 'eu-vat-guard-for-woocommerce'),
                'exempt' => VAT_Guard_Helper::get_exemption_message(),
                'invalid' => __('Invalid VAT number', 'eu-vat-guard-for-woocommerce')
            )
        ));
    }

    /**
     * Register REST API endpoint for VAT validation\
     * 
     */
    public function register_vat_validation_endpoint()
    {
        //TODO not yet used - could be useful in block validation logic later
        register_rest_route('vat-guard/v1', '/validate', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_validate_vat'),
            'permission_callback' => '__return_true',
            'args' => array(
                'vat_number' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'billing_country' => array(
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ));
    }

    /**
     * REST API callback for VAT validation
     */
    public function rest_validate_vat($request)
    {
        //TODO: not yet used - could be useful in block validation logic later
        $vat = $request->get_param('vat_number');
        $billing_country = $request->get_param('billing_country');

        if (empty($vat)) {
            return new WP_REST_Response(array(
                'valid' => true,
                'exempt' => false,
                'message' => ''
            ), 200);
        }

        $error_message = '';
        $is_valid = VAT_Guard_Helper::is_valid_eu_vat_number($vat, $error_message);

        if (!$is_valid) {
            return new WP_REST_Response(array(
                'valid' => false,
                'exempt' => false,
                'message' => $error_message
            ), 200);
        }

        // Check country matching
        $vat_country = substr(strtoupper(str_replace([' ', '-', '.'], '', $vat)), 0, 2);
        if (!empty($billing_country) && $billing_country !== $vat_country) {
            return new WP_REST_Response(array(
                'valid' => false,
                'exempt' => false,
                'message' => __('The billing country must match the country of the VAT number.', 'eu-vat-guard-for-woocommerce')
            ), 200);
        }

        // Check if VAT exempt
        $shop_base_country = wc_get_base_location()['country'];
        $is_exempt = !empty($vat) && $vat_country && $vat_country !== $shop_base_country;

        return new WP_REST_Response(array(
            'valid' => true,
            'exempt' => $is_exempt,
            'message' => $is_exempt ? VAT_Guard_Helper::get_exemption_message() : __('VAT number is valid', 'eu-vat-guard-for-woocommerce')
        ), 200);
    }

    /**
     * Register Store API integration for block checkout
     */
    public function register_block_checkout_integration()
    {
        if (!class_exists('Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface')) {
            return;
        }

        add_action('woocommerce_blocks_checkout_block_registration', function ($integration_registry) {
            $integration_registry->register($this);
        });
    }


    /**
     * Trigger frontend refresh to update VAT exempt status display
     * TODO: is this necessary? doesn't it just work by calculating totals? - 24/10 to be removed in future versions
     */
    private function trigger_frontend_refresh()
    {
        // // Method 1: Invalidate WooCommerce cart cache
        // if (function_exists('wc_clear_notices')) {
        //     // Clear any existing notices to prevent duplicates
        //     wc_clear_notices();
        // }

        // if (WC()->cart) {
        //     WC()->cart->calculate_totals();
        // }


        // // Method 3: Set a flag that JavaScript can detect
        // if (WC()->session) {
        //     WC()->session->set('vat_guard_status_changed', time());
        // }

        // // Method 4: Add a JavaScript snippet to force refresh (for block checkout)
        // add_action('wp_footer', function () {
        //     if (is_checkout() || is_cart()) {
        //         echo '<script>
        //             if (window.wp && window.wp.data && window.wp.data.dispatch) {
        //                 // Force refresh of cart data
        //                 setTimeout(function() {
        //                     const cartStore = window.wp.data.dispatch("wc/store/cart");
        //                     if (cartStore && cartStore.invalidateResolutionForStore) {
        //                         cartStore.invalidateResolutionForStore();
        //                     }
        //                     // Also try to refresh checkout data
        //                     const checkoutStore = window.wp.data.dispatch("wc/store/checkout");
        //                     if (checkoutStore && checkoutStore.invalidateResolutionForStore) {
        //                         checkoutStore.invalidateResolutionForStore();
        //                     }
        //                 }, 100);
        //             }
        //         </script>';
        //     }
        // });
    }

    /**
     * Get current VAT number from session (not from user data: could be outdated)
     */
    private function get_current_vat_number()
    {
        $vat = '';

        if (empty($vat) && WC()->session) {
            $vat = WC()->session->get(EU_VAT_GUARD_META_ORDER_VAT);
        }
        return $vat;
    }



}
