<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Gateway_WTG_Telegram_Crypto extends WC_Payment_Gateway {

	public function __construct() {

		$this->id = 'wtg_telegram_crypto';

		$this->icon = WTG_URL . 'assets/telegram.svg';

		$this->has_fields = false;

		$this->method_title = 'Pay with Telegram Crypto';

		$this->method_description =
			'Accept cryptocurrency payments through Telegram Crypto Pay.';

		$this->supports = array(
			'products',
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->title = $this->get_option(
			'title',
			'Pay with Telegram Crypto'
		);

		$this->description = $this->get_option(
			'description',
			'Secure cryptocurrency payment via Telegram Crypto Bot.'
		);

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);
	}

	/**
	 * Admin fields.
	 */
	public function init_form_fields() {

		$this->form_fields = array(

			'enabled' => array(
				'title'   => 'Enable / Disable',
				'type'    => 'checkbox',
				'label'   => 'Enable Pay with Telegram Crypto',
				'default' => 'no',
			),

			'title' => array(
				'title'   => 'Title',
				'type'    => 'text',
				'default' => 'Pay with Telegram Crypto',
			),

			'description' => array(
				'title'   => 'Description',
				'type'    => 'textarea',
				'default' =>
					'Secure cryptocurrency payment via Telegram Crypto Bot.',
			),

			'api_token' => array(
				'title'       => 'Crypto Pay API Token',
				'type'        => 'password',
				'description' =>
					'Create an app in Crypto Bot → Crypto Pay → My Apps and paste the API token here.',
				'default'     => '',
				'desc_tip'    => true,
			),

			'asset' => array(
				'title'   => 'Payment Asset',
				'type'    => 'select',
				'default' => 'USDT',
				'options' => array(
					'USDT' => 'USDT',
					'TON'  => 'TON',
					'BTC'  => 'BTC',
				),
			),

			'webhook_secret' => array(
				'title'       => 'Webhook Secret',
				'type'        => 'password',
				'description' =>
					'Use a long random secret in the webhook URL. This value is also used to verify the webhook request signature.',
				'default'     => '',
				'desc_tip'    => true,
			),

			'debug' => array(
				'title'   => 'Debug Logging',
				'type'    => 'checkbox',
				'label'   => 'Enable detailed gateway logging',
				'default' => 'no',
			),
		);
	}

	/**
	 * Payment processing.
	 */
	public function process_payment( $order_id ) {

		$order = wc_get_order( $order_id );

		if ( ! $order ) {

			wc_add_notice(
				'Unable to load the order.',
				'error'
			);

			return array(
				'result' => 'failure',
			);
		}

		/**
		 * Prevent creating another invoice for an already paid order.
		 */
		if ( $order->is_paid() ) {

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		/**
		 * Create Crypto Pay invoice.
		 */
		$result = WTG_API::create_invoice( $order );

		if ( is_wp_error( $result ) ) {

			$error_message = $result->get_error_message();

			$order->add_order_note(
				'Telegram Crypto invoice creation failed: ' . $error_message
			);

			WTG_API::log(
				'error',
				'Invoice creation failed for order #' .
				$order_id .
				': ' .
				$error_message
			);

			wc_add_notice(
				'We could not create the cryptocurrency payment. Please try again.',
				'error'
			);

			return array(
				'result' => 'failure',
			);
		}

		/**
		 * Make sure we have an invoice ID.
		 */
		if (
			empty( $result['invoice_id'] ) &&
			empty( $result['id'] )
		) {

			$order->add_order_note(
				'Telegram Crypto invoice was returned without an invoice ID.'
			);

			WTG_API::log(
				'error',
				'Crypto Pay returned an invoice without an invoice ID for order #' .
				$order_id
			);

			wc_add_notice(
				'Crypto payment could not be initialized. Please try again.',
				'error'
			);

			return array(
				'result' => 'failure',
			);
		}

		/**
		 * Store invoice ID if necessary.
		 */
		$invoice_id = ! empty( $result['invoice_id'] )
			? $result['invoice_id']
			: $result['id'];

		/**
		 * Put order into pending status.
		 */
		if ( ! $order->has_status( 'pending' ) ) {

			$order->update_status(
				'pending',
				'Waiting for Telegram Crypto payment.'
			);
		}

		$order->add_order_note(
			'Telegram Crypto invoice created. Invoice ID: ' . $invoice_id
		);

		/**
		 * ---------------------------------------------------------
		 * PAYMENT URL
		 * ---------------------------------------------------------
		 *
		 * Preferred:
		 * bot_invoice_url
		 *
		 * This is the Crypto Bot invoice link that opens the
		 * payment flow through Telegram/CryptoBot.
		 *
		 * We deliberately prefer this over web_app_invoice_url
		 * because the desired checkout flow is:
		 *
		 * Website
		 *   ↓
		 * Crypto Bot
		 *   ↓
		 * Invoice
		 *   ↓
		 * Select crypto
		 *   ↓
		 * Pay Now
		 *
		 * ---------------------------------------------------------
		 */

		$redirect = '';

		/**
		 * 1. Crypto Bot invoice URL.
		 */
		if ( ! empty( $result['bot_invoice_url'] ) ) {

			$redirect = $result['bot_invoice_url'];

		}

		/**
		 * 2. Older/legacy payment URL.
		 */
		elseif ( ! empty( $result['pay_url'] ) ) {

			$redirect = $result['pay_url'];

		}

		/**
		 * 3. Web invoice URL.
		 *
		 * Fallback only.
		 */
		elseif ( ! empty( $result['web_app_invoice_url'] ) ) {

			$redirect = $result['web_app_invoice_url'];

		}

		/**
		 * 4. Mini App invoice URL.
		 *
		 * Fallback only.
		 */
		elseif ( ! empty( $result['mini_app_invoice_url'] ) ) {

			$redirect = $result['mini_app_invoice_url'];

		}

		/**
		 * No payment URL.
		 */
		if ( empty( $redirect ) ) {

			$order->add_order_note(
				'Crypto Pay invoice was created, but no payment URL was returned.'
			);

			WTG_API::log(
				'error',
				'Invoice #' .
				$invoice_id .
				' created for order #' .
				$order_id .
				' but no payment URL was returned.'
			);

			wc_add_notice(
				'Crypto payment URL was not returned by Crypto Pay. Please try again.',
				'error'
			);

			return array(
				'result' => 'failure',
			);
		}

		/**
		 * Log which URL type was selected.
		 */
		$url_type = 'unknown';

		if ( ! empty( $result['bot_invoice_url'] ) ) {

			$url_type = 'bot_invoice_url';

		} elseif ( ! empty( $result['pay_url'] ) ) {

			$url_type = 'pay_url';

		} elseif ( ! empty( $result['web_app_invoice_url'] ) ) {

			$url_type = 'web_app_invoice_url';

		} elseif ( ! empty( $result['mini_app_invoice_url'] ) ) {

			$url_type = 'mini_app_invoice_url';
		}

		WTG_API::log(
			'info',
			'Invoice created successfully for order #' .
			$order_id .
			'. Invoice ID: ' .
			$invoice_id .
			'. Redirect type: ' .
			$url_type
		);

		/**
		 * Save redirect URL in order metadata for debugging.
		 *
		 * This is not the API token and contains no secret.
		 */
		$order->update_meta_data(
			'_wtg_payment_url_type',
			$url_type
		);

		$order->save();

		/**
		 * Redirect customer to Crypto Bot invoice.
		 */
		return array(
			'result'   => 'success',
			'redirect' => esc_url_raw( $redirect ),
		);
	}
}