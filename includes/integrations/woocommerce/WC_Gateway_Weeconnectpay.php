<?php
// phpcs:disable WordPress.PHP.DevelopmentFunctions
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName
// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode
// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase

use WeeConnectPay\Api\Requests\CreateCloverCustomerRequest;
use WeeConnectPay\Api\Requests\CreateCloverOrderChargeRequest;
use WeeConnectPay\Api\Requests\VerifyAuthenticationRequest;
use WeeConnectPay\CloverMerchant;
use WeeConnectPay\CloverReceiptsHelper;
use WeeConnectPay\Dependencies\GuzzleHttp\Client;
use WeeConnectPay\Dependencies\GuzzleHttp\Exception\ClientException;
use WeeConnectPay\Dependencies\GuzzleHttp\Exception\RequestException;
use WeeConnectPay\Exceptions\Codes\ExceptionCode;
use WeeConnectPay\Exceptions\CustomerCreationException;
use WeeConnectPay\Integrations\AdminPanel;
use WeeConnectPay\Integrations\DependencyChecker;
use WeeConnectPay\Integrations\GoogleRecaptcha;
use WeeConnectPay\Integrations\PaymentFields;
use WeeConnectPay\Integrations\RecaptchaVerifier;
use WeeConnectPay\WordPress\Plugin\includes\WeeConnectPayUtilities;
use WeeConnectPay\WordPress\Plugin\includes\WeeConnectPayAPI;
use WeeConnectPay\WordPress\Plugin\includes\WeeConnectPayHelper;
use WeeConnectPay\Exceptions\WeeConnectPayException;
use WeeConnectPay\Integrations\Authentication;
use WeeConnectPay\Integrations\IntegrationSettings;
use WeeConnectPay\Settings;
use WeeConnectPay\StandardizedResponse;

if ( ! class_exists( WC_Payment_Gateway::class ) ) {
	return null;
}

/**
 * Handles the WeeConnectPay/Clover/WooCommerce payment gateway
 */
class WC_Gateway_Weeconnectpay extends WC_Payment_Gateway {
	/**
	 * Instance of the WeeConnectPay API
	 * @var WeeConnectPayAPI $api
	 */
	private $api;
	/**
	 * @var string
	 */
	private $url_api;
	/**
	 * @var string
	 */
	private $integration_id;
	/**
	 * @var int
	 */
	private $authVerifyHttpCode;

	/**
	 * @return WeeConnectPayAPI
	 */
	public function getApi(): WeeConnectPayAPI {
		return $this->api;
	}

	/**
	 * @param WeeConnectPayAPI $api
	 *
	 * @return WC_Gateway_Weeconnectpay
	 */
	public function setApi( WeeConnectPayAPI $api ): WC_Gateway_Weeconnectpay {
		$this->api = $api;

		return $this;
	}

//	/**
//	 * JSON Web Token used to authorize communication with the WeeConnectPay API
//	 *
//	 * @var string
//	 */
//	private $weeconnectpay_access_token;

	private $is_debug = true;

	private $iframe_settings;

//	private $clover_api_key;

//	private $ownership_key;

	/**
	 * @var IntegrationSettings $integrationSettings
	 */
	private $integrationSettings;

	/**
	 * @return IntegrationSettings
	 */
	public function getIntegrationSettings(): IntegrationSettings {
		return $this->integrationSettings;
	}

	/**
	 * @param IntegrationSettings $integrationSettings
	 *
	 * @return WC_Gateway_Weeconnectpay
	 */
	public function setIntegrationSettings( IntegrationSettings $integrationSettings ): WC_Gateway_Weeconnectpay {
		$this->integrationSettings = $integrationSettings;

		return $this;
	}

