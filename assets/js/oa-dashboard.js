/**
 * TapToSell Core Scripts for the Operational Admin Frontend Dashboard
 *
 * This script handles all interactive elements on the OA dashboard, including:
 * - User rejection modal with reason input.
 * - User details modal with AJAX data loading.
 * - Product rejection modal with reason input.
 * - Product details modal for setting per-product commission.
 *
 * @version 1.2.0
 */
jQuery(document).ready(function($) {

    // =================================================================
    // 1. USER MANAGEMENT MODALS
    // =================================================================

    // --- User Rejection Modal ---
    const userRejectionModal = $('#tts-rejection-modal');
    const userReasonText = $('#tts-rejection-reason-text');
    const userCancelBtn = $('#tts-rejection-cancel');
    const userConfirmBtn = $('#tts-rejection-confirm');

    // When an OA clicks a "Reject" button in the user list
    $('body').on('click', '.oa-reject-user-btn', function(e) {
        e.preventDefault();
        const rejectionUrl = $(this).data('reject-url');
        userConfirmBtn.data('base-url', rejectionUrl);
        userReasonText.val('');
        userRejectionModal.show();
    });

    // Handle the user rejection confirmation
    userConfirmBtn.on('click', function() {
        const baseUrl = $(this).data('base-url');
        const reason = userReasonText.val();
        const finalUrl = baseUrl + '&reason=' + encodeURIComponent(reason);
        window.location.href = finalUrl;
    });

    // Close the user rejection modal
    userCancelBtn.on('click', function() { userRejectionModal.hide(); });
    $('#tts-rejection-modal .tts-modal-close').on('click', function() { userRejectionModal.hide(); });

    // --- User Details Modal (AJAX) ---
    const userDetailsModal = $('#tts-details-modal');
    const userDetailsContent = $('#tts-details-modal-content');

    // When an OA clicks a "Details" button in the user list
    $('body').on('click', '.oa-user-details-btn', function(e) {
        e.preventDefault();
        const userId = $(this).data('userid');
        
        userDetailsContent.html('<p>Loading details...</p>');
        userDetailsModal.show();

        $.ajax({
            url: tts_oa_dashboard.ajax_url,
            type: 'POST',
            data: {
                action: 'taptosell_get_user_details',
                security: tts_oa_dashboard.details_nonce,
                user_id: userId
            },
            success: function(response) {
                if (response.success) {
                    userDetailsContent.html(response.data.html);
                } else {
                    userDetailsContent.html('<p>Error: ' + (response.data.message || 'Unknown error.') + '</p>');
                }
            },
            error: function() {
                userDetailsContent.html('<p>An unexpected error occurred. Please try again.</p>');
            }
        });
    });

    // Close the user details modal
    userDetailsModal.find('.tts-modal-close').on('click', function() {
        userDetailsModal.hide();
    });


    // =================================================================
    // 2. PRODUCT MANAGEMENT MODALS
    // =================================================================

    // --- Product Details & Commission Modal ---
    const productDetailsModal = $('#taptosell-product-details-modal');
    
    // When a "Details" button is clicked in the product list
    $('body').on('click', '.taptosell-oa-product-details-btn', function() {
        // Get data from the button's data attributes
        const productId = $(this).data('product-id');
        const productName = $(this).data('product-name');
        const currentCommission = $(this).data('current-commission');
        const globalCommission = $(this).data('global-commission');

        // Populate the modal's static and form content
        productDetailsModal.find('#modal-product-name').text(productName);
        productDetailsModal.find('#modal-product-id').val(productId);
        
        const commissionInput = productDetailsModal.find('#modal-commission-rate');
        commissionInput.val(currentCommission); // Set the current saved value
        commissionInput.attr('placeholder', 'Global: ' + globalCommission + '%'); // Set the placeholder

        // Display the modal
        productDetailsModal.css('display', 'block');
    });

    // Close the product details modal
    productDetailsModal.find('.taptosell-modal-close').on('click', function() {
        productDetailsModal.css('display', 'none');
    });


    // =================================================================
    // 3. GENERAL MODAL BEHAVIOR
    // =================================================================
    
    // Close any open modal when clicking on the dark overlay
    $(window).on('click', function(event) {
        if ($(event.target).is('.taptosell-modal-overlay')) {
            $(event.target).css('display', 'none');
        }
    });

});