<?php

/**
 * Plugin Name: ConcordPay for Easy Digital Downloads
 * Plugin URI: https://concordpay.concord.ua
 * Description: ConcordPay Payment Gateway for Easy Digital Downloads.
 * Version: 1.0.0
 * Author: ConcordPay
 * Author URI: https://concordpay.concord.ua
 * Domain Path: /lang
 * Text Domain: concordpay-for-edd
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * EDD requires at least: 2.0
 * EDD tested up to: 2.11.4
 *
 * @package EDD\Gateways
 */

require_once 'class-concordpay-api.php';

// Variables for translate plugin header.
$plugin_name        = esc_html__( 'ConcordPay for Easy Digital Downloads', 'concordpay-for-edd' );
$plugin_description = esc_html__( 'ConcordPay Payment Gateway for Easy Digital Downloads.', 'concordpay-for-edd' );
define( 'CONCORDPAY_IMGDIR', WP_PLUGIN_URL . '/' . plugin_basename( __DIR__ ) . '/assets/img/' );

class EDD_ConcordPay {

	public const CONCORDPAY_GATEWAY_NAME           = 'concordpay';
	public const CONCORDPAY_ORDER_STATUS_PUBLISH   = 'publish';
	public const CONCORDPAY_ORDER_STATUS_PENDING   = 'pending';
	public const CONCORDPAY_ORDER_STATUS_COMPLETE  = 'complete';
	public const CONCORDPAY_ORDER_STATUS_REFUNDED  = 'refunded';
	public const CONCORDPAY_ORDER_STATUS_FAILED    = 'failed';
	public const CONCORDPAY_ORDER_STATUS_ABANDONED = 'abandoned';
	public const CONCORDPAY_ORDER_STATUS_REVOKED   = 'revoked';

	/**
	 * Class instance.
	 *
	 * @var EDD_ConcordPay Class
	 */
	private static EDD_ConcordPay $instance;

	/**
	 * ConcordPay_API class instance.
	 *
	 * @var ConcordPay_API
	 */
	public ConcordPay_API $concordpay;

	/**
	 * Plugin file absolute path.
	 *
	 * @var string
	 */
	public string $file;

	/**
	 * Plugin install path.
	 *
	 * @var string
	 */
	public string $plugin_path;

	/**
	 * Plugin URL.
	 *
	 * @var string
	 */
	public string $plugin_url;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	public string $version;

	/**
	 * Order status after successful payment.
	 *
	 * @var string
	 */
	public string $order_status_approved;

	/**
	 * Order status after declined payment.
	 *
	 * @var string
	 */
	public string $order_status_declined;

	/**
	 * Order status after refunded payment.
	 *
	 * @var string
	 */
	public string $order_status_refunded;

	/**
	 * EDD_ConcordPay constructor.
	 *
	 * @param string $file Plugin file absolute path.
	 */
	private function __construct( string $file ) {
		global $edd_options;

		$this->version     = '1.0.0';
		$this->file        = $file;
		$this->plugin_url  = trailingslashit( plugins_url( '', $plugin = $file ) );
		$this->plugin_path = trailingslashit( dirname( $file ) );

		$secret_key = $edd_options[ self::CONCORDPAY_GATEWAY_NAME . '_secret_key' ] ?? '';

		$this->order_status_approved = $edd_options[ self::CONCORDPAY_GATEWAY_NAME . '_order_status_approved' ] ?? self::CONCORDPAY_ORDER_STATUS_PUBLISH;
		$this->order_status_declined = $edd_options[ self::CONCORDPAY_GATEWAY_NAME . '_order_status_declined' ] ?? self::CONCORDPAY_ORDER_STATUS_FAILED;
		$this->order_status_refunded = $edd_options[ self::CONCORDPAY_GATEWAY_NAME . '_order_status_refunded' ] ?? self::CONCORDPAY_ORDER_STATUS_REFUNDED;

		$this->concordpay = new ConcordPay_API( $secret_key );

		if ( ! function_exists( 'json_decode' ) ) {
			if ( is_admin() ) {
				add_action( 'admin_notices', array( &$this, 'edd_concordpay_initialization_warning' ) );
			}
			return;
		}
		/* Hooks */
		if ( is_admin() ) {
			add_filter( 'edd_settings_gateways', array( &$this, 'edd_concordpay_add_gateway_settings' ) );
			add_filter( 'edd_settings_sections_gateways', array( &$this, 'edd_concordpay_register_gateway_section' ) );
		}
		// Load translation files.
		add_action( 'init', array( &$this, 'edd_concordpay_load_textdomain' ) );

		add_filter( 'edd_accepted_payment_icons', array( &$this, 'edd_concordpay_payment_icon' ) );
		add_filter( 'edd_payment_gateways', array( &$this, 'edd_concordpay_register_gateway' ) );
		add_action( 'edd_concordpay_cc_form', array( &$this, 'edd_concordpay_gateway_checkout_form' ) );
		add_action( 'edd_gateway_concordpay', array( &$this, 'edd_concordpay_process_payment' ) );
		// Notify from Concordpay gateway.
		add_action( 'init', array( &$this, 'edd_concordpay_validate_report_back' ) );
		add_action( 'edd_concordpay_check', array( &$this, 'edd_concordpay_process_notify' ) );
	}

