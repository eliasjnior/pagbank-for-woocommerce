<?php
if(!defined('ABSPATH')) {
	exit;
}
?>
<h2><?php esc_html_e( 'PagBank Marketplace', 'pagbank-for-woocommerce' ); ?></h2>
<div class="wcfm_clearfix"></div>
<div class="form-field">
    <p class="wcfm_title wcfm_ele"><strong><?php esc_html_e( 'Identificador da conta', 'pagbank-for-woocommerce' ); ?></strong></p>
    <label class="screen-reader-text" for="pagbank_account_id"><?php esc_html_e( 'Identificador da conta', 'pagbank-for-woocommerce' ); ?></label>
    <input type="text" id="pagbank_account_id" name="payment[pagbank][account_id]" class="wcfm-text wcfm_ele" value="<?php echo esc_attr( $account_id ); ?>" placeholder="">
	<p class="wcfm_desc">
		<a href="https://minhaconta.pagbank.com.br/venda-online/integracoes/identificador-de-marketplace" target="_blank" rel="noopener">
			<?php esc_html_e( 'Clique aqui para acessar a página do Identificador para Marketplace.', 'pagbank-for-woocommerce' ); ?>
		</a>
	</p>
</div>
