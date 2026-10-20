<?php
/**
 * Tests for the WooCommerce email hook callbacks.
 *
 * These hooks are loosely typed, so the callbacks must survive whatever a third
 * party passes. See CLAUDE.md.
 *
 * @package PagBank_WooCommerce\Tests\Presentation
 */

namespace PagBank_WooCommerce\Tests\Presentation;

use PagBank_WooCommerce\Presentation\Hooks;
use PHPUnit\Framework\TestCase;
use WC_Order;

/**
 * Class HooksEmailTest.
 */
class HooksEmailTest extends TestCase {

	/**
	 * Reset the state the bootstrap shims record into.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['pagbank_test_orders']              = array();
		$GLOBALS['pagbank_test_rendered_templates']  = array();
	}

	/**
	 * A pending Pix order.
	 */
	private function pix_order( bool $paid = false ): WC_Order {
		return new WC_Order(
			1,
			'pagbank_pix',
			$paid,
			array(
				'_pagbank_pix_expiration_date' => '2026-08-30T12:00:00-03:00',
				'_pagbank_pix_text'            => '00020126...',
				'_pagbank_pix_qr_code'         => 'https://pagbank.test/qr.png',
			)
		);
	}

	/**
	 * A pending boleto order.
	 */
	private function boleto_order( bool $paid = false ): WC_Order {
		return new WC_Order(
			2,
			'pagbank_boleto',
			$paid,
			array(
				'_pagbank_boleto_expiration_date' => '2026-08-30',
				'_pagbank_boleto_barcode'         => '34191...',
				'_pagbank_boleto_link_pdf'        => 'https://pagbank.test/boleto.pdf',
				'_pagbank_boleto_link_png'        => 'https://pagbank.test/boleto.png',
			)
		);
	}

	/**
	 * A WooCommerce email object.
	 *
	 * @param string $id Email id.
	 */
	private function email( string $id ) {
		return (object) array( 'id' => $id );
	}

	/**
	 * Templates rendered through the `wc_get_template` shim.
	 */
	private function rendered_templates(): array {
		return array_column( $GLOBALS['pagbank_test_rendered_templates'], 'template' );
	}

	/**
	 * The reported regression: `true` where the email object belongs must be a
	 * no-op, not a TypeError.
	 */
	public function test_pix_callback_survives_boolean_in_the_email_argument(): void {
		Hooks::get_instance()->add_pix_details_to_email( $this->pix_order(), false, false, true );

		$this->assertSame( array(), $this->rendered_templates() );
	}

	/**
	 * Same for the boleto callback.
	 */
	public function test_boleto_callback_survives_boolean_in_the_email_argument(): void {
		Hooks::get_instance()->add_boleto_details_to_email( $this->boleto_order(), false, false, true );

		$this->assertSame( array(), $this->rendered_templates() );
	}

	/**
	 * WP_Hook forwards only as many arguments as it received.
	 */
	public function test_callbacks_survive_missing_arguments(): void {
		$hooks = Hooks::get_instance();

		$hooks->add_pix_details_to_email( $this->pix_order() );
		$hooks->add_boleto_details_to_email( $this->boleto_order() );

		$this->assertSame( array(), $this->rendered_templates() );
	}

	/**
	 * Every shape a third party has been seen putting in the email argument.
	 *
	 * @dataProvider hostile_email_arguments
	 *
	 * @param mixed $email Value passed where the email object belongs.
	 */
	public function test_callbacks_survive_hostile_email_arguments( $email ): void {
		Hooks::get_instance()->add_pix_details_to_email( $this->pix_order(), false, false, $email );

		$this->assertSame( array(), $this->rendered_templates() );
	}

	/**
	 * Hostile values for the email argument.
	 */
	public static function hostile_email_arguments(): array {
		return array(
			'boolean true'       => array( true ),
			'boolean false'      => array( false ),
			'null'               => array( null ),
			'empty string'       => array( '' ),
			'integer'            => array( 0 ),
			'array'              => array( array( 'id' => 'customer_processing_order' ) ),
			'object without id'  => array( (object) array( 'heading' => 'Hello' ) ),
			'unrelated email id' => array( (object) array( 'id' => 'customer_completed_order' ) ),
		);
	}

	/**
	 * An order id in the order argument still resolves — some callers pass one.
	 */
	public function test_callbacks_survive_hostile_order_arguments(): void {
		$hooks = Hooks::get_instance();
		$email = $this->email( 'customer_processing_order' );

		$hooks->add_pix_details_to_email( true, false, false, $email );
		$hooks->add_pix_details_to_email( null, false, false, $email );
		$hooks->add_pix_details_to_email( 'not-an-order', false, false, $email );
		$hooks->add_pix_details_to_email( 999, false, false, $email );

		$this->assertSame( array(), $this->rendered_templates() );
	}

	/**
	 * Orders paid with another gateway never reach the template.
	 */
	public function test_other_gateways_are_skipped(): void {
		$order = new WC_Order( 3, 'stripe', false, array() );

		Hooks::get_instance()->add_pix_details_to_email( $order, false, false, $this->email( 'customer_processing_order' ) );

		$this->assertSame( array(), $this->rendered_templates() );
	}

	/**
	 * The happy path still renders the Pix instructions.
	 */
	public function test_pix_instructions_are_rendered_for_pending_orders(): void {
		Hooks::get_instance()->add_pix_details_to_email( $this->pix_order(), false, false, $this->email( 'customer_on_hold_order' ) );

		$this->assertSame( array( 'emails/email-pix-instructions.php' ), $this->rendered_templates() );
		$this->assertSame( '00020126...', $GLOBALS['pagbank_test_rendered_templates'][0]['args']['pix_text'] );
	}

