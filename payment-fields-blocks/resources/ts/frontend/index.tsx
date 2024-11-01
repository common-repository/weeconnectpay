import {CloverElementType} from "./constants"

/**
 * External dependencies
 */
import {useEffect} from "react";
// @ts-expect-error -- Not yet published by WooCommerce, taken care of by webpack + dependency extraction
import {getSetting} from "@woocommerce/settings";
// @ts-expect-error -- Blocks registry is taken care of by webpack + dependency extraction
import {registerPaymentMethod} from '@woocommerce/blocks-registry';
import {decodeEntities} from '@wordpress/html-entities';
import {__} from '@wordpress/i18n';

/**
 * Project dependencies
 */
import CloverManager from "./CloverManager";
import GoogleRecaptchaManager from "./GoogleRecaptchaManager";


const PAYMENT_METHOD_NAME = 'weeconnectpay'

// Plugin settings
const settings: Settings = getSetting('weeconnectpay_data', {});
// TODO: settings.woocommerce.gateway.title is not set if brand new install
const label = decodeEntities(settings.woocommerce?.gateway?.title) || __('Credit Card', 'weeconnectpay');


const canMakePayment = () => {
    /**
     * These are the function parameters we can use:
     *     cart: Cart,
     *     cartTotals: CartTotals,
     *     cartNeedsShipping: boolean,
     *     shippingAddress: CartShippingAddress,
     *     billingData: BillingData,
     *     selectedShippingMethods: Record<string,unknown>,
     *     paymentRequirements: string[],
     */
    return true;
};

type EventRegistrationFunction = () => Promise<{
    type: string;
    message?: string;
    meta?: {
        paymentMethodData?: {
            token: string;
            tokenizedZip: string;
        };
    };
}>;

type EmitResponseProps = {
    noticeContexts: {
        PAYMENTS: string;
    }
    responseTypes: {
        ERROR: string;
        SUCCESS: string;
    };
};

type ContentProps = {
    eventRegistration: {
        onPaymentSetup: EventRegistrationFunction;
    };
    emitResponse: EmitResponseProps;
};


/**
 * Content component
 */
