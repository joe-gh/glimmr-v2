<?php
/**
 * Contact Response Handler
 *
 * Handles sending admin responses to customer contact requests via email.
 *
 * @package Glimmr_AI
 * @since 1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Contact_Response
 *
 * Sends admin responses to customers via email and stores response records.
 */
class Glimmr_AI_Contact_Response {

    /**
     * Send a response to a contact request.
     *
     * @param object $request       The contact request object.
     * @param string $response_text The admin's response text.
     * @param array  $options       Optional settings.
     *                              - send_email: bool Whether to send email (default true).
     *                              - update_status: string New status for request (default null).
     * @return array Result with 'success', 'message', and 'response_id'.
     */
    public static function send_response( $request, $response_text, $options = array() ) {
        $defaults = array(
            'send_email'    => true,
            'update_status' => null,
        );
        $options = wp_parse_args( $options, $defaults );

        // Validate inputs.
        if ( empty( $request->request_id ) ) {
            return array(
                'success' => false,
                'message' => __( 'Invalid contact request.', 'glimmr-ai' ),
            );
        }

        if ( empty( $response_text ) ) {
            return array(
                'success' => false,
                'message' => __( 'Response text cannot be empty.', 'glimmr-ai' ),
            );
        }

        $email_sent = false;

        // Send email if requested.
        if ( $options['send_email'] ) {
            $email_sent = self::send_response_email( $request, $response_text );
        }

        // Store the response in database.
        $response_id = Glimmr_AI_Database::insert_contact_response( array(
            'request_id'    => $request->request_id,
            'admin_id'      => get_current_user_id(),
            'response_text' => sanitize_textarea_field( $response_text ),
            'email_sent'    => $email_sent ? 1 : 0,
        ) );

        if ( ! $response_id ) {
            return array(
                'success' => false,
                'message' => __( 'Failed to save response.', 'glimmr-ai' ),
            );
        }

        // Update request status if specified.
        if ( ! empty( $options['update_status'] ) ) {
            Glimmr_AI_Database::update_contact_request( $request->request_id, array(
                'status' => sanitize_text_field( $options['update_status'] ),
            ) );
        }

        // Log the response.
        if ( class_exists( 'Glimmr_AI_Logger' ) ) {
            Glimmr_AI_Logger::info(
                'Contact request response sent',
                array(
                    'request_id'  => $request->request_id,
                    'admin_id'    => get_current_user_id(),
                    'email_sent'  => $email_sent,
                ),
                'contact'
            );
        }

        // Fire action for integrations.
        do_action( 'glimmr_ai_contact_response_sent', $request->request_id, array(
            'response_text' => $response_text,
            'admin_id'      => get_current_user_id(),
            'email_sent'    => $email_sent,
            'response_id'   => $response_id,
        ) );

        return array(
            'success'     => true,
            'message'     => $email_sent
                ? __( 'Response sent successfully.', 'glimmr-ai' )
                : __( 'Response saved but email could not be sent.', 'glimmr-ai' ),
            'response_id' => $response_id,
            'email_sent'  => $email_sent,
        );
    }

    /**
     * Send the response email to the customer.
     *
     * @param object $request       The contact request object.
     * @param string $response_text The admin's response text.
     * @return bool Whether the email was sent successfully.
     */
    private static function send_response_email( $request, $response_text ) {
        // Build email subject.
        $subject = sprintf(
            /* translators: 1: subject line, 2: reference number */
            __( 'Re: %1$s - Ref: %2$s', 'glimmr-ai' ),
            $request->subject, // @phpstan-ignore property.notFound
            $request->request_id // @phpstan-ignore property.notFound
        );

        // Build email body.
        $body = self::build_email_body( $request, $response_text );

        // Get store name for From header.
        $store_name = get_bloginfo( 'name' );

        // Get support email for Reply-To.
        $support_email = Glimmr_AI_Settings::get( 'support_email', '' );
        if ( empty( $support_email ) ) {
            $support_email = get_option( 'admin_email' );
        }

        // Set headers for HTML email.
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $store_name . ' <' . $support_email . '>',
            'Reply-To: ' . $support_email,
        );

