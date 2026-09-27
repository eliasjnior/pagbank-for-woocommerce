<?php
/**
 * Tests for CPF/CNPJ parsing helpers, including the new alphanumeric CNPJ format.
 *
 * @package PagBank_WooCommerce\Tests\Presentation
 */

namespace PagBank_WooCommerce\Tests\Presentation;

use PagBank_WooCommerce\Presentation\Helpers;
use PHPUnit\Framework\TestCase;

/**
 * Class HelpersTest.
 */
class HelpersTest extends TestCase {

	/**
	 * With the alphanumeric flag OFF (default), an alphanumeric CNPJ must be
	 * rejected because letters are stripped and the value no longer validates.
	 */
	public function test_parse_rejects_alphanumeric_cnpj_when_flag_disabled(): void {
		$parsed = Helpers::parse_cpf_or_cnpj( '12.ABC.345/01DE-35' );

		$this->assertFalse( $parsed['is_valid'] );
		$this->assertSame( 'unknown', $parsed['type'] );
		$this->assertNull( $parsed['value'] );
	}

	/**
	 * A traditional numeric CNPJ keeps working regardless of the flag.
	 */
	public function test_parse_accepts_numeric_cnpj_when_flag_disabled(): void {
		$parsed = Helpers::parse_cpf_or_cnpj( '11.222.333/0001-81' );

		$this->assertTrue( $parsed['is_valid'] );
		$this->assertSame( 'cnpj', $parsed['type'] );
		$this->assertSame( '11222333000181', $parsed['value'] );
	}

	/**
	 * With the flag OFF, sanitize_cnpj keeps the legacy digits-only behavior.
	 */
	public function test_sanitize_cnpj_strips_letters_when_flag_disabled(): void {
		$this->assertSame( '123450135', Helpers::sanitize_cnpj( '12.ABC.345/01DE-35' ) );
	}

	/**
	 * With the alphanumeric flag ON, the whole CPF/CNPJ pipeline must preserve
	 * letters and validate/format the new alphanumeric CNPJ end-to-end.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_alphanumeric_cnpj_is_supported_when_flag_enabled(): void {
		define( 'PAGBANK_FEATURE_FLAG_ALPHANUMERIC_CNPJ_ENABLED', true );

		// Sanitization preserves letters and uppercases them.
		$this->assertSame( '12ABC34501DE35', Helpers::sanitize_cnpj( '12.abc.345/01de-35' ) );

		// Parsing recognizes the alphanumeric CNPJ and returns the raw value.
		$parsed = Helpers::parse_cpf_or_cnpj( '12.ABC.345/01DE-35' );
		$this->assertTrue( $parsed['is_valid'] );
		$this->assertSame( 'cnpj', $parsed['type'] );
		$this->assertSame( '12ABC34501DE35', $parsed['value'] );

		// Formatting applies the standard CNPJ mask to the alphanumeric value.
		$this->assertSame( '12.ABC.345/01DE-35', Helpers::format_cnpj( '12ABC34501DE35' ) );

		// Traditional numeric CNPJ and CPF remain valid with the flag enabled.
		$numeric = Helpers::parse_cpf_or_cnpj( '11.222.333/0001-81' );
		$this->assertTrue( $numeric['is_valid'] );
		$this->assertSame( 'cnpj', $numeric['type'] );
		$this->assertSame( '11222333000181', $numeric['value'] );

		$cpf = Helpers::parse_cpf_or_cnpj( '111.444.777-35' );
		$this->assertTrue( $cpf['is_valid'] );
		$this->assertSame( 'cpf', $cpf['type'] );
		$this->assertSame( '11144477735', $cpf['value'] );
	}

	/**
	 * Regression: the international format's +55 was read back as the DDD,
	 * turning "+55 11 99999-9999" into "(55) 11999-9999".
	 *
	 * @dataProvider cellphone_format_provider
	 */
	public function test_format_cellphone( string $input, string $expected ): void {
		$this->assertSame( $expected, Helpers::format_cellphone( $input ) );
	}

	public function cellphone_format_provider(): array {
		return array(
			'as typed'                   => array( '(11) 99999-9999', '(11) 99999-9999' ),
			'digits only'                => array( '11999999999', '(11) 99999-9999' ),
			'the old stored format'      => array( '+55 11 99999-9999', '(11) 99999-9999' ),
			'E164'                       => array( '+5511999999999', '(11) 99999-9999' ),
			'landline length'            => array( '(11) 3333-3333', '(11) 3333-3333' ),
			'area code 55 is a real DDD' => array( '(55) 99999-9999', '(55) 99999-9999' ),
		);
	}

	/**
	 * Keeping the raw value lets validation show what the customer typed.
	 */
	public function test_format_cellphone_keeps_unparseable_input(): void {
		$this->assertSame( '', Helpers::format_cellphone( '' ) );
		$this->assertSame( 'abc', Helpers::format_cellphone( 'abc' ) );
	}

	/**
	 * Otherwise the stored value drifts every time the customer reopens the
	 * checkout.
	 */
	public function test_format_cellphone_is_idempotent(): void {
		$once  = Helpers::format_cellphone( '+55 11 99999-9999' );
		$twice = Helpers::format_cellphone( $once );

		$this->assertSame( $once, $twice );
	}

	/**
	 * @dataProvider cellphone_validity_provider
	 */
	public function test_is_valid_cellphone( string $input, bool $expected ): void {
		$this->assertSame( $expected, Helpers::is_valid_cellphone( $input ) );
	}

	public function cellphone_validity_provider(): array {
		return array(
			'national'      => array( '(11) 99999-9999', true ),
			'international' => array( '+55 11 99999-9999', true ),
			'digits only'   => array( '11999999999', true ),
			'empty'         => array( '', false ),
			'letters'       => array( 'abc', false ),
			'too short'     => array( '1199', false ),
			'bad area code' => array( '(00) 99999-9999', false ),
		);
	}
}
