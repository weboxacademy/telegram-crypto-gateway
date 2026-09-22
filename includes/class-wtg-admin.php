<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTG_Admin {

	public static function init() {

		add_action(
			'wp_ajax_wtg_test_connection',
			array(
				__CLASS__,
				'test_connection',
			)
		);

		add_action(
			'admin_enqueue_scripts',
			array(
				__CLASS__,
				'assets',
			)
		);

		add_action(
			'woocommerce_update_options_payment_gateways_wtg_telegram_crypto',
			array(
				__CLASS__,
				'after_settings_saved',
			),
			20
		);

		add_action(
			'woocommerce_admin_order_data_after_order_details',
			array(
				__CLASS__,
				'order_details',
			)
		);
	}

	/**
	 * Admin assets.
	 */
	public static function assets( $hook ) {

		if (
			'woocommerce_page_wc-settings' !== $hook
		) {
			return;
		}

		wp_enqueue_script(
			'wtg-admin',
			WTG_URL . 'assets/admin.js',
			array( 'jquery' ),
			WTG_VERSION,
			true
		);

		wp_localize_script(
			'wtg-admin',
			'WTGAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce(
					'wtg_test_connection'
				),
			)
		);
	}

	/**
	 * Test API connection.
	 */
	public static function test_connection() {

		if (
			! current_user_can(
				'manage_woocommerce'
			)
		) {

			wp_send_json_error(
				array(
					'message' => 'Permission denied.',
				),
				403
			);
		}

		check_ajax_referer(
			'wtg_test_connection',
			'nonce'
		);

		$result = WTG_API::get_me();

		if ( is_wp_error( $result ) ) {

			wp_send_json_error(
				array(
					'message' =>
						$result->get_error_message(),
				)
			);
		}

		$name = ! empty( $result['name'] )
			? $result['name']
			: 'Crypto Pay App';

		$username =
			! empty(
				$result['payment_processing_bot_username']
			)
				? $result[
					'payment_processing_bot_username'
				]
				: '';

		$message =
			'Connection successful. App: ' .
			$name;

		if ( $username ) {
			$message .= ' (@' . $username . ')';
		}

		wp_send_json_success(
			array(
				'message' => $message,
			)
		);
	}

	/**
	 * Display webhook information after settings.
	 */
	public static function after_settings_saved() {

		// Nothing needed here.
	}

	/**
	 * Order payment information.
	 */
	public static function order_details( $order ) {

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$invoice_id =
			$order->get_meta(
				'_wtg_invoice_id',
				true
			);

		if ( empty( $invoice_id ) ) {
			return;
		}

		$asset =
			$order->get_meta(
				'_wtg_invoice_asset',
				true
			);

		$amount =
			$order->get_meta(
				'_wtg_invoice_amount',
				true
			);

		$processed =
			$order->get_meta(
				'_wtg_payment_processed',
				true
			);

		?>
		<div class="wtg-order-info" style="margin-top:20px;">
			<h4><?php echo esc_html( 'Telegram Crypto Payment' ); ?></h4>

			<p>
				<strong>Invoice ID:</strong>
				<?php echo esc_html( $invoice_id ); ?>
			</p>

			<?php if ( $amount ) : ?>
				<p>
					<strong>Invoice Amount:</strong>
					<?php echo esc_html( $amount . ' ' . $asset ); ?>
				</p>
			<?php endif; ?>

			<p>
				<strong>Payment Status:</strong>
				<?php
				echo 'yes' === $processed
					? esc_html( 'Paid' )
					: esc_html( 'Pending' );
				?>
			</p>
		</div>
		<?php
	}
}