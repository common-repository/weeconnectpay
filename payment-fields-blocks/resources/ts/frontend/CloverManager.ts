import {CloverElementType, WrapperElementId, ErrorDisplayElementId} from "./constants"

interface CloverMountableHTMLElement extends HTMLElement {
    mount: MountFunction;
}

interface CloverElements extends CloverMountableHTMLElement {
    create: (elementType: CloverElementType, elementStyles: CloverElementStyles) => CloverElement;
}

interface AttachEventParams {
    elements: Record<CloverElementType, CloverMountableHTMLElement>;
    events: EventTypes[];
    handler: (event: CloverValidationEvent) => void;
}

declare class Clover {
    constructor(pakms: string);
    readonly apiKey?: string;
    readonly merchantId?: string;
    readonly locale?: string;
    options?: CloverOptions;
    elements: () => CloverElements;
    createToken: () => Promise<CloverIframeTokenizationResponse>;
    initElemMaps: () => never;
}


export default class CloverManager {
    private static instance: CloverManager;
    cloverInstance: Clover;
    private cloverConfig: CloverConfig;
    static validationState: CloverValidationEvent = <Event & {
        CARD_NUMBER?: EventElementState;
        CARD_DATE?: EventElementState;
        CARD_CVV?: EventElementState;
        CARD_POSTAL_CODE?: EventElementState
    }>{};

    private constructor(cloverConfig: CloverConfig) {
        this.cloverConfig = cloverConfig;
        this.verifyCloverSdkIsLoaded();
        this.getPakmsOrFail(cloverConfig.pakms); // Pakms will now be validated directly in the method
        this.cloverInstance = this.createCloverInstance(cloverConfig.pakms);
    }

    public static getInstance(cloverConfig?: CloverConfig): CloverManager {
        if (!this.instance) {
            if (!cloverConfig) {
                console.error("Clover settings must be provided for initialization.");
                throw new Error("Clover settings must be provided for initialization.");
            }
            this.instance = new CloverManager(cloverConfig);
        }
        return this.instance;
    }

    private createCloverInstance(pakms: string): Clover {
        try {
            return new Clover(pakms); // Attempt to create a new Clover instance
        } catch (error) {
            // @ts-expect-error -- Undefined type for now for the instantiation error
            const errorMessage = `WeeConnectPay failed to initialize Clover instance: ${error.message}`;
            console.error(errorMessage);
            throw new Error(errorMessage);
        }
    }

    public getCloverInstance(): Clover {
        return this.cloverInstance;
    }

    private verifyCloverSdkIsLoaded(): void {
        if (typeof Clover === "undefined") {
            throw new Error("Clover SDK is not loaded.");
        }
    }

    private getPakmsOrFail(pakms: string): string {
        if (!pakms || pakms.trim() === '') {
            const errorMessage = "WeeConnectPay Gateway for WooCommerce Blocks cannot load the Clover iframes. Reason: Missing Clover public merchant (pakms) key, is the merchant authenticated?";
            console.error(errorMessage);
            throw new Error(errorMessage);
        }
        return pakms;
    }

    private static getWrapperId(type: CloverElementType): string | undefined {
        switch (type) {
            case CloverElementType.NUMBER:
                return WrapperElementId.NUMBER;
            case CloverElementType.DATE:
                return WrapperElementId.DATE;
            case CloverElementType.CVV:
                return WrapperElementId.CVV;
            case CloverElementType.POSTAL_CODE:
                return WrapperElementId.ZIP;
            case CloverElementType.PAYMENT_REQUEST_BUTTON:
                return WrapperElementId.PAYMENT_REQUEST_BUTTON;
            default:
                return undefined; // No wrapper ID defined for the type
        }
    }

