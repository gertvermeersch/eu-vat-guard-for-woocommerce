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
	 * VAT errors from the current Store API update.
	 *
	 * @var string[]
	 */
	private $store_api_vat_errors = array();

	/**
	 * Whether VAT and address validation succeeded during this request.
	 *
	 * @var bool
	 */
	private $store_api_vat_valid = false;

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
	 * Report the VAT state used to calculate totals without changing it.
	 *
	 * @return array Cart and checkout extension data.
	 */
	public function extend_cart_data() {
		$is_exempt = WC()->customer ? WC()->customer->get_is_vat_exempt() : false;
		return array(
			'vat_exempt'           => $is_exempt,
			'vat_number'           => $this->get_current_vat_number(),
			'vat_exempt_message'   => $is_exempt ? VAT_Guard_Helper::get_exemption_message() : '',
			'vat_validation_valid' => $this->store_api_vat_valid,
		);
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
			'vat_validation_valid' => array(
				'description' => __( 'Whether VAT and address validation succeeded in this response', 'eu-vat-guard-for-woocommerce' ),
				'type'        => 'boolean',
				'readonly'    => true,
			),
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

		// Establish exemption before the Store API calculates totals.
		add_action(
			'woocommerce_store_api_cart_update_customer_from_request',
			array( $this, 'handle_customer_address_update_store_api' ),
			10,
			2
		);
		add_action(
			'woocommerce_store_api_checkout_update_customer_from_request',
			array( $this, 'handle_checkout_customer_update_store_api' ),
			10,
			2
		);
		add_action( 'woocommerce_store_api_cart_errors', array( $this, 'add_store_api_vat_errors' ), 10, 2 );

        // Preload the VAT number field with user meta data if available
        add_filter(
            "woocommerce_get_default_value_for_eu-vat-guard/vat_number",
            array($this, 'preload_vat_field'),
            10,
            3
        );

        // Save VAT number to customer account after block checkout order is placed
        add_action(
            'woocommerce_store_api_checkout_order_processed',
            array($this, 'save_order_vat_to_user_account'),
            10,
            1
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
	 * Synchronize a validated VAT field and its exemption decision.
	 *
	 * @param string                 $key       Additional field identifier.
	 * @param string                 $value     Validated field value.
	 * @param string                 $group     Field group.
	 * @param \WC_Order|\WC_Customer $wc_object Order or customer receiving the field.
	 */
	public function handle_vat_field_update( $key, $value, $group, $wc_object ) {
		if ( 'eu-vat-guard/vat_number' !== $key ) {
			return;
		}
		$was_exempt = WC()->customer ? WC()->customer->get_is_vat_exempt() : false;
		$vat        = VAT_Guard_Helper::sanitize_vat_field( $value );
		if ( $wc_object instanceof \WC_Customer ) {
			$wc_object->update_meta_data( EU_VAT_GUARD_META_VAT_NUMBER, $vat );
		} elseif ( $wc_object instanceof \WC_Order ) {
			$wc_object->update_meta_data( EU_VAT_GUARD_META_ORDER_VAT, $vat );
		}
		if ( WC()->session ) {
			WC()->session->set( EU_VAT_GUARD_META_ORDER_VAT, $vat );
		}
		if ( WC()->customer ) {
			WC()->customer->update_meta_data( EU_VAT_GUARD_META_BLOCK_VAT, $vat );
			WC()->customer->update_meta_data( EU_VAT_GUARD_META_VAT_NUMBER, $vat );
		}

		$is_exempt = $this->update_store_api_vat_exemption();
		if ( WC()->customer ) {
			WC()->customer->save();
		}
		if ( $wc_object instanceof \WC_Order ) {
			$wc_object->update_meta_data( EU_VAT_GUARD_META_ORDER_EXEMPT, $is_exempt ? 'yes' : 'no' );
			$wc_object->update_meta_data( 'is_vat_exempt', $is_exempt ? 'yes' : 'no' );
		}

		// Contact fields may be persisted after totals, or without a totals update on PATCH.
		if ( WC()->cart && $was_exempt !== $is_exempt ) {
			WC()->cart->calculate_totals();
		}
	}

    /**
     * Save VAT number from order to customer account after block checkout order is placed.
     * Handles both logged-in users and new accounts created at checkout.
     *
     * @param \WC_Order $order
     */
    public function save_order_vat_to_user_account($order)
    {
        if (!$order) {
            return;
        }

        $vat_number = $order->get_meta(EU_VAT_GUARD_META_ORDER_VAT);
        if (empty($vat_number)) {
            return;
        }

        $customer_id = $order->get_customer_id();
        if (!$customer_id) {
            return; // Guest checkout — no account to save to
        }

        $current_vat = get_user_meta($customer_id, EU_VAT_GUARD_META_VAT_NUMBER, true);
        if ($vat_number !== $current_vat) {
            update_user_meta($customer_id, EU_VAT_GUARD_META_VAT_NUMBER, $vat_number);

            do_action('eu_vat_guard_customer_vat_updated', $customer_id, array(
                'vat_number' => $vat_number,
                'previous_vat' => $current_vat,
                'order_id' => $order->get_id(),
            ));
        }
    }

	/**
	 * Establish exemption before the shipping route calculates totals.
	 *
	 * @param string|null      $package_id   Shipping package identifier.
	 * @param string           $rate_id      Selected rate identifier.
	 * @param \WP_REST_Request $request_data Full request data.
	 */
	public function handle_shipping_method_change_store_api( $package_id, $rate_id, $request_data ) {
		$this->update_store_api_vat_exemption();
		// WooCommerce calculates totals immediately after this hook.
	}

	/**
	 * Establish exemption before the address route calculates totals.
	 *
	 * @param \WC_Customer     $customer_data Updated customer.
	 * @param \WP_REST_Request $request_data  Full request data.
	 */
	public function handle_customer_address_update_store_api( $customer_data, $request_data ) {
		$this->update_store_api_vat_exemption();
		// WooCommerce saves the customer and calculates totals after this hook.
	}

	/**
	 * Apply final checkout address and VAT input before WooCommerce copies cart totals.
	 *
	 * @param \WC_Customer     $customer Updated customer.
	 * @param \WP_REST_Request $request  Full request data.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException Invalid VAT context.
	 */
	public function handle_checkout_customer_update_store_api( $customer, $request ) {
		// Contact fields are persisted later; use the validated submission now.
		$fields = $request->get_param( 'additional_fields' );
		if ( is_array( $fields ) && array_key_exists( 'eu-vat-guard/vat_number', $fields ) && WC()->session ) {
			WC()->session->set( EU_VAT_GUARD_META_ORDER_VAT, VAT_Guard_Helper::sanitize_vat_field( $fields['eu-vat-guard/vat_number'] ) );
		}

		$this->update_store_api_vat_exemption();
		if ( 'POST' === $request->get_method() && ! empty( $this->store_api_vat_errors ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'eu_vat_guard_vat_validation',
				esc_html( implode( ' ', $this->store_api_vat_errors ) ),
				400
			);
		}
	}

	/**
	 * Set exemption from current session inputs, including explicitly cleared VAT.
	 *
	 * @return bool Whether the customer is exempt.
	 */
	private function update_store_api_vat_exemption() {
		$this->store_api_vat_errors = array();
		$is_exempt                  = $this->main_class->validate_and_set_vat_exemption(
			$this->get_current_vat_number(),
			WC()->customer ? WC()->customer->get_billing_country() : '',
			WC()->customer ? WC()->customer->get_shipping_country() : '',
			$this->store_api_vat_errors
		);
		$this->store_api_vat_valid  = empty( $this->store_api_vat_errors );
		if ( $this->store_api_vat_valid ) {
			$this->clear_resolved_country_notices();
		}
		return $is_exempt;
	}

	/**
	 * Remove legacy country mismatch notices after successful validation.
	 */
	private function clear_resolved_country_notices() {
		if ( ! WC()->session ) {
			return;
		}
		$messages = array(
			__( 'The billing country must match the country of the VAT number.', 'eu-vat-guard-for-woocommerce' ),
			__( 'The shipping country must match the country of the VAT number.', 'eu-vat-guard-for-woocommerce' ),
		);
		$notices  = wc_get_notices();
		$changed  = false;
		foreach ( $notices['error'] ?? array() as $index => $notice ) {
			$message = is_array( $notice ) ? ( $notice['notice'] ?? '' ) : $notice;
			if ( in_array( trim( wp_strip_all_tags( $message ) ), $messages, true ) ) {
				unset( $notices['error'][ $index ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			$notices['error'] = array_values( $notices['error'] );
			wc_set_notices( $notices );
		}
	}

	/**
	 * Return update errors through Store API validation without changing totals or notices.
	 *
	 * @param \WP_Error $errors Store API validation errors.
	 * @param \WC_Cart  $cart   Current cart.
	 */
	public function add_store_api_vat_errors( $errors, $cart ) {
		foreach ( $this->store_api_vat_errors as $message ) {
			$errors->add( 'eu_vat_guard_vat_validation', $message );
		}
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
			array( 'wp-element', 'wp-hooks', 'wp-data', 'wp-i18n', 'wp-notices', 'wc-blocks-data-store' ),
			filemtime( EU_VAT_GUARD_PLUGIN_DIR . 'assets/js/vat-guard-block-checkout.js' ),
            true
        );

        wp_localize_script('vat-guard-block-checkout-fallback', 'vatGuardBlock', array(
            'restUrl' => rest_url('vat-guard/v1/validate'),
            'nonce' => wp_create_nonce('wp_rest'),
            'messages' => array(
                'validating' => __('Validating VAT number...', 'eu-vat-guard-for-woocommerce'),
                'valid' => __('VAT number is valid', 'eu-vat-guard-for-woocommerce'),
                'exempt' => VAT_Guard_Helper::get_exemption_message(),
				'invalid'    => __( 'Invalid VAT number', 'eu-vat-guard-for-woocommerce' ),
			),
			'countryMismatchMessages' => array(
				__( 'The billing country must match the country of the VAT number.', 'eu-vat-guard-for-woocommerce' ),
				__( 'The shipping country must match the country of the VAT number.', 'eu-vat-guard-for-woocommerce' ),
			),
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
        $vat_woocommerce_country = VAT_Guard_Helper::vat_country_to_woocommerce_country($vat_country);
        if (!empty($billing_country) && strtoupper($billing_country) !== $vat_woocommerce_country) {
            return new WP_REST_Response(array(
                'valid' => false,
                'exempt' => false,
                'message' => __('The billing country must match the country of the VAT number.', 'eu-vat-guard-for-woocommerce')
            ), 200);
        }

        // Check if VAT exempt
        $shop_base_country = wc_get_base_location()['country'];
        $is_exempt = !empty($vat) && $vat_woocommerce_country && $vat_woocommerce_country !== $shop_base_country;

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