	/**
	 * Get ConcordPay class instance.
	 *
	 * @return EDD_ConcordPay
	 */
	public static function instance(): self {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new EDD_ConcordPay( __FILE__ );
		}
		return self::$instance;
	}

	/**
	 * JSON extension required.
	 */
	public function edd_concordpay_initialization_warning(): void {
		/* translators: 1: JSON. */
		echo '<div id="edd-concordpay-warning" class="updated fade"><p><strong>' . sprintf( __( '%s PHP library not installed.', 'concordpay-for-edd' ), 'JSON' ) . '</strong> ';
		/* translators: 1: PHP JSON docs. */
		echo sprintf( __( 'EDD ConcordPay Payment Gateway plugin will not function without <a href="%s">PHP JSON functions</a> enabled. Please update your version of WordPress for improved compatibility and/or enable native JSON support for PHP.', 'concordpay-for-edd' ), esc_html( 'https://www.php.net/manual/ru/book.json.php' ) );
		echo '</p></div>';
	}

	/**
	 * Returns ConcordPay logo path.
	 *
	 * @param array $icons Gateway icons.
	 *
	 * @return array
	 */
	public function edd_concordpay_payment_icon( array $icons ): array {
		$icons[ $this->plugin_url . 'assets/img/concordpay.png' ] = __( 'ConcordPay Payment Gateway', 'concordpay-for-edd' );

		return $icons;
	}

	/**
	 * Register ConcordPay Gateway.
	 *
	 * @param array $gateways List of gateways.
	 *
	 * @return mixed
	 */
	public function edd_concordpay_register_gateway( array $gateways ): array {
		$gateways[ self::CONCORDPAY_GATEWAY_NAME ] = array(
			'admin_label'    => __( 'ConcordPay', 'concordpay-for-edd' ),
			'checkout_label' => __( 'ConcordPay', 'concordpay-for-edd' ),
		);

		return $gateways;
	}

	/**
	 * Register a subsection for ConcordPay Gateway in gateway options tab.
	 *
	 * @param array $gateway_sections Current Gateway Tab Subsections.
	 *
	 * @return array Gateway Tab Subsections with ConcordPay Gateway.
	 */
	public function edd_concordpay_register_gateway_section( array $gateway_sections ): array {
		$gateway_sections[ self::CONCORDPAY_GATEWAY_NAME ] = __( 'ConcordPay', 'concordpay-for-edd' );

		return $gateway_sections;
	}

	/**
	 * Gateway settings.
	 *
	 * @param array $gateway_settings Gateway settings array.
	 *
	 * @return array
	 */
	public function edd_concordpay_add_gateway_settings( array $gateway_settings ): array {
		$statuses = edd_get_payment_statuses();

		$concordpay_settings = array(
			array(
				'id'   => self::CONCORDPAY_GATEWAY_NAME . '_settings',
				'name' => '<strong>' . __( 'ConcordPay Payment Gateway Settings', 'concordpay-for-edd' ) . '</strong>',
				'type' => 'header',
			),
			array(
				'id'   => self::CONCORDPAY_GATEWAY_NAME . '_merchant_id',
				'name' => __( 'Merchant ID', 'concordpay-for-edd' ),
				'desc' => __( 'Enter your Merchant ID, you can find it <a href=https://concordpay.concord.ua target=_blank>here</a>.', 'concordpay-for-edd' ),
				'type' => 'text',
				'size' => 'regular',
			),
			array(
				'id'   => self::CONCORDPAY_GATEWAY_NAME . '_secret_key',
				'name' => __( 'Secret Key', 'concordpay-for-edd' ),
				'desc' => __( 'Enter your Secret Key, you can find it <a href=https://concordpay.concord.ua target=_blank>here</a>.', 'concordpay-for-edd' ),
				'type' => 'text',
				'size' => 'regular',
			),
			array(
				'id'      => self::CONCORDPAY_GATEWAY_NAME . '_order_status_approved',
				'type'    => 'select',
				'name'    => __( 'Payment completed order status', 'concordpay-for-edd' ),
				'desc'    => __( 'The order status after successful payment.', 'concordpay-for-edd' ),
				'size'    => 'regular',
				'options' => $statuses,
				'std'     => $this->order_status_approved,
			),
			array(
				'id'      => self::CONCORDPAY_GATEWAY_NAME . '_order_status_declined',
				'type'    => 'select',
				'name'    => __( 'Payment declined order status', 'concordpay-for-edd' ),
				'desc'    => __( 'Order status when payment was declined.', 'concordpay-for-edd' ),
				'size'    => 'regular',
				'options' => $statuses,
				'std'     => $this->order_status_declined,
			),
			array(
				'id'      => self::CONCORDPAY_GATEWAY_NAME . '_order_status_refunded',
				'type'    => 'select',
				'name'    => __( 'Payment refunded order status', 'concordpay-for-edd' ),
				'desc'    => __( 'Order status when payment was refunded.', 'concordpay-for-edd' ),
				'size'    => 'regular',
				'options' => $statuses,
				'std'     => $this->order_status_refunded,
			),
			array(
				'id'      => self::CONCORDPAY_GATEWAY_NAME . '_lang',
				'type'    => 'select',
				'name'    => __( 'Payment page language', 'concordpay-for-edd' ),
				'desc'    => __( 'Choose ConcordPay payment page language', 'concordpay-for-edd' ),
				'size'    => 'regular',
				'options' => $this->concordpay->get_allowed_payment_page_languages(),
			),
		);

		$concordpay_settings = apply_filters( 'edd_' . self::CONCORDPAY_GATEWAY_NAME . '_settings', $concordpay_settings );

		$gateway_settings[ self::CONCORDPAY_GATEWAY_NAME ] = $concordpay_settings;

		return $gateway_settings;
	}

	/**
	 * Payment without redirect to the site Payment system (in this version of plugin is not implemented).
	 *
	 * @return void
	 */
	public static function edd_concordpay_gateway_checkout_form() {
		return;
	}

	/**
	 * Process payment handler.
	 *
	 * @param array $purchase_data Purchase data.
	 *
	 * @return bool
	 */
	public function edd_concordpay_process_payment( array $purchase_data ): bool {
		global $edd_options;

		if ( ! isset( $purchase_data['post_data']['edd-gateway'] ) ) {
			return false;
		}

		$errors = edd_get_errors();
		$fail   = false;
		if ( ! $errors ) {
			if ( ! $edd_options['currency'] ) {
				edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
			}
			$payment_data = array(
				'price'        => $purchase_data['price'],
				'date'         => $purchase_data['date'],
				'user_email'   => $purchase_data['user_email'],
				'purchase_key' => $purchase_data['purchase_key'],
				'currency'     => $edd_options['currency'],
				'downloads'    => $purchase_data['downloads'],
				'user_info'    => $purchase_data['user_info'],
				'cart_details' => $purchase_data['cart_details'],
				'status'       => 'pending',
			);

			$payment_id = (int) edd_insert_payment( $payment_data );

			if ( ! $payment_id ) {
				edd_record_gateway_error(
					__( 'Payment Error', 'concordpay-for-edd' ),
					/* Translators: 1. Payment data */
					sprintf( __( 'Payment creation failed. Payment data: %s', 'concordpay-for-edd' ), wp_json_encode( $payment_data ) ),
					$payment_id
				);
				edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
			} else {
				$args = $this->edd_concordpay_get_data( $payment_id, $purchase_data );
				// Build the external request form.
				echo $this->concordpay->generate_form( $args );
				exit;
			}
		} else {
			$fail = true;
		}

		if ( false !== $fail ) {
			edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
		}

		return false;
	}

	/**
	 * Validate callback.
	 */
	public function edd_concordpay_validate_report_back(): void {
		global $edd_options;

		// Regular ConcordPay notify.
		if ( isset( $_GET['concordpay'] ) && strtolower( $_GET['concordpay'] ) === 'notify' ) {
			do_action( 'edd_concordpay_check' );
		}
	}

	/**
	 * ConcordPay Callback url
	 * Update order status.
	 *
	 * Predefined order statuses: pending, complete, refunded, failed, abandoned, revoked.
	 *
	 * @return bool
	 */
	public function edd_concordpay_process_notify(): bool {
		global $edd_options;

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return false;
		}

		$settings = array(
			'merchant_id' => $edd_options[ self::CONCORDPAY_GATEWAY_NAME . '_merchant_id' ],
			'secret_key'  => $edd_options[ self::CONCORDPAY_GATEWAY_NAME . '_secret_key' ],
		);

		$response = json_decode( file_get_contents( 'php://input' ), true );
		$is_valid = $this->concordpay->is_payment_valid( $response, $settings );

		if ( $is_valid ) {
			$payment_id = explode( ConcordPay_API::ORDER_SEPARATOR, $response['orderReference'] )[0] ?? null;

			$transaction_status = $response['transactionStatus'] ?? null;

			// Checking a repeat payment attempt.
			if (
				get_post_status( $payment_id ) === self::CONCORDPAY_ORDER_STATUS_COMPLETE
				&& ConcordPay_API::RESPONSE_TYPE_REVERSE !== $response['type']
			) {
				return false;
			}

			// Checking gateway.
			if ( edd_get_payment_gateway( $payment_id ) !== self::CONCORDPAY_GATEWAY_NAME ) {
				return false;
			}

			// Checking amount.
			$concordpay_amount = (float) get_post_meta( $payment_id, '_edd_payment_total', true );
			if ( (float) $response['amount'] !== $concordpay_amount ) {
				return false;
			}

			// Update order status.
			if ( ConcordPay_API::TRANSACTION_STATUS_APPROVED === $transaction_status ) {
				if ( ConcordPay_API::RESPONSE_TYPE_PAYMENT === $response['type'] ) {
					// Ordinary payment.
					edd_update_payment_status( $payment_id, $this->order_status_approved );
					edd_insert_payment_note( $payment_id, 'Payment ID: ' . $payment_id );
					edd_insert_payment_note( $payment_id, 'ConcordPay transaction ID: ' . $response['transactionId'] );
					// Emptying the shopping cart.
					edd_empty_cart();
				} elseif ( ConcordPay_API::RESPONSE_TYPE_REVERSE === $response['type'] ) {
					// Refunded payment.
					edd_update_payment_status( $payment_id, $this->order_status_refunded );
					edd_insert_payment_note( $payment_id, 'Payment ID: ' . $payment_id );
					edd_insert_payment_note( $payment_id, 'ConcordPay transaction ID: ' . $response['transactionId'] );
				} elseif ( ConcordPay_API::TRANSACTION_STATUS_DECLINED === $transaction_status ) {
					edd_update_payment_status( $payment_id, $this->order_status_declined );
				}
			}
			exit();
		}

		return false;
	}

	/**
	 * Get ConcordPay prepared payment data.
	 *
	 * @param int   $payment_id Payment ID.
	 * @param array $purchase_data Payment data.
	 * @return array
	 */
	private function edd_concordpay_get_data( int $payment_id, array $purchase_data ): array {
		global $edd_options;

		$return_url        = add_query_arg( 'payment-confirmation', 'concordpay', get_permalink( $edd_options['success_page'] ) );
		$callback_url      = trailingslashit( home_url() ) . '?concordpay=notify';
		$client_first_name = $purchase_data['post_data']['edd_first'];
		$client_last_name  = $purchase_data['post_data']['edd_last'];

		$email = $purchase_data['post_data']['edd_email'];
		$phone = $purchase_data['post_data']['edd_phone'] ?? '';

		$description = __( 'Payment by card on the site', 'concordpay-for-edd' ) . ' '
			. get_site_url() . ", $client_first_name $client_last_name" . ( $phone ?? ", $phone" );

		// ConcordPay payment form args.
		$args                 = array();
		$args['operation']    = 'Purchase';
		$args['merchant_id']  = $edd_options[ self::CONCORDPAY_GATEWAY_NAME . '_merchant_id' ];
		$args['amount']       = (float) $purchase_data['price'] - (float) $purchase_data['tax'];
		$args['order_id']     = $payment_id . ConcordPay_API::ORDER_SEPARATOR . time();
		$args['currency_iso'] = $edd_options['currency'];
		$args['description']  = $description;
		$args['approve_url']  = $return_url;
		$args['decline_url']  = $return_url;
		$args['cancel_url']   = $return_url;
		$args['callback_url'] = $callback_url;
		$args['language']     = $edd_options[ self::CONCORDPAY_GATEWAY_NAME . '_lang' ];
		// Statistics.
		$args['client_last_name']  = $client_last_name;
		$args['client_first_name'] = $client_first_name;
		$args['email']             = $email;
		$args['phone']             = $phone;

		$args['signature'] = $this->concordpay->get_request_signature( $args );

		return $args;
	}

	/**
	 * Load translation files.
	 *
	 * @return void
	 */
	public function edd_concordpay_load_textdomain(): void {
		load_plugin_textdomain( 'concordpay-for-edd', false, dirname( plugin_basename( __FILE__ ) ) . '/lang' );
	}
}

