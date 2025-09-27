// In: assets/js/logout-warning.js

jQuery(document).ready(function($) {

    // First, check if our data object from PHP exists.
    // Then, check if the user role is one we want to target.
    if (typeof taptosellLogoutData !== 'undefined' && 
       (taptosellLogoutData.currentUserRole === 'administrator' || taptosellLogoutData.currentUserRole === 'operational_admin')) {
        
        // Find EITHER the admin bar logout link OR our custom front-end logout button
            var logoutLink = $('#wp-admin-bar-logout a, a.tts-logout-button');

        // Attach a click event listener.
        logoutLink.on('click', function(event) {
            
            // Get the number of pending tasks from our new data object.
            var pendingTasks = taptosellLogoutData.pendingTasks;
            
            // Create the dynamic confirmation message with the task count.
            var confirmMessage = "You still have " + pendingTasks + " pending task(s). Are you sure you want to log out?";
            
            // Show the confirmation dialog box.
            var confirmLogout = confirm(confirmMessage);
            
            // If the user clicks "Cancel", prevent the logout.
            if (!confirmLogout) {
                event.preventDefault();
            }
        });
    }
});