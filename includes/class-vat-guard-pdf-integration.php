<?php
/**
 * VAT Guard PDF Integration Class
 *
 * Handles integration with PDF invoice plugins, specifically WooCommerce PDF Invoices & Packing Slips
 *
 * @package Stormlabs\EUVATGuard
 */

namespace Stormlabs\EUVATGuard;

if (!defined('ABSPATH')) {
    exit;
}

class VAT_Guard_PDF_Integration
{
    /**
     * Single instance of the class
     *
     * @var VAT_Guard_PDF_Integration
     */
    private static $instance = null;

    /**
     * Reference to main VAT_Guard instance
     *
     * @var VAT_Guard
     */
    private $vat_guard;

    /**
     * Get single instance of the class
     *
     * @return VAT_Guard_PDF_Integration
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->vat_guard = VAT_Guard::instance();
        $this->init();
    }

    /**
     * Initialize PDF integration
     */
    public function init()
    {
        // Setup PDF invoice integration
        $this->setup_pdf_invoice_integration();

        // Setup address formatting hooks (optional)
        $this->setup_address_formatting_hooks();

        // Load PDF template helper functions
        $this->load_template_helpers();
    }

    /**
     * Setup address formatting hooks for VAT display
     */
    private function setup_address_formatting_hooks()
    {
        // These hooks can be enabled if needed for address formatting
        // Currently commented out in the original implementation
        // add_filter('woocommerce_order_formatted_billing_address', array($this, 'add_vat_to_formatted_address'), 10, 2);
        // add_filter('woocommerce_my_account_my_address_formatted_address', array($this, 'add_vat_to_my_account_address'), 10, 3);
    }

    /**
     * Setup PDF Invoice integration for WooCommerce PDF Invoices & Packing Slips
     */
    private function setup_pdf_invoice_integration()
    {
        // Check if WooCommerce PDF Invoices & Packing Slips is active
        if (!class_exists('WPO_WCPDF')) {
            return;
        }

        // Add VAT number and exemption status to PDF invoices
        add_action('wpo_wcpdf_after_billing_address', array($this, 'add_vat_to_pdf_invoice'), 10, 2);
        add_action('wpo_wcpdf_after_order_data', array($this, 'add_vat_exemption_to_pdf_invoice'), 10, 2);
    }

    /**
     * Load PDF template helper functions
     */
    private function load_template_helpers()
    {
        require_once plugin_dir_path(__FILE__) . 'pdf-template-helpers.php';
    }

    /**
     * Add VAT number to PDF invoice billing address section
     *
     * @param string $document_type The document type (invoice, packing-slip, etc.)
     * @param \WC_Order $order The WooCommerce order object
     */
    public function add_vat_to_pdf_invoice($document_type, $order)
    {
        // Only show on invoices
        if ($document_type !== 'invoice') {
            return;
        }

        $vat_number = VAT_Guard_Helper::get_order_vat_number($order);
        if (!empty($vat_number)) {
            echo '<div class="vat-number" style="margin-top: 10px;">';
            echo '<strong>' . esc_html(VAT_Guard_Helper::get_vat_label()) . ':</strong> ' . esc_html($vat_number);
            echo '</div>';
        }
    }

    /**
     * Add VAT exemption status to PDF invoice order data section
     *
     * @param string $document_type The document type (invoice, packing-slip, etc.)
     * @param \WC_Order $order The WooCommerce order object
     */
    public function add_vat_exemption_to_pdf_invoice($document_type, $order)
    {
        // Only show on invoices
        if ($document_type !== 'invoice') {
            return;
        }

        $is_exempt = $order->get_meta(EU_VAT_GUARD_META_ORDER_EXEMPT);

        if ($is_exempt === 'yes') {
            echo '<tr class="vat-exempt-status">';
            echo '<th>' . esc_html__('VAT Status', 'eu-vat-guard-for-woocommerce') . '</th>';
            echo '<td><strong style="color: #46b450;">' . esc_html(VAT_Guard_Helper::get_exemption_message()) . '</strong></td>';
            echo '</tr>';
        }
    }

    /**
     * Get VAT information for PDF templates (can be called directly from templates)
     *
     * @param \WC_Order $order The WooCommerce order object
     * @return array VAT information array
     */
    public static function get_pdf_vat_info($order)
    {
        $vat_guard = VAT_Guard::instance();
        $vat_number = VAT_Guard_Helper::get_order_vat_number($order);
        $is_exempt = $order->get_meta(EU_VAT_GUARD_META_ORDER_EXEMPT);

        return [
            'vat_number' => $vat_number,
            'is_exempt' => $is_exempt === 'yes',
            'vat_label' => VAT_Guard_Helper::get_vat_label(),
            'exemption_message' => VAT_Guard_Helper::get_exemption_message()
        ];
    }

