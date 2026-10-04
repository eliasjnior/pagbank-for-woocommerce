/**
 * PagBank Boleto - WooCommerce Checkout Blocks Integration.
 *
 * @package PagBank_WooCommerce
 */

import { registerPaymentMethod } from "@woocommerce/blocks-registry";
import { getSetting } from "@woocommerce/settings";
import type { EmitResponseProps, EventRegistrationProps } from "@woocommerce/types";
import { decodeEntities } from "@wordpress/html-entities";
import { Label, useSubmissionId } from "../shared";

interface PaymentMethodSettings {
	title: string;
	description: string;
	icon: string;
	supports: string[];
}

const settings = getSetting<PaymentMethodSettings>("pagbank_boleto_data", {
	title: "Boleto",
	description: "O boleto será gerado assim que você finalizar o pedido.",
	icon: "",
	supports: [],
});

interface ContentProps {
	eventRegistration: EventRegistrationProps;
	emitResponse: EmitResponseProps;
}

const Content = ({ eventRegistration, emitResponse }: ContentProps): JSX.Element => {
	useSubmissionId({ eventRegistration, emitResponse });

	return (
		<div className="pagbank-boleto-description">
			{decodeEntities(settings.description || "")}
		</div>
	);
};

registerPaymentMethod({
	name: "pagbank_boleto",
	label: <Label title={settings.title} icon={settings.icon} />,
	// @ts-expect-error: WooCommerce Blocks injects props at runtime.
	content: <Content />,
	// @ts-expect-error: WooCommerce Blocks injects props at runtime.
	edit: <Content />,
	canMakePayment: () => true,
	ariaLabel: decodeEntities(settings.title),
	supports: {
		features: settings.supports,
	},
});
