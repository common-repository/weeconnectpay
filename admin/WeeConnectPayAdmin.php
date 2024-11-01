<?php
// phpcs:disable WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
// phpcs:disable WordPress.PHP.YodaConditions.NotYoda

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://github.com/ParogDev
 * @since      1.0.0
 *
 * @package    WeeConnectPay
 * @subpackage WeeConnectPay/admin
 */

namespace WeeConnectPay\WordPress\Plugin\admin;

use WC_Order;
use WeeConnectPay\WordPress\Plugin\includes\RegisterSettings;
use WeeConnectPay\WordPress\Plugin\includes\WeeConnectPayController;
use WeeConnectPay\WordPress\Plugin\includes\WeeConnectPaySettingsCallback;
use WeeConnectPay\WordPress\Plugin\includes\WeeConnectPayUtilities;
use WeeConnectPay\WordPress\Plugin\includes\WeeConnectPayWooProductImport;

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    WeeConnectPay
 * @subpackage WeeConnectPay/admin
 * @author     ParogDev <integration@cspaiement.com>
 */
class WeeConnectPayAdmin {


	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string $pluginName The ID of this plugin.
	 */
	private $pluginName;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string $version The current version of this plugin.
	 */
	private $version;

	/**
	 * An instance of the WeeConnectPaySettingsCallback class
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var WeeConnectPaySettingsCallback Class holding settings callback functions
	 */
	private $settingsCallback;

	/**
	 * An instance of the RegisterSettings class
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var RegisterSettings
	 */
	private $settings;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param string $pluginName The name of this plugin.
	 * @param string $version The version of this plugin.
	 *
	 * @since    1.0.0
	 */
	public function __construct( string $pluginName, string $version ) {
		$this->pluginName       = $pluginName;
		$this->version          = $version;
		$this->settingsCallback = new WeeConnectPaySettingsCallback();
		$this->settings         = new RegisterSettings( $this->settingsCallback );
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueueStyles() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in WeeConnectPayLoader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The WeeConnectPayLoader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

//		 PostProcess CSS
		      wp_enqueue_style(
		          $this->pluginName,
		          plugin_dir_url( __FILE__ ) . 'css/weeconnectpay-admin.css',
		          array(),
		          time(),
		          'all'
		      );

	}

	/**
	 * Register the JavaScript for the admin area.
	 * @updated  2.0.4
	 * @since    1.0.0
	 */
	public function enqueueScripts() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in WeeConnectPayLoader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The WeeConnectPayLoader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_script(
			$this->pluginName,
			plugin_dir_url( __FILE__ ) . 'js/weeconnectpay-admin.js',
			array( 'jquery' ),
			$this->version,
			false
		);
	}

	/**
	 * Add the top level menu for the admin area
	 *
	 * @since   1.0.0
	 */
	public function addToplevelMenu() {
		add_menu_page(
			'WeeConnectPay Settings',
			'WeeConnectPay',
			'manage_options',
			'weeconnectpay',
			array( $this, 'loadAdminPageContent' ),
			'dashicons-admin-generic',
			null
		);
	}

	/**
	 * Adds plugin action links.
	 *
	 * @param  $actions
	 *
	 * @return array
	 * @since 1.0.1
	 */
	public function pluginActionLinks( $actions ): array {
		$woocommerce_settings_url = WeeConnectPayUtilities::getSettingsURL();
		$plugin_links             = array(
			"<a href=$woocommerce_settings_url>" . __( 'WooCommerce Settings', 'weeconnectpay' ) . '</a>',
		);

		return array_merge( $plugin_links, $actions );
	}


	/**
	 * Ajax init_weeconnectpay_import handler
	 */
	public function init_weeconnectpay_import() {
		$import = new WeeConnectPayWooProductImport();
		$import->process_import();
		wp_send_json_success( 'It works' );
	}

	/**
	 * Load the admin page partial
	 *
	 * @since   1.0.0
	 * @noinspection PhpIncludeInspection
	 */
	public function loadAdminPageContent() {
		require_once plugin_dir_path( __FILE__ ) . 'partials/weeconnectpayAdminDisplay.php';
	}


	/**
	 * Registers the settings
	 *
	 * @since 1.0.0
	 */
	public function registerSettings() {
		$registerSettings = new RegisterSettings( $this->settingsCallback );
		$registerSettings->run();
	}

	public function register_routes() {
			$WeeConnectPayController = new WeeConnectPayController();
			$WeeConnectPayController->register_routes();
	}

	/**
	 *  Adds search additional fields to query when using the WooCommerce order search bar
	 *
	 * @since 2.2.0
	 * @param array $search_fields
	 *
	 * @return array
	 */
	function added_wc_search_fields( array $search_fields ): array {
		$search_fields[] = 'weeconnectpay_clover_order_uuid';
		$search_fields[] = 'weeconnectpay_clover_payment_uuid';
		return $search_fields;
	}


	/**
	 * Since we do not deal with orders but simply the ordering of an array, this can be used for both hpos and legacy.
	 * The name is specified since we do use a different hook for it in each case.
	 *
	 * @param $columns
	 *
	 * @return array
	 * @since 3.9.0
	 */
	function add_custom_orders_column_card_brand_hpos_and_legacy($columns): array {
		$reordered_columns = array();

		foreach( $columns as $key => $column){
			$reordered_columns[$key] = $column;

			if( $key ===  'shipping_address' ){
				// Inserting after "Total" column
				$reordered_columns['card-brand'] = __( 'Card Brand','weeconnectpay');
			}
		}
		return $reordered_columns;
	}


	/**
	 * Populates the "Card Brand" column with data from the order if available.
	 *
	 * @param $column
	 * @param $order
	 *
	 * @return void
	 * @since 3.9.0
	 */
	public function populate_custom_orders_column_card_brand_hpos($column, $order): void {
		$this->populate_custom_orders_column_card_brand_wrapper( $column, $order );
	}


	/**
	 * Populates the "Card Brand" column with data from the order if available.
	 *
	 * @param $column
	 * @param $post_id
	 *
	 * @return void
	 * @since 3.9.0
	 */
	public function populate_custom_orders_column_card_brand_legacy($column, $post_id): void {
		$order = wc_get_order( $post_id );

		$this->populate_custom_orders_column_card_brand_wrapper( $column, $order );
	}

	/**
	 * Wrapper to ensure that if we change anything, it will be changed for both legacy and hpos versions
	 * @param $column
	 * @param $order
	 *
	 * @return void
	 * @since 3.9.0
	 */
	protected function populate_custom_orders_column_card_brand_wrapper( $column, $order ): void {
		if ( $order instanceof WC_Order && $column === 'card-brand' ) {
			// Get custom order metadata
			$cardBrand = $order->get_meta( 'weeconnectpay_card_brand' );
			if ( ! empty( $cardBrand ) ) {
				echo esc_html( $cardBrand );
			}
		}
	}


}
