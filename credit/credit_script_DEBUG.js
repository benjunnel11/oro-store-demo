console.log('🚀 credit_script.js loaded at:', new Date().toLocaleTimeString());

let cart = [];
let currentPanel = 'left';
let selectedProductIndex = 0;
let selectedReceiptIndex = 0;
let currentSelectedProduct = null;

// ✅ Re-edit mode variables
let isReEditMode = false;
let originalTransactionId = null;
let originalTransactionItems = [];

// ✅ CRITICAL: Check sessionStorage IMMEDIATELY when script loads
console.log('📦 Checking sessionStorage IMMEDIATELY...');
const immediateCheck = sessionStorage.getItem('reEditData');
console.log('📦 reEditData exists?', immediateCheck ? 'YES' : 'NO');
if (immediateCheck) {
    console.log('📦 reEditData content:', immediateCheck);
}

// ✅ SINGLE DOMContentLoaded event
document.addEventListener('DOMContentLoaded', function() {
    console.log('═══════════════════════════════════════════════════════');
    console.log('🔍 DOM Content Loaded - Starting initialization...');
    console.log('⏰ Time:', new Date().toLocaleTimeString());
    console.log('═══════════════════════════════════════════════════════');
    
    // Check if required elements exist
    console.log('🔍 Checking for required DOM elements...');
    const elements = {
        'receipt-items': document.getElementById('receipt-items'),
        'receipt-total': document.getElementById('receipt-total'),
        'total-items': document.getElementById('total-items'),
        'subtotal': document.getElementById('subtotal'),
        'grand-total': document.getElementById('grand-total'),
        'product-list': document.getElementById('product-list'),
        'cashier-search': document.getElementById('cashier-search')
    };
    
    for (const [name, element] of Object.entries(elements)) {
        console.log(`  ${element ? '✅' : '❌'} ${name}:`, element);
    }
    
    // ✅ PRIORITY 1: Check for re-edit mode FIRST
    const reEditData = sessionStorage.getItem('reEditData');
    console.log('');
    console.log('═══════════════════════════════════════════════════════');
    console.log('🔍 CHECKING FOR RE-EDIT MODE');
    console.log('═══════════════════════════════════════════════════════');
    console.log('📦 reEditData from sessionStorage:', reEditData ? 'FOUND' : 'NOT FOUND');
    
    if (reEditData) {
        console.log('📦 Raw reEditData:', reEditData);
        
        try {
            const data = JSON.parse(reEditData);
            console.log('✅ Successfully parsed reEditData:', data);
            console.log('   - isReEditMode:', data.isReEditMode);
            console.log('   - originalTransactionId:', data.originalTransactionId);
            console.log('   - cart length:', data.cart ? data.cart.length : 'NO CART');
            
            if (data.isReEditMode) {
                console.log('');
                console.log('🎯🎯🎯 RE-EDIT MODE DETECTED! 🎯🎯🎯');
                console.log('');
                
                // Set re-edit mode flags
                isReEditMode = true;
                originalTransactionId = data.originalTransactionId;
                originalTransactionItems = data.originalTransactionItems;
                
                console.log('📝 Setting global variables:');
                console.log('   - isReEditMode =', isReEditMode);
                console.log('   - originalTransactionId =', originalTransactionId);
                console.log('   - originalTransactionItems count =', originalTransactionItems.length);
                
                // Store transaction info
                window.currentTransactionInfo = data.transactionInfo;
                console.log('💾 Stored transaction info in window.currentTransactionInfo');
                
                // Load cart from re-edit data
                cart = data.cart;
                console.log('🛒 Cart loaded from re-edit data:');
                console.log('   - Cart length:', cart.length);
                console.log('   - Cart contents:', cart);
                
                // Save to creditCart for persistence
                sessionStorage.setItem('creditCart', JSON.stringify(cart));
                console.log('💾 Cart saved to creditCart in sessionStorage');
                
                // Clear reEditData immediately
                sessionStorage.removeItem('reEditData');
                console.log('🗑️ reEditData cleared from sessionStorage');
                
                // Update receipt immediately
                console.log('');
                console.log('🔄 Calling updateReceipt()...');
                updateReceipt();
                console.log('✅ updateReceipt() completed');
                
                // Show alert after a delay
                setTimeout(() => {
                    const message = '✏️ RE-EDIT MODE\n\n' +
                                  '✓ Cart loaded with transaction items (' + cart.length + ' items)\n' +
                                  '✓ You can add, edit, or remove items\n' +
                                  '✓ Complete the form to save changes\n' +
                                  '✓ Original Transaction #' + originalTransactionId + ' will be marked as "edited"';
                    console.log('📢 Showing alert:', message);
                    alert(message);
                }, 500);
                
                console.log('');
                console.log('✅✅✅ Re-edit initialization complete ✅✅✅');
                console.log('═══════════════════════════════════════════════════════');
                
                // Load products
                loadProducts();
                setupSearch();
                
                return; // ✅ Exit early
            } else {
                console.log('⚠️ reEditData exists but isReEditMode is false');
            }
        } catch (e) {
            console.error('❌ Error loading re-edit data:', e);
            console.error('   Stack:', e.stack);
            sessionStorage.removeItem('reEditData');
        }
    } else {
        console.log('ℹ️ No reEditData found in sessionStorage');
    }
    
    console.log('');
    console.log('═══════════════════════════════════════════════════════');
    console.log('🔍 CHECKING OTHER CART SOURCES');
    console.log('═══════════════════════════════════════════════════════');
    
    // ✅ PRIORITY 2: Check for creditCart (normal credit mode)
    const savedCreditCart = sessionStorage.getItem('creditCart');
    if (savedCreditCart) {
        console.log('💳 Found creditCart in sessionStorage');
        cart = JSON.parse(savedCreditCart);
        console.log('   - Cart loaded with', cart.length, 'items');
        updateReceipt();
    } 
    // ✅ PRIORITY 3: Check for deliveryCart (coming from cashier F5)
    else {
        const savedDeliveryCart = sessionStorage.getItem('deliveryCart');
        if (savedDeliveryCart) {
            console.log('🚚 Found deliveryCart in sessionStorage');
            cart = JSON.parse(savedDeliveryCart);
            console.log('   - Cart loaded with', cart.length, 'items');
            sessionStorage.setItem('creditCart', JSON.stringify(cart));
            sessionStorage.removeItem('deliveryCart');
            updateReceipt();
        } else {
            console.log('ℹ️ No saved cart found');
        }
    }
    
    // Load products and setup search
    loadProducts();
    setupSearch();
    
    console.log('');
    console.log('✅ Initialization complete');
    console.log('═══════════════════════════════════════════════════════');
});

