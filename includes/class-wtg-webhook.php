<?php
/**
 * Webox Telegram Crypto Gateway
 * Webhook Handler
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTG_Webhook {

	/**
	 * Initialize webhook listener.
	 */
	public static function init() {

		add_action(
			'init',
			array( __CLASS__, 'handle' ),
			1
		);
	}

	/**
	 * Handle incoming Crypto Pay webhook.
	 */
	public static function handle() {

		/*
		 * Only process our webhook endpoint.
		 */
		if ( ! isset( $_GET['wtg_webhook'] ) ) {
			return;
		}

		$webhook_flag = sanitize_text_field(
			wp_unslash( $_GET['wtg_webhook'] )
		);

		if ( '1' !== $webhook_flag ) {
			return;
		}

		/*
		 * Only POST requests are accepted.
		 */
		$request_method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper(
				sanitize_text_field(
					wp_unslash( $_SERVER['REQUEST_METHOD'] )
				)
			)
			: '';

		if ( 'POST' !== $request_method ) {

			status_header( 405 );

			header(
				'Allow: POST'
			);

			exit;
		}

		/*
		 * Get plugin settings.
		 */
		$settings = get_option(
			'woocommerce_wtg_telegram_crypto_settings',
			array()
		);

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$webhook_secret = isset( $settings['webhook_secret'] )
			? trim(
				(string) $settings['webhook_secret']
			)
			: '';

		/*
		 * Webhook secret is required.
		 */
		if ( empty( $webhook_secret ) ) {

			WTG_API::log(
				'error',
				'Webhook rejected: Webhook Secret is not configured.'
			);

			status_header( 500 );
			exit;
		}

		/*
		 * Verify secret URL parameter.
		 *
		 * Example:
		 * ?wtg_webhook=1&wtg_secret=YOUR_SECRET
		 */
		$request_secret = isset( $_GET['wtg_secret'] )
			? sanitize_text_field(
				wp_unslash( $_GET['wtg_secret'] )
			)
			: '';

		if (
			empty( $request_secret ) ||
			! hash_equals(
				$webhook_secret,
				$request_secret
			)
		) {

			WTG_API::log(
				'warning',
				'Webhook rejected: invalid webhook secret.'
			);

			status_header( 403 );
			exit;
		}

		/*
		 * Read raw request body.
		 *
		 * IMPORTANT:
		 * We must calculate the HMAC from the raw body,
		 * before modifying or decoding it.
		 */
		$raw_body = file_get_contents( 'php://input' );

		if (
			false === $raw_body ||
			'' === $raw_body
		) {

			WTG_API::log(
				'warning',
				'Webhook rejected: empty request body.'
			);

			status_header( 400 );
			exit;
		}

		/*
		 * Get Crypto Pay API token.
		 */
		$api_token = WTG_API::get_token();

		if ( empty( $api_token ) ) {

			WTG_API::log(
				'error',
				'Webhook rejected: Crypto Pay API token is missing.'
			);

			status_header( 500 );
			exit;
		}

		/*
		 * Crypto Pay Webhook Signature Verification.
		 *
		 * Secret key:
		 * SHA256(API_TOKEN) in binary form.
		 *
		 * Signature:
		 * HMAC-SHA256(raw request body, secret key)
		 */
		$signature = isset(
			$_SERVER['HTTP_CRYPTO_PAY_API_SIGNATURE']
		)
			? trim(
				sanitize_text_field(
					wp_unslash(
						$_SERVER['HTTP_CRYPTO_PAY_API_SIGNATURE']
					)
				)
			)
			: '';

		$secret_key = hash(
			'sha256',
			$api_token,
			true
		);

		$expected_signature = hash_hmac(
			'sha256',
			$raw_body,
			$secret_key
		);

		if (
			empty( $signature ) ||
			! hash_equals(
				$expected_signature,
				$signature
			)
		) {

			WTG_API::log(
				'warning',
				'Webhook rejected: invalid Crypto Pay signature.'
			);

			status_header( 403 );
			exit;
		}

		/*
		 * Decode JSON.
		 */
		$data = json_decode(
			$raw_body,
			true
		);

		if (
			! is_array( $data ) ||
			json_last_error() !== JSON_ERROR_NONE
		) {

			WTG_API::log(
				'warning',
				'Webhook rejected: invalid JSON payload.'
			);

			status_header( 400 );
			exit;
		}

		/*
		 * Check request_date.
		 *
		 * Crypto Pay includes request_date in webhook updates.
		 * We reject requests older than 10 minutes.
		 */
		if ( ! empty( $data['request_date'] ) ) {

			$request_timestamp = strtotime(
				$data['request_date']
			);

			if ( false !== $request_timestamp ) {

				$age = abs(
					time() - $request_timestamp
				);

				if ( $age > 600 ) {

					WTG_API::log(
						'warning',
						'Webhook rejected: request_date is older than 10 minutes.'
					);

					status_header( 403 );
					exit;
				}
			}
		}

		/*
		 * Read update type.
		 */
		$update_type = isset(
			$data['update_type']
		)
			? sanitize_text_field(
				$data['update_type']
			)
			: '';

		/*
		 * We only need invoice_paid.
		 *
		 * Other valid Crypto Pay events are ignored safely.
		 */
		if ( 'invoice_paid' !== $update_type ) {

			WTG_API::log(
				'info',
				'Webhook received and ignored. Update type: ' .
				$update_type
			);

			self::respond_ok();
		}

		/*
		 * Invoice payload.
		 */
		if (
			empty( $data['payload'] ) ||
			! is_array( $data['payload'] )
		) {

			WTG_API::log(
				'warning',
				'Webhook rejected: invoice payload is missing.'
			);

			status_header( 400 );
			exit;
		}

		$invoice = $data['payload'];

		/*
		 * Invoice ID.
		 */
		$invoice_id = isset(
			$invoice['invoice_id']
		)
			? absint(
				$invoice['invoice_id']
			)
			: 0;

		if ( ! $invoice_id ) {

			WTG_API::log(
				'warning',
				'Webhook rejected: invoice ID is missing.'
			);

			status_header( 400 );
			exit;
		}

		/*
		 * Invoice status.
		 */
		$invoice_status = isset(
			$invoice['status']
		)
			? sanitize_text_field(
				$invoice['status']
			)
			: '';

		if ( 'paid' !== $invoice_status ) {

			WTG_API::log(
				'warning',
				'Invoice webhook received but invoice status is not paid. Invoice ID: ' .
				$invoice_id
			);

			status_header( 200 );
			echo 'OK';
			exit;
		}

		/*
		 * Invoice payload.
		 *
		 * Example:
		 * wc_order_123_RANDOMSTRING
		 */
		$invoice_payload = isset(
			$invoice['payload']
		)
			? sanitize_text_field(
				$invoice['payload']
			)
			: '';

		if ( empty( $invoice_payload ) ) {

			WTG_API::log(
				'warning',
				'Webhook rejected: invoice payload is empty. Invoice ID: ' .
				$invoice_id
			);

			status_header( 400 );
			exit;
		}

		/*
		 * Extract WooCommerce order ID.
		 */
		if (
			! preg_match(
				'/^wc_order_(\d+)_/',
				$invoice_payload,
				$matches
			)
		) {

			WTG_API::log(
				'warning',
				'Webhook rejected: invalid WooCommerce order payload. Invoice ID: ' .
				$invoice_id
			);

			status_header( 400 );
			exit;
		}

		$order_id = absint(
			$matches[1]
		);

		if ( ! $order_id ) {

			status_header( 400 );
			exit;
		}

		/*
		 * Load WooCommerce order.
		 *
		 * This is HPOS-compatible.
		 */
		$order = wc_get_order(
			$order_id
		);

		if ( ! $order ) {

			WTG_API::log(
				'error',
				'Webhook order not found. Order ID: ' .
				$order_id .
				'. Invoice ID: ' .
				$invoice_id
			);

			status_header( 404 );
			exit;
		}

		/*
		 * Check whether payment was already processed.
		 *
		 * This protects against duplicate webhook delivery.
		 */
		$payment_processed = $order->get_meta(
			'_wtg_payment_processed',
			true
		);

		if ( 'yes' === $payment_processed ) {

			WTG_API::log(
				'info',
				'Duplicate webhook ignored for order #' .
				$order_id .
				'. Invoice ID: ' .
				$invoice_id
			);

			self::respond_ok();
		}

		/*
		 * Verify the invoice ID against the order.
		 */
		$saved_invoice_id = $order->get_meta(
			'_wtg_invoice_id',
			true
		);

		if (
			empty( $saved_invoice_id ) ||
			(string) $saved_invoice_id !==
			(string) $invoice_id
		) {

			WTG_API::log(
				'warning',
				'Invoice ID mismatch for order #' .
				$order_id .
				'. Expected: ' .
				$saved_invoice_id .
				'. Received: ' .
				$invoice_id
			);

			status_header( 403 );
			exit;
		}

		/*
		 * Verify payment asset.
		 */
		$saved_asset = $order->get_meta(
			'_wtg_invoice_asset',
			true
		);

		$invoice_asset = isset(
			$invoice['asset']
		)
			? strtoupper(
				sanitize_text_field(
					$invoice['asset']
				)
			)
			: '';

		if (
			! empty( $saved_asset ) &&
			! empty( $invoice_asset ) &&
			strtoupper( $saved_asset ) !==
			$invoice_asset
		) {

			WTG_API::log(
				'warning',
				'Asset mismatch for order #' .
				$order_id .
				'. Expected: ' .
				$saved_asset .
				'. Received: ' .
				$invoice_asset
			);

			status_header( 403 );
			exit;
		}

		/*
		 * Verify invoice amount.
		 */
		$saved_amount = $order->get_meta(
			'_wtg_invoice_amount',
			true
		);

		$invoice_amount = isset(
			$invoice['amount']
		)
			? (string) $invoice['amount']
			: '';

		if (
			! empty( $saved_amount ) &&
			! empty( $invoice_amount )
		) {

			if (
				! self::amounts_equal(
					$saved_amount,
					$invoice_amount
				)
			) {

				WTG_API::log(
					'warning',
					'Amount mismatch for order #' .
					$order_id .
					'. Expected: ' .
					$saved_amount .
					'. Received: ' .
					$invoice_amount
				);

				status_header( 403 );
				exit;
			}
		}

		/*
		 * Save payment information.
		 */
		$order->update_meta_data(
			'_wtg_payment_processed',
			'yes'
		);

		$order->update_meta_data(
			'_wtg_paid_invoice_id',
			(string) $invoice_id
		);

		$order->update_meta_data(
			'_wtg_paid_at',
			current_time( 'mysql', true )
		);

		if ( ! empty( $invoice['paid_amount'] ) ) {

			$order->update_meta_data(
				'_wtg_paid_amount',
				sanitize_text_field(
					$invoice['paid_amount']
				)
			);
		}

		if ( ! empty( $invoice['paid_asset'] ) ) {

			$order->update_meta_data(
				'_wtg_paid_asset',
				sanitize_text_field(
					$invoice['paid_asset']
				)
			);
		}

		if ( ! empty( $invoice['hash'] ) ) {

			$order->update_meta_data(
				'_wtg_payment_hash',
				sanitize_text_field(
					$invoice['hash']
				)
			);
		}

		$order->save();

		/*
		 * Complete WooCommerce payment.
		 *
		 * This triggers WooCommerce's normal payment
		 * completion workflow and digital download permissions.
		 */
		$order->payment_complete(
			(string) $invoice_id
		);

		/*
		 * Add order note.
		 */
		$order->add_order_note(
			'Payment confirmed via Telegram Crypto. ' .
			'Invoice ID: ' .
			$invoice_id .
			'.'
		);

		/*
		 * Log success.
		 */
		WTG_API::log(
			'info',
			'Telegram Crypto payment completed successfully. ' .
			'Order #' .
			$order_id .
			', Invoice ID: ' .
			$invoice_id .
			'.'
		);

		/*
		 * Respond to Crypto Pay.
		 */
		self::respond_ok();
	}

	/**
	 * Get webhook secret.
	 *
	 * @return string
	 */
	private static function get_webhook_secret() {

		$settings = get_option(
			'woocommerce_wtg_telegram_crypto_settings',
			array()
		);

		if ( ! is_array( $settings ) ) {
			return '';
		}

		return isset(
			$settings['webhook_secret']
		)
			? trim(
				(string) $settings['webhook_secret']
			)
			: '';
	}

	/**
	 * Compare decimal amounts.
	 *
	 * BCMath is used when available.
	 *
	 * @param string $a First amount.
	 * @param string $b Second amount.
	 * @return bool
	 */
	private static function amounts_equal(
		$a,
		$b
	) {

		$a = (string) $a;
		$b = (string) $b;

		if ( function_exists( 'bccomp' ) ) {

			return 0 === bccomp(
				$a,
				$b,
				8
			);
		}

		/*
		 * Fallback for hosts without BCMath.
		 */
		return number_format(
			(float) $a,
			8,
			'.',
			''
		) === number_format(
			(float) $b,
			8,
			'.',
			''
		);
	}

	/**
	 * Return successful response.
	 */
	private static function respond_ok() {

		status_header( 200 );

		header(
			'Content-Type: text/plain; charset=utf-8'
		);

		echo 'OK';

		exit;
	}
}