/**
 * Throw an error if Easy Digital Downloads is not installed.
 *
 * @since 0.2
 */
function concordpay_missing_edd(): void {
	echo '<div class="error"><p>' . sprintf( __( 'Please %1$sinstall &amp; activate Easy Digital Downloads%2$s to allow this plugin to work.', 'concordpay-for-edd' ), '<a href="' . admin_url( 'plugin-install.php?tab=search&type=term&s=easy+digital+downloads&plugin-search-input=Search+Plugins' ) . '">', '</a>' ) . '</p></div>';
}

/**
 * Check wp version
 */
function concordpay_error_wordpress_version(): void {
	echo '<div class="error"><p>' . __( 'Please upgrade WordPress to the latest version to allow WordPress and this plugin to work properly.', 'concordpay-for-edd' ) . '</p></div>';
}

/**
 * Get EDD_ConcordPay instance.
 *
 * @return EDD_ConcordPay|void
 */
function edd_concordpay() {
	if ( ! function_exists( 'EDD' ) ) {
		return;
	}

	return EDD_ConcordPay::instance();
}

// Add ConcordPay settings link on Plugins page.
$plugin_file = plugin_basename( __FILE__ );
add_filter( "plugin_action_links_$plugin_file", 'concordpay_plugin_settings_link' );

/**
 * Add ConcordPay settings link on Plugins page.
 *
 * @param array $links Links under the name of the plugin.
 *
 * @return array
 */
function concordpay_plugin_settings_link( array $links ): array {
	$settings_link = '<a href="' . admin_url( 'edit.php?post_type=download&page=edd-settings&tab=gateways&section=concordpay' ) . '">' . __( 'Settings', 'concordpay-for-edd' ) . '</a>';
	array_unshift( $links, $settings_link );

	return $links;
}

/**
 * Load plugin.
 */
function edd_concordpay_init() {
	global $wp_version;

	if ( ! version_compare( $wp_version, '3.4', '>=' ) ) {
		add_action( 'all_admin_notices', 'concordpay_error_wordpress_version' );
	} elseif ( class_exists( 'Easy_Digital_Downloads' ) ) {
		edd_concordpay();
	} else {
		add_action( 'all_admin_notices', 'concordpay_missing_edd' );
	}
}

add_action( 'plugins_loaded', 'edd_concordpay_init', 10 );
