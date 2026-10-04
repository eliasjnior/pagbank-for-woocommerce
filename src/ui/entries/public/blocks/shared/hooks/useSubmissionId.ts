/**
 * Send a per-submission identifier with the payment request.
 *
 * @package PagBank_WooCommerce
 */

import type { EmitResponseProps, EventRegistrationProps } from "@woocommerce/types";
import { useEffect } from "react";
import { createSubmissionId, SUBMISSION_ID_FIELD } from "@/shared/submission-id";

interface UseSubmissionIdParams {
	eventRegistration: EventRegistrationProps;
	emitResponse: EmitResponseProps;
}

/**
 * For payment methods that carry no other payment data of their own.
 *
 * Methods that already return `paymentMethodData` add the field to their own
 * object instead: only one `onPaymentSetup` observer per payment method is
 * guaranteed to have its data forwarded, so registering a second one here
 * would race with theirs.
 */
export const useSubmissionId = ({
	eventRegistration,
	emitResponse,
}: UseSubmissionIdParams): void => {
	// biome-ignore lint/correctness/useExhaustiveDependencies: eventRegistration and emitResponse are stable WooCommerce Blocks references.
	useEffect(() => {
		const unsubscribe = eventRegistration.onPaymentSetup(async () => ({
			type: emitResponse.responseTypes.SUCCESS,
			meta: {
				paymentMethodData: {
					[SUBMISSION_ID_FIELD]: createSubmissionId(),
				},
			},
		}));

		return unsubscribe;
	}, []);
};
