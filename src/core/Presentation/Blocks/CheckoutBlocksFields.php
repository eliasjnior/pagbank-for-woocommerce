<?php
/**
 * Registers additional checkout fields for WooCommerce Blocks.
 *
 * These fields are required by PagBank API (CPF/CNPJ, number, neighborhood).
 *
 * @package PagBank_WooCommerce\Presentation\Blocks
 */

namespace PagBank_WooCommerce\Presentation\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields as WooCheckoutFields;
use Automattic\WooCommerce\Blocks\Package;
use PagBank_WooCommerce\Presentation\Helpers;
use PagBank_WooCommerce\Presentation\LegacyCheckoutFields;
use WC_Customer;
use WC_Order;
use WP_Error;

/**
 * Class CheckoutBlocksFields.
 */
class CheckoutBlocksFields {

	/**
	 * Every additional checkout field this plugin may register.
	 */
	public const FIELD_IDS = array(
		'pagbank/persontype',
		'pagbank/cpf',
		'pagbank/cnpj',
		'pagbank/company',
		'pagbank/address-number',
		'pagbank/neighborhood',
		'pagbank/cellphone',
	);

	/**
	 * Interop meta suffix of each field. The company has none: it maps to the
	 * core company address field.
	 */
	private const LEGACY_META_SUFFIXES = array(
		'pagbank/persontype'     => 'persontype',
		'pagbank/cpf'            => 'cpf',
		'pagbank/cnpj'           => 'cnpj',
		'pagbank/address-number' => 'number',
		'pagbank/neighborhood'   => 'neighborhood',
		'pagbank/cellphone'      => 'cellphone',
	);

	/**
	 * Instance.
	 */
	private static ?CheckoutBlocksFields $instance = null;

	/**
	 * Fields deregistered from the My Account address pages in this request.
	 *
	 * @var string[]
	 */
	private array $account_field_ids = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_init', array( $this, 'register_additional_checkout_fields' ) );
		// Priority 1000: after any plugin that rewrites the BR locale (the
		// Brazilian Market plugin replaces it with a postcode-only entry).
		add_filter( 'woocommerce_get_country_locale', array( $this, 'pin_brazil_locale_priorities' ), 1000 );
		add_action( 'wp', array( $this, 'maybe_set_default_persontype' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'woocommerce_set_additional_field_value', array( $this, 'save_field_to_legacy_meta' ), 10, 4 );
		add_action( 'wp', array( $this, 'maybe_deregister_on_my_account' ) );
		add_filter( 'woocommerce_my_account_edit_address_field_value', array( $this, 'fill_edit_address_value' ), 10, 2 );
		// Priority 20: after WooCommerce saved the customer and its own additional fields.
		add_action( 'woocommerce_customer_save_address', array( $this, 'mirror_account_address_to_fields' ), 20, 4 );

		foreach ( array_keys( self::LEGACY_META_SUFFIXES ) as $field_id ) {
			add_filter( 'woocommerce_get_default_value_for_' . $field_id, array( $this, 'default_from_legacy_meta' ), 10, 3 );
		}
	}

