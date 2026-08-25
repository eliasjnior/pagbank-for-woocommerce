<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package PagBank_WooCommerce
 */

// Define ABSPATH for WordPress compatibility.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// Load Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Shim WordPress functions used by code under test that are otherwise unavailable
// in this pure-PHPUnit context.
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

// Minimal WooCommerce/WordPress surface, so the tests can call the email hook
// callbacks for real instead of only testing the helpers.
if ( ! class_exists( 'WC_Order' ) ) {
	/**
	 * Stand-in for WC_Order carrying only what the callbacks under test read.
	 */
	class WC_Order {

		public $id;
		public $payment_method;
		public $paid;
		public $meta;

		public function __construct( $id = 1, $payment_method = '', $paid = false, $meta = array() ) {
			$this->id             = $id;
			$this->payment_method = $payment_method;
			$this->paid           = $paid;
			$this->meta           = $meta;
		}

		public function get_id() {
			return $this->id;
		}

		public function get_payment_method() {
			return $this->payment_method;
		}

		public function is_paid() {
			return $this->paid;
		}

		public function get_meta( $key ) {
			return $this->meta[ $key ] ?? '';
		}
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $order_id ) {
		return $GLOBALS['pagbank_test_orders'][ $order_id ] ?? false;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook_name, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'wc_get_template' ) ) {
	function wc_get_template( $template_name, $args = array(), $template_path = '', $default_path = '' ) {
		$GLOBALS['pagbank_test_rendered_templates'][] = array(
			'template' => $template_name,
			'args'     => $args,
		);
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook_name ) {
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook_name ) {
		return true;
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return $file;
	}
}

if ( ! defined( 'PAGBANK_WOOCOMMERCE_FILE_PATH' ) ) {
	define( 'PAGBANK_WOOCOMMERCE_FILE_PATH', dirname( __DIR__ ) . '/pagbank-for-woocommerce.php' );
}

if ( ! defined( 'PAGBANK_WOOCOMMERCE_TEMPLATES_PATH' ) ) {
	define( 'PAGBANK_WOOCOMMERCE_TEMPLATES_PATH', dirname( __DIR__ ) . '/src/templates/' );
}
