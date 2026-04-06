<?php
/**
 * Pix payment instructions for emails (HTML).
 *
 * @package PagBank_WooCommerce\Templates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<h2><?php esc_html_e( 'Instruções de pagamento Pix', 'pagbank-for-woocommerce' ); ?></h2>

<h3><?php esc_html_e( 'Opção 1: Escaneie o QR code do Pix', 'pagbank-for-woocommerce' ); ?></h3>
<ol>
	<li><?php esc_html_e( 'Abra o aplicativo do seu banco e selecione a opção "Pagar com Pix"', 'pagbank-for-woocommerce' ); ?></li>
	<li><?php esc_html_e( 'Escaneie o QR code abaixo e confirme o pagamento', 'pagbank-for-woocommerce' ); ?></li>
</ol>

<p style="text-align: center;">
	<img src="<?php echo esc_url( $pix_qr_code ); ?>" alt="<?php esc_attr_e( 'QR Code Pix', 'pagbank-for-woocommerce' ); ?>" style="max-width: 300px; height: auto;" />
</p>

<h3><?php esc_html_e( 'Opção 2: Use o código do Pix', 'pagbank-for-woocommerce' ); ?></h3>
<p><?php esc_html_e( 'Copie o código abaixo e cole no aplicativo do seu banco:', 'pagbank-for-woocommerce' ); ?></p>

<div style="background-color: #f5f5f5; padding: 15px; margin: 15px 0; word-wrap: break-word; font-family: monospace; font-size: 12px; border: 1px solid #ddd;">
	<?php echo esc_html( $pix_text ); ?>
</div>

<h4><?php esc_html_e( 'Passos para pagar:', 'pagbank-for-woocommerce' ); ?></h4>
<ol>
	<li><?php esc_html_e( 'Abra o aplicativo ou site do seu banco e selecione a opção "Pagar com Pix"', 'pagbank-for-woocommerce' ); ?></li>
	<li><?php esc_html_e( 'Cole o código acima e conclua o pagamento', 'pagbank-for-woocommerce' ); ?></li>
</ol>

<?php if ( ! empty( $pix_expiration_date ) ) : ?>
	<p style="color: #d63638; font-weight: bold;">
		<?php
		// translators: %s is the expiration date.
		printf( esc_html__( 'Atenção: Este código Pix expira em %s', 'pagbank-for-woocommerce' ), esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $pix_expiration_date ) ) ) );
		?>
	</p>
<?php endif; ?>

<p><?php esc_html_e( 'Assim que o pagamento for confirmado, você receberá um email de confirmação.', 'pagbank-for-woocommerce' ); ?></p>
