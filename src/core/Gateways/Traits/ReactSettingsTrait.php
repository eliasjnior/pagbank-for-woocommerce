<?php
/**
 * Trait for rendering React-based gateway settings.
 *
 * @package PagBank_WooCommerce\Gateways\Traits
 */

namespace PagBank_WooCommerce\Gateways\Traits;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait ReactSettingsTrait.
 */
trait ReactSettingsTrait {

	/**
	 * Output the admin settings page with React root.
	 */
	public function admin_options(): void {
		// A native save would submit none of our fields, wiping the settings.
		$GLOBALS['hide_save_button'] = true;

		echo '<div id="pagbank-gateway-settings-root" data-gateway-id="' . esc_attr( $this->id ) . '"></div>';
	}

	/**
	 * Persist the classic form fields, ignoring posts that do not carry them.
	 *
	 * An absent field reads as empty, so a foreign post would wipe the gateway.
	 *
	 * @return bool Whether the settings were saved.
	 */
	public function process_admin_options(): bool {
		if ( ! $this->has_posted_settings_fields() ) {
			return false;
		}

		return (bool) parent::process_admin_options();
	}

	/**
	 * Whether the current request posts at least one of this gateway's fields.
	 */
	private function has_posted_settings_fields(): bool {
		$post_data = $this->get_post_data();

		foreach ( array_keys( $this->get_form_fields() ) as $key ) {
			if ( isset( $post_data[ $this->get_field_key( $key ) ] ) ) {
				return true;
			}
		}

		return false;
	}
}
