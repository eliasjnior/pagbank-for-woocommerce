/**
 * Classic checkout: carry a per-submission identifier with the payment request.
 *
 * @package PagBank_WooCommerce
 */

import { createSubmissionId, SUBMISSION_ID_FIELD } from "@/shared/submission-id";

const FORM_SELECTORS = ["form.checkout", "form#order_review"];

const getForms = (): HTMLFormElement[] =>
	FORM_SELECTORS.flatMap((selector) =>
		Array.from(document.querySelectorAll<HTMLFormElement>(selector)),
	);

/**
 * Write a fresh identifier into every checkout form on the page.
 *
 * The input is appended to the form itself rather than to the review-order
 * container, which WooCommerce replaces wholesale on `updated_checkout`.
 */
const refreshSubmissionId = (): void => {
	for (const form of getForms()) {
		let input = form.querySelector<HTMLInputElement>(`input[name="${SUBMISSION_ID_FIELD}"]`);

		if (!input) {
			input = document.createElement("input");
			input.type = "hidden";
			input.name = SUBMISSION_ID_FIELD;
			form.appendChild(input);
		}

		input.value = createSubmissionId();
	}
};

jQuery(() => {
	refreshSubmissionId();

	// A declined payment keeps the same order and the same DOM, so without this
	// the next attempt would reuse the identifier and PagBank would answer
	// IDEMPOTENCY_CONFLICT instead of charging the new card.
	jQuery(document.body).on("checkout_error", refreshSubmissionId);

	// `updated_checkout` can introduce a form that was not in the initial DOM.
	jQuery(document.body).on("updated_checkout", refreshSubmissionId);
});

// A page restored from the back/forward cache still holds the identifier it was
// submitted with.
window.addEventListener("pageshow", (event) => {
	if (event.persisted) {
		refreshSubmissionId();
	}
});
