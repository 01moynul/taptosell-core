jQuery(document).ready(function($) {
    
    // Find the modal and its components
    const reasonModal = $('#tts-rejection-modal');
    const reasonText = $('#tts-rejection-reason-text');
    const cancelBtn = $('#tts-rejection-cancel');
    const confirmBtn = $('#tts-rejection-confirm');
    
    // When an OA clicks ANY reject button in the user list...
    $('.oa-reject-user-btn').on('click', function(e) {
        e.preventDefault(); // Stop the link from navigating immediately
        
        // Get the rejection URL from the button's data attribute
        const rejectionUrl = $(this).data('reject-url');
        
        // Store this URL on the confirm button so we know where to go later
        confirmBtn.data('base-url', rejectionUrl);
        
        // Clear any previous reason text and show the modal
        reasonText.val('');
        reasonModal.show();
    });

    // When the OA clicks the "Confirm Rejection" button in the modal...
    confirmBtn.on('click', function() {
        const baseUrl = $(this).data('base-url');
        const reason = reasonText.val();
        
        // Add the reason to the URL as a new parameter
        const finalUrl = baseUrl + '&reason=' + encodeURIComponent(reason);
        
        // Navigate to the final URL to process the rejection
        window.location.href = finalUrl;
    });

    // When the OA clicks "Cancel" or the close 'x', hide the modal
    cancelBtn.on('click', function() { reasonModal.hide(); });
    $('.tts-modal-close').on('click', function() { reasonModal.hide(); });
    
});

// --- User Details Modal Logic ---
    const detailsModal = $('#tts-details-modal');
    const detailsContent = $('#tts-details-modal-content');

    // When an OA clicks a "Details" button
    $('.oa-user-details-btn').on('click', function(e) {
        e.preventDefault();
        
        const userId = $(this).data('userid');
        
        // Show the modal with a loading message
        detailsContent.html('<p>Loading details...</p>');
        detailsModal.show();

        // Prepare the AJAX request
        $.ajax({
            url: tts_oa_dashboard.ajax_url, // URL passed from PHP
            type: 'POST',
            data: {
                action: 'taptosell_get_user_details', // Our PHP handler
                security: tts_oa_dashboard.details_nonce, // Security token
                user_id: userId
            },
            success: function(response) {
                if (response.success) {
                    // If successful, populate the modal with the HTML from PHP
                    detailsContent.html(response.data.html);
                } else {
                    // If there was an error, show the error message
                    detailsContent.html('<p>Error: ' + response.data.message + '</p>');
                }
            },
            error: function() {
                // Handle server errors
                detailsContent.html('<p>An unexpected error occurred. Please try again.</p>');
            }
        });
    });

    // Make the close button on the details modal work
    detailsModal.find('.tts-modal-close').on('click', function() {
        detailsModal.hide();
    });