jQuery(document).ready(function($) {

    // --- MAIN VARIABLES ---
    const variationsContainer = $('#variations-container');
    const simpleFieldsContainer = $('#simple-product-fields');
    const toggle = $('#enable-variations-toggle');
    const groupsWrapper = $('#variation-groups-wrapper');
    const addGroupButton = $('#add-variation-group');
    const tableWrapper = $('#variation-list-table-wrapper');

    // =================================================================
    // 1. INITIALIZATION LOGIC (FOR EDIT PAGE)
    // =================================================================
    // This is the new block to handle pre-filling the form on the edit page.
    function initializeEditForm() {
        // Check if the taptosell_edit_data object exists (it's passed from PHP).
        if (typeof taptosell_edit_data !== 'undefined' && taptosell_edit_data.attributes.length > 0) {
            
            // A) Pre-fill the variation groups and options (e.g., "Color": "Red", "Blue")
            taptosell_edit_data.attributes.forEach((group, index) => {
                let groupIndex = index + 1;
                let groupHTML = `
                    <div class="variation-group">
                        <div class="variation-header">
                            <input type="text" name="variation[${groupIndex}][name]" class="variation-name-input" value="${group.name}">
                            <button type="button" class="remove-variation-group" title="Remove Variation">&times;</button>
                        </div>
                        <div class="variation-options">
                            <input type="text" class="variation-option-input" placeholder="Add another option...">
                        </div>
                    </div>`;
                groupsWrapper.append(groupHTML);

                let currentGroup = groupsWrapper.find('.variation-group').last();
                let optionsContainer = currentGroup.find('.variation-options');

                group.options.forEach(optionValue => {
                    let tagHTML = `<span class="variation-option-tag">${optionValue}<span class="remove-option">&times;</span></span>`;
                    let hiddenInputHTML = `<input type="hidden" name="variation[${groupIndex}][options][]" value="${optionValue}">`;
                    currentGroup.find('.variation-option-input').before(tagHTML);
                    optionsContainer.append(hiddenInputHTML);
                });
            });

            // B) Regenerate the pricing table with the saved values
            generateVariationTable(taptosell_edit_data.variations);
        }
    }

    // =================================================================
    // 2. TOGGLE VARIATIONS ON/OFF
    // =================================================================
    toggle.on('change', function() {
        if ($(this).is(':checked')) {
            variationsContainer.slideDown();
            simpleFieldsContainer.slideUp();
            simpleFieldsContainer.find('input').prop('required', false);
        } else {
            variationsContainer.slideUp();
            simpleFieldsContainer.slideDown();
            simpleFieldsContainer.find('input').prop('required', true);
        }
    });

    // =================================================================
    // 3. ADD / REMOVE VARIATION GROUPS (e.g., Color, Size)
    // =================================================================
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

    groupsWrapper.on('click', '.remove-variation-group', function() {
        $(this).closest('.variation-group').remove();
        generateVariationTable();
    });

    // =================================================================
    // 4. ADD / REMOVE VARIATION OPTIONS (e.g., Red, Blue)
    // =================================================================
    groupsWrapper.on('keydown', '.variation-option-input', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            let inputValue = $(this).val().trim();
            if (inputValue) {
                let optionsContainer = $(this).closest('.variation-options');
                let groupIndex = optionsContainer.closest('.variation-group').index() + 1;
                let tagHTML = `<span class="variation-option-tag">${inputValue}<span class="remove-option">&times;</span></span>`;
                let hiddenInputHTML = `<input type="hidden" name="variation[${groupIndex}][options][]" value="${inputValue}">`;
                $(this).before(tagHTML);
                optionsContainer.append(hiddenInputHTML);
                $(this).val('');
                generateVariationTable();
            }
        }
    });

    groupsWrapper.on('click', '.remove-option', function() {
        let optionText = $(this).parent().text().replace('×', '').trim();
        let optionsContainer = $(this).closest('.variation-options');
        optionsContainer.find(`input[type="hidden"][value="${optionText}"]`).remove();
        $(this).parent().remove();
        generateVariationTable();
    });

    // =================================================================
    // 5. DYNAMICALLY GENERATE THE VARIATION PRICE/STOCK TABLE
    // =================================================================
    function generateVariationTable(savedVariations = []) {
        let variationGroupsData = [];
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

        if (variationGroupsData.length === 0) {
            tableWrapper.html('');
            return;
        }

        let combinations = variationGroupsData.reduce((acc, curr) => {
            let result = [];
            acc.forEach(a => {
                curr.options.forEach(b => {
                    result.push(a.concat([b]));
                });
            });
            return result;
        }, [
            []
        ]);

        let tableHTML = '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        variationGroupsData.forEach(group => {
            tableHTML += `<th>${group.name}</th>`;
        });
        tableHTML += '<th>Price (RM)</th><th>Stock</th><th>SKU</th></tr></thead><tbody>';

        combinations.forEach((combo, index) => {
            let validCombo = combo.slice(1);
            if (validCombo.length === 0) return;

            let comboName = validCombo.join(',');
            // Find saved data for this specific combination
            let savedData = savedVariations.find(v => v.name === comboName) || {};
            let price = savedData.price || '';
            let stock = savedData.stock || '';
            let sku = savedData.sku || '';

            tableHTML += '<tr>';
            validCombo.forEach(option => {
                tableHTML += `<td>${option}</td>`;
            });
            tableHTML += `<td><input type="number" step="0.01" name="variants[${index}][price]" value="${price}" placeholder="Price" required></td>`;
            tableHTML += `<td><input type="number" step="1" name="variants[${index}][stock]" value="${stock}" placeholder="Stock" required></td>`;
            tableHTML += `<td><input type="text" name="variants[${index}][sku]" value="${sku}" placeholder="SKU"></td>`;
            tableHTML += `<input type="hidden" name="variants[${index}][name]" value="${comboName}">`;
            tableHTML += '</tr>';
        });

        tableHTML += '</tbody></table>';
        tableWrapper.html(tableHTML);
    }

    // =================================================================
    // 6. HANDLE "SAVE AS DRAFT" SUBMISSION
    // =================================================================
    $('button[name="save_as_draft"]').on('click', function() {
        // This now targets both the new product form AND the edit product form.
        $('#new-product-form, #edit-product-form').find('input, select, textarea').prop('required', false);
    });

    // --- KICK IT OFF ---
    initializeEditForm(); // Run the initialization function when the script loads.
});