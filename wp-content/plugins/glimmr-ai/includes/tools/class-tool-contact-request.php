<?php
/**
 * Contact Request Tool
 *
 * Stores customer contact requests with AI-assisted information collection.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Tool_Contact_Request
 *
 * Creates and stores contact requests in the database with email notifications.
 * Supports conversation context storage and logged-in user info preloading.
 */
class Glimmr_AI_Tool_Contact_Request extends Glimmr_AI_Tool_Base {

    /**
     * Tool name.
     *
     * @var string
     */
    protected $name = 'contact_request';

    /**
     * Tool description.
     *
     * @var string
     */
    protected $description = 'Submit a contact request to the store support team. WHEN TO USE: Only use this tool after (a) you have tried to help and cannot resolve the issue, OR (b) the customer explicitly asks to speak with a human/support team. Do NOT use this tool as the first response to "I have an issue" - first ask what the issue is and try to help! WORKFLOW: (1) Ask what the specific issue is. (2) Try to resolve it yourself using other tools (order_status, apply_coupon, etc.). (3) If you cannot help OR customer insists on human contact, THEN gather details for the contact request. (4) Ask: "What details should I include for the support team?" - do NOT fabricate the message. (5) For logged-in users, use their account name/email automatically. (6) Show confirmation summary before submitting. NEVER fabricate issue details - the message field must contain what the customer actually told you, not your assumptions.';

    /**
     * Tool parameters.
     *
     * @var array
     */
    protected $parameters = array(
        'name' => array(
            'type'        => 'string',
            'description' => 'Customer name',
            'required'    => true,
        ),
        'email' => array(
            'type'        => 'string',
            'description' => 'Customer email address',
            'required'    => true,
        ),
        'phone' => array(
            'type'        => 'string',
            'description' => 'Customer phone number (optional)',
        ),
        'subject' => array(
            'type'        => 'string',
            'description' => 'Brief subject line based on the customer\'s stated issue (e.g., "Order #1234 not delivered", "Question about sizing", "Return request"). If you only know "issue with order" without specifics, you must ask for details first before calling this tool.',
            'required'    => true,
        ),
        'category' => array(
            'type'        => 'string',
            'description' => 'Category of the request',
            'enum'        => array( 'general', 'order_issue', 'product_question', 'return_exchange', 'shipping', 'billing', 'feedback', 'other' ),
        ),
        'message' => array(
            'type'        => 'string',
            'description' => 'The customer\'s own words describing their issue. MUST be based on what the customer actually said - do NOT fabricate or assume details. If you only know "I have an issue with my order", you do NOT have enough information. Ask: "What specifically is wrong with your order?" before using this tool. Include order numbers, product names, dates, what happened, what they need - but only information the customer provided.',
            'required'    => true,
        ),
        'order_id' => array(
            'type'        => 'integer',
            'description' => 'Related order ID if applicable',
        ),
        'product_id' => array(
            'type'        => 'integer',
            'description' => 'Related product ID if applicable',
        ),
        'priority' => array(
            'type'        => 'string',
            'description' => 'Priority level of the request',
            'enum'        => array( 'low', 'normal', 'high', 'urgent' ),
        ),
        'include_conversation' => array(
            'type'        => 'boolean',
            'description' => 'Whether to include recent conversation context with the request (helps store understand the issue)',
        ),
    );

