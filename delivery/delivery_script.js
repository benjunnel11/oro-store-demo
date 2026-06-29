console.log('🚀 delivery_script.js loaded!');

// Get cart data from sessionStorage
let cart = [];
let currentPanel = 'left';
let selectedProductIndex = 0;
let selectedReceiptIndex = 0;
let currentSelectedProduct = null;
let currentEditIndex = null;
let autocompleteTimeout = null;
let selectedAutocompleteIndex = -1;
let autocompleteResults = [];

// ✅ Re-edit mode variables
let isReEditMode = false;
let originalTransactionId = null;
let originalTransactionItems = [];

const DELIVERY_SCRIPT_VERSION = '6.0-REEDIT-FIXED';
console.log('=== DELIVERY SCRIPT VERSION: ' + DELIVERY_SCRIPT_VERSION + ' ===');

// ✅ SINGLE DOMContentLoaded event - checks re-edit FIRST
document.addEventListener('DOMContentLoaded', function() {
    console.log('🔍 DOM Content Loaded - Starting initialization...');
    
    // Load products and setup search first
    loadProducts();
    setupSearch();
    setupRecipientAutocomplete();
    
    // ✅ PRIORITY 1: Check for re-edit mode FIRST
    const reEditData = sessionStorage.getItem('reEditData');
    console.log('📦 reEditData from sessionStorage:', reEditData ? 'FOUND' : 'NOT FOUND');
    
    if (reEditData) {
        try {
            const data = JSON.parse(reEditData);
            console.log('✅ Parsed reEditData:', data);
            
            if (data.isReEditMode) {
                console.log('🎯 RE-EDIT MODE DETECTED!');
                
                // Set re-edit mode flags
                isReEditMode = true;
                 originalTransactionId = data.originalTransactionId;  // ✅ This should work
                originalTransactionItems = data.originalTransactionItems;
                
                console.log('📝 Original Transaction ID:', originalTransactionId);
                
                // Store transaction info
                window.currentTransactionInfo = data.transactionInfo;
                
                // Load cart from re-edit data
                cart = data.cart;
                console.log('🛒 Cart loaded with', cart.length, 'items');
                
                // Save to deliveryCart for persistence
                sessionStorage.setItem('deliveryCart', JSON.stringify(cart));
                console.log('💾 Cart saved to deliveryCart');
                
                // Clear reEditData immediately
                sessionStorage.removeItem('reEditData');
                console.log('🗑️ reEditData cleared');
                
                // Update receipt immediately
                updateReceipt();
                console.log('✅ Receipt updated!');
                
                // Show alert after a delay
                setTimeout(() => {
                    alert('✏️ RE-EDIT MODE\n\n✓ Cart loaded with transaction items (' + cart.length + ' items)\n✓ You can add, edit, or remove items\n✓ Complete the form to save changes\n✓ Original Transaction #' + originalTransactionId + ' will be marked as "edited"');
                }, 500);
                
                console.log('✅ Re-edit initialization complete');
                return; // ✅ Exit early
            }
        } catch (e) {
            console.error('❌ Error loading re-edit data:', e);
            sessionStorage.removeItem('reEditData');
        }
    }
    
    // ✅ PRIORITY 2: Check for deliveryCart (normal delivery mode)
    const savedCart = sessionStorage.getItem('deliveryCart');
    if (savedCart) {
        console.log('🚚 Loading from deliveryCart...');
        cart = JSON.parse(savedCart);
        updateReceipt();
    }
    
    console.log('✅ Initialization complete');
});

