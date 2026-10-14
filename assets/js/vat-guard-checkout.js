/* global euVatGuardCheckout */
jQuery(function ($) {
    'use strict';

    if (typeof euVatGuardCheckout === 'undefined') {
        return;
    }

    var previousVisibility;

    function syncVatField(refreshTotals) {
        var $checkout = $('form.checkout');
        var $company = $checkout.find('#billing_company');
        var $vat = $checkout.find('[name="' + euVatGuardCheckout.vatField + '"]');
        var $row = $vat.closest('.form-row');

        if (!$company.length || !$vat.length || !$row.length) {
            return;
        }

        var visible = $.trim($company.val()) !== '';
        var required = visible && $vat.attr('data-vat-guard-required') === '1';

        $row.toggle(visible).toggleClass('validate-required', required);
        $vat.prop('disabled', !visible).prop('required', required).attr('aria-required', required ? 'true' : 'false');

        if (!visible) {
            $row.removeClass('woocommerce-invalid woocommerce-invalid-required-field woocommerce-validated');
        }

        if ($vat.attr('data-vat-guard-required') === '1') {
            var $label = $row.find('label');
            $label.find('.optional').remove();
            if (!$label.find('.required').length) {
                $label.append($('<abbr class="required"> *</abbr>').attr('title', euVatGuardCheckout.required));
            }
        }

        var changed = previousVisibility !== undefined && visible !== previousVisibility;
        previousVisibility = visible;
        if (refreshTotals && changed) {
            $(document.body).trigger('update_checkout');
        }
    }

    $(document.body).on('input change focusout', 'form.checkout #billing_company', function () {
        syncVatField(true);
    });
    $(document.body).on('init_checkout updated_checkout checkout_error', function () {
        syncVatField(false);
    });
    $(window).on('pageshow', function () {
        syncVatField(true);
    });
    syncVatField(false);
});