        // Send email.
        return wp_mail( $request->email, $subject, $body, $headers ); // @phpstan-ignore property.notFound
    }

    /**
     * Build the HTML email body for the response.
     *
     * @param object $request       The contact request object.
     * @param string $response_text The admin's response text.
     * @return string HTML email body.
     */
    private static function build_email_body( $request, $response_text ) {
        $store_name = get_bloginfo( 'name' );
        $store_url  = home_url();

        // Format response text (convert line breaks to <br>).
        $formatted_response = nl2br( esc_html( $response_text ) );

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                    line-height: 1.6;
                    color: #333333;
                    margin: 0;
                    padding: 0;
                    background-color: #f5f5f5;
                }
                .email-wrapper {
                    max-width: 600px;
                    margin: 0 auto;
                    padding: 20px;
                }
                .email-container {
                    background: #ffffff;
                    border-radius: 8px;
                    overflow: hidden;
                    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                }
                .email-header {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    padding: 30px 20px;
                    text-align: center;
                }
                .email-header h1 {
                    margin: 0;
                    color: #ffffff;
                    font-size: 24px;
                    font-weight: 600;
                }
                .email-content {
                    padding: 30px 25px;
                }
                .greeting {
                    font-size: 18px;
                    color: #333333;
                    margin-bottom: 20px;
                }
                .response-text {
                    background: #f8f9fa;
                    padding: 20px;
                    border-radius: 6px;
                    border-left: 4px solid #667eea;
                    margin-bottom: 25px;
                    color: #333333;
                    font-size: 15px;
                }
                .original-request {
                    border-top: 1px solid #e9ecef;
                    padding-top: 20px;
                    margin-top: 20px;
                }
                .original-request h3 {
                    color: #6c757d;
                    font-size: 14px;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    margin: 0 0 15px 0;
                }
                .original-message {
                    background: #f8f9fa;
                    padding: 15px;
                    border-radius: 6px;
                    font-size: 14px;
                    color: #6c757d;
                }
                .reference-badge {
                    display: inline-block;
                    background: #e9ecef;
                    color: #495057;
                    padding: 4px 10px;
                    border-radius: 4px;
                    font-size: 12px;
                    margin-bottom: 15px;
                }
                .email-footer {
                    background: #f8f9fa;
                    padding: 20px 25px;
                    text-align: center;
                    font-size: 13px;
                    color: #6c757d;
                }
                .email-footer a {
                    color: #667eea;
                    text-decoration: none;
                }
                .email-footer p {
                    margin: 5px 0;
                }
            </style>
        </head>
        <body>
            <div class="email-wrapper">
                <div class="email-container">
                    <div class="email-header">
                        <h1><?php echo esc_html( $store_name ); ?></h1>
                    </div>
                    <div class="email-content">
                        <div class="reference-badge">
                            <?php
                            printf(
                                /* translators: %s: reference number */
                                esc_html__( 'Reference: %s', 'glimmr-ai' ),
                                esc_html( $request->request_id ) // @phpstan-ignore property.notFound
                            );
                            ?>
                        </div>

                        <p class="greeting">
                            <?php
                            printf(
                                /* translators: %s: customer name */
                                esc_html__( 'Hi %s,', 'glimmr-ai' ),
                                esc_html( $request->name ) // @phpstan-ignore property.notFound
                            );
                            ?>
                        </p>

                        <p><?php esc_html_e( 'Thank you for contacting us. Here is our response to your inquiry:', 'glimmr-ai' ); ?></p>

                        <div class="response-text">
                            <?php echo $formatted_response; // Already escaped above. ?>
                        </div>

                        <p><?php esc_html_e( 'If you have any further questions, please reply to this email.', 'glimmr-ai' ); ?></p>

                        <div class="original-request">
                            <h3><?php esc_html_e( 'Your Original Request', 'glimmr-ai' ); ?></h3>
                            <p><strong><?php esc_html_e( 'Subject:', 'glimmr-ai' ); ?></strong> <?php echo esc_html( $request->subject ); // @phpstan-ignore property.notFound ?></p>
                            <div class="original-message">
                                <?php echo nl2br( esc_html( $request->message ) ); // @phpstan-ignore property.notFound ?>
                            </div>
                        </div>
                    </div>
                    <div class="email-footer">
                        <p><?php echo esc_html( $store_name ); ?></p>
                        <p><a href="<?php echo esc_url( $store_url ); ?>"><?php echo esc_html( $store_url ); ?></a></p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        $body = ob_get_clean();

        return $body !== false ? $body : '';
    }

    /**
     * Get the category display name.
     *
     * @param string $category Category slug.
     * @return string Display name.
     */
    public static function get_category_name( $category ) {
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

    /**
     * Get the priority display name and color.
     *
     * @param string $priority Priority slug.
     * @return array Array with 'name' and 'color'.
     */
    public static function get_priority_info( $priority ) {
        $priorities = array(
            'low'    => array(
                'name'  => __( 'Low', 'glimmr-ai' ),
                'color' => '#28a745',
            ),
            'normal' => array(
                'name'  => __( 'Normal', 'glimmr-ai' ),
                'color' => '#17a2b8',
            ),
            'high'   => array(
                'name'  => __( 'High', 'glimmr-ai' ),
                'color' => '#ffc107',
            ),
            'urgent' => array(
                'name'  => __( 'Urgent', 'glimmr-ai' ),
                'color' => '#dc3545',
            ),
        );

        return $priorities[ $priority ] ?? array(
            'name'  => ucfirst( $priority ),
            'color' => '#6c757d',
        );
    }

    /**
     * Get the status display name and color.
     *
     * @param string $status Status slug.
     * @return array Array with 'name' and 'color'.
     */
    public static function get_status_info( $status ) {
        $statuses = array(
            'new'         => array(
                'name'  => __( 'New', 'glimmr-ai' ),
                'color' => '#dc3545',
            ),
            'in_progress' => array(
                'name'  => __( 'In Progress', 'glimmr-ai' ),
                'color' => '#ffc107',
            ),
            'resolved'    => array(
                'name'  => __( 'Resolved', 'glimmr-ai' ),
                'color' => '#28a745',
            ),
        );

        return $statuses[ $status ] ?? array(
            'name'  => ucfirst( str_replace( '_', ' ', $status ) ),
            'color' => '#6c757d',
        );
    }
}