// Setup autocomplete for recipient name
function setupRecipientAutocomplete() {
    const recipientNameInput = document.getElementById('recipient-name');
    const recipientAddressInput = document.getElementById('recipient-address');
    const autocompleteDropdown = document.getElementById('autocomplete-dropdown');
    
    if (!recipientNameInput || !autocompleteDropdown) return;
    
    recipientNameInput.addEventListener('input', function() {
        const searchTerm = this.value.trim();
        
        clearTimeout(autocompleteTimeout);
        selectedAutocompleteIndex = -1;
        
        if (searchTerm.length < 2) {
            autocompleteDropdown.style.display = 'none';
            autocompleteResults = [];
            return;
        }
        
        autocompleteTimeout = setTimeout(() => {
            fetch(`/oro-store/delivery/delivery.php?action=search_customers&search=${encodeURIComponent(searchTerm)}`)
                .then(response => response.json())
                .then(customers => {
                    autocompleteResults = customers;
                    
                    if (customers.length === 0) {
                        autocompleteDropdown.style.display = 'none';
                        return;
                    }
                    
                    let html = '';
                    customers.forEach((customer, index) => {
                        const name = escapeHtml(customer.recipient_name || '');
                        const address = escapeHtml(customer.recipient_address || 'No address');
                        
                        html += `
                            <div class="autocomplete-item ${index === selectedAutocompleteIndex ? 'selected' : ''}" data-index="${index}">
                                <strong>${name}</strong>
                                <small>${address}</small>
                            </div>
                        `;
                    });
                    
                    autocompleteDropdown.innerHTML = html;
                    autocompleteDropdown.style.display = 'block';
                    
                    // Add click handlers
                    document.querySelectorAll('.autocomplete-item').forEach(item => {
                        item.addEventListener('click', function() {
                            const index = parseInt(this.dataset.index);
                            selectAutocompleteItem(index);
                        });
                    });
                })
                .catch(error => {
                    console.error('Autocomplete error:', error);
                    autocompleteDropdown.style.display = 'none';
                });
        }, 300);
    });
    
    // Handle keyboard navigation in autocomplete
    recipientNameInput.addEventListener('keydown', function(e) {
        const dropdown = document.getElementById('autocomplete-dropdown');
        
        if (dropdown.style.display === 'none' || autocompleteResults.length === 0) {
            return;
        }
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectedAutocompleteIndex = Math.min(selectedAutocompleteIndex + 1, autocompleteResults.length - 1);
            updateAutocompleteSelection();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedAutocompleteIndex = Math.max(selectedAutocompleteIndex - 1, 0);
            updateAutocompleteSelection();
        } else if (e.key === 'Enter' && selectedAutocompleteIndex >= 0) {
            e.preventDefault();
            selectAutocompleteItem(selectedAutocompleteIndex);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            dropdown.style.display = 'none';
            selectedAutocompleteIndex = -1;
        }
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!recipientNameInput.contains(e.target) && !autocompleteDropdown.contains(e.target)) {
            autocompleteDropdown.style.display = 'none';
            selectedAutocompleteIndex = -1;
        }
    });
}