    /**
     * Get the corresponding error display element ID for a given Clover element type.
     */
    private static getErrorDisplayId(type: CloverElementType): string | undefined {
        switch (type) {
            case CloverElementType.NUMBER:
                return ErrorDisplayElementId.NUMBER;
            case CloverElementType.DATE:
                return ErrorDisplayElementId.DATE;
            case CloverElementType.CVV:
                return ErrorDisplayElementId.CVV;
            case CloverElementType.POSTAL_CODE:
                return ErrorDisplayElementId.ZIP;
            case CloverElementType.PAYMENT_REQUEST_BUTTON:
                return ErrorDisplayElementId.PAYMENT_REQUEST_BUTTON;
            default:
                return undefined; // No error display ID defined for the type
        }
    }

    private getDefaultStyles(): CloverElementStyles {
        const cvvPlaceholder = this.getLocalizedCvvPlaceholderStyles(this.cloverConfig.websiteLocale)
        // Define default styles that apply to all elements, if any
        return {
            'input': {
                padding: '0px',
                margin: '0px',
                height: '3.4em',
                width: '100%',
                border: '1px #C8C8C8 solid',
                borderRadius: '3px',
                textAlign: 'center',
            },
            '::-webkit-input-placeholder': { /* Chrome/Opera/Safari */
                textAlign: 'center',
            },
            '::-moz-placeholder': { /* Firefox 19+ */
                textAlign: 'center',
            },
            ':-ms-input-placeholder': { /* IE 10+ */
                textAlign: 'center',
            },
            ':-moz-placeholder': { /* Firefox 18- */
                textAlign: 'center',
            },
            'card-cvv input::-webkit-input-placeholder': cvvPlaceholder, /* Chrome/Opera/Safari */
            'card-cvv input::-moz-placeholder': cvvPlaceholder, /* Firefox 19+ */
            'card-cvv input:-ms-input-placeholder': cvvPlaceholder, /* IE 10+ */
            'card-cvv input:-moz-placeholder': cvvPlaceholder, /* Firefox 18- */
        };
    }

    public createElements(elementsToCreate: [CloverElementType, Partial<CloverElementStyles>][]): Record<CloverElementType, CloverElement> {
        const elements: CloverElements = this.cloverInstance.elements(); // Adjusted to match the actual way of obtaining elements from Clover SDK
        const createdElements = {} as Record<CloverElementType, CloverElement>;
        elementsToCreate.forEach(([type, styles]) => {
            const defaultStyles: object = this.getDefaultStyles();
            const mergedStyles = { ...defaultStyles, ...styles };
            try {
                createdElements[type] = elements.create(type, mergedStyles);
            } catch (e) {
                console.error(`Error creating element for type ${type}:`, e);
            }
        });
        return createdElements;
    }

    public mountElements(createdElements: Record<CloverElementType, CloverElement>): void {
        // Corrected to ensure enum types are correctly handled
        Object.entries(createdElements).forEach(([typeString, element]) => {
            const type = typeString as CloverElementType;  // Assert type to CloverElementType
            const wrapperId = CloverManager.getWrapperId(type);
            if (wrapperId && element.mount) {
                element.mount('#' + wrapperId);
            }
        });
    }

    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    public static validationEventHandler = (event: CloverValidationEvent, calledBy?: 'onPaymentSetup') => {


        if (!(calledBy === 'onPaymentSetup')) {
            // Overwrite the validationState with the new event data if it's not a manual trigger.
            // Manual triggers can contain constructed data to force errors to be displayed when data is missing.
            CloverManager.validationState = {
                ...event,
            };
        }
        // console.log('validationEventHandler fired: ',{calledBy: calledBy, event: event,validationState: CloverManager.validationState});

        // Process each card field type
        // Using a type assertion on Object.entries to ensure type correctness
        (Object.entries(event) as [keyof typeof CloverElementType, EventElementState][]).forEach(
            ([key, eventElementState]) => {
                const type = key as CloverElementType; // Ensuring type safety by asserting as CloverElementType
                const wrapperElementId = CloverManager.getWrapperId(type);
                const errorDisplayElementId = CloverManager.getErrorDisplayId(type);

                const wrapperElement = wrapperElementId ? document.getElementById(wrapperElementId) : null;
                const errorDisplayElement = errorDisplayElementId ? document.getElementById(errorDisplayElementId) : null;

                if (wrapperElement && errorDisplayElement) {
                    if (eventElementState.error && eventElementState.touched) {
                        CloverManager.addError(wrapperElement, errorDisplayElement, eventElementState.error);
                    } else if (eventElementState.touched) {
                        CloverManager.removeError(wrapperElement, errorDisplayElement);
                    } else {
                        /**
                         *   Technically this is only when an element hasn't been touched yet and has no error,
                         *    CloverValidationEvent still sends us the update
                         *    but the user hasn't touched it yet, so we should do nothing.
                         */
                    }
                } else {
                    console.error(`WeeConnectPay failed to handle the event for the Clover Iframe element type: ${type}. The wrapperElement or errorDisplayElement were not found. `, {
                        wrapperElement: {
                            id: wrapperElementId,
                            element: wrapperElement
                        }, errorDisplayElement: {
                            id: errorDisplayElementId,
                            element: errorDisplayElement
                        }
                    });
                }
            }
        );
    }

