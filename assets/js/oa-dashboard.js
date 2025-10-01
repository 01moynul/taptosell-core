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