// Load products
function loadProducts() {
    console.log('📦 Loading products from server...');
    fetch('/oro-store-demo/products/get_products.php')
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(products => {
            console.log('✅ Products loaded:', products.length, 'products');
            const productList = document.getElementById('product-list');
            let html = '';
            
            products.forEach((product, index) => {
                const isOutOfStock = product.stock <= 0;
                const outOfStockClass = isOutOfStock ? 'out-of-stock' : '';
                const productName = escapeHtml(product.name || '');
                const parentProductId = product.parent_product_id || '';
                
                html += `
                    <li class="product-item-cashier ${outOfStockClass}" 
                        data-id="${product.id}"
                        data-name="${productName}"
                        data-price="${product.price}"
                        data-purchase-price="${product.purchase_price}"
                        data-stock="${product.stock}"
                        data-parent-product-id="${parentProductId}"
                        data-index="${index}">
                        <span class="product-name-cashier">
                            ${productName}
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
            console.error('❌ Error loading products:', error);
            alert('Error loading products. Please refresh the page.');
        });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function setupSearch() {
    const searchInput = document.getElementById('cashier-search');
    
    searchInput.addEventListener('input', function() {
        const query = this.value.toLowerCase();
        const productItems = document.querySelectorAll('.product-item-cashier');
        let visibleIndex = 0;
        
        productItems.forEach((item) => {
            const name = item.dataset.name.toLowerCase();
            const matches = name.includes(query);
            
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

function updateProductSelection() {
    const items = Array.from(document.querySelectorAll('.product-item-cashier')).filter(item => item.style.display !== 'none');
    items.forEach((item, index) => {
        item.classList.toggle('selected', index === selectedProductIndex);
    });
    
    if (items[selectedProductIndex]) {
        items[selectedProductIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}

function updateReceiptSelection() {
    const items = document.querySelectorAll('.receipt-item');
    items.forEach((item, index) => {
        item.classList.toggle('selected', index === selectedReceiptIndex);
    });
    
    if (items[selectedReceiptIndex]) {
        items[selectedReceiptIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}

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

function closeQuantityModal() {
    document.getElementById('quantity-modal').classList.remove('active');
    currentSelectedProduct = null;
    document.getElementById('cashier-search').focus();
}

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

    const existingIndex = cart.findIndex(item => item.id === currentSelectedProduct.id);
    
    if (existingIndex !== -1) {
        cart[existingIndex].quantity += quantity;
        cart[existingIndex].subtotal = cart[existingIndex].quantity * cart[existingIndex].price;
        cart[existingIndex].profit = (cart[existingIndex].price - cart[existingIndex].purchase_price) * cart[existingIndex].quantity;
    } else {
        cart.push({
            id: currentSelectedProduct.id,
            name: currentSelectedProduct.name,
            price: currentSelectedProduct.price,
            purchase_price: currentSelectedProduct.purchase_price,
            quantity: quantity,
            subtotal: quantity * currentSelectedProduct.price,
            profit: (currentSelectedProduct.price - currentSelectedProduct.purchase_price) * quantity,
            parent_product_id: currentSelectedProduct.parent_product_id || null
        });
    }

    sessionStorage.setItem('creditCart', JSON.stringify(cart));
    updateReceipt();
    closeQuantityModal();
    
    document.getElementById('cashier-search').value = '';
    document.getElementById('cashier-search').dispatchEvent(new Event('input'));
    document.getElementById('cashier-search').focus();
}

function updateReceipt() {
    console.log('🔄 updateReceipt() called');
    console.log('   - Cart length:', cart.length);
    console.log('   - isReEditMode:', isReEditMode);
    
    const receiptItems = document.getElementById('receipt-items');
    const receiptTotal = document.getElementById('receipt-total');
    
    console.log('   - receipt-items element:', receiptItems);
    console.log('   - receipt-total element:', receiptTotal);
    
    if (!receiptItems || !receiptTotal) {
        console.error('❌ Receipt elements not found!');
        return;
    }
    
    if (cart.length === 0) {
        console.log('   - Cart is empty, showing empty cart message');
        receiptItems.innerHTML = '<div class="empty-cart"><h3>Cart is Empty</h3><p>Add products for credit sale</p></div>';
        receiptTotal.style.display = 'none';
        return;
    }

    console.log('   - Building receipt HTML...');
    let html = '';
    
    // ✅ Add mode indicator at the top for re-edit mode
    if (isReEditMode) {
        html += `
            <div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; padding: 10px; text-align: center; font-weight: bold; margin-bottom: 10px; border-radius: 4px;">
                ✏️ RE-EDIT MODE (Editing Transaction #${originalTransactionId})
            </div>
        `;
        console.log('   - Added RE-EDIT MODE banner');
    }
    
    let totalItems = 0;
    let subtotal = 0;

    cart.forEach((item, index) => {
        totalItems += item.quantity;
        subtotal += item.subtotal;
        html += `
            <div class="receipt-item" data-index="${index}">
                <div class="receipt-item-details">
                    <div class="receipt-item-name">${item.name}</div>
                    <div class="receipt-item-qty">${item.quantity} x ₱${item.price.toFixed(2)}</div>
                </div>
                <div class="receipt-item-price">₱${item.subtotal.toFixed(2)}</div>
            </div>
        `;
    });

    console.log('   - Setting receipt HTML...');
    receiptItems.innerHTML = html;
    receiptTotal.style.display = 'block';
    
    console.log('   - Updating totals...');
    document.getElementById('total-items').textContent = totalItems;
    document.getElementById('subtotal').textContent = '₱' + subtotal.toFixed(2);
    document.getElementById('grand-total').textContent = '₱' + subtotal.toFixed(2);

    if (currentPanel === 'right') {
        updateReceiptSelection();
    }
    
    console.log('✅ updateReceipt() completed successfully');
}

function openCreditInfoModal() {
    if (cart.length === 0) {
        alert('Cart is empty');
        return;
    }
    
    document.getElementById('credit-info-modal').classList.add('active');
    document.getElementById('customer-name').focus();
    setupCustomerAutocomplete();
}

function closeCreditInfoModal() {
    document.getElementById('credit-info-modal').classList.remove('active');
}

function completeCreditTransaction() {
    const customerName = document.getElementById('customer-name').value.trim();
    const customerContact = document.getElementById('customer-contact').value.trim();
    const customerAddress = document.getElementById('customer-address').value.trim();
    
    if (!customerName || !customerContact) {
        alert('Please fill in customer name and contact number');
        return;
    }
    
    const total = cart.reduce((sum, item) => sum + item.subtotal, 0);
    const profit = cart.reduce((sum, item) => sum + item.profit, 0);
    const itemCount = cart.reduce((sum, item) => sum + item.quantity, 0);

    const formData = new FormData();
    
    // ✅ Check if we're in re-edit mode
    if (isReEditMode) {
        console.log('📤 Sending re-edit transaction to server...');
        formData.append('action', 'reedit_transaction');
        formData.append('original_transaction_id', originalTransactionId);
        formData.append('original_items', JSON.stringify(originalTransactionItems));
        formData.append('new_items', JSON.stringify(cart));
        formData.append('total_amount', total);
        formData.append('total_profit', profit);
        formData.append('items_count', itemCount);
        formData.append('payment_method', 'credit');
        formData.append('amount_paid', 0);
        formData.append('change_amount', 0);
        formData.append('customer_name', customerName);
        formData.append('customer_contact', customerContact);
        formData.append('customer_address', customerAddress);
    } else {
        console.log('📤 Sending new credit transaction to server...');
        formData.append('action', 'complete_credit');
        formData.append('items', JSON.stringify(cart));
        formData.append('total_amount', total);
        formData.append('total_profit', profit);
        formData.append('items_count', itemCount);
        formData.append('customer_name', customerName);
        formData.append('customer_contact', customerContact);
        formData.append('customer_address', customerAddress);
    }

    fetch('/oro-store-demo/credit/credit.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        return response.text().then(text => {
            console.log('Raw response:', text);
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('JSON parse error:', e);
                console.error('Response text:', text);
                throw new Error('Invalid JSON response from server.');
            }
        });
    })
    .then(data => {
        if (data.success) {
            if (isReEditMode) {
                alert('✅ Credit Transaction Updated!\n\nOriginal Transaction: #' + originalTransactionId + '\nNew Record ID: #' + data.transaction_id + '\nTransaction #: ' + data.transaction_number);
            } else {
                alert('Credit transaction created successfully!\nTransaction #: ' + data.transaction_number);
            }
            
            // Clear everything
            sessionStorage.removeItem('creditCart');
            sessionStorage.removeItem('reEditData');
            cart = [];
            isReEditMode = false;
            originalTransactionId = null;
            originalTransactionItems = [];
            updateReceipt();
            closeCreditInfoModal();
            
            document.getElementById('customer-name').value = '';
            document.getElementById('customer-contact').value = '';
            document.getElementById('customer-address').value = '';
            
            // Reload page after delay
            setTimeout(() => {
                location.reload();
            }, 1000);
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Full error:', error);
        alert('Error: ' + error.message);
    });
}

function printReceipt() {
    if (cart.length === 0) {
        alert('Cart is empty. Add items before printing receipt.');
        return;
    }
    
    openCreditInfoModal();
}

function printReceiptFromModal() {
    const customerName = document.getElementById('customer-name').value.trim();
    const customerContact = document.getElementById('customer-contact').value.trim();
    const customerAddress = document.getElementById('customer-address').value.trim();
    
    if (!customerName || !customerContact) {
        alert('Please fill in customer name and contact number');
        return;
    }

    if (cart.length === 0) {
        alert('Cart is empty');
        return;
    }

    if (typeof STORE_INFO === 'undefined') {
        alert('ERROR: STORE_INFO is not defined! Check credit.php script section.');
        console.error('STORE_INFO is undefined!');
        return;
    }

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
        total: cart.reduce((sum, item) => sum + item.subtotal, 0),
        itemCount: cart.reduce((sum, item) => sum + item.quantity, 0),
        date: new Date().toLocaleString(),
        paymentMethod: 'credit',
        creditInfo: {
            customerName: customerName,
            customerContact: customerContact,
            customerAddress: customerAddress
        },
        storeName: STORE_INFO.storeName,
        storeAddress: STORE_INFO.storeAddress,
        cashierName: STORE_INFO.cashierName
    };

    const receiptWindow = window.open('/oro-store-demo/print/print_receipt.php', '_blank', 'width=400,height=600');
    
    if (receiptWindow) {
        receiptWindow.addEventListener('load', function() {
            receiptWindow.postMessage(receiptData, '*');
        });
        
        setTimeout(() => {
            completeCreditTransaction();
        }, 500);
    }
}

// Keyboard navigation
document.addEventListener('keydown', function(e) {
    if (e.key === 'F1' || e.keyCode === 112) {
        e.preventDefault();
        e.stopPropagation();
        
        const creditInfoModalOpen = document.getElementById('credit-info-modal').classList.contains('active');
        
        if (creditInfoModalOpen) {
            printReceiptFromModal();
        } else {
            printReceipt();
        }
        return;
    }
    
    if (e.key === 'F3') {
        e.preventDefault();
        if (cart.length > 0) {
            sessionStorage.setItem('creditCart', JSON.stringify(cart));
        }
        window.location.href = '/oro-store-demo/cashier/cashier.php';
        return;
    }
    
    if (e.key === 'F6') {
        e.preventDefault();
        window.location.href = '/oro-store-demo/credit/credit_details.php';
        return;
    }
    
    const creditInfoModalOpen = document.getElementById('credit-info-modal').classList.contains('active');
    const quantityModalOpen = document.getElementById('quantity-modal').classList.contains('active');
    
    if (creditInfoModalOpen) {
        if (e.key === 'Enter') {
            e.preventDefault();
            completeCreditTransaction();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closeCreditInfoModal();
        }
        return;
    }
    
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
                    parent_product_id: item.dataset.parentProductId || null
                };
                openQuantityModal(product);
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            if (cart.length > 0) {
                if (confirm('Clear cart and search?')) {
                    cart = [];
                    sessionStorage.removeItem('creditCart');
                    updateReceipt();
                }
            }
            document.getElementById('cashier-search').value = '';
            document.getElementById('cashier-search').dispatchEvent(new Event('input'));
            document.getElementById('cashier-search').focus();
        } else if (e.key === 'Home') {
            e.preventDefault();
            if (cart.length > 0) {
                currentPanel = 'right';
                selectedReceiptIndex = 0;
                updateReceiptSelection();
            }
        }
    }
    else if (currentPanel === 'right') {
        if (e.key === 'Enter') {
            e.preventDefault();
            openCreditInfoModal();
        } else if (e.key === 'Home' || e.key === 'Escape') {
            e.preventDefault();
            currentPanel = 'left';
            document.getElementById('cashier-search').focus();
            updateProductSelection();
        }
    }
});

// Setup autocomplete for customer name
function setupCustomerAutocomplete() {
    const customerNameInput = document.getElementById('customer-name');
    const customerContactInput = document.getElementById('customer-contact');
    const customerAddressInput = document.getElementById('customer-address');
    const dropdown = document.getElementById('credit-autocomplete-dropdown');
    
    let debounceTimer;
    
    customerNameInput.addEventListener('input', function() {
        const query = this.value.trim();
        
        clearTimeout(debounceTimer);
        
        if (query.length < 2) {
            dropdown.classList.remove('active');
            dropdown.innerHTML = '';
            return;
        }
        
        debounceTimer = setTimeout(() => {
            fetch(`/oro-store-demo/credit/credit.php?action=search_customers&search=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(customers => {
                    if (customers.length === 0) {
                        dropdown.classList.remove('active');
                        dropdown.innerHTML = '';
                        return;
                    }
                    
                    let html = '';
                    customers.forEach(customer => {
                        html += `
                            <div class="autocomplete-item" 
                                 data-name="${escapeHtml(customer.name)}"
                                 data-contact="${escapeHtml(customer.contact_number || '')}"
                                 data-address="${escapeHtml(customer.address || '')}">
                                <strong>${escapeHtml(customer.name)}</strong>
                                <small>${escapeHtml(customer.contact_number || 'No contact')} - ${escapeHtml(customer.address || 'No address')}</small>
                            </div>
                        `;
                    });
                    
                    dropdown.innerHTML = html;
                    dropdown.classList.add('active');
                    
                    // Add click handlers
                    dropdown.querySelectorAll('.autocomplete-item').forEach(item => {
                        item.addEventListener('click', function() {
                            customerNameInput.value = this.dataset.name;
                            customerContactInput.value = this.dataset.contact;
                            customerAddressInput.value = this.dataset.address;
                            dropdown.classList.remove('active');
                            dropdown.innerHTML = '';
                            customerContactInput.focus();
                        });
                    });
                })
                .catch(error => {
                    console.error('Error fetching customers:', error);
                });
        }, 300);
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.autocomplete-container')) {
            dropdown.classList.remove('active');
            dropdown.innerHTML = '';
        }
    });
}

console.log('✅ credit_script.js fully loaded and ready');