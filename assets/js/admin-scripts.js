// Ensure this code runs after the document is ready
jQuery(document).ready(function($) {
    
    // We use '.on()' because the Quick Edit row is added to the page dynamically.
    // This listens for a click on an '.editinline' link inside the '#the-list' element.
    $('#the-list').on('click', '.editinline', function() {
        
        // Get the table row that this 'Quick Edit' link is inside of.
        var tag_row = $(this).closest('tr');
        
        // --- 1. Populate the 'Order' field ---
        // From the row, find the 'Order' column and get its text content.
        var term_order = tag_row.find('.column-term_order').text();
        // Find the 'Order' input field within the Quick Edit form and set its value.
        $('.inline-edit-row input[name="term_order"]').val(term_order);
        

        // --- 2. Populate the 'Parent Category' field ---
        // Get the term ID from the row's ID attribute (e.g., "tag-123" becomes "123").
        var term_id = tag_row.attr('id').replace('tag-', '');
        
        // Find the hidden div that WordPress creates which holds the term's data.
        var hidden_data_div = $('#inline_' .concat(term_id));
        
        // From that hidden div, find the element with class '.parent' and get the parent's ID.
        var parent_id = hidden_data_div.find('.parent').text();
        
        // Find our 'Parent Category' dropdown in the Quick Edit form and set its value to the parent ID.
        $('.inline-edit-row select[name="parent"]').val(parent_id);
    });
    
});