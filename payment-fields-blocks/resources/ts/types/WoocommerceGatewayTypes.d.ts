interface CloverConfig {
    pakms: string;
    websiteLocale: string;
}

interface GoogleRecaptchaConfig {
    isEnabled: string; // Thanks WordPress...
    siteKey: string;
}

interface Settings {
    clover: CloverConfig;
    woocommerce: {
        gateway: {
            supports: string[];
            title: string;
        };
    };
    wordpress: {
        locale: string;
    };
    googleRecaptcha: GoogleRecaptchaConfig;
}