    /**
     * This function enables us to take the Clover event and treat it as if we should have touched all the fields,
     * therefore running the checks for it and returning the default error values if they are missing. It also allows
     * us to cover the scenario where none of the iframe fields were touched and the event state is still an empty object
     */
    public static createFinalValidationState(): CloverValidationEvent {
        const defaultErrors: CloverValidationEventState = {
            CARD_NUMBER: {
                error: "Card number is required",
                touched: true
            },
            CARD_DATE: {
                error: "Card expiry is required",
                touched: true
            },
            CARD_CVV: {
                error: "Card CVV is required",
                touched: true
            },
            CARD_POSTAL_CODE: {
                error: "Card postal code is required",
                touched: true
            }
        } as Partial<CloverValidationEvent>;

        const currentValidationState = CloverManager.getValidationState() as CloverValidationEventState;
        const updatedValidationState: CloverValidationEventState = {};

        Object.keys(defaultErrors).forEach(key => {
            const customKey = key as keyof CloverValidationEventState;
            const fieldState = currentValidationState[customKey];

            if (!fieldState) {
                // console.log(`Updating ${customKey}: default value applied`);
                updatedValidationState[customKey] = defaultErrors[customKey]!;
            } else if (fieldState.touched && !fieldState.error) {
                // Leave the field unchanged if it's touched and has no error
                // console.log(`Leaving ${customKey} unchanged:`, fieldState);
                updatedValidationState[customKey] = fieldState;
            } else if (fieldState.touched && fieldState.error) {
                // Leave the field unchanged if it's touched and has an error
                // console.log(`Leaving ${customKey} unchanged with error:`, fieldState);
                updatedValidationState[customKey] = fieldState;
            } else {
                // Apply the default value if it's not touched
                // console.log(`Updating ${customKey}: default value applied`);
                updatedValidationState[customKey] = defaultErrors[customKey]!;
            }
        });

        return updatedValidationState as CloverValidationEvent;
    }

    /**
     * Attaches specified event listeners to given elements.
     */
    public attachEventListeners({elements, events, handler}: AttachEventParams): void {
        Object.keys(elements).forEach((elementType) => {
            const element = elements[elementType as CloverElementType];
            events.forEach((event) => {
                element.addEventListener(event, handler as EventListener);
            });
        });
    }

    private static addError = (wrapperElement: HTMLElement, errorDisplayElement: HTMLElement, errorText: string): void => {
        errorDisplayElement.textContent = errorText;
        errorDisplayElement.classList.add('error');
        wrapperElement.classList.remove('success');
        wrapperElement.classList.add('error');
    }

    private static removeError = (wrapperElement: HTMLElement, errorDisplayElement: HTMLElement): void => {
        errorDisplayElement.textContent = null;
        errorDisplayElement.classList.remove('error');
        wrapperElement.classList.remove('error');
        wrapperElement.classList.add('success');
    }

