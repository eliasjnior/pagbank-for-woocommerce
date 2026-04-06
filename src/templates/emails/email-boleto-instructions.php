<?php
/**
 * Boleto payment instructions for emails (HTML).
 *
 * @package PagBank_WooCommerce\Templates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<h2><?php esc_html_e( 'Instruções de pagamento do Boleto', 'pagbank-for-woocommerce' ); ?></h2>

<p><?php esc_html_e( 'Use o código de barras abaixo para pagar o boleto:', 'pagbank-for-woocommerce' ); ?></p>

<div style="background-color: #f5f5f5; padding: 15px; margin: 15px 0; word-wrap: break-word; font-family: monospace; font-size: 14px; border: 1px solid #ddd; text-align: center;">
	<?php echo esc_html( $boleto_barcode ); ?>
</div>

<h3><?php esc_html_e( 'Opções de pagamento:', 'pagbank-for-woocommerce' ); ?></h3>

<ol>
	<li>
		<strong><?php esc_html_e( 'Pelo aplicativo do banco:', 'pagbank-for-woocommerce' ); ?></strong>
		<?php esc_html_e( 'Copie o código de barras acima e cole no aplicativo do seu banco na opção de pagamento de boleto.', 'pagbank-for-woocommerce' ); ?>
	</li>
	<li>
		<strong><?php esc_html_e( 'Imprimir o boleto:', 'pagbank-for-woocommerce' ); ?></strong>
		<a href="<?php echo esc_url( $boleto_link_pdf ); ?>" target="_blank" style="color: #0073aa; text-decoration: none;">
			<?php esc_html_e( 'Clique aqui para baixar o PDF do boleto', 'pagbank-for-woocommerce' ); ?>
		</a>
		<?php esc_html_e( ' e efetue o pagamento em qualquer banco ou lotérica.', 'pagbank-for-woocommerce' ); ?>
	</li>
	<li>
		<strong><?php esc_html_e( 'Internet Banking:', 'pagbank-for-woocommerce' ); ?></strong>
		<?php esc_html_e( 'Você também pode pagar usando o internet banking do seu banco, usando o código de barras.', 'pagbank-for-woocommerce' ); ?>
	</li>
</ol>

<?php if ( ! empty( $boleto_expiration_date ) ) : ?>
	<p style="color: #d63638; font-weight: bold;">
		<?php
		// translators: %s is the expiration date.
		printf( esc_html__( 'Atenção: Este boleto vence em %s', 'pagbank-for-woocommerce' ), esc_html( date_i18n( get_option( 'date_format' ), strtotime( $boleto_expiration_date ) ) ) );
		?>
	</p>
<?php endif; ?>

<p><?php esc_html_e( 'Assim que o pagamento for confirmado, você receberá um email de confirmação.', 'pagbank-for-woocommerce' ); ?></p>
