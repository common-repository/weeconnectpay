/* eslint-disable no-unused-vars */
class GoogleRecaptchaTokenizationError extends Error {
    constructor(message: string) {
        super(message);
        this.name = "GoogleRecaptchaTokenizationError";
    }
}

export default class GoogleRecaptchaManager {
    private googleRecaptchaConfig: GoogleRecaptchaConfig;
    private static instance: GoogleRecaptchaManager;

    private constructor(googleRecaptchaConfig: GoogleRecaptchaConfig) {
        this.googleRecaptchaConfig = googleRecaptchaConfig;
        this.verifyGoogleRecaptchaSdkIsLoaded();
        if (!GoogleRecaptchaManager.isGoogleRecaptchaEnabled(googleRecaptchaConfig.isEnabled)){
            console.error("Google Recaptcha is disabled in the settings but is still being constructed.");
            throw new Error("Google Recaptcha is disabled in the settings but is still being constructed.");
        }
        this.getSiteKeyOrFail(googleRecaptchaConfig.siteKey);
    }

    public static getInstance(googleRecaptchaConfig?: GoogleRecaptchaConfig): GoogleRecaptchaManager {
        if (!this.instance) {
            if (!googleRecaptchaConfig) {
                console.error("Clover settings must be provided for initialization.");
                throw new Error("Clover settings must be provided for initialization.");
            }
            this.instance = new GoogleRecaptchaManager(googleRecaptchaConfig);
        }
        return this.instance;
    }

    private verifyGoogleRecaptchaSdkIsLoaded(): void {
        if (typeof grecaptcha === "undefined") {
            console.error("Google Recaptcha SDK is not loaded.");
            throw new Error("Google Recaptcha SDK is not loaded.");
        }
    }

    /**
     * @param isEnabled Since this comes from WordPress settings, it's either a "1" or a "0" or "" is not defined... Most likely
     * @private
     */
    public static isGoogleRecaptchaEnabled(isEnabled: string): boolean {
        return isEnabled === "1";
    }

    private getSiteKeyOrFail(siteKey: string): string {
        if (!siteKey || siteKey.trim() === '') {
            const errorMessage = "WeeConnectPay Gateway for WooCommerce Blocks has an error while using Google Recaptcha. Reason: Missing Google Recaptcha \"site key\". Have you set it up in the plugin settings?";
            console.error(errorMessage);
            throw new Error(errorMessage);
        }
        return siteKey;
    }

    private createToken():  Promise<string> {
        return new Promise((resolve, reject) => {
            grecaptcha.ready(() => {
                try {
                    grecaptcha.execute(this.googleRecaptchaConfig.siteKey, { action: 'submit' }).then(
                        token => {
                            resolve(token);
                        },
                        error => {
                            // Set the exception text in the token so that we can check for it in the payment processing part
                            reject(error.toString());
                        }
                    );
                } catch (error) {
                    const exception: GoogleRecaptchaTokenizationError = error as GoogleRecaptchaTokenizationError

                    // Set the exception text in the token so that we can check for it in the payment processing part
                    reject(exception.toString());
                }
            });
        });
    }

    public async getTokenOrExceptionJson(): Promise<string> {
            // Attempt to create the token
        return await this.createToken();
    }

    public static getStringifiedErrorForPaymentMethodData (error: unknown): string {
        const exception: GoogleRecaptchaTokenizationError = error as GoogleRecaptchaTokenizationError
        const exceptionJson: object = {
            exception: exception.toString()
        }
        // Set the exception text in the token so that we can check for it in the payment processing part in the back-end
        return JSON.stringify(exceptionJson);
    }
}
