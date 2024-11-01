// import PaymentForm from "./PaymentForm";
/**
 * External dependencies
 */
const {registerPaymentMethod} = window.wc.wcBlocksRegistry;

// import { __ } from '@wordpress/i18n';
// const __ = require('@wordpress/i18n');
// import { sanitizeHTML } from '@woocommerce/utils';
// const sanitizeHTML = require('@woocommerce/utils');
// import { decodeEntities } from '@wordpress/html-entities';
// const decodeEntities = require('@wordpress/html-entities');
// import { RawHTML } from '@wordpress/element';
// const RawHTML = require('@wordpress/element');

/**
 * Internal dependencies
 */
// import { PAYMENT_METHOD_NAME } from './constants';

const PAYMENT_METHOD_NAME = 'weeconnectpay'

// const settings = getPaymentMethodData( 'cod', {} );
// const settings = {
//     description: 'test description',
//
// }

// todo: make sure the function exists for canMakePayment
const settings = window.wc.wcSettings.getSetting('stripecheckout_data', {});
// todo: make sure the function exists for canMakePayment
const label = window.wp.htmlEntities.decodeEntities(settings.title) || window.wp.i18n.__('Credit Card', 'weeconnectpay');
// const defaultLabel = __( 'WeeConnectPay', 'woocommerce' );
// const label = decodeEntities( settings?.title || '' ) || defaultLabel;
// const label = 'weeconnectpay label';

/**
 * Content component
 */
const Content = () => {
    // return <RawHTML>{ sanitizeHTML( settings.description || '' ) }</RawHTML>;
    // return <RawHTML>{  settings.description  }</RawHTML>;
    // return 'YAYY CONTENT';
    // eslint-disable-next-line no-undef
    // return React.createElement('div', {description: settings.description} )
    return window.wp.htmlEntities.decodeEntities(settings.description || '');
};

// /**
//  * Label component
//  *
//  * @param {*} props Props from payment API.
//  */
// const Label = ( props ) => {
//     const { PaymentMethodLabel } = props.components;
//
//     // eslint-disable-next-line no-undef
//     return React.createElement(PaymentMethodLabel, { text: 'weeconnectpay-label' }, null,)
//     // return <PaymentMethodLabel text={ 'weeconnectpay-label' } />;
// };


const canMakePayment = ({cart: cart, cartTotals: cartTotals}) => {
    console.log('cart: ', cart);
    console.log('cartTotals: ', cartTotals);
    return true;
};

// eslint-disable-next-line no-undef
const testContent = /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
    id: "weeconnectpay-wc-fields"
// eslint-disable-next-line no-undef
}, /*#__PURE__*/React.createElement("div", {
    id: "form-display-no-footer"
// eslint-disable-next-line no-undef
}, /*#__PURE__*/React.createElement("div", {
    className: "top-row-wrapper"
// eslint-disable-next-line no-undef
}, /*#__PURE__*/React.createElement("div", {
    className: "form-row top-row full-width"
// eslint-disable-next-line no-undef
}, /*#__PURE__*/React.createElement("div", {
    id: "weeconnectpay-card-number",
    autoComplete: "cc-number",
    className: "field card-number-field"
// eslint-disable-next-line no-undef
}), /*#__PURE__*/React.createElement("div", {
    className: "input-errors",
    id: "weeconnectpay-card-number-errors",
    role: "alert"
// eslint-disable-next-line no-undef
}))), /*#__PURE__*/React.createElement("div", {
    className: "bottom-row-wrapper"
// eslint-disable-next-line no-undef
}, /*#__PURE__*/React.createElement("div", {
    className: "form-row bottom-row third-width"
// eslint-disable-next-line no-undef
}, /*#__PURE__*/React.createElement("div", {
    id: "weeconnectpay-card-date",
    autoComplete: "cc-exp",
    className: "field card-date-field"
// eslint-disable-next-line no-undef
}), /*#__PURE__*/React.createElement("div", {
    className: "input-errors",
    id: "weeconnectpay-card-date-errors",
    role: "alert"
// eslint-disable-next-line no-undef
})), /*#__PURE__*/React.createElement("div", {
    className: "form-row bottom-row third-width"
// eslint-disable-next-line no-undef
}, /*#__PURE__*/React.createElement("div", {
    id: "weeconnectpay-card-cvv",
    autoComplete: "cc-csc",
    className: "field card-cvv-field"
// eslint-disable-next-line no-undef
}), /*#__PURE__*/React.createElement("div", {
    className: "input-errors",
    id: "weeconnectpay-card-cvv-errors",
    role: "alert"
// eslint-disable-next-line no-undef
})), /*#__PURE__*/React.createElement("div", {
    className: "form-row bottom-row third-width"
// eslint-disable-next-line no-undef
}, /*#__PURE__*/React.createElement("div", {
    id: "weeconnectpay-card-postal-code",
    autoComplete: "billing postal-code",
    className: "field card-postal-code-field"
// eslint-disable-next-line no-undef
}), /*#__PURE__*/React.createElement("div", {
    className: "input-errors",
    id: "weeconnectpay-card-postal-code-errors",
    role: "alert"
// eslint-disable-next-line no-undef
}))), /*#__PURE__*/React.createElement("div", {
    id: "card-response",
    role: "alert"
// eslint-disable-next-line no-undef
}), /*#__PURE__*/React.createElement("div", {
    id: "card-errors",
    role: "alert"
// eslint-disable-next-line no-undef
}), /*#__PURE__*/React.createElement("div", {
    className: "clover-footer"
// eslint-disable-next-line no-undef
}))), /*#__PURE__*/React.createElement("input", {
    type: "hidden",
    value: "",
    name: "token",
    id: "wcp-token"
// eslint-disable-next-line no-undef
}), /*#__PURE__*/React.createElement("input", {
    type: "hidden",
    value: "",
    name: "tokenized-zip",
    id: "wcp-tokenized-zip"
}));

/**
 * Cash on Delivery (COD) payment method config object.
 */
const options = {
    name: PAYMENT_METHOD_NAME,
    paymentMethodId: 'weeconnectpay',
    label: label,
    // content: Object( window.wp.element.createElement )( Content, null ),
    content: testContent,
    // edit: Object( window.wp.element.createElement )( Content, null ),
    edit: testContent,
    canMakePayment: canMakePayment,
    ariaLabel: label,
    supports: {
        features: settings?.supports ?? [
            'products',
            // 'refunds'
        ],
    },
};

registerPaymentMethod(options);
