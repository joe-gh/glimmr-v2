<?php
/**
 * Order Resolver Tool
 *
 * Resolves order number/email to order ID with verification status.
 * Pre-validates access before order_status tool is called.
 *
 * @package Glimmr_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Glimmr_AI_Tool_Resolve_Order
 *
 * Resolver tool that maps order references to order IDs and checks
 * verification requirements for guest access.
 */
class Glimmr_AI_Tool_Resolve_Order extends Glimmr_AI_Tool_Base {

	/**
	 * Tool name.
	 *
	 * @var string
	 */
	protected $name = 'resolve_order';

	/**
	 * Tool description.
	 *
	 * @var string
	 */
	protected $description = 'Resolve order number to order ID and check verification requirements. Use to pre-validate before order_status when unsure if user has access.';

	/**
	 * Tool parameters.
	 *
	 * @var array
	 */
	protected $parameters = array(
		'order_number' => array(
			'type'        => 'string',
			'description' => 'Order number to look up (e.g., "10492" or "#10492")',
			'required'    => true,
			'maxLength'   => 50,
		),
		'email' => array(
			'type'        => 'string',
			'description' => 'Email address for verification (guests)',
			'maxLength'   => 254,
		),
		'zip' => array(
			'type'        => 'string',
			'description' => 'Billing zip code for additional verification (optional)',
			'maxLength'   => 20,
		),
	);

	/**
	 * Rate limit key prefix.
	 *
	 * @var string
	 */
	const RATE_LIMIT_PREFIX = 'glimmr_order_verify_';

	/**
	 * Max verification attempts per 15 minutes.
	 *
	 * @var int
	 */
	const MAX_VERIFY_ATTEMPTS = 5;

	/**
	 * Rate limit window in seconds (15 minutes).
	 *
	 * @var int
	 */
	const RATE_LIMIT_WINDOW = 900;

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Resolution result.
	 */
	public function execute( $arguments ) {
		$wc_check = $this->require_wc();
		if ( $wc_check ) {
			return $wc_check;
		}

		$order_number = $this->get_string_arg( $arguments, 'order_number', '' );
		$email        = $this->get_string_arg( $arguments, 'email', '' );
		$zip          = $this->get_string_arg( $arguments, 'zip', '' );

		if ( empty( $order_number ) ) {
			return $this->format_validation_error(
				'missing_required',
				'order_number',
				__( 'Order number is required.', 'glimmr-ai' )
			);
		}

		// Clean order number (remove # prefix if present).
		$order_number = ltrim( $order_number, '#' );

		// Find the order.
		$order = $this->find_order( $order_number );

		if ( ! $order ) {
			return $this->format_outcome(
				'not_found',
				array(
					'order_number' => $order_number,
				),
				sprintf( __( 'Order #%s was not found. Please check the order number.', 'glimmr-ai' ), $order_number )
			);
		}

		// Check access.
		$verification = $this->check_verification( $order, $email, $zip );

		return $this->format_outcome(
			$verification['status'],
			array(
				'order_id'            => $order->get_id(),
				'order_number'        => $order->get_order_number(),
				'verification_status' => $verification['status'],
				'verification_method' => $verification['method'],
				'required_fields'     => $verification['required'],
				'order_summary'       => $verification['status'] === 'verified' ? $this->get_order_summary( $order ) : null,
			),
			$verification['message']
		);
	}

	/**
	 * Find order by order number.
	 *
	 * @param string $order_number Order number.
	 * @return WC_Order|null Order object or null.
	 */
	private function find_order( $order_number ) {
		// Try direct ID first.
		if ( is_numeric( $order_number ) ) {
			$order = wc_get_order( (int) $order_number );
			if ( $order ) {
				return $order;
			}
		}

		// Search by order number (for custom order number plugins).
		$orders = wc_get_orders( array(
			'limit'        => 1,
			'order_number' => $order_number,
		) );

		if ( ! empty( $orders ) && $orders[0] instanceof WC_Order ) {
			return $orders[0];
		}

		// Final fallback: search by meta.
		$orders = wc_get_orders( array(
			'limit'      => 1,
			'meta_query' => array(
				array(
					'key'   => '_order_number',
					'value' => $order_number,
				),
			),
		) );

		if ( ! empty( $orders ) && $orders[0] instanceof WC_Order ) {
			return $orders[0];
		}

		return null;
	}

