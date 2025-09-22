// In: assets/js/frontend-scripts.js

jQuery(document).ready(function($) {
    
    // --- Login Modal ---

    // Get the modal
    // --- CORRECTION: Changed '#tts-login-modal' to '#taptosell-login-modal' ---
    var modal = $('#taptosell-login-modal');

    // Get the button that opens the modal
    var btn = $('.tts-login-trigger');

    // Get the <span> element that closes the modal
    // --- CORRECTION: Changed '.tts-modal-close' to '.taptosell-modal-close' ---
    var span = modal.find('.taptosell-modal-close');

    // When the user clicks the button, open the modal 
    btn.on('click', function(e) {
        e.preventDefault(); // Prevent default link behavior
        modal.show();
    });

    // When the user clicks on <span> (x), close the modal
    span.on('click', function() {
        modal.hide();
    });

    // When the user clicks anywhere outside of the modal content, close it
    $(window).on('click', function(event) {
        if ($(event.target).is(modal)) {
            modal.hide();
        }
    });

});