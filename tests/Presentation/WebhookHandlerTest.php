<?php
/**
 * Tests for the pure parts of WebhookHandler.
 *
 * @package PagBank_WooCommerce\Tests\Presentation
 */

namespace PagBank_WooCommerce\Tests\Presentation;

use PagBank_WooCommerce\Presentation\WebhookHandler;
use PHPUnit\Framework\TestCase;

/**
 * Class WebhookHandlerTest.
 */
class WebhookHandlerTest extends TestCase {

	private const REFERENCE_ID = '61:b68b8173106b68cddbcd8e94557bcc95';

	/**
	 * The parser returns a discriminated structure: legacy JSON payloads carry
	 * id+password, the new `{order_id}:{hash}` form returns the order id with
	 * the 32-char hex signature, and anything else is rejected.
	 *
	 * @dataProvider reference_id_provider
	 *
	 * @param string|null $raw      Raw reference_id as sent by the webhook payload.
	 * @param array|null  $expected Expected parse result (null = should not parse).
	 */
	public function test_parse_reference_id( ?string $raw, ?array $expected ): void {
		$this->assertSame( $expected, WebhookHandler::parse_reference_id( $raw ) );
	}

	public function reference_id_provider(): array {
		$hash = str_repeat( 'a1b2c3d4', 4 );

		return array(
			'legacy json with id and password'    => array(
				'{"id":"456","password":"secret"}',
				array(
					'kind'     => 'json',
					'order_id' => 456,
					'password' => 'secret',
				),
			),
			'legacy json with integer id'         => array(
				'{"id":789,"password":"x"}',
				array(
					'kind'     => 'json',
					'order_id' => 789,
					'password' => 'x',
				),
			),
			'legacy json without password'        => array( '{"id":"42"}', null ),
			'legacy json with empty password'     => array( '{"id":"42","password":""}', null ),
			'json without id key'                 => array( '{"password":"x"}', null ),
			'json with zero id'                   => array( '{"id":0,"password":"x"}', null ),
			'json with negative id'               => array( '{"id":-5,"password":"x"}', null ),
			'empty json object'                   => array( '{}', null ),
			'signed id:hash'                      => array(
				"123:{$hash}",
				array(
					'kind'     => 'signed',
					'order_id' => 123,
					'hash'     => $hash,
				),
			),
			'signed hash too short'               => array( '123:' . str_repeat( 'a', 16 ), null ),
			'signed hash too long'                => array( '123:' . str_repeat( 'a', 33 ), null ),
			'signed uppercase hex rejected'       => array( '123:' . strtoupper( $hash ), null ),
			'signed with zero id'                 => array( "0:{$hash}", null ),
			'signed missing separator'            => array( '123' . $hash, null ),
			'signed bare hash without id'         => array( $hash, null ),
			'plain numeric string rejected'       => array( '123', null ),
			'non-numeric non-hex string rejected' => array( 'abc', null ),
			'empty string'                        => array( '', null ),
			'null'                                => array( null, null ),
		);
	}

	/**
	 * The status mapper covers every status the plugin reacts to. Unknown
	 * statuses fall through to null so the handler can no-op safely.
	 *
	 * @dataProvider charge_status_provider
	 *
	 * @param string      $charge_status The status string from the PagBank charge payload.
	 * @param string|null $expected      Expected action keyword.
	 */
	public function test_map_charge_status( string $charge_status, ?string $expected ): void {
		$this->assertSame( $expected, WebhookHandler::map_charge_status( $charge_status ) );
	}

	public function charge_status_provider(): array {
		return array(
			'IN_ANALYSIS maps to on-hold' => array( 'IN_ANALYSIS', 'on-hold' ),
			'WAITING maps to on-hold'     => array( 'WAITING', 'on-hold' ),
			'DECLINED maps to failed'     => array( 'DECLINED', 'failed' ),
			'PAID maps to completed'      => array( 'PAID', 'completed' ),
			'CANCELED maps to cancelled'  => array( 'CANCELED', 'cancelled' ),
			'unknown status is ignored'   => array( 'AUTHORIZED', null ),
			'empty string is ignored'     => array( '', null ),
		);
	}

	/**
	 * The GET /orders fallback only yields a charge when the order, its reference,
	 * the charge id and the amount all match what the plugin sent for the WC order.
	 *
	 * @dataProvider order_charge_provider
	 *
	 * @param array             $pagbank_order Decoded GET /orders/{id} response.
	 * @param array|string|null $expected      Expected charge, or rejection reason.
	 */
	public function test_verify_order_charge( array $pagbank_order, $expected ): void {
		$result = WebhookHandler::verify_order_charge(
			$pagbank_order,
			'ORDE_1',
			self::REFERENCE_ID,
			'CHAR_1',
			500,
			'BRL'
		);

		$this->assertSame( $expected ?? $pagbank_order['charges'][0], $result );
	}

	public function order_charge_provider(): array {
		$charge = array(
			'id'           => 'CHAR_1',
			'reference_id' => self::REFERENCE_ID,
			'status'       => 'PAID',
			'amount'       => array(
				'value'    => 500,
				'currency' => 'BRL',
				'summary'  => array(
					'total'    => 500,
					'paid'     => 500,
					'refunded' => 0,
				),
			),
		);

		$order = static function ( array $charge_overrides = array(), array $order_overrides = array() ) use ( $charge ): array {
			return array_replace(
				array(
					'id'           => 'ORDE_1',
					'reference_id' => self::REFERENCE_ID,
					'charges'      => array( array_replace_recursive( $charge, $charge_overrides ) ),
				),
				$order_overrides
			);
		};

		$without_charge_reference = $charge;
		unset( $without_charge_reference['reference_id'] );

		return array(
			'matching order returns the charge'      => array( $order(), null ),
			'charge without reference_id is accepted' => array(
				$order( array(), array( 'charges' => array( $without_charge_reference ) ) ),
				null,
			),
			'charge found among several'             => array(
				$order( array(), array( 'charges' => array( array_replace( $charge, array( 'id' => 'CHAR_0' ) ), $charge ) ) ),
				$charge,
			),
			'different order id'                     => array( $order( array(), array( 'id' => 'ORDE_2' ) ), 'order_id_mismatch' ),
			'different order reference'              => array( $order( array(), array( 'reference_id' => '62:' . str_repeat( 'a', 32 ) ) ), 'reference_id_mismatch' ),
			'missing order reference'                => array( $order( array(), array( 'reference_id' => null ) ), 'reference_id_mismatch' ),
			'different charge id'                    => array( $order( array( 'id' => 'CHAR_2' ) ), 'charge_not_found' ),
			'no charges'                             => array( $order( array(), array( 'charges' => array() ) ), 'charge_not_found' ),
			'different charge reference'             => array( $order( array( 'reference_id' => 'other' ) ), 'charge_reference_id_mismatch' ),
			'different amount'                       => array( $order( array( 'amount' => array( 'value' => 1 ) ) ), 'amount_mismatch' ),
			'amount as string'                       => array( $order( array( 'amount' => array( 'value' => '500' ) ) ), 'amount_mismatch' ),
			'different currency'                     => array( $order( array( 'amount' => array( 'currency' => 'USD' ) ) ), 'currency_mismatch' ),
		);
	}
}
