jQuery(document).ready(function($) {
    // Find the logout link in the admin bar.
    var logoutLink = $('#wp-admin-bar-logout a');

    // Attach a click event listener.
    logoutLink.on('click', function(event) {
        // Show a confirmation dialog box.
        var confirmLogout = confirm("You still have pending tasks. Are you sure you want to log out?");
        
        // If the user clicks "Cancel", prevent the logout.
        if (!confirmLogout) {
            event.preventDefault();
        }
    });
});