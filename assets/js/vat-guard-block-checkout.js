/**
 * VAT Guard Block Checkout Support
 * Provides real-time VAT validation and exemption status for WooCommerce Block Checkout
 */

(function () {
    'use strict';

    // Wait for WooCommerce blocks to be available
    const initVatGuard = () => {
        if (!window.wc || !window.wc.blocksCheckout) {
            setTimeout(initVatGuard, 100);
            return;
        }

        //const { registerCheckoutFilters } = window.wc.blocksCheckout;
        const { __ } = window.wp.i18n;
        const { createElement, useState, useEffect, useRef } = window.wp.element;
        const { select, dispatch } = window.wp.data;

        // A cart response and an already displayed notice have separate lifecycles.
        const normalizeMessage = (message) => typeof message === 'string' ? message.trim().replace(/\s+/g, ' ') : '';
        const mismatchMessages = (vatGuardBlock.countryMismatchMessages || []).map(normalizeMessage);
        const isCountryMismatch = (message) => mismatchMessages.includes(normalizeMessage(message));
        const isUpdatingCustomer = (cartStore) => cartStore.isCustomerDataUpdating?.() || cartStore.isAddressFieldsForShippingRatesUpdating?.();
        let lastNoticeValidation = null;

        const syncResolvedVatNotices = () => {
            const cartStore = select('wc/store/cart');
            if (!cartStore || isUpdatingCustomer(cartStore)) {
                return;
            }
            const validation = cartStore.getCartData?.()?.extensions?.['eu-vat-guard'];
            if (!validation || validation === lastNoticeValidation) {
                return;
            }
            lastNoticeValidation = validation;
            if (validation.vat_validation_valid !== true) {
                return;
            }

            // Let WooCommerce finish publishing notices for this response first.
            setTimeout(() => {
                const currentCartStore = select('wc/store/cart');
                const cartData = currentCartStore?.getCartData?.();
                if (cartData?.extensions?.['eu-vat-guard'] !== validation || isUpdatingCustomer(currentCartStore)) {
                    return;
                }
                const errors = [...(cartData.errors || []), ...(currentCartStore.getCartErrors?.() || [])];
                if (errors.some((error) => error.code === 'eu_vat_guard_vat_validation')) {
                    return;
                }

                const noticesStore = select('core/notices');
                const noticesActions = dispatch('core/notices');
                const registeredContexts = select('wc/store/store-notices')?.getRegisteredContainers?.() || [];
                const contexts = new Set(['default', 'wc/cart', 'wc/checkout', ...registeredContexts]);
                contexts.forEach((context) => {
                    (noticesStore?.getNotices(context) || []).forEach((notice) => {
                        if (notice.status === 'error' && (notice.id === 'eu_vat_guard_vat_validation' || isCountryMismatch(notice.content))) {
                            noticesActions.removeNotice(notice.id, context);
                        }
                    });
                });

                // Older PHP notices are static markup, outside the Blocks notice store.
                document.querySelectorAll('.wc-block-store-notices .woocommerce-error li, .woocommerce-notices-wrapper .woocommerce-error li').forEach((notice) => {
                    if (!isCountryMismatch(notice.textContent)) {
                        return;
                    }
                    const list = notice.parentElement;
                    notice.remove();
                    if (list && !list.querySelector('li')) {
                        list.remove();
                    }
                });
            }, 0);
        };
        window.wp.data.subscribe(syncResolvedVatNotices);
        syncResolvedVatNotices();

        // Add VAT exempt notice to checkout summary
        const addVatExemptNotice = () => {
            const cartData = select('wc/store/cart')?.getCartData?.() || {};
            const isVatExempt = cartData?.extensions?.['eu-vat-guard']?.vat_exempt;

            // Remove existing notice
            const existingNotice = document.querySelector('.vat-guard-exempt-notice');
            if (existingNotice) {
                existingNotice.remove();
            }

            if (isVatExempt) {
                // Find the checkout totals area
                const totalsArea = document.querySelector('.wc-block-components-totals-wrapper, .wp-block-woocommerce-checkout-order-summary-block');
                if (totalsArea) {
                    const notice = document.createElement('div');
                    notice.className = 'vat-guard-exempt-notice';
                    notice.style.cssText = `
                        background: #f0f9ff;
                        border: 1px solid #00a32a;
                        border-radius: 4px;
                        padding: 10px;
                        margin: 10px 0;
                        color: #00a32a;
                        font-weight: bold;
                        text-align: center;
                    `;
                    notice.innerHTML = '✓ ' + vatGuardBlock.messages.exempt;
                    totalsArea.insertBefore(notice, totalsArea.firstChild);
                }
            }
        };

        // Monitor for cart changes
        let lastCartData = null;
        const monitorCartChanges = () => {
            const cartData = select('wc/store/cart')?.getCartData?.() || {};
            const currentVatExempt = cartData?.extensions?.['eu-vat-guard']?.vat_exempt;
            if (lastCartData !== currentVatExempt) {
                lastCartData = currentVatExempt;
                setTimeout(addVatExemptNotice, 100); // Small delay to ensure DOM is updated
            }
        };

        // Check for changes periodically
        setInterval(monitorCartChanges, 500);

        // Add CSS for validation messages
        const style = document.createElement('style');
        style.textContent = `
            .vat-guard-validation-message {
                margin: 5px;
                font-size: 0.9em;
                line-height: 1.4;
            }
            .vat-guard-validation-message.validating {
                color: #666;
            }
            .vat-guard-validation-message.error {
                color: #d63638;
            }
            .vat-guard-validation-message.valid {
                color: #00a32a;
            }
            .vat-guard-validation-message.exempt {
                color: #00a32a;
                font-weight: bold;
            }
            .vat-guard-exempt-notice {
                background: #f0f9ff;
                border: 1px solid #00a32a;
                border-radius: 4px;
                padding: 10px;
                margin: 10px 0;
                color: #00a32a;
                font-weight: bold;
                text-align: center;
            }
        `;
        document.head.appendChild(style);
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initVatGuard);
    } else {
        initVatGuard();
    }
})();
