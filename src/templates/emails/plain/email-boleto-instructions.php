<?php
/**
 * Boleto payment instructions for emails (Plain text).
 *
 * @package PagBank_WooCommerce\Templates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo "\n\n";
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
echo esc_html( strtoupper( __( 'Instruções de pagamento do Boleto', 'pagbank-for-woocommerce' ) ) ) . "\n\n";
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo esc_html__( 'Use o código de barras abaixo para pagar o boleto:', 'pagbank-for-woocommerce' ) . "\n\n";

echo $boleto_barcode . "\n\n";

echo "---------------------------------------------------------------------\n\n";

echo esc_html__( 'OPÇÕES DE PAGAMENTO:', 'pagbank-for-woocommerce' ) . "\n\n";

echo '1. ' . esc_html__( 'PELO APLICATIVO DO BANCO:', 'pagbank-for-woocommerce' ) . "\n";
echo '   ' . esc_html__( 'Copie o código de barras acima e cole no aplicativo do seu banco na opção de pagamento de boleto.', 'pagbank-for-woocommerce' ) . "\n\n";

echo '2. ' . esc_html__( 'IMPRIMIR O BOLETO:', 'pagbank-for-woocommerce' ) . "\n";
echo '   ' . esc_html__( 'Acesse o link abaixo para baixar o PDF do boleto:', 'pagbank-for-woocommerce' ) . "\n";
echo '   ' . $boleto_link_pdf . "\n";
echo '   ' . esc_html__( 'Efetue o pagamento em qualquer banco ou lotérica.', 'pagbank-for-woocommerce' ) . "\n\n";

echo '3. ' . esc_html__( 'INTERNET BANKING:', 'pagbank-for-woocommerce' ) . "\n";
echo '   ' . esc_html__( 'Você também pode pagar usando o internet banking do seu banco, usando o código de barras.', 'pagbank-for-woocommerce' ) . "\n\n";

if ( ! empty( $boleto_expiration_date ) ) {
	// translators: %s is the expiration date.
	echo sprintf( esc_html__( 'ATENÇÃO: Este boleto vence em %s', 'pagbank-for-woocommerce' ), esc_html( date_i18n( get_option( 'date_format' ), strtotime( $boleto_expiration_date ) ) ) ) . "\n\n";
}

echo esc_html__( 'Assim que o pagamento for confirmado, você receberá um email de confirmação.', 'pagbank-for-woocommerce' ) . "\n\n";

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