    /**
     * Execute the tool.
     *
     * @param array $arguments Tool arguments.
     * @return array Tool result.
     */
    public function execute( $arguments ) {
        // Rate limit: 3 contact requests per hour per session.
        $session_id = $this->context['session_id'] ?? ( $this->context['conversation_id'] ?? wp_hash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
        $rate_key = 'glimmr_contact_' . md5( $session_id );
        $attempts = get_transient( $rate_key );
        if ( false !== $attempts && $attempts >= 3 ) {
            return $this->format_outcome(
                'rate_limited',
                array(),
                __( 'Too many contact requests. Please try again later.', 'glimmr-ai' )
            );
        }
        set_transient( $rate_key, ( $attempts ? $attempts + 1 : 1 ), HOUR_IN_SECONDS );

        // Extract and validate required fields.
        $name = $this->get_string_arg( $arguments, 'name' );
        $email = $this->get_string_arg( $arguments, 'email' );
        $subject = $this->get_string_arg( $arguments, 'subject' );
        $message = $this->get_string_arg( $arguments, 'message' );

        // Check for missing required fields.
        $missing_fields = array();
        if ( empty( $name ) ) {
            $missing_fields[] = 'name';
        }
        if ( empty( $email ) ) {
            $missing_fields[] = 'email';
        }
        if ( empty( $subject ) ) {
            $missing_fields[] = 'subject';
        }
        if ( empty( $message ) ) {
            $missing_fields[] = 'message';
        }

        if ( ! empty( $missing_fields ) ) {
            return $this->format_outcome(
                'incomplete',
                array(
                    'missing_fields' => $missing_fields,
                    'user_prefill'   => self::get_user_prefill(),
                ),
                sprintf(
                    /* translators: %s: comma-separated list of missing fields */
                    __( 'Please provide the following information: %s', 'glimmr-ai' ),
                    implode( ', ', $missing_fields )
                )
            );
        }

        // Validate email format.
        if ( ! is_email( $email ) ) {
            return $this->format_outcome(
                'invalid_email',
                array(
                    'email' => $email,
                ),
                __( 'Please provide a valid email address.', 'glimmr-ai' )
            );
        }

        // Extract optional fields.
        $phone = $this->get_string_arg( $arguments, 'phone' );
        $category = $this->get_string_arg( $arguments, 'category', 'general' );
        $order_id = $this->get_int_arg( $arguments, 'order_id' );
        $product_id = $this->get_int_arg( $arguments, 'product_id' );
        $priority = $this->get_string_arg( $arguments, 'priority', 'normal' );
        $include_conversation = ! empty( $arguments['include_conversation'] );

        // Validate category.
        $valid_categories = array( 'general', 'order_issue', 'product_question', 'return_exchange', 'shipping', 'billing', 'feedback', 'other' );
        if ( ! in_array( $category, $valid_categories, true ) ) {
            $category = 'general';
        }

        // Validate priority.
        $valid_priorities = array( 'low', 'normal', 'high', 'urgent' );
        if ( ! in_array( $priority, $valid_priorities, true ) ) {
            $priority = 'normal';
        }

        // Generate unique request ID.
        $request_id = 'CR-' . strtoupper( wp_generate_password( 8, false ) );

        // Get conversation context if requested.
        $conversation_context = null;
        $conversation_id = null;
        if ( $include_conversation ) {
            $context_data = $this->get_conversation_context();
            $conversation_context = $context_data['context'];
            $conversation_id = $context_data['conversation_id'];
        }

        // Get current user ID if logged in.
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            $user_id = null;
        }

        // Insert into database.
        $result = $this->insert_contact_request( array(
            'request_id'           => $request_id,
            'conversation_id'      => $conversation_id,
            'user_id'              => $user_id,
            'name'                 => sanitize_text_field( $name ),
            'email'                => sanitize_email( $email ),
            'phone'                => sanitize_text_field( $phone ),
            'subject'              => sanitize_text_field( $subject ),
            'category'             => $category,
            'message'              => sanitize_textarea_field( $message ),
            'conversation_context' => $conversation_context,
            'order_id'             => $order_id,
            'product_id'           => $product_id,
            'priority'             => $priority,
        ) );

        if ( ! $result ) {
            return $this->format_error(
                'submission_failed',
                __( 'Unable to submit your request at this time. Please try again later.', 'glimmr-ai' )
            );
        }

        // Send email notification.
        $this->send_notification_email( array(
            'request_id' => $request_id,
            'name'       => $name,
            'email'      => $email,
            'phone'      => $phone,
            'subject'    => $subject,
            'category'   => $category,
            'message'    => $message,
            'priority'   => $priority,
            'order_id'   => $order_id,
            'product_id' => $product_id,
            'has_conversation_context' => ! empty( $conversation_context ),
        ) );

        // Fire action for additional integrations.
        do_action( 'glimmr_ai_contact_request_created', $request_id, array(
            'name'        => $name,
            'email'       => $email,
            'subject'     => $subject,
            'category'    => $category,
            'message'     => $message,
            'priority'    => $priority,
            'order_id'    => $order_id,
            'product_id'  => $product_id,
            'user_id'     => $user_id,
        ) );

        return $this->format_outcome(
            'submitted',
            array(
                'request_id'  => $request_id,
                'category'    => $this->format_category_name( $category ),
                'priority'    => $priority,
                'email'       => Glimmr_AI_PII_Masker::mask_email( $email ),
                'is_final'    => true,
                'ai_note'     => 'Request has been submitted and cannot be edited. Do NOT offer to add details or make changes - the request is final. Simply confirm it was sent and offer to help with anything else.',
            ),
            sprintf(
                /* translators: %s: request reference number */
                __( 'Your contact request has been submitted. Reference number: %s. The store team has been notified and will respond to your email. This request cannot be modified after submission.', 'glimmr-ai' ),
                $request_id
            )
        );
    }

