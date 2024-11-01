export enum CloverElementType {
    CARD = "CARD",
    CVV = "CARD_CVV",
    DATE = "CARD_DATE",
    NUMBER = "CARD_NUMBER",
    POSTAL_CODE = "CARD_POSTAL_CODE",
    STREET_ADDRESS = "CARD_STREET_ADDRESS",
    PAYMENT_REQUEST_BUTTON = "PAYMENT_REQUEST_BUTTON"
}

export enum WrapperElementId {
    NUMBER = 'weeconnectpay-card-number',
    DATE = 'weeconnectpay-card-date',
    CVV = 'weeconnectpay-card-cvv',
    ZIP = 'weeconnectpay-card-postal-code',
    PAYMENT_REQUEST_BUTTON = 'weeconnectpay-payment-request-button',
}

export enum ErrorDisplayElementId {
    NUMBER = 'weeconnectpay-card-number-errors',
    DATE = 'weeconnectpay-card-date-errors',
    CVV = 'weeconnectpay-card-cvv-errors',
    ZIP = 'weeconnectpay-card-postal-code-errors',
    PAYMENT_REQUEST_BUTTON = 'weeconnectpay-payment-request-button-errors',
}