	/**
	 * Check verification requirements and status.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $email Provided email.
	 * @param string   $zip   Provided zip.
	 * @return array Verification result.
	 */
	private function check_verification( $order, $email, $zip ) {
		$current_user_id = get_current_user_id();
		$order_user_id   = $order->get_customer_id();

		// Check if logged in as order owner.
		if ( $current_user_id && $order_user_id && $current_user_id === $order_user_id ) {
			return array(
				'status'   => 'verified',
				'method'   => 'logged_in_owner',
				'required' => array(),
				'message'  => __( 'Verified as order owner.', 'glimmr-ai' ),
			);
		}

		// Check if user is an admin.
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return array(
				'status'   => 'verified',
				'method'   => 'admin_access',
				'required' => array(),
				'message'  => __( 'Verified via admin access.', 'glimmr-ai' ),
			);
		}

		// Guest or non-owner: requires email verification.
		if ( empty( $email ) ) {
			return array(
				'status'   => 'needs_verification',
				'method'   => 'none',
				'required' => array( 'email' ),
				'message'  => __( 'Please provide the email address used for this order to verify ownership.', 'glimmr-ai' ),
			);
		}

		// Check rate limiting.
		if ( $this->is_rate_limited( $email ) ) {
			return array(
				'status'   => 'rate_limited',
				'method'   => 'none',
				'required' => array(),
				'message'  => __( 'Too many verification attempts. Please try again in 15 minutes.', 'glimmr-ai' ),
			);
		}

		// Record this verification attempt.
		$this->record_verification_attempt( $email );

		// Validate email matches order.
		$order_email = $order->get_billing_email();
		if ( ! $this->timing_safe_email_compare( $email, $order_email ) ) {
			return array(
				'status'   => 'verification_failed',
				'method'   => 'verification_failed',
				'required' => array( 'email' ),
				'message'  => __( 'Order not found or verification failed.', 'glimmr-ai' ),
			);
		}

		// Email matches. Optionally verify zip for extra security.
		if ( ! empty( $zip ) ) {
			$order_zip = $order->get_billing_postcode();
			$normalized_zip = strtoupper( str_replace( array( ' ', '-' ), '', $zip ) );
			$normalized_order_zip = strtoupper( str_replace( array( ' ', '-' ), '', $order_zip ) );
			if ( ! hash_equals( $normalized_order_zip, $normalized_zip ) ) {
				return array(
					'status'   => 'verification_failed',
					'method'   => 'verification_failed',
					'required' => array( 'zip' ),
					'message'  => __( 'Order not found or verification failed.', 'glimmr-ai' ),
				);
			}
		}

		return array(
			'status'   => 'verified',
			'method'   => 'email_match',
			'required' => array(),
			'message'  => __( 'Verified via email address.', 'glimmr-ai' ),
		);
	}

	/**
	 * Compare emails in timing-safe manner.
	 *
	 * @param string $provided Provided email.
	 * @param string $stored   Stored email.
	 * @return bool True if match.
	 */
	private function timing_safe_email_compare( $provided, $stored ) {
		return hash_equals(
			strtolower( trim( $stored ) ),
			strtolower( trim( $provided ) )
		);
	}

	/**
	 * Check if email is rate limited.
	 *
	 * @param string $email Email address.
	 * @return bool True if rate limited.
	 */
	private function is_rate_limited( $email ) {
		$key      = self::RATE_LIMIT_PREFIX . md5( strtolower( $email ) );
		$attempts = get_transient( $key );

		return $attempts !== false && $attempts >= self::MAX_VERIFY_ATTEMPTS;
	}

	/**
	 * Record verification attempt.
	 *
	 * @param string $email Email address.
	 */
	private function record_verification_attempt( $email ) {
		$key      = self::RATE_LIMIT_PREFIX . md5( strtolower( $email ) );
		$attempts = get_transient( $key );

		if ( $attempts === false ) {
			set_transient( $key, 1, self::RATE_LIMIT_WINDOW );
		} else {
			set_transient( $key, $attempts + 1, self::RATE_LIMIT_WINDOW );
		}
	}

	/**
	 * Get order summary for verified orders.
	 *
	 * @param WC_Order $order Order object.
	 * @return array Order summary.
	 */
	private function get_order_summary( $order ) {
		$date_created = $order->get_date_created();
		return array(
			'order_number'  => $order->get_order_number(),
			'status'        => wc_get_order_status_name( $order->get_status() ),
			'date_created'  => $date_created ? $date_created->format( 'F j, Y' ) : null,
			'total'         => $order->get_formatted_order_total(),
			'item_count'    => $order->get_item_count(),
			'payment_method'=> $order->get_payment_method_title(),
		);
	}
}
