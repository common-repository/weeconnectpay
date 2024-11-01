type CloverElementStyles = NonNullable<object>

type CloverOptions = {
    locale?: string;
    merchantId?: string;
    showSecurePayments?: boolean;
    showPrivacyPolicy?: boolean;
};

type CloverValidationEvent = Event & {
    CARD_NUMBER?: EventElementState,
    CARD_DATE?: EventElementState,
    CARD_CVV?: EventElementState,
    CARD_POSTAL_CODE?: EventElementState
}

type CloverValidationEventState = Omit<CloverValidationEvent, keyof Event>;

type EventElementState = {
    error?: string;
    touched: boolean;
};

type CustomCloverValidationEvent = Omit<CloverValidationEvent, keyof Event>;

type EventTypes = 'change' | 'blur';

type TokenCreationResult = {
    errors?: {
        CARD_NUMBER?: string,
        CARD_DATE?: string,
        CARD_CVV?: string,
        CARD_POSTAL_CODE?: string,
    },
    token?: string,
}

type CloverIframeTokenizationResponse = Event & TokenCreationResult & {
    card?: {
        exp_month: string;
        exp_year: string;
        first6: string;
        last4: string;
        brand: string;
        address_zip?: string;
    };
    token?: string;
}

type CloverElement = CloverMountableHTMLElement

type MountFunction = (selector: string) => void;