	/**
	 * @updated 3.3.0
	 * @throws \WeeConnectPay\Exceptions\SettingsInitializationException
	 */
	public function __construct() {

//		// In checkout page /?wc-ajax=update_order_review gets triggered and reloads - We want the gateway to load after ajax
//		if ( is_checkout() && ! is_wc_endpoint_url( 'order-pay' ) && ! is_ajax() ) {
//			return null;
//		}

//		// We don't do anything with the cart or order-received endpoints
//		if ( is_cart() || is_wc_endpoint_url( 'order-received' ) ) {
//			return null;
//		}

		$this->id                 = 'weeconnectpay';
		$this->method_title       = __( 'Clover Integration', 'weeconnectpay' );
		$this->method_description = __(
			'Simplify online payments by adding the Clover payment option to your shopping cart. Then you will see your payments in real time on your Clover web portal.'
			, 'weeconnectpay' );
		$this->has_fields         = true;
		$this->supports           = array(
			'refunds',
			'products'
        );


		$this->init_form_fields();
		$this->init_settings();


		try {
			$integration_settings = new IntegrationSettings();
			$integration_settings->getIntegrationUuid();
			// Save the integration settings as an attribute on this class
			$this->setIntegrationSettings( $integration_settings );
		} catch ( WeeConnectPayException $e ) {
			error_log( 'WeeConnectPay: Exception in gateway constructor. Message: ' . $e->getMessage() );
			$integration_settings = IntegrationSettings::reinitialize();
			$this->setIntegrationSettings( $integration_settings );
		}

		try {
			// Save the API as an attribute on this class
			$this->setApi( new WeeConnectPayAPI() );
		} catch ( WeeConnectPayException $e ) {
			error_log( 'WeeConnectPay: Exception in gateway constructor. Message: ' . $e->getMessage() );

			return StandardizedResponse::emitError( $e->toObject() );
		}


		$this->url_api        = $this->api->url_api;
		$this->integration_id = $this->integrationSettings->getIntegrationUuid();


		$this->title       = $this->get_option( 'title' );
		$this->enabled     = $this->get_option( 'enabled' );
		$this->description = $this->get_option( 'description' );


		//Runs when we update the gateway options through woocommerce -- Used for options saved using our DB structure and not WooCommerce
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
			$this,
			'update_gateway_options'
		) );

		//Runs when we update the gateway options through woocommerce
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
			$this,
			'process_admin_options'
		) );

		// API Callback
		add_action( 'woocommerce_api_callback_' . strtolower( get_class( $this ) ), array(
			$this,
			'weeconnectpay_callback_handler'
		) );

		add_action( 'woocommerce_after_checkout_validation', array( $this, 'maybe_add_wc_notice' ), 10, 2 );

		//add_action('woocommerce_checkout_after_order_review', array( $this, 'maybe_add_wc_notice' ) );
		//add_action('woocommerce_checkout_before_order_review', array( $this, 'maybe_add_wc_notice' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'maybe_add_wc_notice' ), 10, 2 );

		// Runs when trying to login with Clover.
		add_action( 'woocommerce_sections_checkout',  array( $this, 'display_clover_login_notice' ) );
	}

	public function update_gateway_options() {
		error_log( 'update_gateway_options _POST ' . json_encode( $_POST) );
		// Get the value of the "Post Tokenization Verification" setting from $_POST
		$postTokenizationVerification = $_POST['woocommerce_weeconnectpay_post_tokenization_verification'] ?? '0';
		$googleRecaptchaEnabled = $_POST['woocommerce_weeconnectpay_google_recaptcha_enabled'] ?? '0';
		$googleRecaptchaSiteKey = $_POST['woocommerce_weeconnectpay_google_recaptcha_site_key'] ?? '';
		$googleRecaptchaSecretKey = $_POST['woocommerce_weeconnectpay_google_recaptcha_secret_key'] ?? '';
		$googleRecaptchaMinHumanScoreThreshold = $_POST['woocommerce_weeconnectpay_min_human_score_threshold'] ?? 0.5;
		$honeypotFieldEnabled = $_POST['woocommerce_weeconnectpay_honeypot_field_enabled'] ?? '0';


        // Post Tokenization Verification
		try {
			( new WeeConnectPay\Integrations\IntegrationSettings )->setPostTokenizationVerification( $postTokenizationVerification );
		} catch ( WeeConnectPayException $e ) {
			error_log( 'Failed to saved WooCommerce option for post-tokenization validation: ' . $e->getMessage() );
		}

        // Google reCAPTCHA v3 enabled?
		try {
			( new WeeConnectPay\Integrations\IntegrationSettings )->setGoogleRecaptcha( $googleRecaptchaEnabled );
		} catch ( WeeConnectPayException $e ) {
			error_log( 'Failed to saved WooCommerce option to enable or disable Google reCAPTCHA: ' . $e->getMessage() );
		}

		// Google reCAPTCHA v3 Site Key
		try {
			( new WeeConnectPay\Integrations\IntegrationSettings )->setGoogleRecaptchaSiteKey( $googleRecaptchaSiteKey );
		} catch ( WeeConnectPayException $e ) {
			error_log( 'Failed to saved WooCommerce option for Google reCAPTCHA Site Key: ' . $e->getMessage() );
		}

		// Google reCAPTCHA v3 Secret Key
		try {
			( new WeeConnectPay\Integrations\IntegrationSettings )->setGoogleRecaptchaSecretKey( $googleRecaptchaSecretKey );
		} catch ( WeeConnectPayException $e ) {
			error_log( 'Failed to saved WooCommerce option for Google reCAPTCHA Secret Key: ' . $e->getMessage() );
		}

		// Google reCAPTCHA v3 Minimum Score Human Threshold
		try {
			( new WeeConnectPay\Integrations\IntegrationSettings )->setGoogleRecaptchaMinimumHumanScoreThreshold( $googleRecaptchaMinHumanScoreThreshold );
		} catch ( WeeConnectPayException $e ) {
			error_log( 'Failed to saved WooCommerce option for Google reCAPTCHA Minimum Human Score Threshold: ' . $e->getMessage() );
		}

        //Honeypot Field
		try {
			( new WeeConnectPay\Integrations\IntegrationSettings )->setHoneypotField( $honeypotFieldEnabled );
		} catch ( WeeConnectPayException $e ) {
			error_log( 'Failed to saved WooCommerce option to enable or disable the Honeypot Field: ' . $e->getMessage() );
		}
	}

	/**
	 * Override the parent admin_options method with our app.
	 *
	 * @since 1.4.0
	 * @updated 3.2.1
	 */
	public function admin_options() {

		// This might be called before the gateway is ready -- Make sure everything is set for the gateway first
		if ( ! $this->integrationSettings ) {
			error_log( 'Setting the integrationSettings during admin_options hook' );
			$integrationSettings = new IntegrationSettings();
			error_log( 'Finished the integrationSettings during admin_options hook' );

			error_log( 'Getting the integrationUuid during admin_options hook' );
			$integrationSettings->getIntegrationUuid();
			error_log( 'Finished getting the integrationUuid during admin_options hook' );

			error_log( 'Saving the integrationSettings state during admin_options hook' );
			// Save the integration settings as an attribute on this class
			$this->setIntegrationSettings( $integrationSettings );
			error_log( 'Finished saving the integrationSettings state during admin_options hook' );
		}

		if ( ! $this->api ) {
			error_log( 'Setting the API during admin_options hook' );
			$this->setApi( new WeeConnectPayAPI() );
			error_log( 'Finished setting the API during admin_options hook' );
		}

		if ( ! $this->integration_id ) {
			error_log( 'Setting the integration id during admin_options hook' );
			$this->integration_id = $this->integrationSettings->getIntegrationUuid();
			error_log( 'Finished setting the integration id during admin_options hook. ID: ' . json_encode( $this->integration_id ) );
		}

		try {
			if ( ! $this->integrationSettings->isAuthValid() ) {

				//			$integration_settings = IntegrationSettings::reinitialize();
				//			$this->setIntegrationSettings( $integration_settings);
				$this->integration_id = $this->integrationSettings->getIntegrationUuid();


				// Hide the save button from the payment gateway settings form
				global $hide_save_button;
				$hide_save_button = true;

				$redirect_url = $this->url_api . '/login/clover?intent=authorize-redirect&integration_id=' . $this->integration_id;
				$vue_data     = array(
					'redirectUrl' => $this->integrationSettings::redirectUrl(),
					'pluginUrl'   => WEECONNECTPAY_PLUGIN_URL,
					'redirectUrl' => $redirect_url,
				);

                // GitPod support
                if (getenv('GITPOD_WORKSPACE_URL')) {
                    $vue_data['gitpodBackendWorkspaceUrl'] = GITPOD_WCP_BACKEND_WORKSPACE_URL ?? 'GITPOD_URL_NOT_SET';
                }

				$admin_panel = new AdminPanel();
				$admin_panel->init( $vue_data );
			} else {
				parent::admin_options();
			}
		} catch ( RequestException $exception ) {
			error_log( 'WeeConnectPay exception during authentication validation: ' . $exception->getMessage() );
			DependencyChecker::adminNoticeCallback( $exception );

			// Hide the save button from the payment gateway settings form since we are not loading it
			global $hide_save_button;
			$hide_save_button = true;
		}

	}

	function maybe_add_wc_notice( $fields, WP_Error $errors = null ) {
		global $wp;

		// Reset prevent submit
		$_POST['weeconnectpay_prevent_submit'] = false;

		if ( wc_notice_count( 'error' ) !== 0 ) {
			return;
		}

		// Untouched form
		if ( isset( $_POST['weeconnectpay_prevent_submit_empty_cc_form'] ) ) {
			wc_add_notice( __( "Please enter your payment information.", 'weeconnectpay' ), 'error' );
			$_POST['weeconnectpay_prevent_submit'] = true;
		}

		// Card Number
		if ( isset( $_POST['weeconnectpay_prevent_submit_card_number_error_cc_form'] ) ) {
			wc_add_notice( __( "Please enter a valid credit card number.", 'weeconnectpay' ), 'error' );
			$_POST['weeconnectpay_prevent_submit'] = true;
		}
		if ( isset( $_POST['weeconnectpay_prevent_submit_empty_card_number_cc_form'] ) ) {
			wc_add_notice( __( "Please enter a valid credit card number.", 'weeconnectpay' ), 'error' );
			$_POST['weeconnectpay_prevent_submit'] = true;
		}

		// Expiry Date
		if ( isset( $_POST['weeconnectpay_prevent_submit_date_error_cc_form'] ) ) {
			wc_add_notice( __( "Please enter a valid credit card expiry date.", 'weeconnectpay' ), 'error' );
			$_POST['weeconnectpay_prevent_submit'] = true;
		}
		if ( isset( $_POST['weeconnectpay_prevent_submit_empty_date_cc_form'] ) ) {
			wc_add_notice( __( "Please enter a valid credit card expiry date.", 'weeconnectpay' ), 'error' );
			$_POST['weeconnectpay_prevent_submit'] = true;
		}

		// CVV
		if ( isset( $_POST['weeconnectpay_prevent_submit_cvv_error_cc_form'] ) ) {
			wc_add_notice( __( "Please enter a valid credit card CVV number.", 'weeconnectpay' ), 'error' );
			$_POST['weeconnectpay_prevent_submit'] = true;
		}
		if ( isset( $_POST['weeconnectpay_prevent_submit_empty_cvv_cc_form'] ) ) {
			wc_add_notice( __( "Please enter a valid credit card CVV number.", 'weeconnectpay' ), 'error' );
			$_POST['weeconnectpay_prevent_submit'] = true;
		}

		// Postal Code
		if ( isset( $_POST['weeconnectpay_prevent_submit_postal_code_error_cc_form'] ) ) {
			wc_add_notice( __( "Please enter a valid credit card postal code.", 'weeconnectpay' ), 'error' );
			$_POST['weeconnectpay_prevent_submit'] = true;
		}
		if ( isset( $_POST['weeconnectpay_prevent_submit_empty_postal_code_cc_form'] ) ) {
			wc_add_notice( __( "Please enter a valid credit card postal code.", 'weeconnectpay' ), 'error' );
			$_POST['weeconnectpay_prevent_submit'] = true;
		}
	}

	/**
	 * Displays a confirmation or error message while trying to connect to the Clover API.
	 *
	 * @since 3.11.1
	 * @access public
	 *
	 * @return void
	 */
	public function display_clover_login_notice() {
		// Bails out if it's not the right context.
		if ( ! isset( $_GET['section'], $_GET['weeconnectpay_status'] ) || 'weeconnectpay' !== $_GET['section'] ) {
			return;
		}

		// Defines specific status messages based on the value returned by the 'weeconnectpay_status' parameter.
		$status_messages = array(
			'connected' => __( 'The connection with Clover has been successfully established!', 'weeconnectpay' ),
			'error'     => __( 'An error occurred while trying to establish a connection with Clover, please try again in a few minutes.', 'weeconnectpay' ),
		);

		$notice_class   = in_array( $_GET['weeconnectpay_status'], array( 'connected' ) ) ? 'notice-success' : 'notice-error';
		$notice_message = $status_messages[ $_GET['weeconnectpay_status'] ] ?? $status_messages['error']; // Defaults to 'error' if the status message can't be found.
		echo '<div class="notice is-dismissible ' . sanitize_html_class( $notice_class ) . '"><p>' . esc_html( $notice_message ) . '</p></div>';
	}

	/**
	 * Generates the WeeConnectPay settings form fields for WooCommerce to use in the payment gateway settings
	 * @return void
	 * @since 1.0.0
	 * @updated 3.3.0
	 */
	public function init_form_fields() {

        $integrationSettings =  new IntegrationSettings();
		$isPostTokenizationVerificationActive = $integrationSettings->getPostTokenizationVerificationOrDefault();
        $isGoogleRecaptchaActive = $integrationSettings->getGoogleRecaptchaOrDefault();
        $googleRecaptchaSiteKey = $integrationSettings->getGoogleRecaptchaSiteKeyOrDefault();
		$googleRecaptchaSecretKey = $integrationSettings->getGoogleRecaptchaSecretKeyOrDefault();
		$isHoneypotFieldActive = $integrationSettings->getHoneypotFieldOrDefault();

		$this->form_fields = array(
			'enabled'                        => array(
				'title'       => __( 'Enable', 'weeconnectpay' ),
				'label'       => __( 'Enable payment gateway', 'weeconnectpay' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'yes',
			),
			'authorize_button'               => array(
				'title' => __( 'Authorize Plugin', 'weeconnectpay' ),
				'type'  => 'authorize_button',
			),
			'title'                          => array(
				'title'       => __( 'Title', 'weeconnectpay' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'weeconnectpay' ),
				'desc_tip'    => true,
				'default'     => __( 'Payment by Credit Card', 'weeconnectpay' ),
			),
			'post_tokenization_verification' => array(
                'title'       => __( 'Fraud Analysis', 'weeconnectpay'),
				'type'        => 'checkbox',
				'label'       => __( 'Post Tokenization Verification', 'weeconnectpay' ),
				'description' => __( 'Enable post-tokenization verification', 'weeconnectpay' ),
                'desc_tip'    => true,
				'default'     => $isPostTokenizationVerificationActive ? 'yes' : 'no',
			),
			'google_recaptcha_enabled' => array(
				'title'       => __( 'Google reCAPTCHA', 'weeconnectpay' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Google reCAPTCHA', 'weeconnectpay' ),
				'description' => __( 'Enable Google reCAPTCHA v3 for extra security. This new reCAPTCHA is completely hidden from the customer. A score value between 0 (100% automated) and 1 (100% human) will be added in the order notes for each payment attempt.', 'weeconnectpay' ),
				'desc_tip'    => true,
				'default'     => $isGoogleRecaptchaActive ? 'yes' : 'no',
			),
			'google_recaptcha_site_key' => array(
				'type'        => 'text',
				'title'       => __( 'Google reCAPTCHA Site Key', 'weeconnectpay' ),
				'description' => __( 'Don\'t have a site key and private key for this domain? <a href="https://www.google.com/recaptcha/admin/create" target="_blank">Click here</a> to set it up.', 'weeconnectpay' ),
				'default'     => $googleRecaptchaSiteKey,
			),
			'google_recaptcha_secret_key' => array(
				'type'        => 'password',
				'title'       => __( 'Google reCAPTCHA Secret Key', 'weeconnectpay' ),
				'default'     => $googleRecaptchaSecretKey,
			),
			'min_human_score_threshold' => array(
				'title'       => __( 'Google reCAPTCHA Minimum Human Score Threshold', 'weeconnectpay' ),
				'type'        => 'number',
				'description' => __( 'Enhance order security: Set a reCAPTCHA score threshold. The recommended default value is 0.5. Orders with scores below this setting will be considered as non-human order, the status will be set as "failed" in WooCommerce and no resource will be created in your Clover account.', 'weeconnectpay' ),
				'default'     => '0.5', // You can set a default value here.
				'desc_tip'    => true,
				'custom_attributes' => array(
					'step' => '0.1', // This sets the increment step to 0.1
					'min'  => '0',   // Minimum value
					'max'  => '1',   // Maximum value
				),
			),
			'honeypot_field_enabled' => array(
				'title'       => __( 'Honeypot Fields', 'weeconnectpay' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Honeypot Fields', 'weeconnectpay' ),
				'description' => __( 'As an additional bot detection step, hidden fields that are sometimes filled by bots will be added to the form.', 'weeconnectpay' ),
				'desc_tip'    => true,
				'default'     => $isHoneypotFieldActive ? 'yes' : 'no',
			),
		);
	}

	/**
	 * Screen button Field
	 */
	public function generate_authorize_button_html() {
		//$redirect_url = $this->url_api . '/login/clover?intent=authorize-redirect&integration_id=' . $this->integration_id;
		$redirect_url = IntegrationSettings::redirectUrl();
		try {
			$clover_merchant = $this->integrationSettings->getCloverMerchant();
		} catch ( Throwable $exception ) {
			error_log( "Error fetching the current merchant for display in plugin settings. Message: " . $exception->getMessage() );
		}


		?>
        <tr valign="top">
            <td colspan="2" class="">
                <div>
					<?php
					if ( isset( $clover_merchant ) ) {
						if ( $clover_merchant instanceof CloverMerchant ) {
							echo "<b><div>";
							esc_html_e( "Merchant Name: ", "weeconnectpay" );
							echo "</b>";
							esc_html_e( $clover_merchant->getName() );
							echo "</div>";
							echo "<b><div>";
							esc_html_e( "Merchant ID: ", "weeconnectpay" );
							echo "</b>";
							esc_html_e( $clover_merchant->getUuid() );
							echo "</div></br>";
						}
					}
					?>
                </div>
            </td>
            <td colspan="2" class="">
                <a href="<?php echo esc_url( $redirect_url ); ?>" class="button"><?php
					//					if ( $this->authVerifyHttpCode !== 200 ) {
					//						_e( 'Authorize Plugin', 'weeconnectpay' );
					//					} else {
					esc_html_e( 'Log in as another Clover merchant or employee', 'weeconnectpay' );
					//					}
					?></a>
            </td>
        </tr>
		<?php
	}


	public function action_checkout_order_processed( int $order_id, array $posted_data, WC_Order $order ) {
		// Only happens at first checkout -- Not after payment declines the first time
		$this->prepare_order_and_authed_iframe( $order );
	}

	/* @TODO: Checks to prevent loading the gateway if it shouldn't
	 * @BODY: To check: Physical Location, Currency, etc
	 */
	public function payment_fields() {

		global $woocommerce;
		try {
			$script_data = array(
				'pakms'  => $this->integrationSettings->getPublicAccessKey(),
				'locale' => WeeConnectPayUtilities::getLocale(),
				'amount' => $woocommerce->cart->total * 100,
                'siteKey' => $this->integrationSettings->getGoogleRecaptchaSiteKeyOrDefault()
			);

			$payment_fields = new PaymentFields();
			$payment_fields->init( $script_data );
		} catch ( Exception $exception ) {
			error_log( 'Exception caught in payment_fields: ' . $exception->getMessage() );
		}
	}

	/**
	 * Tells WooCommerce whether the gateway should be available IE:( In checkout / order pay, etc ).
	 * @return bool
	 * @since 1.3.7
	 */
	public function is_available(): bool {

		// If WeeConnectPay is not enabled, return.
		if ( 'no' === $this->enabled ) {
			return false;
		}


		if ( ! ( new IntegrationSettings() )->arePaymentProcessingSettingsReady() ) {
			return false;
		}

		// SSL ( Even though plugin no longer loads without it )
		if ( ! is_ssl() ) {
			return false;
		}

		return true;
	}


	/**
	 * @inheritDoc
	 * @updated 3.4.0
	 */
	public function process_payment( $order_id ): array {
//		error_log( 'DEBUG: process_payment Post data: ' . json_encode( $_POST ) );
		$order = wc_get_order( $order_id );

        if($this->integrationSettings->getHoneypotFieldOrDefault()){

	        if ( ! empty( $_POST['hp-feedback-required'] ) ) {
		        $sanitizedHoneypotField = _sanitize_text_fields( $_POST['hp-feedback-required'] );
		        $honeypotOrderNote = __( 'The hidden honeypot field was filled out. This field is hidden an can only be filled out programmatically. The likelihood of this order having been filled out by a human is extremely low. Cancelling order. Field Value: ' ) . esc_html( $sanitizedHoneypotField );
		        $order->add_order_note($honeypotOrderNote);
		        error_log( 'WeeConnectPay detected a potential bot with the honeypot field!' );
		        $order->update_status( 'cancelled' );

		        // success does not mean success here, but we need to navigate the page away for bots to keep giving their data to Google
		        // We redirect to view order url since it's the only one that will actually display that the order has been cancelled for any legitimate customer.
		        // It does require being logged in sometimes though
		        return array(
			        'result'   => 'success',
			        'redirect' => $order->get_view_order_url(),
		        );
	        }
        }


		if ( GoogleRecaptcha::isEnabledAndReady() ) {
			// Do we have a recaptcha-token?
			if ( ! empty( $_POST['recaptcha-token'] ) ) {
				$recaptchaToken = _sanitize_text_fields( $_POST['recaptcha-token'] );
			} else {
				error_log( 'Missing reCAPTCHA token in response' );

				return array(
					'result'   => 'fail',
					'redirect' => '',
				);
			}

			if ( GoogleRecaptcha::tokenContainsErrorJson( $recaptchaToken ) ) {
				$recaptchaTokenError        = GoogleRecaptcha::extractErrorMessageFromToken( $recaptchaToken );
				$recaptchaFrontEndErrorNote = __( '<b>Google reCAPTCHA API.js (front-end/customer-facing) has encountered an error.</b> Google reCAPTCHA checks will be disabled for this transaction. Here is the error message: ' ) . esc_html( $recaptchaTokenError);
				$order->add_order_note( $recaptchaFrontEndErrorNote );
			} else {

				$remoteIp
					               = $order->get_customer_ip_address();
				$recaptchaVerifier = new RecaptchaVerifier();
				$recaptchaResponse = $recaptchaVerifier->verifyToken( $recaptchaToken, $remoteIp );
				if ( $recaptchaResponse['success'] === true ) {
					$challengeTimestamp = $recaptchaResponse['challenge_ts'];
					$hostname           = $recaptchaResponse['hostname'];
					$score              = $recaptchaResponse['score'];
					$action             = $recaptchaResponse['action'];
					if ( isset( $score ) ) {
						$minimumScore             = $this->integrationSettings->getGoogleRecaptchaMinimumHumanScoreThresholdOrDefault();
						$googleRecaptchaText      = __( 'Google reCAPTCHA: ', 'weeconnectpay' );
						$googleRecaptchaScoreText = __( 'Google reCAPTCHA score: ', 'weeconnectpay' );
						$minimumScoreText         = __( 'Minimum human score setting: ', 'weeconnectpay' );
						$isABot                   = false;
						if ( $score >= $minimumScore ) {
							$recaptchaScoreOrderNote = '<b>' . $googleRecaptchaText . '</b>' . __( 'According to your plugin settings for Google reCAPTCHA, the customer who paid for the order is likely a human being.', 'weeconnectpay' ) . '<br>';
						} else {
							$recaptchaScoreOrderNote = '<b>' . $googleRecaptchaText . '</b>' . __( 'According to your plugin settings for Google reCAPTCHA, the customer who paid for the order is <b>NOT</b> likely a human being. The order will be cancelled. If you are sure that this order was legitimate, please decrease the minimum human score threshold in the gateway settings.', 'weeconnectpay' ) . '<br>';
							$isABot                  = true;
						}
						$recaptchaScoreOrderNote .= '<b>' . $googleRecaptchaScoreText . '</b>' . esc_html( $score ) . '<br>';
						$recaptchaScoreOrderNote .= '<b>' . $minimumScoreText . '</b>' . esc_html( $minimumScore );
						$order->add_order_note( $recaptchaScoreOrderNote );

						if ( $isABot ) {
							error_log( 'WeeConnectPay detected a potential bot!' );
							$order->update_status( 'cancelled' );

							// success does not mean success here, but we need to navigate the page away for bots to keep giving their data to Google
							// We redirect to view order url since it's the only one that will actually display that the order has been cancelled for any legitimate customer.
							// It does require being logged in sometimes though
							return array(
								'result'   => 'success',
								'redirect' => $order->get_view_order_url(),
							);
						}
					} else {
						$unknownErrorOrderNote = __( 'The request to Google contains was successful but is missing the score telling us the likelihood of the user being a human being. See the full response: ' . json_encode( $recaptchaResponse ), 'weeconnectpay' );
						$order->add_order_note( $unknownErrorOrderNote );
					}
				} else {
					error_log( 'The response from Google reCAPTCHA contains errors. See the full response: ' . json_encode( $recaptchaResponse ));

					if ( isset( $recaptchaResponse['exception'] ) ) {
						$exceptionOrderNote = __( 'The request to Google reCAPTCHA triggered an exception. See exception message: ' . esc_html( $recaptchaResponse['exception'] ), 'weeconnectpay' );
						$order->add_order_note( $exceptionOrderNote );
					} else if ( isset( $recaptchaResponse['error-codes'] ) ) {
						$errorCodesOrderNote = __( 'The response from Google reCAPTCHA contains errors. See error codes: ' . json_encode( $recaptchaResponse['error-codes'] ), 'weeconnectpay' );
						$order->add_order_note( $errorCodesOrderNote );
					} else {
						$unknownErrorOrderNote = __( 'The response from Google reCAPTCHA contains unexpected errors. See the full response: ' . json_encode( $recaptchaResponse ), 'weeconnectpay' );
						$order->add_order_note( $unknownErrorOrderNote );
					}
				}
			}
		}

		// Do we have a tokenized card?
		if ( ! empty( $_POST['token'] ) ) {
            // WooCommerce Classic Checkout
			$tokenizedCard = _sanitize_text_fields( $_POST['token'] );
		} else {
			error_log( 'Missing tokenized card to process the payment with.' );

			return array(
				'result'   => 'fail',
				'redirect' => '',
			);
		}

        // Do we have a card brand?
		if ( ! empty( $_POST['card-brand'] ) ) {
			$cardBrand = _sanitize_text_fields( $_POST['card-brand'] );
		} else {
			$cardBrand = '';
		}

		$isPostTokenizationVerificationActive = $this->integrationSettings->getPostTokenizationVerificationOrDefault();



		if ( $isPostTokenizationVerificationActive ) {
			// Do we have a postal code?
			if ( ! empty( $_POST['tokenized-zip'] ) ) {
				// WooCommerce Classic Checkout
				$tokenizationPostalCode = WeeConnectPayUtilities::formatPostalCode( sanitize_text_field( $_POST['tokenized-zip'] ) );
			} else {
				$tokenizationPostalCode = "";
			}
		}


		$customerData = $this->customerPayload( $order );

		// Attempt to create a customer for the order
		try {
			$request                 = new CreateCloverCustomerRequest();
			$customerResponse        = $request->POST( $customerData );
			$decodedCustomerResponse = json_decode( $customerResponse->getBody()->getContents(), true );


			// Did we get a standardized response?
			if ( isset( $decodedCustomerResponse['result'] ) && $decodedCustomerResponse['result'] === 'success' ) {
				// Do we have data?
				if ( isset( $decodedCustomerResponse['data'] ) ) {
					// Do we have a customer ID?
					if ( isset( $decodedCustomerResponse['data']['id'] ) ) {
						$customerId = $decodedCustomerResponse['data']['id'];
					} else {
						throw new CustomerCreationException();
					}
				} else {
					throw new CustomerCreationException();
				}
			} else {
				throw new CustomerCreationException();
			}
		} catch ( WeeConnectPayException $exception ) {
			return $this->handleProcessPaymentException( $exception );
		} catch ( ClientException $exception ) {
			error_log( "Failed to create a customer for WooCommerce order #$order_id. " . json_encode( $exception->getMessage() ) );
			$code = $exception->getCode();

			if ( $exception->getResponse() ) {
				$xApiSource = $exception->getResponse()->getHeaderLine( 'X-Api-Source' );

                // Logout logic
				if ( $code === 401 && $xApiSource === 'Clover') {
                    // Clover API Key is no longer valid. The merchant must re-authenticate with the plugin to generate a new one for us to use.
					error_log( 'The Clover API key has been revoked by the associated Clover merchant, Clover employee or by Clover due to a critical change to one of those 2. Logging out and disabling the payment gateway.' );

                    // logout, but remember settings
                    IntegrationSettings::forceLogout(true);
					wc_add_notice( __('ERROR: The merchant\'s Clover API key needs to be refreshed by the merchant to keep taking payments with this gateway. Please advise the merchant to re-authenticate in the integration to re-enable the payment gateway.','weeconnectpay'), 'error' );
					return array(
						'result'   => 'fail',
						'redirect' => '',
					);
				} else if ( $code === 401 ) {
                    // WeeConnectPay API Key is no longer valid. The merchant must re-authenticate with the plugin to generate a new one.
                    error_log( 'WeeConnectPay API Key is invalid/has expired. Logging out and disabling the payment gateway.' );

                    // logout, but remember settings
                    IntegrationSettings::forceLogout(true);
                    wc_add_notice( __('ERROR: The merchant\'s WeeConnectPay API key needs to be refreshed by the merchant to keep taking payments with this gateway. Please advise the merchant to re-authenticate in the integration to re-enable the payment gateway.','weeconnectpay'), 'error' );
                    return array(
                        'result'   => 'fail',
                        'redirect' => '',
                    );
                }

				$response        = $exception->getResponse();
				$decodedContents = json_decode( $response->getBody()->getContents(), true );
				// Check for our API validation being triggered
				if ( isset( $decodedContents['errors'] )
				     && isset( $decodedContents['errors']['emailAddresses.0.emailAddress'] )
				     && isset( $decodedContents['errors']['emailAddresses.0.emailAddress'][0] ) ) {
					// DNS Validation for the email failed on our end
					wc_add_notice( esc_html( $decodedContents['errors']['emailAddresses.0.emailAddress'][0] ), 'error' );

					return array(
						'result'   => 'fail',
						'redirect' => '',
					);
				}
			}

			wc_add_notice( 'An unexpected error occurred while trying to create or retrieve a customer with Clover.', 'error' );

			return array(
				'result'   => 'fail',
				'redirect' => '',
			);
		} catch (RequestException $exception){
			error_log( "RequestException Thrown: Failed to create a customer for WooCommerce order #$order_id. " . json_encode( $exception->getMessage() ) );



			wc_add_notice( 'An unexpected error occurred while trying to create or retrieve a customer with Clover.', 'error' );

			return array(
				'result'   => 'fail',
				'redirect' => '',
			);
		}

		// Does the order already have a Clover order UUID?
		if ( ! $order->meta_exists( 'weeconnectpay_clover_order_uuid' ) ) {
			try {
				$order_response = $this->api->prepare_order_for_payment( $order, $customerId );
			} catch ( WeeConnectPayException $exception ) {
				error_log( "Something failed preparing or processing the order. Exception: " . json_encode( $exception->getMessage() ) );

				return $this->handleProcessPaymentException( $exception );
			}

			if ( isset( $order_response['id'] ) && isset( $order_response['uuid'] ) && isset( $order_response['amount'] ) ) {
				// Has the secure_uuid for the iframe to know what to pay
				$this->iframe_settings['order_payload'] = $order_response;
                $orderReceiptUrl = CloverReceiptsHelper::getEnvReceiptUrl($order_response['uuid'], CloverReceiptsHelper::RECEIPT_TYPES['ORDER']);

				// Metadata to check at order creation to prevent double orders
				$order->add_meta_data( 'weeconnectpay_clover_order_uuid', $order_response['uuid'] );
				$order->save_meta_data();
                $orderCreatedNote = '<b>' . __( 'Clover order created.', 'weeconnectpay' ) . '</b>' . '<br>';
				$orderCreatedNote .= '<b>' . __( 'Order ID: ', 'weeconnectpay' ) . '</b>' . '<a href="' . esc_url($orderReceiptUrl) . '">' . esc_html($order_response['uuid']) . '</a>';
				$order->add_order_note( $orderCreatedNote );
				$cloverOrderUuid = $order_response['uuid'];
			} else {
				error_log( "Something went wrong while creating the Clover order for WooCommerce order #$order_id" );

				return array(
					'result'   => 'fail',
					'redirect' => '',
				);
			}
		} else {
			$cloverOrderUuid = $order->get_meta( 'weeconnectpay_clover_order_uuid' );
		}


		// Do not attempt to create a charge for the order if the order is over 0
		if ( $order->get_total() <= 0 ) {
			$freeOrderNote = __( 'No payment required: Order total is 0 or under.', 'weeconnectpay' );
			$order->add_order_note( $freeOrderNote );
			$order->payment_complete();
		}


		try {
            $ipAddress = $order->get_customer_ip_address();
			$chargeResponse = ( new CreateCloverOrderChargeRequest() )->POST( $cloverOrderUuid, $tokenizedCard, $ipAddress );
			// add step to validate json before anything as it is possible to receive something else (Unlikely, but possible)
			$chargeResponseContent = $chargeResponse->getBody()->getContents();
			$decodedChargeResponse = WeeConnectPayUtilities::jsonValidate( $chargeResponseContent );


			if ( isset( $decodedChargeResponse->data->clover_payment_status ) ) {
				/**
				 * We should want to know the brand regardless of result as it can update
                 * and will failsafe to an empty string if needed
				 */
				$order->update_meta_data( 'weeconnectpay_card_brand', $cardBrand );

				if ( 'paid' === $decodedChargeResponse->data->clover_payment_status ) {

                    $paymentReceiptUrl = CloverReceiptsHelper::getEnvReceiptUrl($decodedChargeResponse->data->clover_payment_id, CloverReceiptsHelper::RECEIPT_TYPES['CHARGE']);

					$order->add_meta_data( 'weeconnectpay_clover_payment_uuid', $decodedChargeResponse->data->clover_payment_id );
					$successOrderNote = '<b>' . __( 'Clover payment successful!', 'weeconnectpay' ) . '</b><br>';
                    $successOrderNote .= '<b>' . __( 'Payment ID: ', 'weeconnectpay' ) . '</b>' . '<a href="' . esc_url($paymentReceiptUrl) . '">' . esc_html($decodedChargeResponse->data->clover_payment_id) . '</a><br>';
					if ( $cardBrand ) {
						$successOrderNote .= '<b>' . __( 'Card Brand', 'weeconnectpay' ) . ': </b>' . esc_html( $cardBrand );
					}
					$order->add_order_note( $successOrderNote );



					if ( $isPostTokenizationVerificationActive ) {
						$shippingPostalCode     = WeeConnectPayUtilities::formatPostalCode( $order->get_shipping_postcode() );
						$billingPostalCode      = WeeConnectPayUtilities::formatPostalCode( $order->get_billing_postcode() );


						if ( $shippingPostalCode && $shippingPostalCode !== $billingPostalCode ) {
							$info_note = sprintf( __( 'ℹ️ Info: Please note that the shipping ZIP/Postal code "%s" and the billing ZIP/Postal code "%s" are different.', 'weeconnectpay' ), $shippingPostalCode, $billingPostalCode );
							$order->add_order_note( $info_note );
						}

						if ( $billingPostalCode !== $tokenizationPostalCode ) {
							$warning_note = sprintf( __( '⚠️ Warning: Please note that the billing ZIP/Postal code "%s" and the payment card ZIP/Postal code "%s" are different. These should be the same.', 'weeconnectpay' ), $billingPostalCode, $tokenizationPostalCode );
							$order->add_order_note( $warning_note );
						}
					}

					$order->payment_complete();

					return array(
						'result'   => 'success',
						'redirect' => $order->get_checkout_order_received_url(),
					);
				} elseif ( 'failed' === $decodedChargeResponse->data->clover_payment_status ) {
					// If we're trying to pay for an already paid order
					if ( isset( $decodedChargeResponse->data->error->code ) && $decodedChargeResponse->data->error->code === 'order_already_paid' ) {

						if ( isset( $decodedChargeResponse->data->error->message ) ) {
                            $alreadyPaidOrderNote = '<b>' . __( 'Clover error message: ', 'weeconnectpay' ) . '</b><br>';
							$alreadyPaidOrderNote .= esc_html( $decodedChargeResponse->data->error->message ) . '<br>';
							$alreadyPaidOrderNote .= __( 'Please check the order in the Clover dashboard for the full payment information.', 'weeconnectpay' );
							$order->add_order_note( $alreadyPaidOrderNote );
						}

						// Actually completed since payment was made already
						$order->payment_complete();

						return array(
							'result'   => 'success',
							'redirect' => $order->get_checkout_order_received_url(),
						);
					}
					error_log( "decodedChargeResponse: " . json_encode( $decodedChargeResponse ) );
					if ( isset( $decodedChargeResponse->data->error->charge ) ) {

                        $chargeErrorNote = '<b>' . __( 'Payment failed.', 'weeconnectpay' ) . '</b>' . '<br>';
						$chargeErrorNote .= '<b>' . __( 'Payment ID: ', 'weeconnectpay' ) . '</b>' . esc_html($decodedChargeResponse->data->error->charge). '<br>';
						if ( $cardBrand ) {
							$chargeErrorNote .= '<b>' . __( 'Card Brand', 'weeconnectpay' ) . ': </b>' . esc_html( $cardBrand ) . '<br>';
						}
                        if ( isset( $decodedChargeResponse->data->error->message ) ) {
                            $chargeErrorNote .= '<b>' . __( 'Clover error message: ', 'weeconnectpay' ) . '</b>' . esc_html($decodedChargeResponse->data->error->message) . '<br>';
                        }
						$order->add_meta_data( 'weeconnectpay_clover_payment_uuid', $decodedChargeResponse->data->error->charge );
						$order->add_order_note( $chargeErrorNote );
					} elseif ( isset( $decodedChargeResponse->data->message ) || isset( $decodedChargeResponse->data->error->message ) ) {
						$errorNote = '<b>' . __( 'Payment failed.', 'weeconnectpay' ) . '</b>' . '<br>';

						if ( isset( $decodedChargeResponse->data->message ) ) {
							$errorNote .= '<b>' . __( 'Clover response message: ', 'weeconnectpay' ) . '</b>' . esc_html( $decodedChargeResponse->data->message ) . '<br>';
						}
						if ( isset( $decodedChargeResponse->data->error->code ) ) {
							$errorNote .= '<b>' . __( 'Clover error code: ', 'weeconnectpay' ) . '</b>' . esc_html( $decodedChargeResponse->data->error->code ) . '<br>';
						}
						if ( isset( $decodedChargeResponse->data->error->message ) ) {
							$errorNote .= '<b>' . __( 'Clover error message: ', 'weeconnectpay' ) . '</b>' . esc_html( $decodedChargeResponse->data->error->message ) . '<br>';
						}

						$order->add_order_note( $errorNote );
					} else {
                        $otherNote = '<b>' . __( 'Payment failed - Unhandled context, see response payload: ', 'weeconnectpay' ) . '</b>' . json_encode( $decodedChargeResponse );
						$order->add_order_note( $otherNote );
					}
					$order->update_status( 'failed' );

					// Normal payment declines
					return array(
						'result'   => 'success',
						'redirect' => $order->get_checkout_order_received_url(),
					);
				} else {
					error_log( 'Payment processing failed due to a malformed charge response having an invalid clover_payment_status value: ' . $chargeResponseContent );

					return array(
						'result'   => 'fail',
						'redirect' => '',
					);
				}
			} else {
				error_log( 'Payment processing failed due to a malformed charge response missing the clover_payment_status key: ' . $chargeResponseContent );

				return array(
					'result'   => 'fail',
					'redirect' => '',
				);
			}

		} catch ( WeeConnectPayException $exception ) {
			return $this->handleProcessPaymentException( $exception );
		}
	}


	/**
	 * iFrame payment callback handler. Called from the WeeConnectPay API server after a user attempts to pay for their order through the iFrame.
	 *
	 * @updated 3.0.0
	 * @deprecated since 3.0.0
	 */
	public function weeconnectpay_callback_handler() {
		die();
	}

	/**
	 * @param $order_id
	 * @param $amount
	 * @param $reason
	 *
	 * @updated 3.7.0
	 *
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = null ) {

		/** @TODO: Add wpdb prefix on order creation call */
		// Needed for the DB prefix for multi site
		global $wpdb;
		$order = new WC_Order( $order_id );

		$tax_included            = $order->get_meta( 'weeconnectpay_tax_included' );
		$merged_qty              = $order->get_meta( 'weeconnectpay_merged_qty' );
		$shipping_as_line_item   = null;
		$shipping_line_item_name = null;
		$shipping_item           = array();

		// Get the WC_Order Object instance (from the order ID)
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return new WP_Error( 'wc-order', __( 'Provided ID is not a WC Order', 'weeconnectpay' ) );
		}

		// Get the Order refunds (array of refunds)
		$order_refunds = $order->get_refunds();

		// Only get the last refund order created since we're only going to process the one we just created
		if ( ! isset( $order_refunds[0] ) ) {
			return new WP_Error( 'wc-order', __( 'No WC Order Refund found', 'weeconnectpay' ) );
		}
		$latest_refund = $order_refunds[0];

		// Make sure we're not trying to refund an amount that is 0 or higher
		if ( ! $amount || $amount <= 0 ) {
			return new WP_Error( 'wc-order', __( 'Refund amount must be higher than 0.', 'weeconnectpay' ) );
		}

		// Make sure it's an order refund object
		if ( ! is_a( $latest_refund, 'WC_Order_Refund' ) ) {
			return new WP_Error( 'wc-order', __( 'Last created refund is not a WC Order Refund', 'weeconnectpay' ) );
		}

		// Make sure it's not already been refunded HERE ( Payment processor checks need to be done on our backend for other refund means )
		if ( 'refunded' === $latest_refund->get_status() ) {
			return new WP_Error( 'wc-order', __( 'Order has been already refunded', 'weeconnectpay' ) );
		}

		$line_items = array();
		// Potential polymorphic calls during iteration -- Better try/catch as Woocommerce "conveniently" marks the refund as complete if there's an unhandled exception.
		try {
			// Get all the line items to refund

			$undocumentedChangePrefixText = __("Due to an undocumented breaking change in the Clover API, we have temporarily disabled partial refunds.\n", 'weeconnectpay');
            $orderWillNotBeRefundedText = __('This request to refund will not be processed. Should you want to do a partial refund, you can do so through your Clover web dashboard.');
            foreach ( $latest_refund->get_items() as $item_id => $item ) {

//                error_log('Item id => item: '. json_encode( [ [ 'item_id' => $item_id ], [ 'item' => $item ] ] ) );
				// Original order line item
				$refunded_item_id    = $item->get_meta( '_refunded_item_id' );
				$refunded_item       = $order->get_item( $refunded_item_id );

				// Log details for debugging
//				error_log( "Refund check - Order item ID: $refunded_item_id,
//				 Refunded Quantity: " . abs( $item->get_quantity() ) . ", Original Quantity: " . $refunded_item->get_quantity() .
//				           ", Refunded Total: " . abs( $item->get_total() ) . ", Original Total: " . $refunded_item->get_total() .
//				           ", Refunded Taxes: " . abs( $item->get_total_tax() ) . ", Original Taxes: " . $refunded_item->get_total_tax()
//				);

				// Check if the absolute value of refunded quantity, total, and tax match
				if (abs($item->get_quantity()) != $refunded_item->get_quantity()) {
                    // Quantity must match total quantity -- This is no longer going to be relevant with Atomic Order as we will be able to split units on Clover's end and separate taxes
					$refundErrorReasonSprintfFormat = __('To refund this line item (%s), the quantity to refund (currently %s) must be the total line item quantity (%s)');
					$refundFailureReason = sprintf(
						$refundErrorReasonSprintfFormat,
						$refunded_item->get_name(),
						abs($item->get_quantity()),
						$refunded_item->get_quantity()
					);

                    error_log("Refund error - Partial refunds not allowed due to mismatched line item quantity. Item ID: $refunded_item_id");
					return new WP_Error('wc-order', $undocumentedChangePrefixText . $refundFailureReason . "\n\n" . $orderWillNotBeRefundedText);
				} elseif ( WeeConnectPayHelper::safe_amount_to_cents_int( abs( $item->get_total() ) ) != WeeConnectPayHelper::safe_amount_to_cents_int( $refunded_item->get_total() ) ) {
                    // Subtotal amount must match the refund subtotal amount
                    $refundErrorReasonSprintfFormat = __('To refund this line item (%s), the amount before tax to refund (currently $%s) must be the line item total amount before tax ($%s)');
					$refundFailureReason = sprintf(
						$refundErrorReasonSprintfFormat,
						$refunded_item->get_name(),
						abs( $item->get_total() ),
						$refunded_item->get_total()
					);

					error_log("Refund error - Partial refunds not allowed due to mismatched line item total. Item ID: $refunded_item_id");
					return new WP_Error('wc-order', $undocumentedChangePrefixText . $refundFailureReason . "\n\n" . $orderWillNotBeRefundedText);
				} elseif (WeeConnectPayHelper::safe_amount_to_cents_int(abs($item->get_total_tax())) != WeeConnectPayHelper::safe_amount_to_cents_int($refunded_item->get_total_tax())) {
                    // Total Tax amount must match refund tax amount
					$refundErrorReasonSprintfFormat = __('To refund this line item (%s), the tax to refund (currently $%s) must be the line item total tax ($%s)');
                    $refundFailureReason = sprintf(
						$refundErrorReasonSprintfFormat,
						$refunded_item->get_name(),
	                    abs($item->get_total_tax()),
	                    $refunded_item->get_total_tax()
					);

					error_log("Refund error - Partial refunds not allowed due to mismatched line item tax. Item ID: $refunded_item_id");
					return new WP_Error('wc-order', $undocumentedChangePrefixText . $refundFailureReason . "\n\n" . $orderWillNotBeRefundedText);
                }





				// Order Refund line item
				$line_items[] = array(
					'refunded_quantity'    => $item->get_quantity(),
					'refunded_line_total'  => WeeConnectPayHelper::safe_amount_to_cents_int($item->get_total()),
					'refunded_total_tax'   => WeeConnectPayHelper::safe_amount_to_cents_int($item->get_total_tax()),
					'order_refund_item_id' => $item_id,
					'refunded_item'        => array(
						'line_item_id'     => $refunded_item_id,
						'line_total'       => WeeConnectPayHelper::safe_amount_to_cents_int($refunded_item->get_total()),
						'line_total_tax'   => WeeConnectPayHelper::safe_amount_to_cents_int($refunded_item->get_total_tax()),
						'line_quantity'    => $refunded_item->get_quantity(),
						'line_description' => WeeConnectPayHelper::name_and_qty_as_clover_line_desc(
							$refunded_item->get_name(),
							$refunded_item->get_quantity()
						),
					),
				);

				// Log line item details for successful inclusion
				error_log("Refund processed - Item ID: $refunded_item_id, Quantity: " . abs($item->get_quantity()) . ", Line Total: " . abs($item->get_total()) . ", Tax: " . $item->get_total_tax());
			}

            // Fees refund
            /** @var WC_Order_Item_Fee $fee */
            foreach ($latest_refund->get_fees() as $fee_id => $fee) {

                // Get the metadata for the refunded fee item
                $refunded_fee_id = $fee->get_meta('_refunded_item_id');

                // Retrieve all fees from the original order
                $order_fees = $order->get_fees();

                // Initialize variable to hold the original fee item
                $refunded_fee = null;

                // Loop through the order fees to find the matching fee
                foreach ($order_fees as $order_fee_id => $order_fee) {
                    if ($order_fee_id == $refunded_fee_id) {
                        $refunded_fee = $order_fee;
                        break;
                    }
                }

                if (!$refunded_fee) {
                    // Subtotal amount must match the refund subtotal amount
                    $refundErrorReasonSprintfFormat = __('Could not find the fee to refund (%s) within the original order. Please contact support@weeconnectpay.com if you are seeing this message.');
                    $refundFailureReason = sprintf(
                        $refundErrorReasonSprintfFormat,
                        $refunded_fee->get_name()
                    );

                    error_log("Refund error - Could not find the fee to refund (%s) within the original order. Refunded fee ID: $refunded_fee_id | Refunded fee name: {$refunded_fee->get_name()}");
                    return new WP_Error('wc-order', $undocumentedChangePrefixText . $refundFailureReason . "\n\n" . $orderWillNotBeRefundedText);
                }


                // Check if the absolute value of refunded quantity, total, and tax match -- Although quantity should never be used for fees, this is WordPress,
                // and a fee item is a child of an item, and somebody could have the brilliant idea to change the quantity of a fee, so I'm leaving it here.
                if (abs($fee->get_quantity()) != $refunded_fee->get_quantity()) {
                    // Quantity must match total quantity -- This is no longer going to be relevant with Atomic Order as we will be able to split units on Clover's end and separate taxes
                    $refundErrorReasonSprintfFormat = __('To refund this fee (%s), the quantity to refund (currently %s) must be the total fee quantity (%s)');
                    $refundFailureReason = sprintf(
                        $refundErrorReasonSprintfFormat,
                        $refunded_fee->get_name(),
                        abs($fee->get_quantity()),
                        $refunded_fee->get_quantity()
                    );

                    error_log("Refund error - Partial refunds not allowed due to mismatched fee quantity. Item ID: $refunded_fee_id");
                    return new WP_Error('wc-order', $undocumentedChangePrefixText . $refundFailureReason . "\n\n" . $orderWillNotBeRefundedText);

                } elseif (WeeConnectPayHelper::safe_amount_to_cents_int(abs($fee->get_total())) != WeeConnectPayHelper::safe_amount_to_cents_int($refunded_fee->get_total())) {
                    // Subtotal amount must match the refund subtotal amount
                    $refundErrorReasonSprintfFormat = __('To refund this fee (%s), the amount before tax to refund (currently $%s) must be the fee total amount before tax ($%s)');
                    $refundFailureReason = sprintf(
                        $refundErrorReasonSprintfFormat,
                        $refunded_fee->get_name(),
                        abs($fee->get_total()),
                        $refunded_fee->get_total()
                    );

                    error_log("Refund error - Partial refunds not allowed due to mismatched fee total. Fee ID: $refunded_fee_id ");
                    return new WP_Error('wc-order', $undocumentedChangePrefixText . $refundFailureReason . "\n\n" . $orderWillNotBeRefundedText);
                } elseif (WeeConnectPayHelper::safe_amount_to_cents_int(abs($fee->get_total_tax())) != WeeConnectPayHelper::safe_amount_to_cents_int($refunded_fee->get_total_tax())) {
                    // Total Tax amount must match refund tax amount
                    $refundErrorReasonSprintfFormat = __('To refund this fee (%s), the tax to refund (currently $%s) must be the fee total tax ($%s)');
                    $refundFailureReason = sprintf(
                        $refundErrorReasonSprintfFormat,
                        $refunded_fee->get_name(),
                        abs($fee->get_total_tax()),
                        $refunded_fee->get_total_tax()
                    );

                    error_log("Refund error - Partial refunds not allowed due to mismatched fee tax. Item ID: $refunded_fee_id");
                    return new WP_Error('wc-order', $undocumentedChangePrefixText . $refundFailureReason . "\n\n" . $orderWillNotBeRefundedText);
                }


                // Order Refund line fee
                $line_items[] = array(
                    'refunded_quantity' => $fee->get_quantity(),
                    'refunded_line_total' => WeeConnectPayHelper::safe_amount_to_cents_int($fee->get_total()),
                    'refunded_total_tax' => WeeConnectPayHelper::safe_amount_to_cents_int($fee->get_total_tax()),
                    'order_refund_item_id' => $fee_id,
                    'refunded_item' => array(
                        'line_item_id' => $refunded_fee_id,
                        'line_total' => WeeConnectPayHelper::safe_amount_to_cents_int($refunded_fee->get_total()),
                        'line_total_tax' => WeeConnectPayHelper::safe_amount_to_cents_int($refunded_fee->get_total_tax()),
                        'line_quantity' => $refunded_fee->get_quantity(),
                        'line_description' => WeeConnectPayHelper::name_and_qty_as_clover_line_desc(
                            $refunded_fee->get_name(),
                            $refunded_fee->get_quantity()
                        ),
                    ),
                );

                // Log line fee details for successful inclusion
                error_log("Refund processed - Item ID: $refunded_fee_id, Quantity: " . abs($fee->get_quantity()) . ", Line Total: " . abs($fee->get_total()) . ", Tax: " . $fee->get_total_tax());
            }


			// Add shipping if it's part of the refund request
            if ( $latest_refund->get_shipping_total() + $latest_refund->get_shipping_tax() ) {

//				$refundShippingTotal = $latest_refund->get_shipping_total();
//				$refundShippingTax = $latest_refund->get_shipping_tax();
//				$refundShippingMethod = $latest_refund->get_shipping_method();
//				$totalShippingRefunded = $latest_refund->get_total_shipping_refunded();
//				// Log details for debugging
//
//				$order->get_shipping_total();
//				$orderShippingTotal = $order->get_shipping_total();
//				$orderShippingTax = $order->get_shipping_tax();
//				$orderShippingMethod = $order->get_shipping_method();
//				$totalShippingRefunded = $order->get_total_shipping_refunded();

				$shipping_line_item_name = $order->get_meta( 'weeconnectpay_shipping_line_item_name' );
				$shipping_as_line_item   = $order->get_meta( 'weeconnectpay_shipping_as_clover_line_item' );

				error_log( "Refund check - Shipping name: $shipping_line_item_name, 
				            Refunded Shipping Total: " . abs( $latest_refund->get_shipping_total() ) . ", Original Shipping Total: " . $order->get_shipping_total() .",
				            Refunded Shipping Taxes: " . abs( $latest_refund->get_shipping_tax() ) . ", Original Shipping Taxes: " . $order->get_shipping_tax()
				);

            if ( WeeConnectPayHelper::safe_amount_to_cents_int(  $order->get_shipping_total()  ) != WeeConnectPayHelper::safe_amount_to_cents_int( abs($latest_refund->get_shipping_total()) ) ) {
					// Subtotal amount must match the refund subtotal amount
				$refundErrorReasonSprintfFormat = __('To refund this shipping item (%s), the amount before tax to refund (currently $%s) must be the shipping item total amount before tax ($%s)');
				$refundFailureReason = sprintf(
					$refundErrorReasonSprintfFormat,
					$shipping_line_item_name,
					abs($latest_refund->get_shipping_total()),
					$order->get_shipping_total()
				);

				error_log("Refund error - Partial refunds not allowed due to mismatched shipping item total. Shipping item name: $shipping_line_item_name");
				return new WP_Error('wc-order', $undocumentedChangePrefixText . $refundFailureReason . "\n\n" . $orderWillNotBeRefundedText);
			} elseif (WeeConnectPayHelper::safe_amount_to_cents_int($order->get_shipping_tax()) != WeeConnectPayHelper::safe_amount_to_cents_int(abs($latest_refund->get_shipping_tax()))) {
					// Total Tax amount must match refund tax amount
				$refundErrorReasonSprintfFormat = __('To refund this shipping item (%s), the shipping tax to refund (currently $%s) must be the shipping item total tax ($%s)');
				$refundFailureReason = sprintf(
					$refundErrorReasonSprintfFormat,
					$shipping_line_item_name,
					abs($latest_refund->get_shipping_tax()),
					$order->get_shipping_tax()
				);

				error_log("Refund error - Partial refunds not allowed due to mismatched shipping item tax. Shipping item name: $shipping_line_item_name");
				return new WP_Error('wc-order', $undocumentedChangePrefixText . $refundFailureReason . "\n\n" . $orderWillNotBeRefundedText);
			}

				// If there's an amount to refund related to shipping and the order to be refunded has the shipping line item name AND shipping as line item metadata
				if ( $shipping_as_line_item && $shipping_line_item_name ) {

					$shipping_line_item_amount                 = WeeConnectPayHelper::safe_amount_to_cents_int( $latest_refund->get_shipping_total() ) + WeeConnectPayHelper::safe_amount_to_cents_int( $latest_refund->get_shipping_tax() );

                    $shipping_item['refunded_shipping_amount'] = $shipping_line_item_amount;
					$shipping_item['refunded_shipping_name']   = $shipping_line_item_name;
				} else {
                    // Quick insight 2024 -- Do we really want to stop all the refund logic by returning here?
					return false;
				}
			}
		} catch ( Throwable $e ) {
			error_log( "DEBUG: Process refund first try/catch exception: " . $e->getMessage() );

			return false;
		}
		error_log( "DEBUG: Process refund AFTER first try/catch." );

		$formatted_number = WeeConnectPayHelper::safe_amount_to_cents_int( $amount );

		$refund_payload = array(
			'clover_order_uuid'     => $order->get_meta( 'weeconnectpay_clover_order_uuid' ),
			'shipping_as_line_item' => '1' === $shipping_as_line_item,
			'tax_included'          => '1' === $tax_included,
			'merged_qty'            => '1' === $merged_qty,
			'woocommerce_order_id'  => $order_id,
			'wpdb_prefix'           => $wpdb->prefix,
			'amount'                => $formatted_number,
			'reason'                => $reason,
			'line_items'            => $line_items,
		);

		// Add shipping item to payload if it's being refunded as a line item
		if ( isset( $shipping_item['refunded_shipping_amount'] ) ) {
			$refund_payload['shipping_item'] = $shipping_item;
		}

		$refund_response = $this->api->refund_woocommerce_order( $refund_payload );
		error_log( "DEBUG: AFTER refund_woocommerce_order(refund_payload) Response: " . json_encode( $refund_response ) );
		if ( $refund_response instanceof WP_Error ) {
			error_log( "DEBUG: AFTER refund_woocommerce_order and is instanceof WP_Error" );

			return $refund_response;
		}
		if ( isset( $refund_response->id )
		     && isset( $refund_response->amount )
		     && isset( $refund_response->charge )
		     && isset( $refund_response->status )
		     && ( 'succeeded' === $refund_response->status )
		) {
			$formatted_refund_amount = number_format( (float) $refund_response->amount / 100, 2, '.', '' );

			$chargeRefundNote = '<b>' . __( 'Refunded: ', 'weeconnectpay' ) . '</b>';
			$chargeRefundNote .= sprintf(
				                     __( '%1$s %2$s', 'weeconnectpay' ), // %1$s represents the amount being refunded and %2$s represents the currency type
				                     $formatted_refund_amount,
				                     $order->get_currency())
			                     . '<br>';
            $chargeRefundNote .= '<b>' . __( 'Refund ID: ', 'weeconnectpay' ) . '</b>' . $refund_response->id . '<br>'; // The 13 characters ID representing a specific successful Clover Refund for the Clover Merchant
            $chargeRefundNote .= '<b>' . __( 'Charge refunded: ', 'weeconnectpay' ) . '</b>' . $refund_response->charge . '<br>'; // The 13 characters ID representing the specific charge being refunded

            if ( '' !== $reason ) {
				$reason = '<b>' . __( 'Reason: ', 'weeconnectpay' ) . '</b>' . $reason; // The reason given for the refund
	            $chargeRefundNote .= $reason;
			}

			$order->add_order_note( $chargeRefundNote );

			return true;
		} elseif ( isset( $refund_response->id )
		           && isset( $refund_response->amount_returned )
		           && isset( $refund_response->items )
		           && isset( $refund_response->status )
		           && ( 'returned' === $refund_response->status )
		) {

			$formatted_returned_amount = number_format( (float) $refund_response->amount_returned / 100, 2, '.', '' );


			$returnString = '<b>' . __( 'Refunded: ', 'weeconnectpay' ) . '</b>';
			$returnString .= sprintf(
				                     __( '%1$s %2$s', 'weeconnectpay' ), // %1$s represents the total amount being refunded for the items being returned and %2$s represents the currency type
				                     $formatted_returned_amount,
				                     $order->get_currency())
			                     . '<br>';
			$returnString .= '<b>' . __( 'Refund ID: ', 'weeconnectpay' ) . '</b>' . $refund_response->id . '<br>'; // The 13 characters ID representing a specific successful Clover Refund for the Clover Merchant

			if ( '' !== $reason ) {
				$reason = '<b>' . __( 'Reason: ', 'weeconnectpay' ) . '</b>' . $reason; // The reason given for the refund
				$returnString .= $reason;
			}

            foreach ( $refund_response->items as $item_returned ) {
				if ( isset( $item_returned->parent )
				     && isset( $item_returned->description )
				     && isset( $item_returned->amount )
				) {
					$clover_item_id                   = $item_returned->parent;
					$clover_item_returned_description = $item_returned->description ?? null;
					$formatted_return_amount          = number_format( (float) $item_returned->amount / 100, 2, '.', '' );

                    $returnString .= '<b>' . __( 'Returned clover item ID: ', 'weeconnectpay' ) . '</b>';
					$returnString .= sprintf(
						                 __( '%1$s(%2$s %3$s) - %4$s', 'weeconnectpay' ), // %1$s represents the 13 character ID of the item on the order being returned. %2$s represents the total amount being refunded for the number of specific items being returned and %3$s represents the currency type. %4$s Represents the name of the description of the item on the order (Currently the amount of items for that item being refunded. IE: "Refunding 1 out of 2 items.")
						                 $clover_item_id,
						                 $formatted_return_amount,
						                 $order->get_currency(),
						                 $clover_item_returned_description )
					                 . '<br>';
				}
			}
			$order->add_order_note( $returnString );

			return true;
		} else {
			error_log( "very end of process_refund before false" );

			return new WP_Error( 'wc-order', __( 'Order has been already refunded', 'weeconnectpay' ) );
		}
	}

	/**
	 * @param WC_Order $order
	 *
	 * @deprecated since 1.5
	 */
	private function prepare_order_and_authed_iframe( WC_Order $order ): void {
		// Do something right before we try to process the payment
		$order_response = $this->api->prepare_order_for_payment( $order );
		if ( isset( $order_response['id'] ) && isset( $order_response['uuid'] ) && isset( $order_response['amount'] ) ) {
			// Has the secure_uuid for the iframe to know what to pay
			$this->iframe_settings['order_payload'] = $order_response;

			// Metadata to check at order creation to prevent double orders
			$order->add_meta_data( 'weeconnectpay_clover_order_uuid', $order_response['uuid'] );
			$order->save_meta_data();
		}

	}

	/**
	 * Used in process_payment. Handles a WeeConnectPayException to properly display a notice and return the failure array.
	 *
	 * @param WeeConnectPayException $exception
	 *
	 * @return string[]
	 * @since 2.0.6
	 * @updated 2.6.0
	 */
	protected function handleProcessPaymentException( WeeConnectPayException $exception ): array {
		if ( $exception->getCode() === ExceptionCode::MISSING_SHIPPING_STATE
		     || $exception->getCode() === ExceptionCode::CUSTOMER_CREATION_EXCEPTION
		     || $exception->getCode() === ExceptionCode::STANDARDIZED_RESPONSE_EXCEPTION
		     || $exception->getCode() === ExceptionCode::INVALID_JSON_EXCEPTION
		     || $exception->getCode() === ExceptionCode::ORDER_LINE_ITEM_TOTAL_MISMATCH
		     || $exception->getCode() === ExceptionCode::UNSUPPORTED_ORDER_ITEM_TYPE
		) {
			wc_add_notice( esc_html( $exception->getMessage() ), 'error' );
		} else {
			error_log( 'An unhandled exception happened with the payment processor. Message: ' . $exception->getMessage() );
		}

		return array(
			'result'   => 'fail',
			'redirect' => '',
		);
	}

	/**
	 * Used in process_payment. Handles a WeeConnectPayException to properly display a notice and return the failure array.
	 *
	 * @param WeeConnectPayException $exception
	 *
	 * @return string[]
	 * @since 2.4.0
	 */
	protected function handleCustomerCreationException( WeeConnectPayException $exception ): array {
		if ( $exception->getCode() === ExceptionCode::MISSING_SHIPPING_STATE ) {
			wc_add_notice( esc_html( $exception->getMessage() ), 'error' );
		} else {
			error_log( 'An unhandled exception happened while preparing the order with the payment processor.' );
		}

		return array(
			'result'   => 'fail',
			'redirect' => '',
		);
	}

	/**
	 * Creates the customer creation payload for the WeeConnectPay API using only the available resources given to us
	 *
	 * @param WC_Order $order
	 *
	 * @return array
	 * @since 2.4.1
	 */
	protected function customerPayload( WC_Order $order ): array {
		$customer = [];

		if ( $order->get_billing_first_name() ) {
			$customer['firstName'] = $order->get_billing_first_name();
		}

		if ( $order->get_billing_last_name() ) {
			$customer['lastName'] = $order->get_billing_last_name();
		}

		// Let customers follow your business on their own using the email sent to them by Clover -- Do not force this
		$customer['marketingAllowed'] = false;

		// Required: Address1, State, Country, City, Zip
		if ( $order->get_billing_address_1()
		     && $order->get_billing_state()
		     && $order->get_billing_country()
		     && $order->get_billing_city()
		     && $order->get_billing_postcode() ) {

			$customer['addresses'] = [
				[
					"address1"    => $order->get_billing_address_1(),
					"address2"    => $order->get_billing_address_2(),
					"city"        => $order->get_billing_city(),
					"country"     => $order->get_billing_country(),
					"phoneNumber" => $order->get_billing_phone(),
					"state"       => $order->get_billing_state(),
					"zip"         => $order->get_billing_postcode()
				]
			];
		}

		// Required by Clover regardless and used for email DNS validation on our end
		$customer["emailAddresses"] = [
			[
				"emailAddress" => $order->get_billing_email(), // Required for the gateway regardless
				"primaryEmail" => true
			]
		];

		// Is there a phone number available?
		if ( $order->get_billing_phone() ) {
			$customer["phoneNumbers"] = [
				[
					"phoneNumber" => $order->get_billing_phone()
				]
			];
		}

		$customer['metadata'] = [
			"note" => "Customer created by WeeConnectPay WooCommerce integration using the information provided by the customer during checkout.",
		];

		if ( $order->get_billing_company() ) {
			$customer['metadata']['businessName'] = $order->get_billing_company();
		}

		return $customer;
	}

	/**
	 * @return bool
	 */
	private function is_authenticated_iframe(): bool {
		return isset( $this->iframe_settings, $this->iframe_settings['order_payload']['secure_uuid'] );
	}

	/**
	 * @return array|void
	 */
	protected function verifyAuthentication() {
		$wp_env = WeeConnectPayUtilities::get_wp_env();

		switch ( $wp_env ) {
            case 'gitpod':
                $url_api = GITPOD_WCP_BACKEND_WORKSPACE_URL ?? 'GITPOD_URL_NOT_SET';
                break;
			case 'local':
			case 'development':
				// Do dev stuff
				$url_api = 'https://weeconnect-api.test';
				break;
			case 'staging':
				// Do staging stuff
				$url_api = 'https://apidev.weeconnectpay.com';
				break;
			case 'production':
			default:
				// Do production stuff
				$url_api = 'https://api.weeconnectpay.com';
		}

		try {

			$integration_id = Authentication::fetchIntegrationId();

			$integrationSettings = new IntegrationSettings();
			if ( $integrationSettings->accessTokenExists() ) {
				$authVerifyHttpCode = Authentication::verify( $integration_id );
			} else {
				$authVerifyHttpCode = 401;
			}

		} catch ( WeeConnectPayException $exception ) {
			die( json_encode( StandardizedResponse::emitError( $exception->toObject() ) ) );
		}

		return array( $url_api, $integration_id, $authVerifyHttpCode );
	}

}