	/**
	 * Plain text emails get the plain text template.
	 */
	public function test_pix_instructions_use_the_plain_text_template(): void {
		Hooks::get_instance()->add_pix_details_to_email( $this->pix_order(), false, true, $this->email( 'customer_processing_order' ) );

		$this->assertSame( array( 'emails/plain/email-pix-instructions.php' ), $this->rendered_templates() );
	}

	/**
	 * The happy path still renders the boleto instructions.
	 */
	public function test_boleto_instructions_are_rendered_for_pending_orders(): void {
		Hooks::get_instance()->add_boleto_details_to_email( $this->boleto_order(), false, false, $this->email( 'customer_on_hold_order' ) );

		$this->assertSame( array( 'emails/email-boleto-instructions.php' ), $this->rendered_templates() );
	}

	/**
	 * Admin emails and already paid orders are still excluded.
	 */
	public function test_admin_emails_and_paid_orders_are_skipped(): void {
		$hooks = Hooks::get_instance();
		$email = $this->email( 'customer_processing_order' );

		$hooks->add_pix_details_to_email( $this->pix_order(), true, false, $email );
		$hooks->add_pix_details_to_email( $this->pix_order( true ), false, false, $email );

		$this->assertSame( array(), $this->rendered_templates() );
	}

	/**
	 * This filter carries `WC_Email::$object`, documented as `object|bool`.
	 *
	 * @dataProvider hostile_attachment_objects
	 *
	 * @param mixed $object Value passed where the order belongs.
	 */
	public function test_attachments_filter_survives_hostile_objects( $object ): void {
		$attachments = Hooks::get_instance()->attach_boleto_pdf_to_email( array( 'existing.pdf' ), 'customer_on_hold_order', $object );

		$this->assertSame( array( 'existing.pdf' ), $attachments );
	}

	/**
	 * Hostile values for the attachment filter's object argument.
	 */
	public static function hostile_attachment_objects(): array {
		return array(
			'boolean false'  => array( false ),
			'null'           => array( null ),
			'wp user object' => array( (object) array( 'ID' => 7 ) ),
			'string'         => array( 'customer@example.com' ),
		);
	}

	/**
	 * A non-array first argument still yields an array, as the filter promises.
	 */
	public function test_attachments_filter_always_returns_an_array(): void {
		$this->assertSame( array(), Hooks::get_instance()->attach_boleto_pdf_to_email( null, 'customer_on_hold_order', false ) );
	}

	/**
	 * The cleanup callback is a no-op when the email object is not usable.
	 */
	public function test_cleanup_survives_hostile_email_arguments(): void {
		$hooks = Hooks::get_instance();

		$hooks->cleanup_boleto_pdfs_after_email( true, 'customer_on_hold_order', true );
		$hooks->cleanup_boleto_pdfs_after_email( true, 'customer_on_hold_order', null );
		$hooks->cleanup_boleto_pdfs_after_email();

		$this->assertTrue( true );
	}

	/**
	 * Email id resolution across every shape callers use.
	 */
	public function test_resolve_email_id(): void {
		$this->assertSame( 'customer_on_hold_order', Hooks::resolve_email_id( (object) array( 'id' => 'customer_on_hold_order' ) ) );
		$this->assertSame( 'customer_note', Hooks::resolve_email_id( 'customer_note' ) );
		$this->assertSame( '', Hooks::resolve_email_id( true ) );
		$this->assertSame( '', Hooks::resolve_email_id( null ) );
		$this->assertSame( '', Hooks::resolve_email_id( 42 ) );
		$this->assertSame( '', Hooks::resolve_email_id( (object) array( 'heading' => 'Hi' ) ) );
		$this->assertSame( '', Hooks::resolve_email_id( (object) array( 'id' => array( 'nope' ) ) ) );
	}

	/**
	 * Order resolution across every shape callers use.
	 */
	public function test_resolve_order(): void {
		$order = $this->pix_order();

		$GLOBALS['pagbank_test_orders'][ $order->get_id() ] = $order;

		$this->assertSame( $order, Hooks::resolve_order( $order ) );
		$this->assertSame( $order, Hooks::resolve_order( $order->get_id() ) );
		$this->assertSame( $order, Hooks::resolve_order( (string) $order->get_id() ) );
		$this->assertNull( Hooks::resolve_order( 999 ) );
		$this->assertNull( Hooks::resolve_order( true ) );
		$this->assertNull( Hooks::resolve_order( null ) );
		$this->assertNull( Hooks::resolve_order( 'abc' ) );
		$this->assertNull( Hooks::resolve_order( (object) array( 'ID' => 7 ) ) );
	}

	/**
	 * The allow-list decision itself.
	 */
	public function test_should_render_payment_instructions(): void {
		$allowed = array( 'customer_on_hold_order', 'customer_processing_order' );

		$this->assertTrue( Hooks::should_render_payment_instructions( $allowed, 'customer_on_hold_order', false, false ) );
		$this->assertFalse( Hooks::should_render_payment_instructions( $allowed, 'customer_on_hold_order', true, false ) );
		$this->assertFalse( Hooks::should_render_payment_instructions( $allowed, 'customer_on_hold_order', false, true ) );
		$this->assertFalse( Hooks::should_render_payment_instructions( $allowed, 'customer_completed_order', false, false ) );
		$this->assertFalse( Hooks::should_render_payment_instructions( $allowed, '', false, false ) );
		$this->assertFalse( Hooks::should_render_payment_instructions( array(), 'customer_on_hold_order', false, false ) );
	}

	/**
	 * A filtered allow-list with non-string entries still compares strictly.
	 */
	public function test_should_render_payment_instructions_normalises_the_allow_list(): void {
		$this->assertTrue( Hooks::should_render_payment_instructions( array( 123 ), '123', false, false ) );
	}
}
