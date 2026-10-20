<?php
/**
 * Tests for the mapping between the Blocks fields and the interop meta.
 *
 * @package PagBank_WooCommerce\Tests\Presentation
 */

namespace PagBank_WooCommerce\Tests\Presentation;

use PagBank_WooCommerce\Presentation\Blocks\CheckoutBlocksFields;
use PHPUnit\Framework\TestCase;

/**
 * Class CheckoutBlocksFieldsTest.
 */
class CheckoutBlocksFieldsTest extends TestCase {

	/**
	 * Customer keys carry no leading underscore; order keys do.
	 */
	public function test_legacy_meta_key(): void {
		$this->assertSame( 'billing_cpf', CheckoutBlocksFields::legacy_meta_key( 'pagbank/cpf', 'billing', false ) );
		$this->assertSame( '_billing_cpf', CheckoutBlocksFields::legacy_meta_key( 'pagbank/cpf', 'billing', true ) );
		$this->assertSame( 'billing_persontype', CheckoutBlocksFields::legacy_meta_key( 'pagbank/persontype', 'billing', false ) );
		$this->assertSame( '_shipping_number', CheckoutBlocksFields::legacy_meta_key( 'pagbank/address-number', 'shipping', true ) );
		$this->assertSame( 'shipping_neighborhood', CheckoutBlocksFields::legacy_meta_key( 'pagbank/neighborhood', 'shipping', false ) );
		$this->assertSame( 'billing_cellphone', CheckoutBlocksFields::legacy_meta_key( 'pagbank/cellphone', 'billing', false ) );
	}

	/**
	 * The company maps to a core prop and the `other` group has no interop meta.
	 */
	public function test_legacy_meta_key_without_interop_meta(): void {
		$this->assertSame( '', CheckoutBlocksFields::legacy_meta_key( 'pagbank/company', 'billing', false ) );
		$this->assertSame( '', CheckoutBlocksFields::legacy_meta_key( 'pagbank/cpf', 'other', false ) );
		$this->assertSame( '', CheckoutBlocksFields::legacy_meta_key( 'acme/field', 'billing', true ) );
	}

	/**
	 * Interop customer keys resolve back to their group and field.
	 */
	public function test_blocks_field_for_legacy_key(): void {
		$this->assertSame(
			array(
				'group' => 'billing',
				'id'    => 'pagbank/cpf',
			),
			CheckoutBlocksFields::blocks_field_for_legacy_key( 'billing_cpf' )
		);

		$this->assertSame(
			array(
				'group' => 'shipping',
				'id'    => 'pagbank/address-number',
			),
			CheckoutBlocksFields::blocks_field_for_legacy_key( 'shipping_number' )
		);
	}

	/**
	 * Core and unknown keys have no field.
	 */
	public function test_blocks_field_for_unknown_legacy_key(): void {
		$this->assertSame( array(), CheckoutBlocksFields::blocks_field_for_legacy_key( 'billing_company' ) );
		$this->assertSame( array(), CheckoutBlocksFields::blocks_field_for_legacy_key( 'billing_first_name' ) );
		$this->assertSame( array(), CheckoutBlocksFields::blocks_field_for_legacy_key( '_billing_cpf' ) );
		$this->assertSame( array(), CheckoutBlocksFields::blocks_field_for_legacy_key( 'cpf' ) );
	}

	/**
	 * Every interop key round-trips through the field it maps to.
	 */
	public function test_mapping_round_trip(): void {
		foreach ( array( 'billing_persontype', 'billing_cpf', 'billing_cnpj', 'billing_number', 'billing_neighborhood', 'billing_cellphone', 'shipping_number', 'shipping_neighborhood' ) as $legacy_key ) {
			$field = CheckoutBlocksFields::blocks_field_for_legacy_key( $legacy_key );

			$this->assertSame( $legacy_key, CheckoutBlocksFields::legacy_meta_key( $field['id'], $field['group'], false ) );
		}
	}

	/**
	 * Matches the meta key WooCommerce stores the fields under.
	 */
	public function test_blocks_meta_key(): void {
		$this->assertSame( '_wc_billing/pagbank/cpf', CheckoutBlocksFields::blocks_meta_key( 'billing', 'pagbank/cpf' ) );
		$this->assertSame( '_wc_shipping/pagbank/neighborhood', CheckoutBlocksFields::blocks_meta_key( 'shipping', 'pagbank/neighborhood' ) );
	}
}
