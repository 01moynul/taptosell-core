/**
 * TapToSell Product Variations Management Script
 *
 * Handles automatic detection of product type (simple/variable) based on
 * the presence of variation groups.
 *
 * @version 2.1
 */
jQuery(document).ready(function($) {

    // --- CACHE FREQUENTLY USED ELEMENTS ---
    const form = $('#new-product-form, #edit-product-form');
    const simpleProductFieldsContainer = $('#simple-product-fields');
    const simpleProductInputs = simpleProductFieldsContainer.find('input');
    const variationGroupsWrapper = $('#variation-groups-wrapper');
    const addGroupButton = $('#add-variation-group');
    const tableWrapper = $('#variation-list-table-wrapper');

    // --- GLOBAL STATE ---
    let variationData = {};

    /**
     * This is the core new function. It checks if variations exist and updates the UI.
     */
    function updateProductMode() {
        const hasVariations = variationGroupsWrapper.find('.variation-group').length > 0;
        
        // Add or update a hidden input field to tell the backend if this is a variable product.
        let isVariableInput = form.find('input[name="is_variable"]');
        if (isVariableInput.length === 0) {
            form.append('<input type="hidden" name="is_variable" value="0">');
            isVariableInput = form.find('input[name="is_variable"]');
        }

        if (hasVariations) {
            // Product is VARIABLE: disable simple fields and set hidden input to 1.
            simpleProductInputs.prop('required', false);
            simpleProductFieldsContainer.addClass('disabled-fields');
            isVariableInput.val('1');
        } else {
            // Product is SIMPLE: enable simple fields and set hidden input to 0.
            simpleProductInputs.prop('required', true);
            simpleProductFieldsContainer.removeClass('disabled-fields');
            isVariableInput.val('0');
        }
    }

    /**
     * Adds a new variation group UI.
     */
    function addVariationGroup() {
        const groupIndex = Date.now();
        const newGroupHTML = `
            <div class="variation-group">
                <div class="variation-header">
                    <input type="text" name="variation[${groupIndex}][name]" class="variation-name-input" placeholder="e.g., Size">
                    <button type="button" class="remove-variation-group" title="Remove Variation">&times;</button>
                </div>
                <div class="variation-options">
                    <input type="text" class="variation-option-input" placeholder="e.g., Small (Press Enter to add)">
                </div>
            </div>`;
        variationGroupsWrapper.append(newGroupHTML);
    }

    /**
     * Adds a new option tag to a variation group.
     */
    function addOptionTag(optionInput) {
        const optionText = optionInput.val().trim();
        if (optionText === "") return;

        const groupIndex = optionInput.closest('.variation-group').index();
        const newTagHTML = `
            <span class="variation-option-tag">
                ${optionText}
                <input type="hidden" name="variation[${groupIndex}][options][]" value="${optionText}">
                <button type="button" class="remove-option-tag">&times;</button>
            </span>`;
        $(newTagHTML).insertBefore(optionInput);
        optionInput.val('').focus();
        updateVariationDataAndRenderTable();
    }

    // --- Event Listeners ---

    // Add new variation group AND update the product mode
    addGroupButton.on('click', function() {
        addVariationGroup();
        updateProductMode();
    });

    // Remove a variation group AND update the product mode
    variationGroupsWrapper.on('click', '.remove-variation-group', function() {
        $(this).closest('.variation-group').remove();
        updateVariationDataAndRenderTable();
        updateProductMode();
    });

    // Add an option tag on "Enter"
    variationGroupsWrapper.on('keypress', '.variation-option-input', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            addOptionTag($(this));
        }
    });
    
    // Remove an option tag
    variationGroupsWrapper.on('click', '.remove-option-tag', function() {
        $(this).closest('.variation-option-tag').remove();
        updateVariationDataAndRenderTable();
    });
    
    // Update data when a variation name is changed
    variationGroupsWrapper.on('keyup', '.variation-name-input', function() {
        clearTimeout($(this).data('timer'));
        $(this).data('timer', setTimeout(updateVariationDataAndRenderTable, 300));
    });

    /**
     * Reads the DOM and rebuilds the `variationData` object.
     */
    function updateVariationDataAndRenderTable() {
        variationData = {};
        $('.variation-group').each(function() {
            const groupName = $(this).find('.variation-name-input').val().trim();
            if (groupName) {
                const options = [];
                $(this).find('.variation-option-tag').each(function() {
                    // Extract text content only, removing the hidden input value
                    options.push($(this).contents().filter(function() {
                        return this.nodeType === 3; // Node.TEXT_NODE
                    }).text().trim());
                });
                if (options.length > 0) {
                    variationData[groupName] = options;
                }
            }
        });
        renderVariationTable();
    }

    /**
     * Renders the HTML table based on the variation combinations.
     */
    function renderVariationTable() {
        const groups = Object.values(variationData);
        if (groups.length === 0) {
            tableWrapper.html('');
            return;
        }
        
        let combinations = groups.reduce((a, b) => a.flatMap(x => b.map(y => [...x, y])), [[]]);
        combinations = combinations.filter(combo => combo.length > 0);
        
        const headers = Object.keys(variationData);
        
        let tableHTML = '<table class="taptosell-variation-table"><thead><tr>';
        headers.forEach(header => tableHTML += `<th>${header}</th>`);
        tableHTML += '<th>Your Price (RM)</th><th>SKU</th><th>Stock Quantity</th></tr></thead><tbody>';

        combinations.forEach((combo, index) => {
            const comboName = combo.join(' / ');
            tableHTML += `<tr>`;
            combo.forEach(option => tableHTML += `<td>${option}</td>`);
            tableHTML += `
                <td><input type="number" step="0.01" name="variants[${index}][price]" placeholder="e.g., 25.50" required></td>
                <td><input type="text" name="variants[${index}][sku]" placeholder="e.g., TSHIRT-RED-S" required></td>
                <td><input type="number" step="1" name="variants[${index}][stock]" placeholder="e.g., 50" required></td>
                <input type="hidden" name="variants[${index}][name]" value="${comboName}">
            `;
            tableHTML += `</tr>`;
        });

        tableHTML += '</tbody></table>';
        tableWrapper.html(tableHTML);
    }

    // --- KICK IT OFF ---
    updateProductMode(); // Run on page load to set the initial state.
});