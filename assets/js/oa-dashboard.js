// In: /assets/js/oa-dashboard.js

jQuery(document).ready(function($) {

    // =================================================================
    // 1. USER MANAGEMENT ACTIONS (AJAX)
    // =================================================================

    // --- User Details Modal ---
    const userDetailsModal = $('#tts-user-details-modal');
    $('body').on('click', '.view-user-details', function() {
        const userId = $(this).data('user-id');
        const modalBody = userDetailsModal.find('.taptosell-modal-body');
        modalBody.html('<p>Loading details...</p>'); // Reset modal content
        userDetailsModal.show();

        $.ajax({
            url: taptosell_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'taptosell_get_user_details',
                security: taptosell_ajax_object.user_actions_nonce,
                user_id: userId
            },
            success: function(response) {
                if (response.success) {
                    modalBody.html(response.data.html);
                } else {
                    modalBody.html('<p class="error-message">' + response.data.message + '</p>');
                }
            },
            error: function() {
                modalBody.html('<p class="error-message">An unexpected error occurred. Please try again.</p>');
            }
        });
    });

    // --- User Approval ---
    $('body').on('click', '.oa-approve-user-btn', function() {
        if (!confirm('Are you sure you want to approve this user?')) {
            return;
        }
        const button = $(this);
        const userId = button.data('user-id');
        const userRow = $('#user-row-' + userId);

        button.prop('disabled', true).text('Approving...');

        $.ajax({
            url: taptosell_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'taptosell_approve_user',
                security: taptosell_ajax_object.user_actions_nonce,
                user_id: userId
            },
            success: function(response) {
                if (response.success) {
                    alert('User approved successfully.');
                    userRow.fadeOut(500, function() { $(this).remove(); });
                } else {
                    alert('Error: ' + response.data.message);
                    button.prop('disabled', false).text('Approve');
                }
            },
            error: function() {
                alert('An unexpected server error occurred. Please try again.');
                button.prop('disabled', false).text('Approve');
            }
        });
    });

    // --- User Rejection ---
    const userRejectionModal = $('#tts-user-rejection-modal');
    const userReasonText = $('#tts-user-rejection-reason-text');
    const userConfirmBtn = $('#tts-user-rejection-confirm');

    // Show the rejection modal
    $('body').on('click', '.oa-reject-user-btn', function() {
        const userId = $(this).data('user-id');
        userConfirmBtn.data('user-id', userId); // Store user ID on the confirm button
        userReasonText.val('');
        userRejectionModal.show();
    });

    // Handle the confirm rejection click
    userConfirmBtn.on('click', function() {
        const button = $(this);
        const userId = button.data('user-id');
        const reason = userReasonText.val();
        const userRow = $('#user-row-' + userId);

        if (reason.trim() === '') {
            alert('Please provide a reason for rejection.');
            return;
        }

        button.prop('disabled', true).text('Rejecting...');

        $.ajax({
            url: taptosell_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'taptosell_reject_user',
                security: taptosell_ajax_object.user_actions_nonce,
                user_id: userId,
                reason: reason
            },
            success: function(response) {
                if (response.success) {
                    alert('User rejected and deleted.');
                    userRejectionModal.hide();
                    userRow.fadeOut(500, function() { $(this).remove(); });
                } else {
                    alert('Error: ' + response.data.message);
                }
            },
            error: function() {
                alert('An unexpected server error occurred. Please try again.');
            },
            complete: function() {
                button.prop('disabled', false).text('Confirm Rejection');
            }
        });
    });


    // =================================================================
    // 2. PRODUCT MANAGEMENT ACTIONS (AJAX)
    // =================================================================

    // --- Product Details Modal ---
    const productDetailsModal = $('#tts-product-details-modal');
    $('body').on('click', '.view-product-details', function() {
        const productId = $(this).data('product-id');
        const modalBody = productDetailsModal.find('.taptosell-modal-body');
        modalBody.html('<p>Loading details...</p>');
        productDetailsModal.show();

        $.ajax({
            url: taptosell_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'taptosell_get_product_details',
                security: taptosell_ajax_object.product_actions_nonce,
                product_id: productId
            },
            success: function(response) {
                if (response.success) {
                    modalBody.html(response.data.html);
                } else {
                    modalBody.html('<p class="error-message">' + response.data.message + '</p>');
                }
            },
            error: function() {
                modalBody.html('<p class="error-message">An unexpected error occurred. Please try again.</p>');
            }
        });
    });
    
    // --- Product Approval ---
    $('body').on('click', '.oa-approve-product-btn', function() {
        if (!confirm('Are you sure you want to approve this product?')) {
            return;
        }
        const button = $(this);
        const productId = button.data('product-id');
        const productRow = $('#product-row-' + productId);

        button.prop('disabled', true).text('Approving...');

        $.ajax({
            url: taptosell_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'taptosell_approve_product',
                security: taptosell_ajax_object.product_actions_nonce,
                product_id: productId
            },
            success: function(response) {
                if (response.success) {
                    alert('Product approved successfully.');
                    productRow.fadeOut(500, function() { $(this).remove(); });
                } else {
                    alert('Error: ' + response.data.message);
                    button.prop('disabled', false).text('Approve');
                }
            },
            error: function() {
                alert('An unexpected server error occurred. Please try again.');
                button.prop('disabled', false).text('Approve');
            }
        });
    });

    // --- Product Rejection ---
    const productRejectionModal = $('#tts-product-rejection-modal');
    const productReasonText = $('#tts-product-rejection-reason-text');
    const productConfirmBtn = $('#tts-product-rejection-confirm');

    // Show the rejection modal
    $('body').on('click', '.oa-reject-product-btn', function() {
        const productId = $(this).data('product-id');
        productConfirmBtn.data('product-id', productId);
        productReasonText.val('');
        productRejectionModal.show();
    });

    // Handle the confirm rejection click
    productConfirmBtn.on('click', function() {
        const button = $(this);
        const productId = button.data('product-id');
        const reason = productReasonText.val();
        const productRow = $('#product-row-' + productId);

        if (reason.trim() === '') {
            alert('Please provide a reason for rejection.');
            return;
        }

        button.prop('disabled', true).text('Rejecting...');

        $.ajax({
            url: taptosell_ajax_object.ajax_url,
            type: 'POST',
            data: {
                action: 'taptosell_reject_product',
                security: taptosell_ajax_object.product_actions_nonce,
                product_id: productId,
                reason: reason
            },
            success: function(response) {
                if (response.success) {
                    alert('Product rejected.');
                    productRejectionModal.hide();
                    productRow.fadeOut(500, function() { $(this).remove(); });
                } else {
                    alert('Error: ' + response.data.message);
                }
            },
            error: function() {
                alert('An unexpected server error occurred. Please try again.');
            },
            complete: function() {
                button.prop('disabled', false).text('Confirm Rejection');
            }
        });
    });


    // =================================================================
    // 3. GENERAL MODAL BEHAVIOR
    // =================================================================
    
    // Close any modal when the 'close' button or cancel button is clicked
    $('.taptosell-modal-overlay').on('click', '.tts-modal-close, [data-dismiss="modal"]', function() {
        $(this).closest('.taptosell-modal-overlay').hide();
    });

    // Close the modal if the user clicks on the dark overlay background
    $('.taptosell-modal-overlay').on('click', function(e) {
        if ($(e.target).is('.taptosell-modal-overlay')) {
            $(this).hide();
        }
    });

});