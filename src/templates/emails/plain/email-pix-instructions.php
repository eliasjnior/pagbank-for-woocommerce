<?php
/**
 * Pix payment instructions for emails (Plain text).
 *
 * @package PagBank_WooCommerce\Templates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo "\n\n";
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
echo esc_html( strtoupper( __( 'Instruções de pagamento Pix', 'pagbank-for-woocommerce' ) ) ) . "\n\n";
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo esc_html__( 'OPÇÃO 1: ESCANEIE O QR CODE DO PIX', 'pagbank-for-woocommerce' ) . "\n\n";
echo '1. ' . esc_html__( 'Abra o aplicativo do seu banco e selecione a opção "Pagar com Pix"', 'pagbank-for-woocommerce' ) . "\n";
echo '2. ' . esc_html__( 'Escaneie o QR code (disponível na versão HTML deste email) e confirme o pagamento', 'pagbank-for-woocommerce' ) . "\n\n";

echo "---------------------------------------------------------------------\n\n";

echo esc_html__( 'OPÇÃO 2: USE O CÓDIGO DO PIX', 'pagbank-for-woocommerce' ) . "\n\n";
echo esc_html__( 'Copie o código abaixo e cole no aplicativo do seu banco:', 'pagbank-for-woocommerce' ) . "\n\n";

echo $pix_text . "\n\n";

echo esc_html__( 'Passos para pagar:', 'pagbank-for-woocommerce' ) . "\n\n";
echo '1. ' . esc_html__( 'Abra o aplicativo ou site do seu banco e selecione a opção "Pagar com Pix"', 'pagbank-for-woocommerce' ) . "\n";
echo '2. ' . esc_html__( 'Cole o código acima e conclua o pagamento', 'pagbank-for-woocommerce' ) . "\n\n";

if ( ! empty( $pix_expiration_date ) ) {
	// translators: %s is the expiration date.
	echo sprintf( esc_html__( 'ATENÇÃO: Este código Pix expira em %s', 'pagbank-for-woocommerce' ), esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $pix_expiration_date ) ) ) ) . "\n\n";
}

echo esc_html__( 'Assim que o pagamento for confirmado, você receberá um email de confirmação.', 'pagbank-for-woocommerce' ) . "\n\n";

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
