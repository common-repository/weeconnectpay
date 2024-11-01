import React from 'react';

const PaymentForm = ({ lockIconUrl, securedLogosUrl }) => {
    return (
        <div>
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
                            <div id="weeconnectpay-card-date" autoComplete="cc-exp" className="field card-date-field"></div>
                            <div className="input-errors" id="weeconnectpay-card-date-errors" role="alert"></div>
                        </div>

                        <div className="form-row bottom-row third-width">
                            <div id="weeconnectpay-card-cvv" autoComplete="cc-csc" className="field card-cvv-field"></div>
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
            </div>
            <div id="weeconnectpay-separator-with-text">
                OR
            </div>
            <div id="weeconnectpay-payment-request-button"
                 style={{ width: '100%', maxWidth: '200px', margin: '0 auto', marginTop: '8px', marginBottom: '8px', height: '50px', zIndex: 99999 }}></div>
            <input type="hidden" value="" name="token" id="wcp-token"/>
            <input type="hidden" value="" name="tokenized-zip" id="wcp-tokenized-zip"/>
            <div id="weeconnectpay-secured-by-clover">
                <div id="weeconnectpay-secured-by-lock">
                    <img src={lockIconUrl} alt="Lock icon"/>
                </div>
                <div id="weeconnectpay-secured-by-display">
                    <div id="weeconnectpay-secured-by-text">
                        Payment secured by
                    </div>
                    <img id="weeconnectpay-secured-by-img"
                         src={securedLogosUrl}
                         alt="Secured by Clover & WeeConnectPay logos"/>
                </div>
            </div>
        </div>
    );
};

export default PaymentForm;
