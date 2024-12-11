define([
    'jquery', // Make sure jQuery is loaded
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/payment/additional-validators',
    'Magento_Paypal/js/action/set-payment-method',
    'Magento_Customer/js/customer-data'
], function ($,Component,additionalValidators,setPaymentMethodAction,customerData) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Prysoms_PhiPayments/payment/phipayment'
        },

        /** Redirect to PhiPayment */
        continueToPhiPayment: function () {
            if (additionalValidators.validate()) {
                //update payment method information if additional data was changed
                setPaymentMethodAction(this.messageContainer).done(
                    function () {
                        customerData.invalidate(['cart']);
                        $.mage.redirect(
                            window.checkoutConfig.payment.phipayment.redirect_url
                        );
                    }
                );

                return false;
            }
        },

        /** Returns payment acceptance mark image path */
        getPaymentLogoSrc: function () {
            return window.checkoutConfig.payment.phipayment.getPaymentLogoSrc;
        },
    });
});
