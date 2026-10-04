/**
 * Saved token content component for credit card payments.
 *
 * @package PagBank_WooCommerce
 */

import type {
	BillingDataProps,
	EmitResponseProps,
	EventRegistrationProps,
} from "@woocommerce/types";
import { useEffect, useRef } from "react";
import { createSubmissionId, SUBMISSION_ID_FIELD } from "@/shared/submission-id";
import { useInstallments } from "../hooks/useInstallments";
import { settings } from "../settings";
import { InstallmentsSelect } from "./InstallmentsSelect";

interface SavedTokenContentProps {
	token: string;
	activePaymentMethod: string;
	eventRegistration: EventRegistrationProps;
	emitResponse: EmitResponseProps;
	billing: BillingDataProps;
}

export const SavedTokenContent = ({
	token,
	eventRegistration,
	emitResponse,
	billing,
}: SavedTokenContentProps): JSX.Element | null => {
	const { installments, setInstallments, installmentPlans, isLoading } = useInstallments({
		cartTotalInCents: billing.cartTotal.value,
		paymentToken: token,
	});

	// Ref for installments to avoid re-registering callback
	const installmentsRef = useRef(installments);

	useEffect(() => {
		installmentsRef.current = installments;
	}, [installments]);

	// Payment setup handler for saved token
	// biome-ignore lint/correctness/useExhaustiveDependencies: eventRegistration and emitResponse are stable WooCommerce Blocks references.
	useEffect(() => {
		const unsubscribe = eventRegistration.onPaymentSetup(async () => {
			return {
				type: emitResponse.responseTypes.SUCCESS,
				meta: {
					paymentMethodData: {
						[SUBMISSION_ID_FIELD]: createSubmissionId(),
						"wc-pagbank_credit_card-payment-token": token,
						"pagbank_credit_card-installments": installmentsRef.current,
					},
				},
			};
		});

		return unsubscribe;
	}, [token]);

	if (!settings.installments_enabled || settings.cart_has_subscription) {
		return null;
	}

	return (
		<div className="pagbank-credit-card-saved-token">
			<InstallmentsSelect
				id={`pagbank-installments-${token}`}
				value={installments}
				onChange={setInstallments}
				plans={installmentPlans}
				isLoading={isLoading}
			/>
		</div>
	);
};
