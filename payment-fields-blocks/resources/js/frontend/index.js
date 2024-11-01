import {useEffect} from "react";
// import {someFunction} from "./modules/grecaptcha"

/**
 * External dependencies
 */
const {registerPaymentMethod} = window.wc.wcBlocksRegistry;

const PAYMENT_METHOD_NAME = 'weeconnectpay'

// Plugin settings
const settings = window.wc.wcSettings.getSetting('weeconnectpay_data', {});
const label = window.wp.htmlEntities.decodeEntities(settings.title) || window.wp.i18n.__('Credit Card', 'weeconnectpay');

// eslint-disable-next-line no-unused-vars
const canMakePayment = ({cart: cart, cartTotals: cartTotals}) => {
    return true;
};

/**
 * Content component
 */
const Content = (props) => {
        const { eventRegistration, emitResponse } = props;
        const { onPaymentProcessing } = eventRegistration;

        console.log("WeeConnectPay Settings:", settings)

        useEffect(() => {
            const unsubscribe = onPaymentProcessing(async () => {

                const clover = window.wcpClover;

                if (typeof clover === 'undefined') {
                    // clover is undefined, handle accordingly
                    console.error('clover is undefined during onPaymentProcessing');
                    return;
                }

                try {
                    // Use async/await to wait for the token creation
                    const result = await clover.createToken();
                    const wcpCloverToken = result.token;
                    const wcpCloverZip = result.card?.address_zip;

                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                        meta: {
                            paymentMethodData: {
                                token: wcpCloverToken,
                                tokenizedZip: wcpCloverZip
                            },
                        },
                    };
                } catch (error) {
                    console.error('Error creating Clover token:', error);
                    // Handle any errors that occur during the token creation
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: 'Error creating Clover token',
                    };
                }
            });

            // Cleanup on unmount
            return () => unsubscribe();
        }, [emitResponse.responseTypes.ERROR, emitResponse.responseTypes.SUCCESS, onPaymentProcessing]);

        return (
            <div id="weeconnectpay-wc-fields">
                <div id="form-display-no-footer">
                    <div className="top-row-wrapper">
                        <div className="form-row top-row full-width">
                            <div id="weeconnectpay-card-number" autoComplete="cc-number"
                                 className="field card-number-field"></div>
                            <div className="input-errors" id="weeconnectpay-card-number-errors" role="alert"></div>
                        </div>
                    </div>

                    <div className="bottom-row-wrapper">
                        <div className="form-row bottom-row third-width">
                            <div id="weeconnectpay-card-date" autoComplete="cc-exp"
                                 className="field card-date-field"></div>
                            <div className="input-errors" id="weeconnectpay-card-date-errors" role="alert"></div>
                        </div>

                        <div className="form-row bottom-row third-width">
                            <div id="weeconnectpay-card-cvv" autoComplete="cc-csc"
                                 className="field card-cvv-field"></div>
                            <div className="input-errors" id="weeconnectpay-card-cvv-errors" role="alert"></div>
                        </div>

                        <div className="form-row bottom-row third-width">
                            <div id="weeconnectpay-card-postal-code" autoComplete="billing postal-code"
                                 className="field card-postal-code-field"></div>
                            <div className="input-errors" id="weeconnectpay-card-postal-code-errors" role="alert"></div>
                        </div>
                    </div>
                    <div id="card-response" role="alert"></div>
                    <div id="card-errors" role="alert"></div>
                    <div className="clover-footer"></div>
                </div>
                <input type="hidden" value="" name="token" id="wcp-token"/>
                <input type="hidden" value="" name="tokenized-zip" id="wcp-tokenized-zip"/>
            </div>
        );
};

/**
 * Cash on Delivery (COD) payment method config object.
 */
const options = {
    name: PAYMENT_METHOD_NAME,
    paymentMethodId: 'weeconnectpay',
    label: label,
    content: <Content />,
    edit: <Content />,
    canMakePayment: canMakePayment,
    ariaLabel: label,
    supports: {
        features: settings?.supports ?? [
            'products',
            'refunds'
        ],
    },
};

registerPaymentMethod(options);
