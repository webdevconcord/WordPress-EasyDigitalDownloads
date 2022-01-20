<?php
/**
 * ConcordPay API.
 *
 * @description Service class ConcordPay API.
 * @package     ConcordPay API
 * @author      ConcordPay
 * @version     1.0.0
 * @license     GNU GPL v3.0 (https://opensource.org/licenses/GPL-3.0)
 * @copyright   Copyright (c) 2021 https://concordpay.concord.ua
 */

/**
 * ConcordPay_API Class.
 */
class ConcordPay_API {

	/**
	 * ConcordPay API URL
	 *
	 * @var string
	 */
	private string $url = 'https://pay.concord.ua/api/';

	public const SIGNATURE_SEPARATOR         = ';';
	public const ORDER_SEPARATOR             = '#';
	public const TRANSACTION_STATUS_APPROVED = 'Approved';
	public const TRANSACTION_STATUS_DECLINED = 'Declined';
	public const PHONE_LENGTH_MIN            = 10;
	public const PHONE_LENGTH_MAX            = 11;
	public const ALLOWED_CURRENCIES          = array( 'UAH' );
	public const RESPONSE_TYPE_PAYMENT       = 'payment';
	public const RESPONSE_TYPE_REVERSE       = 'reverse';

	/**
	 * Array keys for generate response signature.
	 *
	 * @var string[]
	 */
	protected array $keys_for_response_signature = array(
		'merchantAccount',
		'orderReference',
		'amount',
		'currency',
	);

	/**
	 * Array keys for generate request signature.
	 *
	 * @var string[]
	 */
	protected array $keys_for_request_signature = array(
		'merchant_id',
		'order_id',
		'amount',
		'currency_iso',
		'description',
	);

	/**
	 * Allowed callback operation types.
	 *
	 * @var string[]
	 */
	protected array $allowed_operation_types = array(
		self::RESPONSE_TYPE_PAYMENT,
		self::RESPONSE_TYPE_REVERSE,
	);

	/**
	 * Allowed ConcordPay payment page languages.
	 *
	 * @var array|string[]
	 */
	protected array $allowed_payment_page_languages = array(
		'ru' => 'ru',
		'uk' => 'uk',
		'en' => 'en',
	);

	/**
	 * ConcordPay secret key.
	 *
	 * @var string
	 */
	private string $secret_key;

	/**
	 * ConcordPay_API constructor.
	 *
	 * @param string $secret_key ConcordPay secret key.
	 */
	public function __construct( string $secret_key ) {
		$this->secret_key = $secret_key;
	}

	/**
	 * Getter for request signature keys.
	 *
	 * @return string[]
	 */
	public function get_keys_for_request_signature(): array {
		return $this->keys_for_request_signature;
	}

	/**
	 * Getter for response signature keys.
	 *
	 * @return string[]
	 */
	public function get_keys_for_response_signature(): array {
		return $this->keys_for_response_signature;
	}

	/**
	 * Getter for allowed operation types.
	 *
	 * @return string[]
	 */
	public function get_allowed_operation_types(): array {
		return $this->allowed_operation_types;
	}

	/**
	 * Getter for allowed operation types.
	 *
	 * @return string[]
	 */
	public function get_allowed_payment_page_languages(): array {
		return $this->allowed_payment_page_languages;
	}

	/**
	 * Generate signature for operation.
	 *
	 * @param array $option Request or response data.
	 * @param array $keys Keys for signature.
	 * @return string
	 */
	public function get_signature( array $option, array $keys ): string {
		$hash = array();
		foreach ( $keys as $data_key ) {
			if ( ! isset( $option[ $data_key ] ) ) {
				continue;
			}
			if ( is_array( $option[ $data_key ] ) ) {
				foreach ( $option[ $data_key ] as $v ) {
					$hash[] = $v;
				}
			} else {
				$hash [] = $option[ $data_key ];
			}
		}
		$hash = implode( self::SIGNATURE_SEPARATOR, $hash );

		return hash_hmac( 'md5', $hash, $this->secret_key );
	}

	/**
	 * Generate request signature.
	 *
	 * @param array $options Request data.
	 * @return string
	 */
	public function get_request_signature( array $options ): string {
		return $this->get_signature( $options, $this->keys_for_request_signature );
	}

	/**
	 * Generate response signature.
	 *
	 * @param array $options Response data.
	 * @return string
	 */
	public function get_response_signature( array $options ): string {
		return $this->get_signature( $options, $this->keys_for_response_signature );
	}

	/**
	 * ConcordPay API URL.
	 *
	 * @return string
	 */
	public function get_api_url(): string {
		return $this->url;
	}

	/**
	 * Generate ConcordPay payment form with hidden fields.
	 *
	 * @param array $data Order data, prepared for payment.
	 * @return string
	 */
	public function generate_form( array $data ): string {
		$form = PHP_EOL . "<form method='post' id='form_concordpay' action=$this->url accept-charset=utf-8>" . PHP_EOL;
		foreach ( $data as $k => $v ) {
			$form .= $this->print_input( $k, $v );
		}
		$form .= "<input type='submit' style='display:none;'/>" . PHP_EOL;
		$form .= '</form>' . PHP_EOL;
		$form .= "<script type='text/javascript'>window.addEventListener('DOMContentLoaded', function () { document.querySelector('#form_concordpay').submit(); }) </script>";

		return $form;
	}

	/**
	 * Prints inputs in form.
	 *
	 * @param string       $name Attribute name.
	 * @param array|string $val Attribute value.
	 * @return string
	 */
	protected function print_input( string $name, $val ): string {
		$str = '';
		if ( ! is_array( $val ) ) {
			return "<input type='hidden' name='" . $name . "' value='" . htmlspecialchars( $val ) . "'>" . PHP_EOL;
		}
		foreach ( $val as $v ) {
			$str .= $this->print_input( $name . '[]', $v );
		}
		return $str;
	}

	/**
	 * Validate gateway response.
	 *
	 * @param array $data Response data.
	 * @param array $settings Gateway settings.
	 *
	 * @return bool
	 */
	public function is_payment_valid( array $data, array $settings ): bool {
		if ( $settings['merchant_id'] !== $data['merchantAccount'] ) {
			return false;
		}
		if ( $this->get_response_signature( $data ) !== $data['merchantSignature'] ) {
			return false;
		}

		return true;
	}
}