const Content = (props: ContentProps) => {
    const {eventRegistration, emitResponse} = props;
    const {onPaymentSetup} = eventRegistration;

    useEffect(() => {

        // @ts-expect-error -- onPaymentSetup does not expect async, but I don't make the rules
        const unsubscribe = onPaymentSetup(async () => {

            // CloverManager.standardizeValidationStateForPayment();
            // const validationState = CloverManager.getValidationState();
            // console.log('error notice context:',emitResponse?.noticeContexts?.PAYMENTS);
            // console.log('validationState:',validationState);
            const finalValidationState: CloverValidationEvent = CloverManager.createFinalValidationState();

            CloverManager.validationEventHandler(finalValidationState, 'onPaymentSetup');
            // const updatedValidationState = CloverManager.getValidationState();
            if (!CloverManager.isFinalEventValid(finalValidationState)){
                // console.log("Final validation state was invalid. Attempting to construct an error message out of it!")
                const message = CloverManager.getErrorMessageOrNull(finalValidationState);
                if (message !== null){
                    // console.log('Error message to display: ', message);
                    return {
                        type: emitResponse?.responseTypes.ERROR,
                        message,
                        messageContext: emitResponse?.noticeContexts?.PAYMENTS,
                    };
                } else {
                    const unhandledValidationErrorMessage: string = 'An unexpected validation error has occurred. Please check the console for more details.'
                    console.error('The final validation before tokenizing the card did not pass validation, but could not generate a proper error message.')
                    return {
                        type: emitResponse?.responseTypes.ERROR,
                        unhandledValidationErrorMessage,
                        messageContext: emitResponse?.noticeContexts?.PAYMENTS,
                    };
                }

            } else {
                // console.log("Final validation state was valid. Proceeding with tokenization!")
            }


            // Get the singleton instance of CloverManager with the configuration
            const cloverManager = CloverManager.getInstance(settings.clover);
            const clover = cloverManager.getCloverInstance();

            try {
                let googleRecaptchaToken: string = "";
                if (GoogleRecaptchaManager.isGoogleRecaptchaEnabled(settings.googleRecaptcha.isEnabled)) {
                    try {
                        const googleRecaptchaManager: GoogleRecaptchaManager = GoogleRecaptchaManager.getInstance(settings.googleRecaptcha)
                        googleRecaptchaToken = await googleRecaptchaManager.getTokenOrExceptionJson();
                    } catch (e: unknown) {
                        console.error('Error creating Google Recaptcha Token:', e);
                        googleRecaptchaToken = GoogleRecaptchaManager.getStringifiedErrorForPaymentMethodData(e);
                    }
                }
                // Wait for the token creation
                const result = await clover.createToken();
                const wcpCloverToken = result.token;
                const wcpCloverZip = result.card?.address_zip;

                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            "token": wcpCloverToken,
                            "tokenized-zip": wcpCloverZip,
                            "recaptcha-token" : googleRecaptchaToken
                        },
                    },
                };
            } catch (error) {
                console.error('Error creating Clover token:', error);
                // Handle any errors that occur during the token creation
                return {
                    type: emitResponse?.responseTypes.ERROR,
                    message: 'Error creating Clover token',
                    messageContext: emitResponse?.noticeContexts?.PAYMENTS,
                };
            }
        });

        // Cleanup on unmount
        // @ts-expect-error -- For unsubscribe -- React + TypeScript + Unpublished WooCommerce dependencies = no hair left
        return () => unsubscribe();
    }, [emitResponse.responseTypes.ERROR, emitResponse.responseTypes.SUCCESS, onPaymentSetup]);

    // useEffect with no dependencies to fire when the component has rendered
    useEffect(() => {
        function setupClover() {
            try {
                // console.log('settings.clover: ', settings.clover);

                // Get the singleton instance of CloverManager with the configuration
                const cloverManager = CloverManager.getInstance(settings.clover);

                // Creating elements list to pass to createElements
                const elementsToCreate: [CloverElementType, Partial<CloverElementStyles>][] = [
                    [CloverElementType.NUMBER, {}],
                    [CloverElementType.DATE, {}],
                    [CloverElementType.CVV, {}],
                    [CloverElementType.POSTAL_CODE, {}],
                ];
                // Creating elements using the list of elements to create
                const createdElements = cloverManager.createElements(elementsToCreate);

                // Mount each iframe element to its own associated CloverElementType
                cloverManager.mountElements(createdElements);

                // Add event handlers for each createdElements using the wrappers and error elements.
                // Ids are resolved using enums in constants.ts by referencing CloverElementType for each element
                cloverManager.attachEventListeners({
                    elements: createdElements,
                    events: ['change', 'blur'],
                    handler: CloverManager.validationEventHandler
                });
            } catch (error) {
                // @ts-expect-error -- Not defined error yet
                console.error('WeeConnectPay failed to setup Clover:', error.message);
            }
        }

        setupClover();
    }, []);

    return (
        <div id="weeconnectpay-wc-fields">
            <div id="form-display-no-footer">
                <div className="top-row-wrapper">
                    <div className="form-row top-row full-width">
                        <div id="weeconnectpay-card-number"
                             className="field card-number-field"></div>
                        <div className="input-errors" id="weeconnectpay-card-number-errors" role="alert"></div>
                    </div>
                </div>

                <div className="bottom-row-wrapper">
                    <div className="form-row bottom-row third-width">
                        <div id="weeconnectpay-card-date"
                             className="field card-date-field"></div>
                        <div className="input-errors" id="weeconnectpay-card-date-errors" role="alert"></div>
                    </div>

                    <div className="form-row bottom-row third-width">
                        <div id="weeconnectpay-card-cvv"
                             className="field card-cvv-field"></div>
                        <div className="input-errors" id="weeconnectpay-card-cvv-errors" role="alert"></div>
                    </div>

                    <div className="form-row bottom-row third-width">
                        <div id="weeconnectpay-card-postal-code"
                             className="field card-postal-code-field"></div>
                        <div className="input-errors" id="weeconnectpay-card-postal-code-errors" role="alert"></div>
                    </div>
                </div>
                <div id="card-response" role="alert"></div>
                <div id="card-errors" role="alert"></div>
                <div className="clover-footer"></div>
            </div>
        </div>
    );
};

/**
 * Payment method config object.
 */
const options = {
    name: PAYMENT_METHOD_NAME,
    paymentMethodId: PAYMENT_METHOD_NAME,
    label: label,
    // @ts-expect-error -- props fed dynamically by WooCommerce -- should not define them here
    content: <Content emitResponse={() => {}} eventRegistration={() => {}}/>,
    // @ts-expect-error -- props fed dynamically by WooCommerce -- should not define them here
    edit: <Content emitResponse={() => {}} eventRegistration={() => {}}/>,
    canMakePayment: canMakePayment,
    ariaLabel: label,
    supports: {
        features:  settings.woocommerce?.gateway?.supports ? settings.woocommerce.gateway.supports : ['products'],
    },
};

registerPaymentMethod(options);
