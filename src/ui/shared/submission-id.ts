/**
 * Per-submission identifier used to scope the PagBank idempotency key.
 *
 * @package PagBank_WooCommerce
 */

/**
 * Name of the field read by ApiHelpers::get_request_submission_id() (PHP).
 */
export const SUBMISSION_ID_FIELD = "pagbank_submission_id";

/**
 * Create an identifier for a single payment attempt.
 *
 * PagBank dedupes two requests carrying the same `x-idempotency-key`, so the
 * value has to be stable while one attempt is in flight and new on the next
 * one — resending an attempt must collapse, retrying must not.
 */
export const createSubmissionId = (): string => {
	if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
		return crypto.randomUUID();
	}

	if (typeof crypto !== "undefined" && typeof crypto.getRandomValues === "function") {
		const bytes = crypto.getRandomValues(new Uint8Array(16));

		return Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
	}

	return `${Date.now().toString(16)}${Math.random().toString(16).slice(2)}`;
};
