/**
 * TapToSell OA Dashboard Scripts
 *
 * This file handles the interactive elements on the Operational Admin dashboard.
 * - User rejection and details modals.
 * - Product details modal via AJAX.
 *
 * @version 1.4.0 (Phase 12 Update)
 */
jQuery(document).ready(function($) {

    // =================================================================
    // 1. USER MANAGEMENT MODALS
    // =================================================================

    // --- User Rejection Modal ---
    const userRejectionModal = $('#tts-rejection-modal');
    if (userRejectionModal.length) {
        // ... (This section remains unchanged)
        const userReasonText = $('#tts-rejection-reason-text');
        const userConfirmBtn = $('#tts-rejection-confirm');

        $('body').on('click', '.oa-reject-user-btn', function(e) {
            e.preventDefault();
            const rejectionUrl = $(this).data('reject-url');
            userConfirmBtn.data('base-url', rejectionUrl);
            userReasonText.val('');
            userRejectionModal.show();
        });

        userConfirmBtn.on('click', function() {
            const baseUrl = $(this).data('base-url');
            const reason = userReasonText.val();
            const finalUrl = baseUrl + '&reason=' + encodeURIComponent(reason);
            window.location.href = finalUrl;
        });

        $('#tts-rejection-modal .tts-modal-close, #tts-rejection-cancel').on('click', function() {
            userRejectionModal.hide();
        });
    }


    // --- User Details Modal (AJAX) ---
    const userDetailsModal = $('#tts-details-modal');
    if (userDetailsModal.length) {
        // ... (This section remains unchanged)
        const userDetailsContent = $('#tts-details-modal-content');

        $('body').on('click', '.oa-user-details-btn', function(e) {
            e.preventDefault();
            const userId = $(this).data('userid');
            
            userDetailsContent.html('<p>Loading details...</p>');
            userDetailsModal.show();

            $.ajax({
                url: tts_oa_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'taptosell_get_user_details',
                    security: tts_oa_data.user_details_nonce,
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

        userDetailsModal.find('.tts-modal-close').on('click', function() {
            userDetailsModal.hide();
        });
    }


    // =================================================================
    // 2. PRODUCT MANAGEMENT MODAL (UPDATED)
    // =================================================================

    // --- Product Details Modal (AJAX) ---
    const productDetailsModal = $('#productDetailsModal');
    if (productDetailsModal.length) {
        
        $('body').on('click', '.view-product-details', function(e) {
            e.preventDefault();

            const productId = $(this).data('product-id');
            const modalBody = productDetailsModal.find('.modal-body');
            const modalTitle = productDetailsModal.find('#productDetailsModalLabel');
            
            modalTitle.text('Product Details (ID: ' + productId + ')');
            modalBody.html('<p>Loading product details...</p>');

            // --- NEW: Manually show the modal overlay ---
            productDetailsModal.show();

            $.ajax({
                url: tts_oa_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'taptosell_get_product_details',
                    product_id: productId,
                    security: tts_oa_data.product_details_nonce
                },
                success: function(response) {
                    if (response.success) {
                        modalBody.html(response.data.html);
                    } else {
                        modalBody.html('<p class="error-message">' + response.data.message + '</p>');
                    }
                },
                error: function() {
                    modalBody.html('<p class="error-message">An error occurred while fetching product details. Please try again.</p>');
                }
            });
        });

        // --- NEW: Add a click handler for the close button ---
        productDetailsModal.find('.taptosell-modal-close').on('click', function() {
            productDetailsModal.hide();
        });
    }

    // =================================================================
    // 3. GENERAL MODAL BEHAVIOR
    // =================================================================
    
    // Close any open custom modal when clicking on the dark overlay
    $(window).on('click', function(event) {
        if ($(event.target).is('.taptosell-modal-overlay')) {
            $(event.target).hide();
        }
    });

});