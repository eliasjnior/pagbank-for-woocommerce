<?php
/**
 * Pix payment gateway.
 *
 * @package PagBank_WooCommerce\Gateways
 */

namespace PagBank_WooCommerce\Gateways;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Exception;
use PagBank_WooCommerce\Gateways\Traits\ReactSettingsTrait;
use PagBank_WooCommerce\Presentation\Api;
use PagBank_WooCommerce\Presentation\ApiHelpers;
use PagBank_WooCommerce\Presentation\Connect;
use PagBank_WooCommerce\Presentation\Helpers;
use PagBank_WooCommerce\Presentation\WebhookHandler;
use WC_Order;
use WC_Payment_Gateway;
use WP_Error;

/**
 * Class PixPaymentGateway.
 */
class PixPaymentGateway extends WC_Payment_Gateway {

	use ReactSettingsTrait;

	/**
	 * Api instance.
	 */
	private Api $api;

	/**
	 * Connect instance.
	 */
	public Connect $connect;

	/**
	 * Environment.
	 */
	public string $environment;

	/**
	 * Logs enabled.
	 *
	 * @var string yes|no.
	 */
	private string $logs_enabled;

	/**
	 * PixPaymentGateway constructor.
	 */
	public function __construct() {
		$this->id           = 'pagbank_pix';
		$this->icon         = plugins_url( 'dist/images/icons/pix.png', PAGBANK_WOOCOMMERCE_FILE_PATH );
		$this->method_title = __( 'PagBank Pix', 'pagbank-for-woocommerce' );
		// phpcs:ignore Generic.Files.LineLength -- Translation string cannot be split.
		$this->method_description = __( 'Aceite pagamentos via Pix com QR code e código copia e cola exibidos na página do pedido e no e-mail do cliente. Tempo de expiração configurável, confirmação automática via webhook e reembolso online total ou parcial. É necessário ter uma chave Pix cadastrada na sua conta PagBank.', 'pagbank-for-woocommerce' );
		$this->description        = $this->get_option( 'description' );
		$this->has_fields         = ! empty( $this->description );
		$this->supports           = array(
			'products',
			'refunds',
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->title        = $this->get_option( 'title' );
		$this->environment  = $this->get_option( 'environment' );
		$this->logs_enabled = $this->get_option( 'logs_enabled' );
		$this->connect      = new Connect( $this->environment );
		$this->api          = new Api( $this->environment, $this->logs_enabled === 'yes' ? $this->id : null );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'woocommerce_view_order', array( $this, 'view_order_page' ), 10, 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );

		$this->is_available_validation();
	}

	/**
	 * Initialize form fields.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'            => array(
				'title'   => __( 'Habilitar/Desabilitar', 'pagbank-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Habilitar Pix', 'pagbank-for-woocommerce' ),
				'default' => 'no',
			),
			'environment'        => array(
				'title'       => __( 'Ambiente', 'pagbank-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Isso irá definir o ambiente de testes ou produção.', 'pagbank-for-woocommerce' ),
				'default'     => 'sandbox',
				'options'     => array(
					'sandbox'    => __( 'Ambiente de testes', 'pagbank-for-woocommerce' ),
					'production' => __( 'Produção', 'pagbank-for-woocommerce' ),
				),
				'desc_tip'    => true,
			),
			'pagbank_connect'    => array(
				'title'       => __( 'Conta PagBank', 'pagbank-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Conecte a sua conta PagBank para aceitar pagamentos.', 'pagbank-for-woocommerce' ),
			),
			'title'              => array(
				'title'       => __( 'Título', 'pagbank-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Isso irá controlar o título que o cliente verá durante o checkout.', 'pagbank-for-woocommerce' ),
				'default'     => __( 'Pix', 'pagbank-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'        => array(
				'title'       => __( 'Descrição', 'pagbank-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Isso irá controlar a descrição que o cliente verá durante o checkout.', 'pagbank-for-woocommerce' ),
				'default'     => __( 'O código Pix será gerado assim que você finalizar o pedido.', 'pagbank-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'expiration_minutes' => array(
				'title'             => __( 'Expiração em minutos', 'pagbank-for-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'Isso irá controlar o tempo em minutos que o Pix será válido.', 'pagbank-for-woocommerce' ),
				'default'           => '15',
				'desc_tip'          => true,
				'custom_attributes' => array(
					'min' => 1,
				),
			),
			'logs_enabled'       => array(
				'title'       => __( 'Logs para depuração', 'pagbank-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Ativar logs', 'pagbank-for-woocommerce' ),
				'description' => __( 'Isso irá ativar os logs para depuração para auxiliar em caso de suporte.', 'pagbank-for-woocommerce' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Get payment method title.
	 *
	 * @return string The title.
	 */
	public function get_title(): string {
		if ( is_admin() ) {
			$screen = get_current_screen();

			if ( $screen->id === 'woocommerce_page_wc-orders' ) {
				return $this->method_title;
			}
		}

		return apply_filters( 'woocommerce_gateway_title', $this->title, $this->id );
	}