	/**
	 * Get instance.
	 */
	public static function get_instance(): CheckoutBlocksFields {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Enqueue scripts and styles for checkout blocks fields.
	 */
	public function enqueue_scripts(): void {
		// Version with the bundle mtime so browsers pick up new builds even
		// when the plugin version has not changed (e.g. during development).
		$style_path    = plugin_dir_path( PAGBANK_WOOCOMMERCE_FILE_PATH ) . 'dist/styles/blocks/checkout-fields.css';
		$style_version = PAGBANK_WOOCOMMERCE_VERSION . ( file_exists( $style_path ) ? '.' . filemtime( $style_path ) : '' );

		wp_register_style(
			'pagbank-checkout-blocks-fields',
			plugins_url( 'dist/styles/blocks/checkout-fields.css', PAGBANK_WOOCOMMERCE_FILE_PATH ),
			array(),
			$style_version,
			'all'
		);

		wp_enqueue_style( 'pagbank-checkout-blocks-fields' );

		// Input masks for the pagbank/* fields on the Blocks checkout. Only the
		// document and cellphone fields are masked, so the script is pointless
		// when a third-party plugin provides both.
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		if ( self::blocks_provides_document_fields() && LegacyCheckoutFields::external_provides_cellphone_field() ) {
			return;
		}

		$script_path    = plugin_dir_path( PAGBANK_WOOCOMMERCE_FILE_PATH ) . 'dist/public/blocks/checkout-fields.js';
		$script_version = PAGBANK_WOOCOMMERCE_VERSION . ( file_exists( $script_path ) ? '.' . filemtime( $script_path ) : '' );

		wp_enqueue_script(
			'pagbank-blocks-checkout-fields',
			plugins_url( 'dist/public/blocks/checkout-fields.js', PAGBANK_WOOCOMMERCE_FILE_PATH ),
			array(),
			$script_version,
			true
		);

		wp_scripts()->add_data( 'pagbank-blocks-checkout-fields', 'pagbank_script', true );

		$config = array(
			'alphanumeric_cnpj' => (bool) Helpers::get_constant_value( 'PAGBANK_FEATURE_FLAG_ALPHANUMERIC_CNPJ_ENABLED', false ),
		);

		wp_add_inline_script(
			'pagbank-blocks-checkout-fields',
			'window.PagBankBlocksCheckoutFieldsConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	/**
	 * Pin the BR locale priorities of the core address fields.
	 *
	 * The Blocks address form sorts fields by the defaultFields indexes
	 * (country 1, first_name 10, ..., address_1 40) overridden by the
	 * country locale (country 40, address_1 50, ...). Plugins can rewrite
	 * the BR locale — the Brazilian Market plugin replaces it with a
	 * postcode-only entry, which silently switches the whole scale and
	 * scrambles our field indexes. Filling the missing anchors keeps the
	 * scale stable in every scenario; existing overrides (e.g. the BM
	 * postcode at 45) are preserved.
	 *
	 * @param array|mixed $locale Country locales.
	 *
	 * @return array|mixed
	 */
	public function pin_brazil_locale_priorities( $locale ) {
		if ( ! is_array( $locale ) ) {
			return $locale;
		}

		$anchors = array(
			'first_name' => 10,
			'last_name'  => 20,
			'company'    => 30,
			'country'    => 40,
			'address_1'  => 50,
			'address_2'  => 60,
			'city'       => 70,
			'state'      => 80,
			'postcode'   => 90,
			'phone'      => 100,
		);

		foreach ( $anchors as $key => $priority ) {
			if ( ! isset( $locale['BR'][ $key ]['priority'] ) ) {
				$locale['BR'][ $key ]['priority'] = $priority;
			}
		}

		return $locale;
	}

	/**
	 * Register additional checkout fields for Blocks.
	 */
	public function register_additional_checkout_fields(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		// Gated per group: LinkNacional covers document, number and neighborhood
		// behind independent settings, so one of theirs being on must not suppress
		// the rest of ours.

		// Field indexes are relative to the BR locale scale pinned by
		// pin_brazil_locale_priorities() (first_name 10, last_name 20,
		// company 30, country 40, address_1 50, address_2 60, city 70,
		// state 80, postcode 90, phone 100), which overrides the raw
		// defaultFields indexes and is stable regardless of other plugins.

		if ( ! self::blocks_provides_document_fields() ) {
			// Person type selector - shown only for Brazil. Mirrors the classic
			// checkout experience: Pessoa física shows the CPF field; Pessoa
			// jurídica shows the CNPJ and Razão Social fields. Rendered right
			// after the country field, which drives its visibility.
			woocommerce_register_additional_checkout_field(
				array(
					'id'                         => 'pagbank/persontype',
					'index'                      => 41,
					'label'                      => __( 'Tipo de pessoa', 'pagbank-for-woocommerce' ),
					// The field is only visible when it is effectively required
					// (Brazil), so never show the "(optional)" suffix.
					'optionalLabel'              => __( 'Tipo de pessoa', 'pagbank-for-woocommerce' ),
					'location'                   => 'address',
					'type'                       => 'select',
					'show_in_order_confirmation' => false,
					'options'                    => array(
						array(
							'value' => '1',
							'label' => __( 'Pessoa física', 'pagbank-for-woocommerce' ),
						),
						array(
							'value' => '2',
							'label' => __( 'Pessoa jurídica', 'pagbank-for-woocommerce' ),
						),
					),
					'required'                   => self::rule_country_is_brazil(),
					'hidden'                     => self::rule_country_is_not_brazil(),
				)
			);

			// CPF - visible for Brazil while the person type is not legal person
			// (an empty selection defaults to Pessoa física, like the classic
			// checkout). Hidden fields are never required, so requiring for
			// Brazil is enough.
			woocommerce_register_additional_checkout_field(
				array(
					'id'                         => 'pagbank/cpf',
					'index'                      => 42,
					'label'                      => __( 'CPF', 'pagbank-for-woocommerce' ),
					'optionalLabel'              => __( 'CPF', 'pagbank-for-woocommerce' ),
					'location'                   => 'address',
					'type'                       => 'text',
					'show_in_order_confirmation' => false,
					'required'                   => self::rule_country_is_brazil(),
					'hidden'                     => array(
						'anyOf' => array(
							self::rule_country_is_not_brazil(),
							self::rule_persontype_is_legal_person(),
						),
					),
					'sanitize_callback'          => function ( $field_value ) {
						$value = (string) $field_value;

						return Helpers::is_valid_cpf( $value ) ? Helpers::format_cpf( $value ) : $field_value;
					},
					'validate_callback'          => function ( $field_value ) {
						$value = (string) $field_value;

						if ( '' !== $value && ! Helpers::is_valid_cpf( $value ) ) {
							return new WP_Error( 'invalid_cpf', __( 'CPF inválido. Verifique os dígitos informados.', 'pagbank-for-woocommerce' ) );
						}
					},
				)
			);

			// CNPJ - visible only for Brazil legal persons. Accepts the new
			// alphanumeric format when the feature flag is enabled.
			woocommerce_register_additional_checkout_field(
				array(
					'id'                         => 'pagbank/cnpj',
					'index'                      => 43,
					'label'                      => __( 'CNPJ', 'pagbank-for-woocommerce' ),
					'optionalLabel'              => __( 'CNPJ', 'pagbank-for-woocommerce' ),
					'location'                   => 'address',
					'type'                       => 'text',
					'show_in_order_confirmation' => false,
					'required'                   => self::rule_country_is_brazil(),
					'hidden'                     => array(
						'anyOf' => array(
							self::rule_country_is_not_brazil(),
							self::rule_persontype_is_not_legal_person(),
						),
					),
					'sanitize_callback'          => function ( $field_value ) {
						$value = (string) $field_value;

						return Helpers::is_valid_cnpj( $value ) ? Helpers::format_cnpj( $value ) : $field_value;
					},
					'validate_callback'          => function ( $field_value ) {
						$value = (string) $field_value;

						if ( '' !== $value && ! Helpers::is_valid_cnpj( $value ) ) {
							return new WP_Error( 'invalid_cnpj', __( 'CNPJ inválido. Verifique os dígitos informados.', 'pagbank-for-woocommerce' ) );
						}
					},
				)
			);

			// Razão Social - only when the store hides the core company field
			// (otherwise the Blocks checkout already offers it). Visible only for
			// Brazil legal persons.
			if ( 'hidden' === get_option( 'woocommerce_checkout_company_field', 'optional' ) ) {
				woocommerce_register_additional_checkout_field(
					array(
						'id'                         => 'pagbank/company',
						'index'                      => 44,
						'label'                      => __( 'Razão Social', 'pagbank-for-woocommerce' ),
						'optionalLabel'              => __( 'Razão Social', 'pagbank-for-woocommerce' ),
						'location'                   => 'address',
						'type'                       => 'text',
						'show_in_order_confirmation' => false,
						'required'                   => self::rule_country_is_brazil(),
						'hidden'                     => array(
							'anyOf' => array(
								self::rule_country_is_not_brazil(),
								self::rule_persontype_is_not_legal_person(),
							),
						),
					)
				);
			}
		}

		if ( ! LegacyCheckoutFields::external_provides_number_field() ) {
			// Billing number - between address_1 (50) and address_2 (60).
			woocommerce_register_additional_checkout_field(
				array(
					'id'                         => 'pagbank/address-number',
					'index'                      => 51,
					'label'                      => __( 'Número', 'pagbank-for-woocommerce' ),
					'location'                   => 'address',
					'type'                       => 'text',
					'show_in_order_confirmation' => false,
					'required'                   => true,
				)
			);
		}

		if ( ! LegacyCheckoutFields::external_provides_neighborhood_field() ) {
			// Billing neighborhood.
			woocommerce_register_additional_checkout_field(
				array(
					'id'                         => 'pagbank/neighborhood',
					'index'                      => 52,
					'label'                      => __( 'Bairro', 'pagbank-for-woocommerce' ),
					'location'                   => 'address',
					'type'                       => 'text',
					'show_in_order_confirmation' => false,
					'required'                   => true,
				)
			);
		}

		if ( ! LegacyCheckoutFields::external_provides_cellphone_field() ) {
			// Cellphone - required, so it comes before the optional core phone (100).
			woocommerce_register_additional_checkout_field(
				array(
					'id'                         => 'pagbank/cellphone',
					'index'                      => 99,
					'label'                      => __( 'Celular', 'pagbank-for-woocommerce' ),
					'location'                   => 'address',
					'type'                       => 'text',
					'show_in_order_confirmation' => false,
					'required'                   => true,
					'sanitize_callback'          => function ( $field_value ) {
						return Helpers::format_cellphone( (string) $field_value );
					},
					'validate_callback'          => function ( $field_value ) {
						if ( ! Helpers::is_valid_cellphone( (string) $field_value ) ) {
							return new WP_Error( 'invalid_cellphone', __( 'Número de celular inválido.', 'pagbank-for-woocommerce' ) );
						}
					},
				)
			);
		}
	}

	/**
	 * Whether a third-party plugin already provides the document fields on Blocks.
	 *
	 * Only LinkNacional registers Store API document fields; the Brazilian
	 * Market plugin is classic-checkout only, so it never gates Blocks.
	 */
	private static function blocks_provides_document_fields(): bool {
		return LegacyCheckoutFields::linknacional_provides_document_fields();
	}


	/**
	 * Default the person type to Pessoa física on the checkout page.
	 *
	 * The additional checkout fields API has no default-value option: the
	 * select hydrates from the customer's stored value, which is empty for
	 * new customers. Persist '1' (individual) when empty so the selector
	 * starts as Pessoa física, matching the classic checkout behavior. Runs
	 * before the Blocks hydration reads the customer object.
	 */
	public function maybe_set_default_persontype(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! function_exists( 'WC' ) || empty( WC()->customer ) ) {
			return;
		}

		if ( self::blocks_provides_document_fields() ) {
			return;
		}

		if ( ! class_exists( Package::class ) ) {
			return;
		}

		try {
			$checkout_fields = Package::container()->get( WooCheckoutFields::class );

			foreach ( array( 'billing', 'shipping' ) as $group ) {
				$current = $checkout_fields->get_field_from_object( 'pagbank/persontype', WC()->customer, $group );

				if ( empty( $current ) ) {
					$checkout_fields->persist_field_for_customer( 'pagbank/persontype', '1', WC()->customer, $group );
				}
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Conditional rule: the address country is Brazil.
	 *
	 * Evaluated against the checkout document object (Opis JSON Schema); the
	 * address-location additional fields live inside `customer.address`
	 * alongside the core address properties.
	 */
	private static function rule_country_is_brazil(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'customer' => array(
					'properties' => array(
						'address' => array(
							'properties' => array(
								'country' => array(
									'const' => 'BR',
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Conditional rule: the address country is not Brazil.
	 */
	private static function rule_country_is_not_brazil(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'customer' => array(
					'properties' => array(
						'address' => array(
							'properties' => array(
								'country' => array(
									'not' => array(
										'const' => 'BR',
									),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Conditional rule: the person type is legal person (CNPJ).
	 *
	 * The `required` keyword guards against a missing person type (a JSON
	 * Schema `properties` check passes when the property is absent), so the
	 * CPF field stays visible before any selection is made.
	 */
	private static function rule_persontype_is_legal_person(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'customer' => array(
					'properties' => array(
						'address' => array(
							'properties' => array(
								'pagbank/persontype' => array(
									'const' => '2',
								),
							),
							'required'   => array( 'pagbank/persontype' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Conditional rule: the person type is not legal person.
	 *
	 * A missing or empty selection counts as not legal person, so the CNPJ
	 * and Razão Social fields stay hidden by default (Pessoa física).
	 */
	private static function rule_persontype_is_not_legal_person(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'customer' => array(
					'properties' => array(
						'address' => array(
							'properties' => array(
								'pagbank/persontype' => array(
									'not' => array(
										'const' => '2',
									),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Save field values to legacy meta keys for compatibility with existing code.
	 *
	 * This method maps the new checkout block fields to the interop meta keys
	 * used by the classic checkout, the My Account address form and other
	 * parts of the plugin.
	 *
	 * @param string $key       Field key.
	 * @param mixed  $value     Field value.
	 * @param string $group     Field group (billing, shipping, other).
	 * @param object $wc_object WooCommerce object (WC_Order or WC_Customer).
	 */
	public function save_field_to_legacy_meta( string $key, $value, string $group, object $wc_object ): void {
		$is_order = $wc_object instanceof WC_Order;

		if ( ! $is_order && ! ( $wc_object instanceof WC_Customer ) ) {
			return;
		}

		if ( 'pagbank/company' === $key ) {
			// Razão Social maps to the core company address field.
			if ( 'billing' === $group ) {
				$wc_object->set_billing_company( (string) $value );
			} elseif ( 'shipping' === $group ) {
				$wc_object->set_shipping_company( (string) $value );
			}

			return;
		}

		if ( 'pagbank/persontype' === $key && ! in_array( (string) $value, array( '1', '2' ), true ) ) {
			return;
		}

		$meta_key = self::legacy_meta_key( $key, $group, $is_order );

		if ( '' === $meta_key ) {
			return;
		}

		$wc_object->update_meta_data( $meta_key, (string) $value );

		// Whoever persists a customer field saves the customer afterwards; saving
		// here would write a half-updated session customer.
		if ( $is_order ) {
			$wc_object->save_meta_data();
		}
	}

	/**
	 * Remove the fields from the My Account address pages.
	 *
	 * WooCommerce adds every address-location additional field to the edit
	 * address form, where the classic fields (ours or a third-party plugin's)
	 * already collect the same data. Deregistering, rather than filtering the
	 * form, also keeps WooCommerce from saving the missing fields as empty.
	 */
	public function maybe_deregister_on_my_account(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! is_wc_endpoint_url( 'edit-address' ) ) {
			return;
		}

		$this->account_field_ids = self::deregister_fields( self::FIELD_IDS );
	}

	/**
	 * Deregister additional checkout fields for the current request.
	 *
	 * @param string[] $field_ids Field IDs.
	 *
	 * @return string[] The IDs that were registered and are now removed.
	 */
	public static function deregister_fields( array $field_ids ): array {
		if ( ! class_exists( Package::class ) ) {
			return array();
		}

		try {
			$checkout_fields = Package::container()->get( WooCheckoutFields::class );
		} catch ( \Throwable $e ) {
			return array();
		}

		// `deregister_checkout_field()` is marked @internal in WooCommerce and has
		// no public equivalent; degrade to keeping the fields if it ever goes away.
		if ( ! ( $checkout_fields instanceof WooCheckoutFields ) || ! method_exists( $checkout_fields, 'deregister_checkout_field' ) ) {
			return array();
		}

		$removed = array();

		foreach ( $field_ids as $field_id ) {
			if ( $checkout_fields->is_field( $field_id ) ) {
				$checkout_fields->deregister_checkout_field( $field_id );
				$removed[] = $field_id;
			}
		}

		return $removed;
	}

	/**
	 * Prefill an empty classic field on the My Account edit address form with
	 * the value saved by the Blocks checkout.
	 *
	 * @param mixed  $value The stored user meta value.
	 * @param string $key   The address field key.
	 *
	 * @return mixed
	 */
	public function fill_edit_address_value( $value, string $key ) {
		if ( ! empty( $value ) ) {
			return $value;
		}

		$fallback = self::get_customer_meta( get_current_user_id(), $key );

		return '' === $fallback ? $value : $fallback;
	}

	/**
	 * Copy the classic fields saved on the My Account address form to the
	 * Blocks fields, so the next Blocks checkout prefills the new values.
	 *
	 * Core's own callback on this hook defaults `$address` and `$customer`,
	 * both added in WooCommerce 9.8.
	 *
	 * @param mixed $user_id      User ID.
	 * @param mixed $address_type Address type (billing|shipping).
	 * @param mixed $address      The address fields of the submitted form.
	 * @param mixed $customer     The saved customer.
	 */
	public function mirror_account_address_to_fields( $user_id = 0, $address_type = '', $address = array(), $customer = null ): void {
		if ( empty( $this->account_field_ids ) || ! is_array( $address ) ) {
			return;
		}

		if ( ! ( $customer instanceof WC_Customer ) ) {
			if ( (int) $user_id <= 0 ) {
				return;
			}

			$customer = new WC_Customer( (int) $user_id );
		}

		$changed = false;

		foreach ( array_keys( $address ) as $key ) {
			$field = self::blocks_field_for_legacy_key( (string) $key );

			if ( ! $field || $address_type !== $field['group'] || ! in_array( $field['id'], $this->account_field_ids, true ) ) {
				continue;
			}

			$meta_key = self::blocks_meta_key( $field['group'], $field['id'] );

			$customer->update_meta_data( $meta_key, (string) $customer->get_meta( (string) $key ) );
			$changed = true;
		}

		if ( $changed ) {
			$customer->save_meta_data();
		}
	}

	/**
	 * Default an empty field to the interop meta saved by the classic checkout,
	 * the My Account form or a third-party plugin.
	 *
	 * @param mixed $value     The default value (null).
	 * @param mixed $group     The field group (billing|shipping|other).
	 * @param mixed $wc_object The object the field is read from.
	 *
	 * @return mixed
	 */
	public function default_from_legacy_meta( $value = null, $group = '', $wc_object = null ) {
		if ( null !== $value || ! ( $wc_object instanceof WC_Customer ) ) {
			return $value;
		}

		$field_id = substr( current_filter(), strlen( 'woocommerce_get_default_value_for_' ) );
		$meta_key = self::legacy_meta_key( $field_id, (string) $group, false );

		if ( '' === $meta_key ) {
			return $value;
		}

		$legacy = (string) $wc_object->get_meta( $meta_key );

		return '' === $legacy ? $value : $legacy;
	}

	/**
	 * Read an interop customer meta, falling back to the Blocks field that
	 * holds the same data.
	 *
	 * @param int    $customer_id Customer ID.
	 * @param string $legacy_key  Interop meta key, e.g. `billing_cpf`.
	 */
	public static function get_customer_meta( int $customer_id, string $legacy_key ): string {
		$value = (string) get_user_meta( $customer_id, $legacy_key, true );

		if ( '' !== $value ) {
			return $value;
		}

		$field = self::blocks_field_for_legacy_key( $legacy_key );

		if ( ! $field ) {
			return '';
		}

		return (string) get_user_meta( $customer_id, self::blocks_meta_key( $field['group'], $field['id'] ), true );
	}

	/**
	 * Interop meta key of a field: `billing_cpf` on the customer, `_billing_cpf`
	 * on the order.
	 *
	 * @param string $field_id  Field ID.
	 * @param string $group     Field group (billing|shipping|other).
	 * @param bool   $for_order Whether the key is for an order.
	 *
	 * @return string Empty when the field has no interop meta.
	 */
	public static function legacy_meta_key( string $field_id, string $group, bool $for_order ): string {
		if ( ! isset( self::LEGACY_META_SUFFIXES[ $field_id ] ) || ( 'billing' !== $group && 'shipping' !== $group ) ) {
			return '';
		}

		return ( $for_order ? '_' : '' ) . $group . '_' . self::LEGACY_META_SUFFIXES[ $field_id ];
	}

	/**
	 * Field behind an interop customer meta key.
	 *
	 * @param string $legacy_key Interop meta key, e.g. `billing_cpf`.
	 *
	 * @return array{group?:string,id?:string} Empty when no field matches.
	 */
	public static function blocks_field_for_legacy_key( string $legacy_key ): array {
		if ( ! preg_match( '/^(billing|shipping)_(.+)$/', $legacy_key, $matches ) ) {
			return array();
		}

		$field_id = array_search( $matches[2], self::LEGACY_META_SUFFIXES, true );

		return false === $field_id ? array() : array(
			'group' => $matches[1],
			'id'    => $field_id,
		);
	}

	/**
	 * Meta key WooCommerce stores a field under.
	 *
	 * Literal copy of `CheckoutFields::get_group_key()` so the mapping is
	 * testable without WooCommerce.
	 *
	 * @param string $group    Field group (billing|shipping).
	 * @param string $field_id Field ID.
	 */
	public static function blocks_meta_key( string $group, string $field_id ): string {
		return '_wc_' . $group . '/' . $field_id;
	}
}
