<?php 

namespace Stormlabs\EUVATGuard;

if (!defined('ABSPATH')) {
    exit;
}

class VAT_Guard_Account
{
    private VAT_Guard $main_class;

    private static VAT_Guard_Account $instance;

    public static function instance(): VAT_Guard_Account
    {
        if (!isset(self::$instance)) {
            self::$instance = new VAT_Guard_Account(VAT_Guard::instance());
        }
        return self::$instance;
    }

    private function __construct(VAT_Guard $main_class) { 
        $this->main_class = $main_class::instance();
       require_once('class-vat-guard-helper.php');

    }

    public function setup_hooks() {
        // Account and registration hooks
        add_action('woocommerce_edit_account_form_start', array($this, 'add_account_fields'));
        add_action('woocommerce_save_account_details', array($this, 'save_fields_registration'));

        // Only add registration hooks if registration fields are not hidden
        if (get_option('eu_vat_guard_hide_registration_fields', '0') !== '1') {
            add_action('woocommerce_register_form', array($this, 'add_registration_fields'));
            add_filter('woocommerce_registration_errors', array($this, 'validate_registration_fields'), 10, 3);
            add_action('woocommerce_created_customer', array($this, 'save_fields_registration'));
        }
    }

     /* adds registration field to create account 
     * 
     */
    public function add_registration_fields()
    {
        $require_company = get_option('eu_vat_guard_require_company', 1);
        $require_vat = get_option('eu_vat_guard_require_vat', 1);
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="company_name"
                id="company_name"
                placeholder="<?php echo esc_attr(VAT_Guard_Helper::get_company_label()); ?><?php echo $require_company ? ' *' : ''; ?>"
                <?php if ($require_company)
                    echo 'required'; ?> value="<?php if (!empty($_POST['company_name']))
                           // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Just displaying previously submitted value
                           echo esc_attr(sanitize_text_field(wp_unslash($_POST['company_name']))); ?>" />
        </p>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="vat_number" id="vat_number"
                placeholder="<?php echo esc_attr(VAT_Guard_Helper::get_vat_label()); ?><?php echo $require_vat ? ' *' : ''; ?>" value="<?php if (!empty($_POST['vat_number']))
                              // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Just displaying previously submitted value
                              echo esc_attr(sanitize_text_field(wp_unslash($_POST['vat_number']))); ?>" <?php if ($require_vat) {
                                    echo 'required';
                                } ?> />
        </p>
        <?php
    }

    public function add_account_fields()
    {
        $company_name = get_user_meta(get_current_user_id(), EU_VAT_GUARD_META_COMPANY_NAME, true);
        $vat_number = get_user_meta(get_current_user_id(), EU_VAT_GUARD_META_VAT_NUMBER, true);

        $require_company = get_option('eu_vat_guard_require_company', 1);
        $require_vat = get_option('eu_vat_guard_require_vat', 1);
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="company_name"><?php echo esc_html(VAT_Guard_Helper::get_company_label());
            if ($require_company) { ?><span class="required">*</span> <?php } ?></label>
            <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="company_name"
                id="company_name" value="<?php echo esc_attr($company_name); ?>" />
        </p>
        <?php //if (!get_option('eu_vat_guard_enable_block_checkout', 0)) { 
                // TODO: check if we still need this condition check
                // ?>

        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="vat_number"><?php echo esc_html(VAT_Guard_Helper::get_vat_label());
            if ($require_vat) { ?><span class="required">*</span> <?php } ?></label>
            <input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="vat_number" id="vat_number"
                value="<?php echo esc_attr($vat_number); ?>" />
        </p>
        <?php
        //  }
    }

     /* Validate registration fields for company name and VAT number
     * @param WP_Error $errors
     * @param string $username
     * @param string $email
     * @return WP_Error
     */
    public function validate_registration_fields($errors, $username, $email)
    {
        $require_company = get_option('eu_vat_guard_require_company', 1);
        $require_vat = get_option('eu_vat_guard_require_vat', 1);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress handles nonce verification for registration forms
        $company_name = isset($_POST['company_name']) ? sanitize_text_field(wp_unslash($_POST['company_name'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress handles nonce verification for registration forms
        $vat_number_raw = isset($_POST['vat_number']) ? sanitize_text_field(wp_unslash($_POST['vat_number'])) : '';

        if ($require_company && empty($company_name)) {
            $errors->add('company_name_error', __('Please enter your company name.', 'eu-vat-guard-for-woocommerce'));
        }
        if ($require_vat && empty($vat_number_raw)) {
            $errors->add('vat_number_error', __('Please enter your VAT number.', 'eu-vat-guard-for-woocommerce'));
        } elseif (!empty($vat_number_raw)) {
            $error_message = '';
            if (!VAT_Guard_Helper::is_valid_eu_vat_number($vat_number_raw, $error_message)) {
                $errors->add('vat_number_error', $error_message);
            }
        }
        return $errors;
    }

    /* Save registration fields for company name and VAT number
     * @param int $customer_id
     */
    public function save_fields_registration($customer_id)
    {
        $require_company = get_option('eu_vat_guard_require_company', 1);
        $require_vat = get_option('eu_vat_guard_require_vat', 1);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress handles nonce verification for registration forms
        $vat_number = isset($_POST['vat_number']) ? VAT_Guard_Helper::sanitize_vat_field(wp_unslash($_POST['vat_number'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress handles nonce verification for registration forms
        $company_name = isset($_POST['company_name']) ? sanitize_text_field(wp_unslash($_POST['company_name'])) : '';

        if ($require_vat && empty($vat_number)) {
            wc_add_notice(__('Please enter your VAT number.', 'eu-vat-guard-for-woocommerce'), 'error');
            return;
        }
        if (!empty($vat_number)) {
            $error_message = '';
            if (!VAT_Guard_Helper::is_valid_eu_vat_number($vat_number, $error_message)) {
                wc_add_notice($error_message, 'error');
                return;
            }
        }
        if ($require_company && empty($company_name)) {
            wc_add_notice(__('Please enter your company name.', 'eu-vat-guard-for-woocommerce'), 'error');
            return;
        }
        if (!empty($company_name)) {
            update_user_meta($customer_id, EU_VAT_GUARD_META_COMPANY_NAME, $company_name);
        }
        if (!empty($vat_number)) {
            update_user_meta($customer_id, EU_VAT_GUARD_META_VAT_NUMBER, $vat_number);
        }
    }

}

