jQuery(document).ready(function($) {

    // --- MAIN VARIABLES ---
    const variationsContainer = $('#variations-container');
    const simpleFieldsContainer = $('#simple-product-fields');
    const toggle = $('#enable-variations-toggle');
    const groupsWrapper = $('#variation-groups-wrapper');
    const addGroupButton = $('#add-variation-group');
    const tableWrapper = $('#variation-list-table-wrapper');

    // =================================================================
    // 1. TOGGLE VARIATIONS ON/OFF
    // =================================================================
    toggle.on('change', function() {
        if ($(this).is(':checked')) {
            // Variations are ENABLED
            variationsContainer.slideDown();
            simpleFieldsContainer.slideUp();
            // Make simple fields non-required when variations are active
            simpleFieldsContainer.find('input').prop('required', false);
        } else {
            // Variations are DISABLED
            variationsContainer.slideUp();
            simpleFieldsContainer.slideDown();
            // Make simple fields required again when variations are disabled
            simpleFieldsContainer.find('input').prop('required', true);
        }
    });

    // =================================================================
    // 2. ADD / REMOVE VARIATION GROUPS (e.g., Color, Size)
    // =================================================================

    // --- Add a new variation group ---
    addGroupButton.on('click', function() {
        let groupCount = groupsWrapper.find('.variation-group').length;
        if (groupCount >= 2) {
            alert('You can add a maximum of 2 variation types.');
            return;
        }

        let newIndex = groupCount + 1;
        let newGroupHTML = `
            <div class="variation-group">
                <div class="variation-header">
                    <input type="text" name="variation[${newIndex}][name]" class="variation-name-input" placeholder="e.g., Size">
                    <button type="button" class="remove-variation-group" title="Remove Variation">&times;</button>
                </div>
                <div class="variation-options">
                    <input type="text" class="variation-option-input" placeholder="e.g., Small (Press Enter to add)">
                </div>
            </div>`;
        
        groupsWrapper.append(newGroupHTML);
    });

    // --- Remove a variation group (using event delegation) ---
    groupsWrapper.on('click', '.remove-variation-group', function() {
        $(this).closest('.variation-group').remove();
        generateVariationTable(); // Regenerate the table after removing a group
    });

    // =================================================================
    // 3. ADD / REMOVE VARIATION OPTIONS (e.g., Red, Blue)
    // =================================================================

    // --- Add a new option tag when user presses Enter ---
    groupsWrapper.on('keydown', '.variation-option-input', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            let inputValue = $(this).val().trim();
            if (inputValue) {
                let optionsContainer = $(this).closest('.variation-options');
                let groupIndex = optionsContainer.closest('.variation-group').index() + 1;

                // Create the visible tag
                let tagHTML = `
                    <span class="variation-option-tag">
                        ${inputValue}
                        <span class="remove-option">&times;</span>
                    </span>`;
                
                // Create the hidden input to submit the data
                let hiddenInputHTML = `<input type="hidden" name="variation[${groupIndex}][options][]" value="${inputValue}">`;

                $(this).before(tagHTML);
                optionsContainer.append(hiddenInputHTML);
                $(this).val(''); // Clear the input
                generateVariationTable(); // Regenerate the table with the new option
            }
        }
    });

    // --- Remove an option tag ---
    groupsWrapper.on('click', '.remove-option', function() {
        let optionText = $(this).parent().text().replace('×', '').trim();
        let optionsContainer = $(this).closest('.variation-options');
        
        // Remove the hidden input that corresponds to this tag
        optionsContainer.find(`input[type="hidden"][value="${optionText}"]`).remove();
        
        // Remove the visible tag
        $(this).parent().remove();
        
        generateVariationTable(); // Regenerate table after removing option
    });

    // =================================================================
    // 4. DYNAMICALLY GENERATE THE VARIATION PRICE/STOCK TABLE
    // =================================================================
    function generateVariationTable() {
        let variationGroupsData = [];
        
        // Step 1: Collect all variation data from the form
        groupsWrapper.find('.variation-group').each(function() {
            let groupName = $(this).find('.variation-name-input').val().trim();
            let options = [];
            $(this).find('input[type="hidden"]').each(function() {
                options.push($(this).val());
            });

            if (groupName && options.length > 0) {
                variationGroupsData.push({ name: groupName, options: options });
            }
        });

        // If there's no valid data, clear the table and exit
        if (variationGroupsData.length === 0) {
            tableWrapper.html('');
            return;
        }

        // Step 2: Calculate all possible combinations (Cartesian product)
        let combinations = variationGroupsData.reduce((acc, curr) => {
            let result = [];
            acc.forEach(a => {
                curr.options.forEach(b => {
                    result.push(a.concat([b]));
                });
            });
            return result;
        }, [[]]);

        // Step 3: Build the HTML for the table
        let tableHTML = '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        
        // Create table headers from variation group names
        variationGroupsData.forEach(group => {
            tableHTML += `<th>${group.name}</th>`;
        });
        tableHTML += '<th>Price (RM)</th><th>Stock</th><th>SKU</th></tr></thead><tbody>';

        // Create a row for each combination
        combinations.forEach((combo, index) => {
            // Remove the first empty element from the reduce function
            let validCombo = combo.slice(1); 
            if (validCombo.length === 0) return;

            tableHTML += '<tr>';
            validCombo.forEach(option => {
                tableHTML += `<td>${option}</td>`;
            });
            
            // Add input fields for price, stock, and SKU
            tableHTML += `<td><input type="number" step="0.01" name="variants[${index}][price]" placeholder="Price" required></td>`;
            tableHTML += `<td><input type="number" step="1" name="variants[${index}][stock]" placeholder="Stock" required></td>`;
            tableHTML += `<td><input type="text" name="variants[${index}][sku]" placeholder="SKU"></td>`;

            // This hidden field stores the variant combination, e.g., "Red,Small"
            tableHTML += `<input type="hidden" name="variants[${index}][name]" value="${validCombo.join(',')}">`;
            
            tableHTML += '</tr>';
        });

        tableHTML += '</tbody></table>';

        // Step 4: Display the generated table
        tableWrapper.html(tableHTML);
    }
});

// =================================================================
    // 5. HANDLE "SAVE AS DRAFT" SUBMISSION
    // =================================================================
    $('button[name="save_as_draft"]').on('click', function() {
        // When the draft button is clicked, temporarily remove the 'required'
        // attribute from all inputs in the form. This allows the form to
        // be submitted even if some fields are empty.
        $('#new-product-form').find('input, select, textarea').prop('required', false);
    });