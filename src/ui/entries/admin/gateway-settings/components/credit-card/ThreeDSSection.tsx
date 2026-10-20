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
			</AnimatedField>
		</SettingsCard>
	);
};