    /**
     * Display VAT information block for PDF templates
     *
     * @param \WC_Order $order The WooCommerce order object
     * @param string $style Display style: 'table' or 'div'
     */
    public static function display_pdf_vat_block($order, $style = 'table')
    {
        $vat_info = self::get_pdf_vat_info($order);

        if (empty($vat_info['vat_number']) && !$vat_info['is_exempt']) {
            return; // Nothing to display
        }

        if ($style === 'table') {
            echo '<table class="vat-info" style="margin: 10px 0; width: 100%;">';

            if (!empty($vat_info['vat_number'])) {
                echo '<tr>';
                echo '<th style="text-align: left; padding: 5px 10px 5px 0;">' . esc_html($vat_info['vat_label']) . ':</th>';
                echo '<td style="padding: 5px 0;">' . esc_html($vat_info['vat_number']) . '</td>';
                echo '</tr>';
            }

            if ($vat_info['is_exempt']) {
                echo '<tr>';
                echo '<th style="text-align: left; padding: 5px 10px 5px 0;">' . esc_html__('VAT Status', 'eu-vat-guard-for-woocommerce') . ':</th>';
                echo '<td style="padding: 5px 0; color: #46b450; font-weight: bold;">' . esc_html($vat_info['exemption_message']) . '</td>';
                echo '</tr>';
            }

            echo '</table>';
        } else {
            // Simple div style
            echo '<div class="vat-info" style="margin: 10px 0;">';

            if (!empty($vat_info['vat_number'])) {
                echo '<div style="margin-bottom: 5px;">';
                echo '<strong>' . esc_html($vat_info['vat_label']) . ':</strong> ' . esc_html($vat_info['vat_number']);
                echo '</div>';
            }

            if ($vat_info['is_exempt']) {
                echo '<div style="color: #46b450; font-weight: bold;">';
                echo esc_html($vat_info['exemption_message']);
                echo '</div>';
            }

            echo '</div>';
        }
    }

    /**
     * Add VAT number to address formats
     *
     * @param array $formats Address formats array
     * @return array Modified address formats
     */
    public function add_vat_to_address_format($formats)
    {
        foreach ($formats as $country => &$format) {
            if (strpos($format, '{vat_number}') === false) {
                // Add VAT number after company if exists
                $format = str_replace('{company}', "{company}\n{vat_number}", $format);
            }
        }
        return $formats;
    }

    /**
     * Add VAT number replacement for address formatting
     *
     * @param array $replacements Address replacements array
     * @param array $args Address arguments
     * @return array Modified replacements
     */
    public function add_vat_number_replacement($replacements, $args)
    {
        $replacements['{vat_number}'] = !empty($args['vat_number']) ?
            __('VAT Number:', 'eu-vat-guard-for-woocommerce') . ' ' . $args['vat_number'] : '';
        return $replacements;
    }

    /**
     * Add VAT number to order's formatted address
     *
     * @param array $address Address array
     * @param \WC_Order $order The WooCommerce order object
     * @return array Modified address array
     */
    public function add_vat_to_formatted_address($address, $order)
    {
        $vat = VAT_Guard_Helper::get_order_vat_number($order);

        if (!empty($vat)) {
            $address['vat_number'] = $vat;

            // Add VAT exempt status if applicable
            $is_exempt = $order->get_meta(EU_VAT_GUARD_META_ORDER_EXEMPT);
            if ($is_exempt === 'yes') {
                $address['vat_status'] = __('VAT exempt', 'eu-vat-guard-for-woocommerce');
            }
        }
        return $address;
    }

    /**
     * Add VAT number to My Account formatted address
     *
     * @param array $address Address array
     * @param int $customer_id Customer ID
     * @param string $address_type Address type (billing/shipping)
     * @return array Modified address array
     */
    public function add_vat_to_my_account_address($address, $customer_id, $address_type)
    {
        if ($address_type === 'billing') {
            $vat = get_user_meta($customer_id, EU_VAT_GUARD_META_VAT_NUMBER, true);
            if (!empty($vat)) {
                $address['vat_number'] = $vat;
            }
        }
        return $address;
    }
}
