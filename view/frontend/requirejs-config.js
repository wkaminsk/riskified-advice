var config = {
    map: {
        '*': {
            "Magento_Checkout/js/model/quote" : "Riskified_Advice/js/model/quote"
        }
    },
    config: {
        mixins: {
            'Adyen_Payment/js/view/payment/method-renderer/adyen-cc-method': {
                'Riskified_Advice/js/view/payment/method-renderer/adyen-cc-method-mixin': true
            },
            'PayPal_Braintree/js/view/payment/3d-secure': {
                'Riskified_Advice/js/view/payment/braintree/3d-secure-mixin': true
            },
            'PayPal_Braintree/js/view/payment/method-renderer/cc-form': {
                'Riskified_Advice/js/view/payment/method-renderer/cc-form-mixin': true
            },
        },
    }
};