// Update autocomplete selection highlight
function updateAutocompleteSelection() {
    const items = document.querySelectorAll('.autocomplete-item');
    items.forEach((item, index) => {
        item.classList.toggle('selected', index === selectedAutocompleteIndex);
    });
    
    if (items[selectedAutocompleteIndex]) {
        items[selectedAutocompleteIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}

// Select autocomplete item
function selectAutocompleteItem(index) {
    if (index < 0 || index >= autocompleteResults.length) return;
    
    const customer = autocompleteResults[index];
    document.getElementById('recipient-name').value = customer.recipient_name;
    document.getElementById('recipient-address').value = customer.recipient_address;
    document.getElementById('autocomplete-dropdown').style.display = 'none';
    selectedAutocompleteIndex = -1;
    
    // Focus on address field
    document.getElementById('recipient-address').focus();
}

// Load products
function loadProducts() {
    fetch('/oro-store/products/get_products.php')
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(products => {
            const productList = document.getElementById('product-list');
            let html = '';
            
            products.forEach((product, index) => {
                const isOutOfStock = product.stock <= 0;
                const outOfStockClass = isOutOfStock ? 'out-of-stock' : '';
                const productName = escapeHtml(product.name || '');
                const parentProductId = product.parent_product_id || '';
                
                const isChild = !!(product.individual_sell_unit);
                const tags = [];
                if (product.individual_sell_unit) tags.push(`<span style="font-size:9px;font-weight:600;padding:1px 5px;border-radius:3px;background:#fef3c7;color:#92400e;">${escapeHtml(product.individual_sell_unit.charAt(0).toUpperCase()+product.individual_sell_unit.slice(1))}</span>`);
                if (product.category_name && !isChild) tags.push(`<span style="font-size:9px;font-weight:600;padding:1px 5px;border-radius:3px;background:#ede9fe;color:#7c3aed;">${escapeHtml(product.category_name)}</span>`);
                if (product.brand_name) tags.push(`<span style="font-size:9px;font-weight:600;padding:1px 5px;border-radius:3px;background:#dbeafe;color:#1d4ed8;">${escapeHtml(product.brand_name)}</span>`);
                const tagHtml = tags.length ? ` <span style="display:inline-flex;gap:3px;margin-left:4px;vertical-align:middle;">${tags.join('')}</span>` : '';
                html += `
                    <li class="product-item-cashier ${outOfStockClass}"
                        data-id="${product.id}"
                        data-name="${productName}"
                        data-price="${product.price}"
                        data-purchase-price="${product.purchase_price}"
                        data-stock="${product.stock}"
                        data-brand="${product.brand_name||''}"
                        data-category="${product.category_name||''}"
                        data-unit="${product.individual_sell_unit||''}"
                        data-parent-product-id="${parentProductId}"
                        data-index="${index}"
                        onclick="handleProductTap(this)"
                        style="-webkit-user-select:none;user-select:none;"
                        <span class="product-name-cashier">
                            ${productName}${tagHtml}
                            ${isOutOfStock ? '<span style="color: #dc3545; font-weight: bold; font-size: 11px;"> (OUT OF STOCK)</span>' : ''}
                        </span>
                        <span class="product-stock-cashier" style="${isOutOfStock ? 'color: #dc3545; font-weight: bold;' : ''}">
                            Stock: ${product.stock}
                        </span>
                        <span class="product-price-cashier">₱${parseFloat(product.price).toFixed(2)}</span>
                    </li>
                `;
            });
            
            productList.innerHTML = html;
            updateProductSelection();
        })
        .catch(error => {
            console.error('Error loading products:', error);
            alert('Error loading products. Please refresh the page.');
        });
}

// Helper function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Search functionality
function setupSearch() {
    const searchInput = document.getElementById('cashier-search');
    
    searchInput.addEventListener('input', function() {
        const query = this.value.toLowerCase();
        const productItems = document.querySelectorAll('.product-item-cashier');
        let visibleIndex = 0;

        productItems.forEach((item) => {
            const isChild = (item.dataset.unit||'').trim() !== '';
            let searchable = (item.dataset.name||'')+' '+(item.dataset.brand||'')+' '+(item.dataset.unit||'');
            if (!isChild) searchable += ' '+(item.dataset.category||'');
            const matches = query === '' || searchable.toLowerCase().includes(query);

            item.style.display = matches ? 'flex' : 'none';
            if (matches) {
                item.dataset.visibleIndex = visibleIndex;
                visibleIndex++;
            }
        });

        selectedProductIndex = 0;
        updateProductSelection();
    });
}

// Update product selection
function updateProductSelection() {
    const items = Array.from(document.querySelectorAll('.product-item-cashier')).filter(item => item.style.display !== 'none');
    items.forEach((item, index) => {
        item.classList.toggle('selected', index === selectedProductIndex);
    });
    
    if (items[selectedProductIndex]) {
        items[selectedProductIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}

// Update receipt selection
function updateReceiptSelection() {
    const items = document.querySelectorAll('.receipt-item');
    items.forEach((item, index) => {
        item.classList.toggle('selected', index === selectedReceiptIndex);
    });
    
    if (items[selectedReceiptIndex]) {
        items[selectedReceiptIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}

// Open quantity modal
function openQuantityModal(product) {
    currentSelectedProduct = product;
    document.getElementById('modal-product-name').textContent = product.name;
    document.getElementById('modal-stock').textContent = product.stock;
    document.getElementById('quantity-input').value = '1';
    
    const isIndividual = product.parent_product_id && product.parent_product_id !== '';
    if (isIndividual) {
        document.getElementById('quantity-input').removeAttribute('max');
        document.getElementById('modal-stock').innerHTML = product.stock + ' <span style="color: #28a745; font-weight: bold;">(Unlimited - Auto-opens packs)</span>';
    } else {
        document.getElementById('quantity-input').max = product.stock;
    }
    
    document.getElementById('quantity-modal').classList.add('active');
    document.getElementById('quantity-input').focus();
}

// Close quantity modal
function closeQuantityModal() {
    document.getElementById('quantity-modal').classList.remove('active');
    currentSelectedProduct = null;
    document.getElementById('cashier-search').focus();
}

// Confirm quantity
function confirmQuantity() {
    const quantity = parseInt(document.getElementById('quantity-input').value);
    
    if (!quantity || quantity < 1) {
        alert('Please enter a valid quantity');
        return;
    }
    
    const isIndividual = currentSelectedProduct.parent_product_id && currentSelectedProduct.parent_product_id !== '';
    
    if (!isIndividual && quantity > currentSelectedProduct.stock) {
        alert('Quantity exceeds available stock');
        return;
    }

    // Fetch delivery fee for this product
    fetch(`/oro-store/delivery/delivery.php?action=get_product_delivery_info&product_id=${currentSelectedProduct.id}`)
    .then(r => r.json())
    .then(feeData => {
        const deliveryFee = feeData.fee || 0;
        const existingIndex = cart.findIndex(item => item.id === currentSelectedProduct.id);

        if (existingIndex !== -1) {
            cart[existingIndex].quantity += quantity;
            cart[existingIndex].subtotal = cart[existingIndex].quantity * cart[existingIndex].price;
            cart[existingIndex].profit = (cart[existingIndex].price - cart[existingIndex].purchase_price) * cart[existingIndex].quantity;
            cart[existingIndex].delivery_fee = deliveryFee;
            cart[existingIndex].total_fee = deliveryFee * cart[existingIndex].quantity;
        } else {
            cart.push({
                id: currentSelectedProduct.id,
                name: currentSelectedProduct.name,
                price: currentSelectedProduct.price,
                purchase_price: currentSelectedProduct.purchase_price,
                quantity: quantity,
                subtotal: quantity * currentSelectedProduct.price,
                profit: (currentSelectedProduct.price - currentSelectedProduct.purchase_price) * quantity,
                parent_product_id: currentSelectedProduct.parent_product_id || null,
                category: currentSelectedProduct.category || '',
                brand: currentSelectedProduct.brand || '',
                unit: currentSelectedProduct.unit || '',
                delivery_fee: deliveryFee,
                total_fee: deliveryFee * quantity
            });
        }

        sessionStorage.setItem('deliveryCart', JSON.stringify(cart));
        updateReceipt();
        closeQuantityModal();
    })
    .catch(() => {
        // Fallback: add without fee
        const existingIndex = cart.findIndex(item => item.id === currentSelectedProduct.id);
        if (existingIndex !== -1) {
            cart[existingIndex].quantity += quantity;
            cart[existingIndex].subtotal = cart[existingIndex].quantity * cart[existingIndex].price;
            cart[existingIndex].profit = (cart[existingIndex].price - cart[existingIndex].purchase_price) * cart[existingIndex].quantity;
        } else {
            cart.push({
                id: currentSelectedProduct.id, name: currentSelectedProduct.name,
                price: currentSelectedProduct.price, purchase_price: currentSelectedProduct.purchase_price,
                quantity: quantity, subtotal: quantity * currentSelectedProduct.price,
                profit: (currentSelectedProduct.price - currentSelectedProduct.purchase_price) * quantity,
                parent_product_id: currentSelectedProduct.parent_product_id || null,
                delivery_fee: 0, total_fee: 0
            });
        }
        sessionStorage.setItem('deliveryCart', JSON.stringify(cart));
        updateReceipt();
        closeQuantityModal();
    });
    
    document.getElementById('cashier-search').value = '';
    document.getElementById('cashier-search').dispatchEvent(new Event('input'));
    document.getElementById('cashier-search').focus();
}

// Update receipt
function updateReceipt() {
    const receiptItems = document.getElementById('receipt-items');
    const receiptTotal = document.getElementById('receipt-total');
    
    if (!receiptItems || !receiptTotal) {
        console.error('Receipt elements not found!');
        return;
    }
    
    if (cart.length === 0) {
        receiptItems.innerHTML = '<div class="empty-cart"><h3>Cart is Empty</h3><p>Add products for delivery</p></div>';
        receiptTotal.style.display = 'none';
        return;
    }

    let html = '';
    
    // ✅ Add mode indicator at the top for re-edit mode
    if (isReEditMode) {
        html += `
            <div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; padding: 10px; text-align: center; font-weight: bold; margin-bottom: 10px; border-radius: 4px;">
                ✏️ RE-EDIT MODE (Editing Transaction #${originalTransactionId})
            </div>
        `;
    }
    
    let totalItems = 0;
    let subtotal = 0;
    let totalFees = 0;

    cart.forEach((item, index) => {
        totalItems += item.quantity;
        subtotal += item.subtotal;
        const itemFee = (item.delivery_fee || 0) * item.quantity;
        totalFees += itemFee;
        html += `
            <div class="receipt-item" data-index="${index}" ontouchstart="startLongPress(${index})" ontouchend="cancelLongPress()" ontouchmove="cancelLongPress()" style="-webkit-user-select:none;user-select:none;">
                <div class="receipt-item-details">
                    <div class="receipt-item-name">${item.name}</div>
                    <div class="receipt-item-qty">${item.quantity} x ₱${item.price.toFixed(2)}${itemFee > 0 ? ` <span style="color:#f59e0b;font-size:11px;">+₱${itemFee.toFixed(0)} fee</span>` : ''}</div>
                </div>
                <div class="receipt-item-price">₱${(item.subtotal + itemFee).toFixed(2)}</div>
            </div>
        `;
    });

    receiptItems.innerHTML = html;
    receiptTotal.style.display = 'block';

    const grandTotal = subtotal + totalFees;
    document.getElementById('total-items').textContent = totalItems;
    document.getElementById('subtotal').textContent = '₱' + subtotal.toFixed(2);
    const feeEl = document.getElementById('total-delivery-fee') || document.getElementById('total-fees');
    if (feeEl) feeEl.textContent = '₱' + totalFees.toFixed(2);
    document.getElementById('grand-total').textContent = '₱' + grandTotal.toFixed(2);

    if (currentPanel === 'right') {
        updateReceiptSelection();
    }
}

// Delete item from cart
function deleteCartItem(index) {
    customConfirm('Remove this item from cart?', function() {
        cart.splice(index, 1);
        if (selectedReceiptIndex >= cart.length) {
            selectedReceiptIndex = Math.max(0, cart.length - 1);
        }
        sessionStorage.setItem('deliveryCart', JSON.stringify(cart));
        updateReceipt();
    });
}

// Open edit modal
function openEditModal(index) {
    currentEditIndex = index;
    const item = cart[index];
    document.getElementById('edit-product-name').textContent = item.name;
    document.getElementById('edit-current-qty').textContent = item.quantity;
    document.getElementById('edit-quantity-input').value = item.quantity;
    
    const isIndividual = item.parent_product_id && item.parent_product_id !== null;
    if (isIndividual) {
        document.getElementById('edit-quantity-input').removeAttribute('max');
    } else {
        document.getElementById('edit-quantity-input').max = item.stock || 999;
    }
    
    document.getElementById('edit-modal').classList.add('active');
    document.getElementById('edit-quantity-input').focus();
}

// Close edit modal
function closeEditModal() {
    document.getElementById('edit-modal').classList.remove('active');
    currentEditIndex = null;
}

// Confirm edit
function confirmEdit() {
    const newQuantity = parseInt(document.getElementById('edit-quantity-input').value);
    
    if (!newQuantity || newQuantity < 1) {
        alert('Please enter a valid quantity');
        return;
    }
    
    const item = cart[currentEditIndex];
    const isIndividual = item.parent_product_id && item.parent_product_id !== null;
    
    if (!isIndividual && item.stock && newQuantity > item.stock) {
        alert('Quantity exceeds available stock');
        return;
    }

    cart[currentEditIndex].quantity = newQuantity;
    cart[currentEditIndex].subtotal = newQuantity * cart[currentEditIndex].price;
    cart[currentEditIndex].profit = (cart[currentEditIndex].price - cart[currentEditIndex].purchase_price) * newQuantity;

    sessionStorage.setItem('deliveryCart', JSON.stringify(cart));
    updateReceipt();
    closeEditModal();
}

// Click handlers for receipt items
document.addEventListener('click', function(e) {
    if (e.target.closest('.receipt-item')) {
        if (typeof setActivePanel === 'function') setActivePanel('right'); else currentPanel = 'right';
        const item = e.target.closest('.receipt-item');
        selectedReceiptIndex = parseInt(item.dataset.index);
        updateReceiptSelection();
    }
});

// Calculate total helper function
// NOTE: overridden by inline script in delivery.php to be async + half-pack aware.
// Always call as: await Promise.resolve(calculateTotal())
function calculateTotal() {
    return cart.reduce((sum, item) => sum + item.subtotal + ((item.delivery_fee || 0) * item.quantity), 0);
}

// Open delivery info modal
function openDeliveryInfoModal() {
    if (cart.length === 0) {
        alert('Cart is empty');
        return;
    }
    
    document.getElementById('delivery-info-modal').classList.add('active');
    document.getElementById('recipient-name').focus();
}

// Close delivery info modal
function closeDeliveryInfoModal() {
    document.getElementById('delivery-info-modal').classList.remove('active');
    document.getElementById('autocomplete-dropdown').style.display = 'none';
}

// Complete delivery transaction
// CHANGED: async so we can await calculateTotal() which may be overridden to return a Promise.
async function completeDeliveryTransaction() {
    const recipientName = document.getElementById('recipient-name').value.trim();
    const recipientAddress = document.getElementById('recipient-address').value.trim();
    
    if (!recipientName || !recipientAddress) {
        alert('Please fill in all delivery information');
        return;
    }
    
    if (cart.length === 0) {
        alert('Cart is empty');
        return;
    }
    
    if (typeof STORE_INFO === 'undefined') {
        alert('ERROR: STORE_INFO is not defined! Check delivery.php script section.');
        console.error('STORE_INFO is undefined!');
        return;
    }
    
    // CHANGED: await so the half-pack-aware override is respected
    const total = await Promise.resolve(calculateTotal());
    const profit = cart.reduce((sum, item) => sum + item.profit, 0);
    const itemCount = cart.reduce((sum, item) => sum + item.quantity, 0);

    const formData = new FormData();
    
    // ✅ CHECK IF WE'RE IN RE-EDIT MODE
    if (isReEditMode) {
        console.log('🔄 RE-EDIT MODE: Sending reedit_transaction action');
        console.log('📝 Original Transaction ID:', originalTransactionId);
        console.log('📦 Original Items:', originalTransactionItems);
        
        formData.append('action', 'reedit_transaction');
        formData.append('original_transaction_id', originalTransactionId);
        formData.append('original_items', JSON.stringify(originalTransactionItems));
        formData.append('new_items', JSON.stringify(cart));
        formData.append('total_amount', total);
        formData.append('total_profit', profit);
        formData.append('items_count', itemCount);
        formData.append('recipient_name', recipientName);
        formData.append('recipient_address', recipientAddress);
    } else {
        console.log('✨ NORMAL MODE: Sending complete_delivery action');
        
        formData.append('action', 'complete_delivery');
        formData.append('items', JSON.stringify(cart));
        formData.append('total_amount', total);
        formData.append('total_profit', profit);
        formData.append('items_count', itemCount);
        formData.append('recipient_name', recipientName);
        formData.append('recipient_address', recipientAddress);
    }

    fetch('/oro-store/delivery/delivery.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            var msg = isReEditMode
                ? 'Delivery Transaction Updated!\n\nOriginal Transaction: #' + originalTransactionId + '\nNew Record ID: #' + data.transaction_id + '\nTransaction #: ' + data.transaction_number
                : 'Delivery transaction created successfully!\nTransaction #: ' + data.transaction_number;

            sessionStorage.removeItem('deliveryCart');
            sessionStorage.removeItem('reEditData');
            cart = [];
            isReEditMode = false;
            originalTransactionId = null;
            originalTransactionItems = [];
            updateReceipt();
            closeDeliveryInfoModal();
            document.getElementById('recipient-name').value = '';
            document.getElementById('recipient-address').value = '';

            customAlert(msg, 'success', function(){ location.reload(); });
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error completing delivery: ' + error.message);
    });
}

// Print receipt from modal
// CHANGED: async so we can await calculateTotal() which may be overridden to return a Promise.
async function printReceiptFromModal() {
    const recipientName = document.getElementById('recipient-name').value.trim();
    const recipientAddress = document.getElementById('recipient-address').value.trim();
    
    if (!recipientName || !recipientAddress) {
        alert('Please fill in recipient name and address');
        return;
    }
    
    if (cart.length === 0) {
        alert('Cart is empty');
        return;
    }
    
    if (typeof STORE_INFO === 'undefined') {
        alert('ERROR: STORE_INFO is not defined!');
        return;
    }
    
    // CHANGED: await so the half-pack-aware override is respected
    const total = await Promise.resolve(calculateTotal());

    const receiptData = {
        items: cart.map(item => ({
            id: item.id,
            name: item.name,
            quantity: item.quantity,
            price: item.price,
            purchase_price: item.purchase_price || 0,
            subtotal: item.price * item.quantity,
            profit: (item.price - (item.purchase_price || 0)) * item.quantity
        })),
        total: total,
        itemCount: cart.reduce((sum, item) => sum + item.quantity, 0),
        date: new Date().toLocaleString(),
        paymentMethod: 'delivery',
        deliveryInfo: {
            recipientName: recipientName,
            recipientAddress: recipientAddress
        },
        storeName: STORE_INFO.storeName,
        storeAddress: STORE_INFO.storeAddress,
        cashierName: STORE_INFO.cashierName
    };
    
    if (typeof BTPrint !== 'undefined') {
        BTPrint.printReceipt({
            storeName: receiptData.storeName,
            storeAddress: receiptData.storeAddress,
            title: 'DELIVERY ORDER',
            date: receiptData.date,
            cashier: receiptData.cashierName,
            items: receiptData.items,
            total: receiptData.total
        });
    }

    setTimeout(() => { completeDeliveryTransaction(); }, 500);
}

// Print receipt function
function printReceipt() {
    if (cart.length === 0) {
        alert('Cart is empty. Add items before printing receipt.');
        return;
    }
    
    openDeliveryInfoModal();
}

// Listen for transaction completion
window.addEventListener('message', function(event) {
    if (event.data.action === 'transaction_completed') {
        cart = [];
        sessionStorage.removeItem('deliveryCart');
        updateReceipt();
        
        alert('Delivery completed successfully! Transaction ID: ' + event.data.transaction_id);
        location.reload();
    }
});

// KEYBOARD NAVIGATION
document.addEventListener('keydown', function(e) {
    // F1 - Print receipt
    if (e.key === 'F1') {
        e.preventDefault();
        const deliveryInfoModalOpen = document.getElementById('delivery-info-modal').classList.contains('active');
        
        if (deliveryInfoModalOpen) {
            printReceiptFromModal();
        } else {
            printReceipt();
        }
        return;
    }
    
    if (e.key === 'F2') { e.preventDefault(); window.open('/oro-store/transactions/gcash.php', '_blank', 'width=600,height=700'); return; }
    if (e.key === 'F4') { e.preventDefault(); window.location.href = '/oro-store/credit/credit.php'; return; }
    if (e.key === 'F5') { e.preventDefault(); window.location.href = '/oro-store/angkat/angkat.php'; return; }
    if (e.key === 'F7') { e.preventDefault(); window.open('/oro-store/transactions/card_transaction.php', '_blank', 'width=600,height=700'); return; }
    if (e.key === 'F9') { e.preventDefault(); window.location.href = '/oro-store/delivery/delivery_details.php'; return; }

    if (e.key === 'F11') { e.preventDefault(); window.open('/oro-store/stock/add_stock.php', '_blank', 'width=800,height=600'); return; }
    
    const alertOverlay = document.getElementById('custom-alert-overlay');
    if (alertOverlay && alertOverlay.style.display !== 'none') return;

    const deliveryInfoModalOpen = document.getElementById('delivery-info-modal').classList.contains('active');
    const quantityModalOpen = document.getElementById('quantity-modal').classList.contains('active');
    const editModalOpen = document.getElementById('edit-modal').classList.contains('active');
    
    // Handle delivery info modal
    if (deliveryInfoModalOpen) {
        const autocompleteVisible = document.getElementById('autocomplete-dropdown').style.display !== 'none';
        
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && !autocompleteVisible) {
            e.preventDefault();
            completeDeliveryTransaction();
        } else if (e.key === 'Escape' && !autocompleteVisible) {
            e.preventDefault();
            closeDeliveryInfoModal();
        }
        return;
    }
    
    // Handle quantity modal
    if (quantityModalOpen) {
        if (e.key === 'Enter') {
            e.preventDefault();
            confirmQuantity();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closeQuantityModal();
        }
        return;
    }
    
    // Handle edit modal
    if (editModalOpen) {
        if (e.key === 'Enter') {
            e.preventDefault();
            confirmEdit();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closeEditModal();
        }
        return;
    }
    
    // Left panel navigation
    if (currentPanel === 'left') {
        const visibleItems = Array.from(document.querySelectorAll('.product-item-cashier')).filter(item => item.style.display !== 'none');
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectedProductIndex = Math.min(selectedProductIndex + 1, visibleItems.length - 1);
            updateProductSelection();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedProductIndex = Math.max(selectedProductIndex - 1, 0);
            updateProductSelection();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (visibleItems[selectedProductIndex]) {
                const item = visibleItems[selectedProductIndex];
                const product = {
                    id: parseInt(item.dataset.id),
                    name: item.dataset.name,
                    price: parseFloat(item.dataset.price),
                    purchase_price: parseFloat(item.dataset.purchasePrice),
                    stock: parseInt(item.dataset.stock),
                    parent_product_id: item.dataset.parentProductId || null,
                    category: item.dataset.category || '',
                    brand: item.dataset.brand || '',
                    unit: item.dataset.unit || ''
                };
                openQuantityModal(product);
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            const searchInput = document.getElementById('cashier-search');
            if (searchInput.value !== '') {
                searchInput.value = '';
                searchInput.dispatchEvent(new Event('input'));
                searchInput.focus();
            } else if (cart.length > 0) {
                customConfirm('Clear cart and return to cashier?', function(){
                    sessionStorage.removeItem('deliveryCart');
                    window.location.href = '/oro-store/cashier/cashier.php';
                });
            } else {
                window.location.href = '/oro-store/cashier/cashier.php';
            }
        } else if (e.key === 'Home') {
            e.preventDefault();
            if (cart.length > 0) {
                if (typeof setActivePanel === 'function') setActivePanel('right'); else currentPanel = 'right';
                selectedReceiptIndex = 0;
                updateReceiptSelection();
            }
        }
    }
    // Right panel navigation
    else if (currentPanel === 'right') {
        const receiptItems = document.querySelectorAll('.receipt-item');
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectedReceiptIndex = Math.min(selectedReceiptIndex + 1, receiptItems.length - 1);
            updateReceiptSelection();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedReceiptIndex = Math.max(selectedReceiptIndex - 1, 0);
            updateReceiptSelection();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            openDeliveryInfoModal();
        } else if (e.key === 'Delete') {
            e.preventDefault();
            if (receiptItems[selectedReceiptIndex]) {
                const index = parseInt(receiptItems[selectedReceiptIndex].dataset.index);
                deleteCartItem(index);
            }
        } else if (e.key === 'Insert') {
            e.preventDefault();
            if (receiptItems[selectedReceiptIndex]) {
                const index = parseInt(receiptItems[selectedReceiptIndex].dataset.index);
                openEditModal(index);
            }
        } else if (e.key === 'Home') {
            e.preventDefault();
            if (typeof setActivePanel === 'function') setActivePanel('left'); else currentPanel = 'left';
            document.getElementById('cashier-search').focus();
            updateProductSelection();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            if (typeof setActivePanel === 'function') setActivePanel('left'); else currentPanel = 'left';
            document.getElementById('cashier-search').focus();
            updateProductSelection();
        }
    }
});

console.log('✅ delivery_script.js fully loaded and ready');