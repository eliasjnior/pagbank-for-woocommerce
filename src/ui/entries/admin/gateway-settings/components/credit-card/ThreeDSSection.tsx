/**
 * 3DS section component for credit card settings.
 *
 * @package PagBank_WooCommerce
 */

import { __ } from "@wordpress/i18n";
import { useWatch } from "react-hook-form";
import { useFormContext } from "../../context";
import type { GatewaySettings, YesNo } from "../../schemas/settings";
import { AnimatedField, SettingsCard } from "../common";
import { FormToggle } from "../form";

export const ThreeDSSection = () => {
	const { form } = useFormContext();

	const threedsEnabled = useWatch({
		control: form.control,
		name: "threeds_enabled" as keyof GatewaySettings,
	}) as YesNo;

	const allowUnsupported = useWatch({
		control: form.control,
		name: "threeds_allow_unsupported" as keyof GatewaySettings,
	}) as YesNo;

	return (
		<SettingsCard title={__("Autenticação 3DS", "pagbank-for-woocommerce")}>
			<div className="pagbank-settings-field">
				<FormToggle
					name={"threeds_enabled" as keyof GatewaySettings}
					label={__("Habilitar autenticação 3DS", "pagbank-for-woocommerce")}
					help={__(
						"3DS (3D Secure) adiciona uma camada extra de segurança nas transações com cartão de crédito.",
						"pagbank-for-woocommerce",
					)}
				/>
			</div>

			<AnimatedField visible={threedsEnabled === "yes"}>
				<div className="pagbank-settings-field">
					<FormToggle
						name={"threeds_allow_unsupported" as keyof GatewaySettings}
						label={__(
							"Aceitar cartões não elegíveis ao 3DS",
							"pagbank-for-woocommerce",
						)}
						help={__(
							"Alguns emissores não oferecem 3DS. Com esta opção, esses cartões são cobrados sem autenticação e a responsabilidade por chargeback de fraude permanece com a loja.",
							"pagbank-for-woocommerce",
						)}
					/>
				</div>

				<AnimatedField visible={allowUnsupported === "yes"}>
					<div className="pagbank-settings-field">
						<FormToggle
							name={"threeds_manual_review" as keyof GatewaySettings}
							label={__(
								"Revisar manualmente os pagamentos sem 3DS",
								"pagbank-for-woocommerce",
							)}
							help={__(
								"O pagamento é apenas pré-autorizado e o pedido fica Aguardando até você aprovar ou recusar na tela do pedido. A pré-autorização vale por poucos dias (em geral 5) e o prazo exato aparece em cada pedido; sem aprovação até lá, o PagBank a cancela e o pedido é cancelado. Não se aplica a assinaturas.",
								"pagbank-for-woocommerce",
							)}
						/>
					</div>
				</AnimatedField>
			</AnimatedField>
		</SettingsCard>
	);
};
