define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'phipayment',
                component: 'Prysoms_PhiPayments/js/view/payment/method-renderer/phipayment'
            }
        );
        return Component.extend({});
    }
);