	/**
	 * Process order payment.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @throws Exception When an error occurs.
	 */
	public function process_payment( $order_id ): array {
		try {
			$order                 = wc_get_order( $order_id );
			$expiration_in_minutes = $this->get_option( 'expiration_minutes' );
			$data                  = ApiHelpers::get_pix_payment_api_data( $this, $order, $expiration_in_minutes );
			$response              = $this->api->create_order( $data, ApiHelpers::get_create_order_idempotency_key( $data, $order->get_id(), ApiHelpers::get_request_submission_id() ) );

			$api_error = __( 'Houve um erro ao processar o pagamento. Tente novamente.', 'pagbank-for-woocommerce' );

			if ( is_wp_error( $response ) ) {
				Helpers::add_payment_error_notice( $api_error );

				return array(
					'result'  => 'failure',
					'message' => $api_error,
				);
			}

			$charge = $response['charges'][0] ?? null;

			if ( ! is_array( $charge ) ) {
				throw new Exception( $api_error );
			}

			$charge_status       = isset( $charge['status'] ) ? (string) $charge['status'] : '';
			$unavailable_message = __( 'Não foi possível gerar o Pix. Tente novamente.', 'pagbank-for-woocommerce' );

			// Reuse the webhook mapping so both paths read charge statuses the same way.
			switch ( WebhookHandler::map_charge_status( $charge_status ) ) {
				case 'on-hold':
					if ( ! $this->charge_has_pix_data( $charge ) ) {
						$this->save_payment_response_meta_data( $order, $charge );

						throw new Exception( $unavailable_message );
					}

					$this->save_order_meta_data( $order, $response, $data );
					$this->save_payment_response_meta_data( $order, $charge );
					$order->update_status( 'on-hold', __( 'Aguardando pagamento do Pix.', 'pagbank-for-woocommerce' ) );
					break;
				case 'completed':
					// No QR code check here: a paid order must never fail the checkout.
					$this->save_order_meta_data( $order, $response, $data );
					$this->save_payment_response_meta_data( $order, $charge );
					$order->payment_complete( isset( $charge['id'] ) ? (string) $charge['id'] : '' );
					break;
				case 'failed':
					$this->save_payment_response_meta_data( $order, $charge );

					throw new Exception( __( 'O pagamento foi recusado.', 'pagbank-for-woocommerce' ) );
				default:
					$this->save_payment_response_meta_data( $order, $charge );

					throw new Exception( $unavailable_message );
			}

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		} catch ( Exception $e ) {
			Helpers::add_payment_error_notice( $e->getMessage() );

			return array(
				'result'  => 'failure',
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Process a refund.
	 *
	 * @param int         $order_id Order ID.
	 * @param string|null $amount   Refund amount.
	 * @param string      $reason   Refund reason.
	 *
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order                       = wc_get_order( $order_id );
		$should_process_order_refund = apply_filters( 'pagbank_should_process_order_refund', true, $order );

		if ( is_wp_error( $should_process_order_refund ) ) {
			return $should_process_order_refund;
		}

		if ( $should_process_order_refund === true ) {
			return ApiHelpers::process_order_refund( $this->api, $order, $amount, $reason );
		}

		return new WP_Error( 'error', __( 'Houve um erro desconhecido ao tentar realizar o reembolso.', 'pagbank-for-woocommerce' ) );
	}

	/**
	 * Whether a charge carries a payable QR code.
	 *
	 * @param array $charge Charge data from the order response.
	 */
	private function charge_has_pix_data( array $charge ): bool {
		$text    = (string) ( $charge['qr_code']['text'] ?? '' );
		$qr_code = ApiHelpers::find_charge_link_href( $charge['links'] ?? array(), 'QRCODE.PNG' );

		return '' !== $text && '' !== $qr_code;
	}

	/**
	 * Save order meta data.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $response Response data.
	 * @param array    $request Request data.
	 */
	private function save_order_meta_data( WC_Order $order, array $response, array $request ): void {
		$charge = is_array( $response['charges'][0] ?? null ) ? $response['charges'][0] : array();

		$order->update_meta_data( '_pagbank_order_id', (string) ( $response['id'] ?? '' ) );
		$order->update_meta_data( '_pagbank_charge_id', (string) ( $charge['id'] ?? '' ) );

		$expiration_date = (string) ( $charge['payment_method']['pix']['expiration_date'] ?? '' );

		$order->update_meta_data( '_pagbank_pix_expiration_date', $expiration_date );
		$order->update_meta_data( '_pagbank_pix_text', (string) ( $charge['qr_code']['text'] ?? '' ) );
		$order->update_meta_data( '_pagbank_pix_qr_code', ApiHelpers::find_charge_link_href( $charge['links'] ?? array(), 'QRCODE.PNG' ) );
		$order->update_meta_data( '_pagbank_environment', $this->environment );

		$order->save_meta_data();
	}

	/**
	 * Persist the risk-analysis engine response for auditing.
	 *
	 * @param WC_Order $order  Order object.
	 * @param array    $charge Charge data from the order response.
	 */
	private function save_payment_response_meta_data( WC_Order $order, array $charge ): void {
		$payment_response = $charge['payment_response'] ?? null;

		if ( ! is_array( $payment_response ) ) {
			return;
		}

		$code    = isset( $payment_response['code'] ) ? (string) $payment_response['code'] : '';
		$message = isset( $payment_response['message'] ) ? (string) $payment_response['message'] : '';

		// An approved charge usually carries no reason; skip the empty note.
		if ( '' === $code && '' === $message ) {
			return;
		}

		$order->update_meta_data( '_pagbank_pix_payment_response_code', $code );
		$order->update_meta_data( '_pagbank_pix_payment_response_message', $message );
		$order->save_meta_data();

		$order->add_order_note(
			sprintf(
				/* translators: %1$s: response code, %2$s: response message. */
				__( 'Análise de risco do Pix: %1$s - %2$s', 'pagbank-for-woocommerce' ),
				$code,
				$message
			)
		);
	}

	/**
	 * Thanks you page HTML content.
	 *
	 * @param int $order_id Order ID.
	 */
	public function thankyou_page( int $order_id ): void {
		$order = wc_get_order( $order_id );

		$pix_expiration_date = $order->get_meta( '_pagbank_pix_expiration_date' );
		$pix_text            = $order->get_meta( '_pagbank_pix_text' );
		$pix_qr_code         = $order->get_meta( '_pagbank_pix_qr_code' );

		wc_get_template(
			'order-received/payment-instructions-pix.php',
			array(
				'order_id'            => $order_id,
				'order_key'           => $order->get_order_key(),
				'is_paid'             => $order->is_paid(),
				'pix_expiration_date' => $pix_expiration_date,
				'pix_text'            => $pix_text,
				'pix_qr_code'         => $pix_qr_code,
			),
			'woocommerce/pagbank/',
			PAGBANK_WOOCOMMERCE_TEMPLATES_PATH
		);
	}

	/**
	 * View order page HTML content.
	 *
	 * @param int $order_id Order ID.
	 */
	public function view_order_page( int $order_id ): void {
		$order = wc_get_order( $order_id );

		// Only show for Pix payment method.
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		// Enqueue styles and scripts for view-order page.
		$this->enqueue_pix_scripts();

		// Use the same template as thankyou page.
		$this->thankyou_page( $order_id );
	}

	/**
	 * Check if gateway needs setup.
	 */
	public function needs_setup(): bool {
		$is_connected = (bool) $this->connect->get_data();

		return ! $is_connected;
	}

	/**
	 * Check if gateway is available for use.
	 */
	public function is_available(): bool {
		$is_available = ( 'yes' === $this->enabled );

		if ( ! $is_available ) {
			return false;
		}

		if ( WC()->cart && 0 < $this->get_order_total() && 0 < $this->max_amount && $this->max_amount < $this->get_order_total() ) {
			return false;
		}

		$is_connected          = (bool) $this->connect->get_data();
		$is_brazilian_currency = get_woocommerce_currency() === 'BRL';

		if ( ! $is_connected || ! $is_brazilian_currency ) {
			return false;
		}

		return true;
	}

	/**
	 * Add errors in case of some validation error that will appear during the checkout.
	 */
	public function is_available_validation(): void {
		$is_enabled            = ( 'yes' === $this->enabled );
		$is_connected          = (bool) $this->connect->get_data();
		$is_brazilian_currency = get_woocommerce_currency() === 'BRL';

		$errors = array();

		if ( ! $is_enabled ) {
			$errors[] = __( '- O método de pagamento está desabilitado.', 'pagbank-for-woocommerce' );
		}

		if ( ! $is_connected ) {
			$errors[] = __( '- A sua conta PagBank não está conectada.', 'pagbank-for-woocommerce' );
		}

		if ( ! $is_brazilian_currency ) {
			$errors[] = __( '- A moeda da loja não é BRL.', 'pagbank-for-woocommerce' );
		}

		if ( $errors ) {
			array_unshift( $errors, __( 'Alguns errors podem estar impedindo o método de pagamento de ser exibido durante o checkout:', 'pagbank-for-woocommerce' ) );

			$this->add_error( implode( '<br />', $errors ) );
		}
	}

	/**
	 * Generate HTML settings HTML with errors.
	 *
	 * @param array $form_fields The form fields to display.
	 * @param bool  $echo_output Should echo or return.
	 *
	 * @return string If $echo = false, return the HTML content.
	 */
	public function generate_settings_html( $form_fields = array(), $echo_output = true ): string {
		ob_start();
		$this->display_errors();
		$html = ob_get_clean();

		if ( $echo_output ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XSS ok.
			echo $html . parent::generate_settings_html( $form_fields, $echo_output );

			return '';
		} else {
			return $html . parent::generate_settings_html( $form_fields, $echo_output );
		}
	}

	/**
	 * Enqueue scripts.
	 */
	public function enqueue_styles(): void {
		$is_order_received_page = is_checkout() && ! empty( is_wc_endpoint_url( 'order-received' ) );

		if ( ! $is_order_received_page ) {
			return;
		}

		$order_id               = get_query_var( 'order-received' );
		$order                  = wc_get_order( $order_id );
		$payment_method         = $order->get_payment_method();
		$is_order_paid_with_pix = $this->id === $payment_method;

		if ( ! $is_order_paid_with_pix ) {
			return;
		}

		$this->enqueue_pix_scripts();
	}

	/**
	 * Enqueue Pix scripts and styles.
	 */
	private function enqueue_pix_scripts(): void {
		wp_enqueue_style(
			'pagbank-order-pix',
			plugins_url( 'dist/styles/order-received/order-pix.css', PAGBANK_WOOCOMMERCE_FILE_PATH ),
			array(),
			PAGBANK_WOOCOMMERCE_VERSION,
			'all'
		);

		wp_enqueue_script(
			'pagbank-payment-instructions',
			plugins_url( 'dist/public/order-received/payment-instructions.js', PAGBANK_WOOCOMMERCE_FILE_PATH ),
			array( 'react', 'react-dom', 'wp-i18n' ),
			PAGBANK_WOOCOMMERCE_VERSION,
			true
		);

		wp_localize_script(
			'pagbank-payment-instructions',
			'pagbankOrderStatus',
			array(
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'pagbank-payment-instructions', 'pagbank-for-woocommerce' );
		}

		wp_scripts()->add_data( 'pagbank-payment-instructions', 'pagbank_script', true );
	}
}