    /**
     * Get prefilled user information for logged-in users.
     *
     * @return array|null User info or null if not logged in.
     */
    public static function get_user_prefill() {
        if ( ! is_user_logged_in() ) {
            return null;
        }

        $user = wp_get_current_user();

        return array(
            'name'  => $user->display_name,
            'email' => $user->user_email,
            'phone' => get_user_meta( $user->ID, 'billing_phone', true ),
        );
    }

    /**
     * Get recent conversation context.
     *
     * @return array Array with 'context' and 'conversation_id'.
     */
    private function get_conversation_context() {
        // Try to get conversation ID from the current context.
        $conversation_id = null;
        $context = '';

        // The conversation ID should be available in the global context.
        // This is set by the conversation handler during message processing.
        if ( ! empty( $GLOBALS['glimmr_ai_current_conversation_id'] ) ) {
            $conversation_id = $GLOBALS['glimmr_ai_current_conversation_id'];
        }

        if ( ! empty( $conversation_id ) ) {
            // Get recent messages from this conversation.
            global $wpdb;
            $table_name = $wpdb->prefix . GLIMMR_AI_TABLE_PREFIX . 'messages';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $messages = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT role, content FROM {$table_name}
                    WHERE conversation_id = %s
                    ORDER BY created_at DESC
                    LIMIT 20", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $conversation_id
                ),
                ARRAY_A
            );