    /**
     * Clover normally has "ZIP" as a placeholder, unless Clover has been initialized with the fr-CA locale,
     * in which case the placeholder is "POSTAL CODE", which wraps around and is off vertical center if left untouched.
     */
    private getLocalizedCvvPlaceholderStyles = (locale: string) =>{
        let cvvInputPlaceHolder = {}
        if (locale === 'fr-CA') {

            // fr-CA wraps the line, so we need to push it higher if it takes more than 105px
            const cvvEl = document.getElementById(WrapperElementId.CVV);
            if (cvvEl) {
                if (cvvEl.offsetWidth >= 106 || cvvEl.offsetWidth === 0) {
                    cvvInputPlaceHolder = {
                        whiteSpace: 'pre-line',
                        position: 'relative',
                    }
                } else {
                    cvvInputPlaceHolder = {
                        whiteSpace: 'pre-line',
                        position: 'relative',
                        top: '-7px',
                    }
                }
            } else {
                console.warn('WeeConnectPay could not detect the CVV element during Styles creation. CVV Element placeholder may look off-center depending on your locale.');
            }

        } else {
            // We don't need to wrap up the line higher since it sits on one line
            cvvInputPlaceHolder = {}
        }

        return cvvInputPlaceHolder;
    }

    public static getValidationState(): CloverValidationEvent {
        return CloverManager.validationState;
    }

    /**
     * Helps us see if the final validationState before tokenizing the card has all the fields in good order.
     * This means all 4 fields are touched and contain no errors.
     * @param validationState
     */
    public static isFinalEventValid(validationState: CloverValidationEvent): boolean {
        const requiredKeys: (keyof CloverValidationEventState)[] = [
            'CARD_NUMBER',
            'CARD_DATE',
            'CARD_CVV',
            'CARD_POSTAL_CODE'
        ];

        return requiredKeys.every(key => {
            const fieldState = validationState[key];
            return fieldState && fieldState.touched && !fieldState.error;
        });
    }


// Type guard to check if a value is of type EventElementState
    // @ts-expect-error -- Since this is to try to figure out if value is of a certain format, we do not care about the type. The function will return boolean regardless
    public static isEventElementState(value: never): value is EventElementState {
        return value && typeof value === 'object' && 'touched' in value;
    }

    public static getErrorMessageOrNull(event: CloverValidationEvent): string | null {
        const relevantFields: (keyof CloverValidationEvent)[] = [
            'CARD_CVV',
            'CARD_DATE',
            'CARD_NUMBER',
            'CARD_POSTAL_CODE'
        ];
        let hasErrors = false;
        let errorMessage = '';

        if (Object.keys(event).length === 0) {
            // If validationState is empty, treat all fields as not touched
            // Technically this should not run at all, if we use CloverManager.createFinalValidationState, but better safe than sorry
            relevantFields.forEach(() => {
                hasErrors = true;
                errorMessage += `These fields are required.<br>`;
            });
        } else {
            relevantFields.forEach(field => {
                const eventElementState = event[field];
                // @ts-expect-error -- Since this is to try to figure out if value is of a certain format, we do not care about the type. The function will return boolean regardless. If you have a better solution. Let me know!
                if (CloverManager.isEventElementState(eventElementState)) {
                    if (eventElementState.touched) {
                        if (eventElementState.error) {
                            hasErrors = true;
                            errorMessage += `${eventElementState.error}<br>`;
                        }
                    } else {
                        hasErrors = true;
                        errorMessage += `${CloverElementType[field as keyof typeof CloverElementType]}: This field is required.<br>`;
                    }
                } else {
                    // If the field is missing in the event, it hasn't been touched
                    hasErrors = true;
                    errorMessage += `${CloverElementType[field as keyof typeof CloverElementType]}: This field is required.<br>`;
                }
            });
        }

        return hasErrors ? errorMessage : null;
    }
}
