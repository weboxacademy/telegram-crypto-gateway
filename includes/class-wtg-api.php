<?php
/**
 * Webox Telegram Crypto Gateway
 *
 * Crypto Pay API wrapper.
 *
 * @package WeboxTelegramCryptoGateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTG_API {

	/**
	 * Crypto Pay API base URL.
	 *
	 * @var string
	 */
	private const API_URL = 'https://pay.crypt.bot/api/';

	/**
	 * Gateway settings ID.
	 *
	 * @var string
	 */
	private const SETTINGS_ID = 'woocommerce_wtg_telegram_crypto_settings';


	/**
	 * Get gateway settings.
	 *
	 * @return array
	 */
	private static function get_settings() {

		$settings = get_option( self::SETTINGS_ID, array() );

		return is_array( $settings ) ? $settings : array();
	}


	/**
	 * Get Crypto Pay API token.
	 *
	 * @return string
	 */
	public static function get_token() {

		$settings = self::get_settings();

		if ( ! isset( $settings['api_token'] ) ) {
			return '';
		}

		return trim(
			(string) $settings['api_token']
		);
	}


	/**
	 * Get accepted crypto assets.
	 *
	 * New setting:
	 *
	 * accepted_assets = USDT,TON,BTC
	 *
	 * Backward compatibility:
	 *
	 * asset = USDT
	 *
	 * @return string
	 */
	public static function get_accepted_assets() {

		$settings = self::get_settings();

		$accepted_assets = '';

		/*
		 * New setting.
		 */
		if ( isset( $settings['accepted_assets'] ) ) {
			$accepted_assets = (string) $settings['accepted_assets'];
		}

		/*
		 * Backward compatibility with old gateway setting.
		 */
		if (
			empty( $accepted_assets ) &&
			isset( $settings['asset'] )
		) {
			$accepted_assets = (string) $settings['asset'];
		}

		$accepted_assets = strtoupper(
			$accepted_assets
		);

		/*
		 * Remove everything except letters,
		 * numbers and commas.
		 */
		$accepted_assets = preg_replace(
			'/[^A-Z0-9_,]/',
			'',
			$accepted_assets
		);

		$assets = array_filter(
			array_map(
				'trim',
				explode( ',', $accepted_assets )
			)
		);

		/*
		 * Supported assets.
		 */
		$allowed_assets = array(
			'USDT',
			'TON',
			'BTC',
			'ETH',
			'LTC',
			'BNB',
			'TRX',
			'USDC',
		);

		$assets = array_values(
			array_intersect(
				$assets,
				$allowed_assets
			)
		);

		/*
		 * Default.
		 */
		if ( empty( $assets ) ) {
			$assets = array(
				'USDT',
			);
		}

		return implode(
			',',
			$assets
		);
	}


	/**
	 * Make a request to Crypto Pay API.
	 *
	 * @param string $method API method.
	 * @param array  $body   Request body.
	 *
	 * @return array|WP_Error
	 */
	public static function request(
		$method,
		$body = array()
	) {

		$token = self::get_token();

		if ( empty( $token ) ) {

			return new WP_Error(
				'wtg_missing_api_token',
				__(
					'Crypto Pay API token is not configured.',
					'webox-telegram-crypto-gateway'
				)
			);
		}

		$method = trim(
			(string) $method
		);

		if ( empty( $method ) ) {

			return new WP_Error(
				'wtg_invalid_api_method',
				__(
					'Crypto Pay API method is missing.',
					'webox-telegram-crypto-gateway'
				)
			);
		}

		$url = trailingslashit(
			self::API_URL
		) . ltrim(
			$method,
			'/'
		);

		$args = array(
			'timeout'     => 30,
			'redirection' => 3,
			'httpversion' => '1.1',
			'blocking'    => true,
			'headers'     => array(
				'Crypto-Pay-API-Token' => $token,
				'Content-Type'        => 'application/json; charset=utf-8',
				'Accept'              => 'application/json',
			),
		);

		if ( ! empty( $body ) ) {

			$args['body'] = wp_json_encode(
				$body,
				JSON_UNESCAPED_SLASHES |
				JSON_UNESCAPED_UNICODE
			);
		}

		self::log(
			'Crypto Pay API request.',
			array(
				'method' => $method,
				'body'   => self::sanitize_log_data( $body ),
			)
		);

		$response = wp_remote_post(
			$url,
			$args
		);

		/*
		 * WordPress HTTP error.
		 */
		if ( is_wp_error( $response ) ) {

			self::log(
				'Crypto Pay API request failed.',
				array(
					'method' => $method,
					'error'  => $response->get_error_message(),
				),
				'error'
			);

			return $response;
		}

		$status_code = wp_remote_retrieve_response_code(
			$response
		);

		$raw_body = wp_remote_retrieve_body(
			$response
		);

		/*
		 * Decode JSON.
		 */
		$data = json_decode(
			$raw_body,
			true
		);

		if ( ! is_array( $data ) ) {

			self::log(
				'Invalid JSON response from Crypto Pay API.',
				array(
					'method'      => $method,
					'status_code' => $status_code,
					'body'        => $raw_body,
				),
				'error'
			);

			return new WP_Error(
				'wtg_invalid_api_response',
				__(
					'Invalid response received from Crypto Pay API.',
					'webox-telegram-crypto-gateway'
				),
				array(
					'status_code' => $status_code,
					'body'        => $raw_body,
				)
			);
		}

		/*
		 * Crypto Pay response:
		 *
		 * {
		 *     "ok": true,
		 *     "result": {}
		 * }
		 */
		if (
			$status_code < 200 ||
			$status_code >= 300 ||
			empty( $data['ok'] )
		) {

			$error_code = isset(
				$data['error']['code']
			)
				? $data['error']['code']
				: $status_code;

			$error_name = isset(
				$data['error']['name']
			)
				? $data['error']['name']
				: __(
					'Unknown Crypto Pay API error.',
					'webox-telegram-crypto-gateway'
				);

			self::log(
				'Crypto Pay API returned an error.',
				array(
					'method'      => $method,
					'status_code' => $status_code,
					'error_code'  => $error_code,
					'error_name'  => $error_name,
				),
				'error'
			);

			return new WP_Error(
				'wtg_api_error',
				(string) $error_name,
				array(
					'status_code' => $status_code,
					'error_code'  => $error_code,
					'error_name'  => $error_name,
				)
			);
		}

		/*
		 * Return API result only.
		 */
		return isset( $data['result'] )
			? $data['result']
			: array();
	}


	/**
	 * Test API connection.
	 *
	 * @return array|WP_Error
	 */
	public static function get_me() {

		return self::request(
			'getMe'
		);
	}


	/**
	 * Get exchange rates.
	 *
	 * Available for diagnostics/future functionality.
	 *
	 * We don't use manual conversion for USD invoices.
	 *
	 * @return array|WP_Error
	 */
	public static function get_exchange_rates() {

		return self::request(
			'getExchangeRates'
		);
	}


	/**
	 * Get supported currencies.
	 *
	 * @return array|WP_Error
	 */
	public static function get_currencies() {

		return self::request(
			'getCurrencies'
		);
	}


	/**
	 * Create Crypto Pay invoice.
	 *
	 * WooCommerce currency:
	 *
	 * USD
	 *
	 * Crypto Pay invoice:
	 *
	 * currency_type = fiat
	 * fiat          = USD
	 *
	 * Crypto Pay handles the conversion to the selected
	 * cryptocurrency itself.
	 *
	 * @param WC_Order $order WooCommerce order.
	 *
	 * @return array|WP_Error
	 */
	public static function create_invoice(
		$order
	) {

		if ( ! $order instanceof WC_Order ) {

			return new WP_Error(
				'wtg_invalid_order',
				__(
					'Invalid WooCommerce order.',
					'webox-telegram-crypto-gateway'
				)
			);
		}

		$order_id = $order->get_id();

		if ( empty( $order_id ) ) {

			return new WP_Error(
				'wtg_invalid_order_id',
				__(
					'Invalid WooCommerce order ID.',
					'webox-telegram-crypto-gateway'
				)
			);
		}

		/*
		 * Make sure this gateway is being used
		 * with USD orders.
		 */
		$currency = strtoupper(
			(string) $order->get_currency()
		);

		if ( 'USD' !== $currency ) {

			self::log(
				'Order currency is not USD.',
				array(
					'order_id' => $order_id,
					'currency' => $currency,
				),
				'error'
			);

			return new WP_Error(
				'wtg_invalid_currency',
				sprintf(
					__(
						'Telegram Crypto payments require USD currency. Current order currency: %s',
						'webox-telegram-crypto-gateway'
					),
					$currency
				)
			);
		}

		/*
		 * WooCommerce total is already USD.
		 *
		 * Do NOT convert this manually to BTC/TON.
		 */
		$amount = wc_format_decimal(
			$order->get_total(),
			2
		);

		if (
			! is_numeric( $amount ) ||
			(float) $amount <= 0
		) {

			return new WP_Error(
				'wtg_invalid_amount',
				__(
					'Invalid order amount.',
					'webox-telegram-crypto-gateway'
				)
			);
		}

		/*
		 * Get accepted assets.
		 */
		$accepted_assets = self::get_accepted_assets();

		/*
		 * Generate unique payload.
		 */
		$payload = sprintf(
			'wc_order_%d_%s',
			$order_id,
			wp_generate_password(
				12,
				false,
				false
			)
		);

		/*
		 * Create USD fiat invoice.
		 */
		$request_body = array(
			'currency_type'   => 'fiat',
			'fiat'            => 'USD',
			'amount'          => $amount,
			'accepted_assets' => $accepted_assets,
			'description'     => sprintf(
				'WeboxAcademy Order #%d',
				$order_id
			),
			'payload'         => $payload,
			'allow_comments'  => false,
			'allow_anonymous' => true,
			'expires_in'      => 1800,
		);

		self::log(
			'Creating Crypto Pay invoice.',
			array(
				'order_id'        => $order_id,
				'amount_usd'      => $amount,
				'accepted_assets' => $accepted_assets,
			)
		);

		$invoice = self::request(
			'createInvoice',
			$request_body
		);

		if ( is_wp_error( $invoice ) ) {
			return $invoice;
		}

		if ( ! is_array( $invoice ) ) {

			return new WP_Error(
				'wtg_invalid_invoice',
				__(
					'Crypto Pay returned an invalid invoice.',
					'webox-telegram-crypto-gateway'
				)
			);
		}

		/*
		 * Invoice ID is required.
		 */
		if ( empty( $invoice['invoice_id'] ) ) {

			self::log(
				'Crypto Pay invoice response has no invoice ID.',
				array(
					'order_id' => $order_id,
					'invoice'  => self::sanitize_log_data(
						$invoice
					),
				),
				'error'
			);

			return new WP_Error(
				'wtg_missing_invoice_id',
				__(
					'Crypto Pay did not return an invoice ID.',
					'webox-telegram-crypto-gateway'
				)
			);
		}

		/*
		 * Store invoice ID.
		 */
		$order->update_meta_data(
			'_wtg_invoice_id',
			(string) $invoice['invoice_id']
		);

		/*
		 * Store invoice hash if available.
		 */
		if ( ! empty( $invoice['hash'] ) ) {

			$order->update_meta_data(
				'_wtg_invoice_hash',
				sanitize_text_field(
					(string) $invoice['hash']
				)
			);
		}

		/*
		 * Store exact payload.
		 */
		$order->update_meta_data(
			'_wtg_invoice_payload',
			$payload
		);

		/*
		 * Store currency information.
		 */
		$order->update_meta_data(
			'_wtg_invoice_currency_type',
			'fiat'
		);

		$order->update_meta_data(
			'_wtg_invoice_fiat',
			'USD'
		);

		$order->update_meta_data(
			'_wtg_invoice_amount_usd',
			$amount
		);

		$order->update_meta_data(
			'_wtg_invoice_accepted_assets',
			$accepted_assets
		);

		/*
		 * Backward-compatible metadata.
		 */
		$order->update_meta_data(
			'_wtg_invoice_asset',
			$accepted_assets
		);

		$order->update_meta_data(
			'_wtg_invoice_amount',
			$amount
		);

		/*
		 * If Crypto Pay already returned paid information,
		 * save it.
		 */
		if ( ! empty( $invoice['paid_asset'] ) ) {

			$order->update_meta_data(
				'_wtg_paid_asset',
				sanitize_text_field(
					(string) $invoice['paid_asset']
				)
			);
		}

		if ( ! empty( $invoice['paid_amount'] ) ) {

			$order->update_meta_data(
				'_wtg_paid_amount',
				sanitize_text_field(
					(string) $invoice['paid_amount']
				)
			);
		}

		$order->save();

		self::log(
			'Crypto Pay invoice created successfully.',
			array(
				'order_id'        => $order_id,
				'invoice_id'      => $invoice['invoice_id'],
				'amount_usd'      => $amount,
				'accepted_assets' => $accepted_assets,
				'has_bot_url'     => ! empty(
					$invoice['bot_invoice_url']
				),
				'has_web_url'     => ! empty(
					$invoice['web_app_invoice_url']
				),
				'has_mini_app'    => ! empty(
					$invoice['mini_app_invoice_url']
				),
			)
		);

		return $invoice;
	}


	/**
	 * Get invoices.
	 *
	 * @param int|string $invoice_id Optional invoice ID.
	 *
	 * @return array|WP_Error
	 */
	public static function get_invoice(
		$invoice_id = ''
	) {

		$args = array(
			'count' => 1,
		);

		if (
			! empty( $invoice_id ) &&
			is_numeric( $invoice_id )
		) {

			$args['invoice_ids'] = (string) absint(
				$invoice_id
			);
		}

		return self::request(
			'getInvoices',
			$args
		);
	}


	/**
	 * Delete an invoice.
	 *
	 * @param int|string $invoice_id Invoice ID.
	 *
	 * @return array|WP_Error
	 */
	public static function delete_invoice(
		$invoice_id
	) {

		if (
			empty( $invoice_id ) ||
			! is_numeric( $invoice_id )
		) {

			return new WP_Error(
				'wtg_invalid_invoice_id',
				__(
					'Invalid invoice ID.',
					'webox-telegram-crypto-gateway'
				)
			);
		}

		return self::request(
			'deleteInvoice',
			array(
				'invoice_id' => absint( $invoice_id ),
			)
		);
	}


	/**
	 * Get application balance.
	 *
	 * @return array|WP_Error
	 */
	public static function get_balance() {

		return self::request(
			'getBalance'
		);
	}


	/**
	 * Set webhook URL.
	 *
	 * @param string $url Webhook URL.
	 *
	 * @return array|WP_Error
	 */
	public static function set_webhook(
		$url
	) {

		$url = esc_url_raw(
			$url
		);

		if (
			empty( $url ) ||
			0 !== strpos(
				$url,
				'https://'
			)
		) {

			return new WP_Error(
				'wtg_invalid_webhook_url',
				__(
					'Webhook URL must start with https://',
					'webox-telegram-crypto-gateway'
				)
			);
		}

		return self::request(
			'setWebhook',
			array(
				'url' => $url,
			)
		);
	}


	/**
	 * Delete webhook.
	 *
	 * @return array|WP_Error
	 */
	public static function delete_webhook() {

		return self::request(
			'deleteWebhook'
		);
	}


	/**
	 * Get current webhook information.
	 *
	 * @return array|WP_Error
	 */
	public static function get_webhook_info() {

		return self::request(
			'getWebhookInfo'
		);
	}


	/**
	 * Sanitize sensitive information before logging.
	 *
	 * @param mixed $data Data.
	 *
	 * @return mixed
	 */
	private static function sanitize_log_data(
		$data
	) {

		if ( ! is_array( $data ) ) {
			return $data;
		}

		$sensitive_keys = array(
			'api_token',
			'token',
			'Crypto-Pay-API-Token',
			'webhook_secret',
		);

		foreach ( $sensitive_keys as $key ) {

			if ( isset( $data[ $key ] ) ) {
				$data[ $key ] = '***';
			}
		}

		return $data;
	}


	/**
	 * Write to WooCommerce logger.
	 *
	 * IMPORTANT:
	 * This method is PUBLIC because other gateway classes
	 * need to write diagnostic information to the same log.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @param string $level   Log level.
	 *
	 * @return void
	 */
	public static function log(
		$message,
		$context = array(),
		$level = 'info'
	) {

		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$settings = self::get_settings();

		/*
		 * Only log when Debug is enabled.
		 */
		if (
			isset( $settings['debug'] ) &&
			'yes' === $settings['debug']
		) {

			$logger = wc_get_logger();

			$logger->log(
				$level,
				$message,
				array(
					'source' => defined( 'WTG_LOG_SOURCE' )
						? WTG_LOG_SOURCE
						: 'webox-telegram-crypto-gateway',

					'context' => $context,
				)
			);
		}
	}
}