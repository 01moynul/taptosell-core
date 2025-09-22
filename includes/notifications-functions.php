<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adds a new notification for a specific user.
 *
 * @param int    $user_id The ID of the user to notify.
 * @param string $message The notification message.
 * @param string $link    Optional. The URL the notification should link to.
 */
function taptosell_add_notification( $user_id, $message, $link = '' ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'taptosell_notifications';

    $wpdb->insert(
        $table_name,
        [
            'user_id'      => $user_id,
            'message'      => $message,
            'link'         => $link,
            'is_read'      => 0, // A new notification is always unread
            'created_date' => current_time('mysql'),
        ],
        ['%d', '%s', '%s', '%d', '%s']
    );
}

/**
 * --- UPDATED (UI/UX Styling): Shortcode to display the user's notification panel. ---
 */
function taptosell_notifications_panel_shortcode() {
    if ( ! is_user_logged_in() ) {
        return '<p>You must be logged in to view notifications.</p>';
    }

    $user_id = get_current_user_id();
    global $wpdb;
    $table_name = $wpdb->prefix . 'taptosell_notifications';

    $notifications = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM $table_name WHERE user_id = %d ORDER BY created_date DESC LIMIT 50", $user_id) // Added a reasonable limit
    );

    ob_start();
    ?>
    <div class="taptosell-container taptosell-notifications-panel">
        <h3 style="margin-top: 0;">Notifications</h3>
        <?php if ( ! empty( $notifications ) ) : ?>
            <ul>
                <?php foreach ( $notifications as $notification ) : ?>
                    <?php
                    // --- UPDATED: Add the 'notification-unread' class for new items ---
                    $li_class = ($notification->is_read == 0) ? 'notification-unread' : '';
                    ?>
                    <li class="<?php echo esc_attr($li_class); ?>">
                        <?php
                        $message = esc_html($notification->message);
                        if ( ! empty( $notification->link ) ) {
                            $read_link = add_query_arg('notification_id', $notification->id, $notification->link);
                            echo '<a href="' . esc_url($read_link) . '">' . $message . '</a>';
                        } else {
                            echo $message;
                        }
                        ?>
                        <br>
                        <small><?php echo date('F j, Y, g:i a', strtotime($notification->created_date)); ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else : ?>
            <p>You have no new notifications.</p>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('taptosell_notifications_panel', 'taptosell_notifications_panel_shortcode');


/**
 * --- Checks for a notification ID in the URL on every page load. ---
 * If found, it marks the notification as read and redirects.
 */
function taptosell_mark_notification_as_read() {
    // Check if a notification ID is present in the URL and the user is logged in
    if ( isset($_GET['notification_id']) && is_user_logged_in() ) {
        $notification_id = (int)$_GET['notification_id'];
        $current_user_id = get_current_user_id();

        global $wpdb;
        $table_name = $wpdb->prefix . 'taptosell_notifications';

        // Security check: Make sure the notification belongs to the current user before marking as read
        $notification = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d AND user_id = %d", $notification_id, $current_user_id)
        );

        if ($notification && $notification->is_read == 0) { // Only update if it's currently unread
            // Update the 'is_read' status in the database
            $wpdb->update(
                $table_name,
                ['is_read' => 1],
                ['id' => $notification_id]
            );

            // Get the original URL without our tracking parameter
            $redirect_url = remove_query_arg('notification_id');
            // Redirect the user to the clean URL
            wp_redirect($redirect_url);
            exit;
        } elseif ($notification && $notification->is_read == 1) {
            // If it's already read, just redirect to avoid a loop
            $redirect_url = remove_query_arg('notification_id');
            wp_redirect($redirect_url);
            exit;
        }
    }
}
add_action('init', 'taptosell_mark_notification_as_read');