            if ( ! empty( $messages ) ) {
                // Reverse to get chronological order.
                $messages = array_reverse( $messages );

                // Build context summary.
                $context_lines = array();
                foreach ( $messages as $msg ) {
                    $role = $msg['role'] === 'user' ? 'Customer' : 'Assistant';
                    $content = wp_strip_all_tags( $msg['content'] );
                    // Truncate very long messages.
                    if ( strlen( $content ) > 500 ) {
                        $content = substr( $content, 0, 500 ) . '...';
                    }
                    $context_lines[] = $role . ': ' . $content;
                }

                $context = implode( "\n\n", $context_lines );

                // S10: PII Masking - Apply PII masking to conversation context before storage.
                $context = Glimmr_AI_PII_Masker::mask_text( $context );

                // Cap context size to prevent multi-megabyte strings in DB and email notifications.
                if ( strlen( $context ) > 5000 ) {
                    $context = substr( $context, 0, 5000 ) . "\n\n[... conversation truncated ...]";
                }
            }
        }

        return array(
            'context'         => $context,
            'conversation_id' => $conversation_id,
        );
    }

    /**
     * Insert contact request into database.
     *
     * @param array $data Contact request data.
     * @return bool Whether insertion was successful.
     */
    private function insert_contact_request( $data ) {
        global $wpdb;
        $table_name = $wpdb->prefix . GLIMMR_AI_TABLE_PREFIX . 'contact_requests';

        // S8: Site isolation for multisite.
        $site_id = get_current_blog_id();

        // S-WRITE: Intentional INSERT - stores customer contact request.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $table_name,
            array(
                'site_id'              => $site_id,
                'request_id'           => $data['request_id'],
                'conversation_id'      => $data['conversation_id'],
                'user_id'              => $data['user_id'],
                'name'                 => $data['name'],
                'email'                => $data['email'],
                'phone'                => $data['phone'],
                'subject'              => $data['subject'],
                'category'             => $data['category'],
                'message'              => $data['message'],
                'conversation_context' => $data['conversation_context'],
                'order_id'             => $data['order_id'],
                'product_id'           => $data['product_id'],
                'status'               => 'new',
                'priority'             => $data['priority'],
                'created_at'           => current_time( 'mysql' ),
                'updated_at'           => current_time( 'mysql' ),
            ),
            array(
                '%d', // site_id
                '%s', // request_id
                '%s', // conversation_id
                '%d', // user_id
                '%s', // name
                '%s', // email
                '%s', // phone
                '%s', // subject
                '%s', // category
                '%s', // message
                '%s', // conversation_context
                '%d', // order_id
                '%d', // product_id
                '%s', // status
                '%s', // priority
                '%s', // created_at
                '%s', // updated_at
            )
        );

        return $result !== false;
    }

    /**
     * Send notification email to admin.
     *
     * @param array $data Contact request data.
     * @return bool Whether email was sent.
     */
    private function send_notification_email( $data ) {
        // Get admin email from settings or fallback to site admin.
        $admin_email = Glimmr_AI_Settings::get( 'contact_request_email', '' );
        if ( empty( $admin_email ) ) {
            $admin_email = Glimmr_AI_Settings::get( 'support_email', '' );
        }
        if ( empty( $admin_email ) ) {
            $admin_email = get_option( 'admin_email' );
        }

        if ( empty( $admin_email ) ) {
            return false;
        }

        // Build email subject.
        $subject = sprintf(
            /* translators: 1: subject line, 2: request ID */
            __( '[Contact Request] %1$s - Ref: %2$s', 'glimmr-ai' ),
            $data['subject'],
            $data['request_id']
        );

        // Build email body.
        $body = $this->build_email_body( $data );

        // Set headers for HTML email.
        // Strip newlines from name to prevent email header injection
        // (sanitize_text_field does NOT strip \r and \n).
        $safe_name = str_replace( array( "\r", "\n", "\0" ), '', $data['name'] );
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'Reply-To: ' . $safe_name . ' <' . sanitize_email( $data['email'] ) . '>',
        );

        // Send email.
        return wp_mail( $admin_email, $subject, $body, $headers );
    }

    /**
     * Build HTML email body.
     *
     * @param array $data Contact request data.
     * @return string HTML email body.
     */
    private function build_email_body( $data ) {
        $priority_colors = array(
            'low'    => '#28a745',
            'normal' => '#17a2b8',
            'high'   => '#ffc107',
            'urgent' => '#dc3545',
        );

        $priority_color = $priority_colors[ $data['priority'] ] ?? '#17a2b8';
        $admin_url = admin_url( 'admin.php?page=glimmr-ai' );

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #f8f9fa; padding: 20px; border-radius: 8px 8px 0 0; }
                .content { background: #fff; padding: 20px; border: 1px solid #e9ecef; }
                .footer { background: #f8f9fa; padding: 15px 20px; border-radius: 0 0 8px 8px; font-size: 12px; color: #6c757d; }
                .field { margin-bottom: 15px; }
                .label { font-weight: 600; color: #495057; display: block; margin-bottom: 3px; }
                .value { color: #212529; }
                .priority-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; color: #fff; font-size: 12px; font-weight: 600; }
                .message-box { background: #f8f9fa; padding: 15px; border-radius: 4px; white-space: pre-wrap; }
                .btn { display: inline-block; padding: 10px 20px; background: #0073aa; color: #fff; text-decoration: none; border-radius: 4px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2 style="margin: 0;">New Contact Request</h2>
                    <p style="margin: 5px 0 0;">Reference: <strong><?php echo esc_html( $data['request_id'] ); ?></strong></p>
                </div>
                <div class="content">
                    <div class="field">
                        <span class="label">Priority</span>
                        <span class="priority-badge" style="background: <?php echo esc_attr( $priority_color ); ?>;">
                            <?php echo esc_html( ucfirst( $data['priority'] ) ); ?>
                        </span>
                    </div>

                    <div class="field">
                        <span class="label">From</span>
                        <span class="value"><?php echo esc_html( $data['name'] ); ?> &lt;<?php echo esc_html( $data['email'] ); ?>&gt;</span>
                    </div>

                    <?php if ( ! empty( $data['phone'] ) ) : ?>
                    <div class="field">
                        <span class="label">Phone</span>
                        <span class="value"><?php echo esc_html( $data['phone'] ); ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="field">
                        <span class="label">Category</span>
                        <span class="value"><?php echo esc_html( $this->format_category_name( $data['category'] ) ); ?></span>
                    </div>

                    <div class="field">
                        <span class="label">Subject</span>
                        <span class="value"><?php echo esc_html( $data['subject'] ); ?></span>
                    </div>

                    <?php if ( ! empty( $data['order_id'] ) ) : ?>
                    <div class="field">
                        <span class="label">Related Order</span>
                        <span class="value">#<?php echo esc_html( $data['order_id'] ); ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ( ! empty( $data['product_id'] ) ) : ?>
                    <div class="field">
                        <span class="label">Related Product</span>
                        <span class="value">#<?php echo esc_html( $data['product_id'] ); ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="field">
                        <span class="label">Message</span>
                        <div class="message-box"><?php echo esc_html( $data['message'] ); ?></div>
                    </div>

                    <?php if ( $data['has_conversation_context'] ) : ?>
                    <div class="field">
                        <span class="label">Conversation Context</span>
                        <p style="color: #6c757d; font-size: 14px;">
                            This request includes conversation history. View the full context in the admin dashboard.
                        </p>
                    </div>
                    <?php endif; ?>

                    <p style="margin-top: 20px;">
                        <a href="<?php echo esc_url( $admin_url ); ?>" class="btn">View in Dashboard</a>
                    </p>
                </div>
                <div class="footer">
                    <p>This notification was sent from Glimmr AI Shopping Assistant.</p>
                    <p>You can reply directly to this email to respond to the customer.</p>
                </div>
            </div>
        </body>
        </html>
        <?php
        $body = ob_get_clean();
        return false !== $body ? $body : '';
    }

    /**
     * Format category name for display.
     *
     * @param string $category Category slug.
     * @return string Formatted category name.
     */
    private function format_category_name( $category ) {
        $names = array(
            'general'          => __( 'General Inquiry', 'glimmr-ai' ),
            'order_issue'      => __( 'Order Issue', 'glimmr-ai' ),
            'product_question' => __( 'Product Question', 'glimmr-ai' ),
            'return_exchange'  => __( 'Return/Exchange', 'glimmr-ai' ),
            'shipping'         => __( 'Shipping', 'glimmr-ai' ),
            'billing'          => __( 'Billing', 'glimmr-ai' ),
            'feedback'         => __( 'Feedback', 'glimmr-ai' ),
            'other'            => __( 'Other', 'glimmr-ai' ),
        );

        return $names[ $category ] ?? ucfirst( str_replace( '_', ' ', $category ) );
    }
}
