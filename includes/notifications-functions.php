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
 * --- UPDATED: Shortcode to display the user's notification panel. ---
 * Now adds a tracking parameter to notification links.
 */
function taptosell_notifications_panel_shortcode() {
    if ( ! is_user_logged_in() ) {
        return '<p>You must be logged in to view notifications.</p>';
    }

    $user_id = get_current_user_id();
    global $wpdb;
    $table_name = $wpdb->prefix . 'taptosell_notifications';

    $notifications = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM $table_name WHERE user_id = %d ORDER BY created_date DESC", $user_id)
    );

    ob_start();
    ?>
    <div class="taptosell-notifications-panel">
        <h3 style="margin-top: 0;">Notifications</h3>
        <?php if ( ! empty( $notifications ) ) : ?>
            <ul style="list-style: none; margin: 0; padding: 0;">
                <?php foreach ( $notifications as $notification ) : ?>
                    <?php
                    $bg_color = ($notification->is_read == 0) ? '#eef7ff' : '#f9f9f9';
                    ?>
                    <li style="background-color: <?php echo $bg_color; ?>; border: 1px solid #ddd; padding: 15px; margin-bottom: 10px; border-radius: 4px;">
                        <?php
                        $message = esc_html($notification->message);
                        
                        // --- UPDATED: Add notification ID to the link if it exists ---
                        if ( ! empty( $notification->link ) ) {
                            // Add a query argument to the link to track the click
                            $read_link = add_query_arg('notification_id', $notification->id, $notification->link);
                            echo '<a href="' . esc_url($read_link) . '" style="text-decoration: none; color: inherit;">' . $message . '</a>';
                        } else {
                            echo $message;
                        }
                        ?>
                        <br>
                        <small style="color: #777;"><?php echo date('F j, Y, g:i a', strtotime($notification->created_date)); ?></small>
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
 * --- NEW: Checks for a notification ID in the URL on every page load. ---
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

        if ($notification) {
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
        }
    }
}
add_action('init', 'taptosell_mark_notification_as_read');