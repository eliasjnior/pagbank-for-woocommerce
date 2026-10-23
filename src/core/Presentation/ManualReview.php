<?php
/**
 * Manual review of credit card orders charged without 3DS.
 *
 * @package PagBank_WooCommerce\Presentation
 */

namespace PagBank_WooCommerce\Presentation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WC_Admin_Meta_Boxes;
use WC_Order;
use WP_Error;
use WP_Post;

/**
 * Class ManualReview.
 *
 * The charge is only pre-authorized; the store approves (captures) or rejects
 * (releases) it from the order screen before PagBank's capture deadline.
 */
class ManualReview {

	const STATUS_META         = '_pagbank_review_status';
	const CAPTURE_BEFORE_META = '_pagbank_capture_before';
	const AMOUNT_META         = '_pagbank_authorized_amount';
	const EXPIRE_ACTION       = 'pagbank_review_expire';
	const ACTION_GROUP        = 'pagbank';

	const STATUS_PENDING  = 'pending';
	const STATUS_APPROVED = 'approved';
	const STATUS_REJECTED = 'rejected';
	const STATUS_EXPIRED  = 'expired';

	/**
	 * Instance.
	 */
	private static ?ManualReview $instance = null;

	/**
	 * Get instance.
	 */
	public static function get_instance(): ManualReview {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * ManualReview constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );
		// After WooCommerce saves the order form, which would otherwise overwrite the new status.
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'handle_decision' ), 100 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'release_on_cancel' ) );
		add_action( 'woocommerce_payment_complete', array( $this, 'close_on_payment_complete' ) );
		add_action( self::EXPIRE_ACTION, array( $this, 'expire' ) );
	}

	/**
	 * Put a pre-authorized order on hold until the store reviews it.
	 *
	 * @param WC_Order $order  Order object.
	 * @param array    $charge Charge returned by PagBank.
	 */
	public static function start( WC_Order $order, array $charge ): void {
		$capture_before = isset( $charge['payment_method']['capture_before'] )
			? strtotime( (string) $charge['payment_method']['capture_before'] )
			: false;

		$amount = Helpers::format_money_from_cents( (int) $charge['amount']['value'] );

		$order->update_meta_data( self::STATUS_META, self::STATUS_PENDING );
		$order->update_meta_data( self::AMOUNT_META, $amount );

		if ( $capture_before ) {
			$order->update_meta_data( self::CAPTURE_BEFORE_META, $capture_before );
		}

		$order->save_meta_data();

		$note = $capture_before
			? sprintf(
				/* translators: %s: capture deadline date and time. */
				__( 'Pagamento pré-autorizado sem 3DS, aguardando sua revisão. Aprove ou recuse até %s; depois disso o PagBank cancela a pré-autorização.', 'pagbank-for-woocommerce' ),
				self::format_date( $capture_before )
			)
			: __( 'Pagamento pré-autorizado sem 3DS, aguardando sua revisão.', 'pagbank-for-woocommerce' );

		$order->update_status( 'on-hold', $note );

		if ( $capture_before ) {
			// A margin past the deadline, so the check sees PagBank's final state.
			WC()->queue()->schedule_single(
				$capture_before + 10 * MINUTE_IN_SECONDS,
				self::EXPIRE_ACTION,
				array( 'order_id' => $order->get_id() ),
				self::ACTION_GROUP
			);
		}
	}

	/**
	 * Whether the order still waits for the store's decision.
	 *
	 * @param WC_Order $order Order object.
	 */
	public static function is_pending( WC_Order $order ): bool {
		return self::STATUS_PENDING === $order->get_meta( self::STATUS_META );
	}

	/**
	 * Add the review meta box to orders waiting for a decision.
	 *
	 * @param string $screen_id     Screen ID.
	 * @param mixed  $post_or_order Post or order being edited.
	 */
	public function add_meta_box( $screen_id = '', $post_or_order = null ): void {
		$order = self::resolve_order( $post_or_order );

		if ( ! $order || ! self::has_review( $order ) ) {
			return;
		}

		add_meta_box(
			'pagbank-manual-review',
			__( 'PagBank: revisão do pagamento', 'pagbank-for-woocommerce' ),
			array( $this, 'render_meta_box' ),
			$screen_id,
			'side',
			'high'
		);
	}

	/**
	 * Render the review meta box.
	 *
	 * @param mixed $post_or_order Post or order being edited.
	 */
	public function render_meta_box( $post_or_order ): void {
		$order = self::resolve_order( $post_or_order );

		if ( ! $order ) {
			return;
		}

		$status         = (string) $order->get_meta( self::STATUS_META );
		$capture_before = (int) $order->get_meta( self::CAPTURE_BEFORE_META );
		$amount         = (float) $order->get_meta( self::AMOUNT_META );

		if ( self::STATUS_PENDING !== $status ) {
			$labels = array(
				self::STATUS_APPROVED => __( 'Pagamento aprovado e capturado.', 'pagbank-for-woocommerce' ),
				self::STATUS_REJECTED => __( 'Pagamento recusado; a pré-autorização foi liberada.', 'pagbank-for-woocommerce' ),
				self::STATUS_EXPIRED  => __( 'A pré-autorização expirou sem aprovação.', 'pagbank-for-woocommerce' ),
			);

			echo '<p>' . esc_html( $labels[ $status ] ?? $status ) . '</p>';
			return;
		}

		echo '<p>' . esc_html__( 'O cartão não é elegível ao 3DS e o pagamento foi apenas pré-autorizado. Nada foi cobrado ainda.', 'pagbank-for-woocommerce' ) . '</p>';

		echo '<p><strong>' . esc_html__( 'Valor:', 'pagbank-for-woocommerce' ) . '</strong> ' . wp_kses_post( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ) . '</p>';

		if ( $capture_before ) {
			echo '<p><strong>' . esc_html__( 'Prazo:', 'pagbank-for-woocommerce' ) . '</strong> ' . esc_html( self::format_date( $capture_before ) );

			if ( $capture_before > time() ) {
				/* translators: %s: time left, e.g. "3 days". */
				echo '<br><small>' . esc_html( sprintf( __( 'Restam %s.', 'pagbank-for-woocommerce' ), human_time_diff( time(), $capture_before ) ) ) . '</small>';
			}

			echo '</p>';
		}

		wp_nonce_field( 'pagbank_manual_review', 'pagbank_manual_review_nonce' );

		echo '<p>';
		echo '<button type="submit" class="button button-primary" name="pagbank_manual_review" value="approve">' . esc_html__( 'Aprovar e capturar', 'pagbank-for-woocommerce' ) . '</button> ';
		echo '<button type="submit" class="button" name="pagbank_manual_review" value="reject">' . esc_html__( 'Recusar', 'pagbank-for-woocommerce' ) . '</button>';
		echo '</p>';
	}

	/**
	 * Handle the approve/reject buttons submitted with the order form.
	 *
	 * @param int $order_id Order ID.
	 */
	public function handle_decision( $order_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified right below.
		$decision = isset( $_POST['pagbank_manual_review'] )
			? sanitize_key( wp_unslash( $_POST['pagbank_manual_review'] ) )
			: '';
		$nonce    = isset( $_POST['pagbank_manual_review_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['pagbank_manual_review_nonce'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// WooCommerce checks the user can edit the order before firing this hook.
		if ( '' === $decision || ! wp_verify_nonce( $nonce, 'pagbank_manual_review' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || ! self::is_pending( $order ) ) {
			return;
		}

		$result = 'approve' === $decision ? self::approve( $order ) : self::reject( $order );

		if ( is_wp_error( $result ) ) {
			WC_Admin_Meta_Boxes::add_error( $result->get_error_message() );
		}
	}

	/**
	 * Capture the pre-authorized charge and complete the order.
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return true|WP_Error
	 */
	public static function approve( WC_Order $order ) {
		$charge_id = (string) $order->get_meta( '_pagbank_charge_id' );
		$amount    = (float) $order->get_meta( self::AMOUNT_META );
		$response  = self::get_api( $order )->capture( $charge_id, $amount, md5( 'capture|' . $charge_id ) );

		if ( is_wp_error( $response ) || 'PAID' !== ( $response['status'] ?? '' ) ) {
			$message = __( 'Não foi possível capturar o pagamento no PagBank. Verifique o prazo da pré-autorização e tente novamente.', 'pagbank-for-woocommerce' );
			$order->add_order_note( $message );

			return new WP_Error( 'pagbank_review_capture_failed', $message );
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: user display name. */
				__( 'Pagamento sem 3DS aprovado e capturado por %s.', 'pagbank-for-woocommerce' ),
				wp_get_current_user()->display_name
			)
		);

		// Closes the review through close_on_payment_complete().
		$order->payment_complete( $charge_id );

		do_action( 'pagbank_order_completed', $order );

		return true;
	}

	/**
	 * Release the pre-authorization and cancel the order.
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return true|WP_Error
	 */
	public static function reject( WC_Order $order ) {
		$released = self::release( $order );

		if ( is_wp_error( $released ) ) {
			return $released;
		}

		$order->update_status(
			'cancelled',
			sprintf(
				/* translators: %s: user display name. */
				__( 'Pagamento sem 3DS recusado por %s; a pré-autorização foi liberada.', 'pagbank-for-woocommerce' ),
				wp_get_current_user()->display_name
			)
		);

		return true;
	}

	/**
	 * Release the pre-authorization when the order is cancelled another way.
	 *
	 * @param int $order_id Order ID.
	 */
	public function release_on_cancel( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || ! self::is_pending( $order ) ) {
			return;
		}

		if ( is_wp_error( self::release( $order ) ) ) {
			$order->add_order_note(
				__( 'Não foi possível liberar a pré-autorização no PagBank. Cancele a cobrança pelo painel do PagBank ou aguarde o prazo expirar.', 'pagbank-for-woocommerce' )
			);
		}
	}

	/**
	 * Close the review once the order is paid, whichever path captured it.
	 *
	 * @param int $order_id Order ID.
	 */
	public function close_on_payment_complete( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( $order instanceof WC_Order && self::is_pending( $order ) ) {
			self::close( $order, self::STATUS_APPROVED );
		}
	}

	/**
	 * Cancel the order once the capture deadline has passed without a decision.
	 *
	 * @param int $order_id Order ID.
	 */
	public function expire( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || ! self::is_pending( $order ) ) {
			return;
		}

		$charge_id = (string) $order->get_meta( '_pagbank_charge_id' );
		$charge    = self::get_api( $order )->get_charge( $charge_id );

		// Captured from the PagBank dashboard without the webhook reaching the store.
		if ( ! is_wp_error( $charge ) && 'PAID' === ( $charge['status'] ?? '' ) ) {
			$order->payment_complete( $charge_id );
			return;
		}

		self::close( $order, self::STATUS_EXPIRED );

		$order->update_status( 'cancelled', __( 'A pré-autorização do PagBank expirou sem aprovação.', 'pagbank-for-woocommerce' ) );
	}

	/**
	 * Release the pre-authorization at PagBank.
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @return true|WP_Error
	 */
	private static function release( WC_Order $order ) {
		$charge_id = (string) $order->get_meta( '_pagbank_charge_id' );
		$amount    = (float) $order->get_meta( self::AMOUNT_META );
		$api       = self::get_api( $order );
		$response  = $api->refund( $charge_id, $amount, ApiHelpers::get_refund_idempotency_key( $charge_id, $amount ) );
		$status    = self::STATUS_REJECTED;

		if ( is_wp_error( $response ) ) {
			// PagBank already released it, e.g. when its deadline passed.
			$charge = $api->get_charge( $charge_id );

			if ( is_wp_error( $charge ) || 'CANCELED' !== ( $charge['status'] ?? '' ) ) {
				return new WP_Error(
					'pagbank_review_release_failed',
					__( 'Não foi possível liberar a pré-autorização no PagBank. Tente novamente.', 'pagbank-for-woocommerce' )
				);
			}

			$capture_before = (int) $order->get_meta( self::CAPTURE_BEFORE_META );
			$is_expired     = $capture_before && time() >= $capture_before;
			$status         = $is_expired ? self::STATUS_EXPIRED : self::STATUS_REJECTED;
		}

		self::close( $order, $status );

		return true;
	}

	/**
	 * Record the review outcome and drop the pending expiry check.
	 *
	 * @param WC_Order $order  Order object.
	 * @param string   $status Final review status.
	 */
	private static function close( WC_Order $order, string $status ): void {
		$order->update_meta_data( self::STATUS_META, $status );
		$order->save_meta_data();

		WC()->queue()->cancel_all( self::EXPIRE_ACTION, array( 'order_id' => $order->get_id() ), self::ACTION_GROUP );
	}

	/**
	 * Whether the order went through manual review.
	 *
	 * @param WC_Order $order Order object.
	 */
	private static function has_review( WC_Order $order ): bool {
		return '' !== (string) $order->get_meta( self::STATUS_META );
	}

	/**
	 * API client for the environment the order was paid in.
	 *
	 * @param WC_Order $order Order object.
	 */
	private static function get_api( WC_Order $order ): Api {
		$environment = (string) $order->get_meta( '_pagbank_environment' );

		return new Api( '' !== $environment ? $environment : 'production', 'pagbank_credit_card' );
	}

	/**
	 * Resolve the order from a meta box callback argument.
	 *
	 * @param mixed $post_or_order Post or order.
	 */
	private static function resolve_order( $post_or_order ): ?WC_Order {
		if ( $post_or_order instanceof WP_Post ) {
			$post_or_order = $post_or_order->ID;
		}

		$order = Hooks::resolve_order( $post_or_order );

		return $order && 'pagbank_credit_card' === $order->get_payment_method() ? $order : null;
	}

	/**
	 * Format a timestamp with the site's date and time formats.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	private static function format_date( int $timestamp ): string {
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
