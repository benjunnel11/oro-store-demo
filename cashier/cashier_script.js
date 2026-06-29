// Global state
let currentPanel = 'left';

function updateProductStocks(soldItems) {
    soldItems.forEach(function(item) {
        var el = document.querySelector('.product-item-cashier[data-id="' + item.id + '"]');
        if (!el) return;
        var oldStock = parseInt(el.dataset.stock) || 0;
        var newStock = Math.max(0, oldStock - item.quantity);
        el.dataset.stock = newStock;
        var stockSpan = el.querySelector('.product-stock-cashier');
        if (stockSpan) {
            stockSpan.textContent = 'Stock: ' + newStock;
            stockSpan.className = 'product-stock-cashier' + (newStock <= 0 ? ' oos-stock' : '');
        }
        if (newStock <= 0) {
            el.classList.add('out-of-stock');
            var nameSpan = el.querySelector('.product-name-cashier');
            if (nameSpan && !nameSpan.querySelector('.oos-label')) {
                nameSpan.insertAdjacentHTML('beforeend', ' <span class="oos-label"> (OUT OF STOCK)</span>');
            }
        } else {
            el.classList.remove('out-of-stock');
            var oosLabel = el.querySelector('.oos-label');
            if (oosLabel) oosLabel.remove();
        }
    });
}

function refreshAllStocks() {
    fetch('/oro-store/products/get_products.php')
        .then(function(r) { return r.json(); })
        .then(function(products) {
            products.forEach(function(p) {
                var el = document.querySelector('.product-item-cashier[data-id="' + p.id + '"]');
                if (!el) return;
                var curStock = parseInt(el.dataset.stock) || 0;
                if (curStock === p.stock) return;
                el.dataset.stock = p.stock;
                var stockSpan = el.querySelector('.product-stock-cashier');
                if (stockSpan) {
                    stockSpan.textContent = 'Stock: ' + p.stock;
                    stockSpan.className = 'product-stock-cashier' + (p.stock <= 0 ? ' oos-stock' : '');
                }
                if (p.stock <= 0) {
                    el.classList.add('out-of-stock');
                    var nameSpan = el.querySelector('.product-name-cashier');
                    if (nameSpan && !nameSpan.querySelector('.oos-label')) {
                        nameSpan.insertAdjacentHTML('beforeend', ' <span class="oos-label"> (OUT OF STOCK)</span>');
                    }
                } else {
                    el.classList.remove('out-of-stock');
                    var oosLabel = el.querySelector('.oos-label');
                    if (oosLabel) oosLabel.remove();
                }
                el.dataset.price = p.price;
                var priceSpan = el.querySelector('.product-price-cashier');
                if (priceSpan) priceSpan.textContent = '₱' + parseFloat(p.price).toFixed(2);
            });
        }).catch(function() {});
}

function setActivePanel(panel) {
    currentPanel = panel;
    const lp = document.querySelector('.left-panel');
    const rp = document.querySelector('.right-panel');
    if (lp && rp) {
        lp.classList.toggle('panel-active', panel === 'left');
        rp.classList.toggle('panel-active', panel === 'right');
    }
}
let selectedProductIndex = 0;
let selectedReceiptIndex = 0;
let cart = [];
let currentSelectedProduct = null;
let currentEditIndex = null;
let selectedTransactionId = null;
let selectedTransactionItems = [];
let isReEditMode = false;
let originalTransactionId = null;
let originalTransactionItems = [];

// ─── NEW: Half-pack pricing helpers ──────────────────────────────────────────
// Cache so we only fetch each product once per page load.
const _halfPackCache = {};

function getHalfPackInfo(productId) {
    if (_halfPackCache[productId] !== undefined) {
        return Promise.resolve(_halfPackCache[productId]);
    }
    return fetch('/oro-store/cashier/cashier.php?action=get_halfpack_info&product_id=' + productId)
        .then(r => r.json())
        .then(d => { _halfPackCache[productId] = d; return d; })
        .catch(() => {
            const fallback = { is_individual: false, parent_price: null, individual_pieces_per_pack: null, credit_charge: 0 };
            _halfPackCache[productId] = fallback;
            return fallback;
        });
}

// Returns { effectivePrice, isBulkRate, bulkUnitPrice }
// When qty >= half the pack, switch to bulk rate (parent price per unit) which is cheaper.
// effectivePrice is always PER UNIT — caller always does effectivePrice * quantity.
function computeEffectivePrice(item, info) {
    if (
        info.is_individual &&
        info.parent_price !== null &&
        info.individual_pieces_per_pack &&
        item.quantity >= info.individual_pieces_per_pack / 2
    ) {
        const bulkUnitPrice = info.parent_price / info.individual_pieces_per_pack;
        return {
            effectivePrice: bulkUnitPrice,
            isBulkRate: true,
            bulkUnitPrice: bulkUnitPrice
        };
    }
    return { effectivePrice: parseFloat(item.price), isBulkRate: false, bulkUnitPrice: 0 };
}
// ─────────────────────────────────────────────────────────────────────────────

// Shortcut bar click helpers
function scEsc() {
    const searchInput = document.getElementById('cashier-search');
    if (searchInput.value !== '') { searchInput.value = ''; searchInput.dispatchEvent(new Event('input')); searchInput.focus(); }
    else if (cart.length > 0) { customConfirm('Clear cart?', function(){ cart = []; updateReceipt(); }); }
}
function scHome() {
    if (currentPanel === 'left' && cart.length > 0) { setActivePanel('right'); selectedReceiptIndex = 0; updateReceiptSelection(); }
    else { setActivePanel('left'); document.getElementById('cashier-search').focus(); updateProductSelection(); }
}
function scDel() {
    if (currentPanel === 'right') {
        const items = document.querySelectorAll('.receipt-item');
        if (items[selectedReceiptIndex]) deleteCartItem(parseInt(items[selectedReceiptIndex].dataset.index));
    }
}
function scIns() {
    if (currentPanel === 'right') {
        const items = document.querySelectorAll('.receipt-item');
        if (items[selectedReceiptIndex]) openEditModal(parseInt(items[selectedReceiptIndex].dataset.index));
    }
}
function scF3() { if (cart.length > 0) sessionStorage.setItem('deliveryCart', JSON.stringify(cart)); location.href = '/oro-store/delivery/delivery.php'; }
function scF4() { if (cart.length > 0) sessionStorage.setItem('creditCart', JSON.stringify(cart)); location.href = '/oro-store/credit/credit.php'; }
function scF5() { if (cart.length > 0) sessionStorage.setItem('angkatCart', JSON.stringify(cart)); location.href = '/oro-store/angkat/angkat.php'; }

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    setActivePanel('left');
    updateProductSelection();
    setupSearch();

    // Restore cart from sessionStorage (survives reload when processing kiosk orders)
    var savedCart = sessionStorage.getItem('cashierCart');
    if (savedCart) {
        try {
            var parsed = JSON.parse(savedCart);
            if (parsed && parsed.length > 0) {
                cart = parsed;
                updateReceipt();
            }
        } catch(e) {}
        sessionStorage.removeItem('cashierCart');
    }

    // Click on product items to highlight (not add to cart)
    document.querySelectorAll('.product-item-cashier').forEach(item => {
        item.addEventListener('click', function() {
            const visibleItems = Array.from(document.querySelectorAll('.product-item-cashier')).filter(i => i.style.display !== 'none');
            const idx = visibleItems.indexOf(this);
            if (idx >= 0) {
                selectedProductIndex = idx;
                setActivePanel('left');
                updateProductSelection();
                document.getElementById('cashier-search').focus();
            }
        });
    });
});

// Search functionality
function setupSearch() {
    const searchInput = document.getElementById('cashier-search');

    searchInput.addEventListener('input', function() {
        const query = this.value.toLowerCase().trim();
        const productItems = document.querySelectorAll('.product-item-cashier'); // re-query each time
        let visibleIndex = 0;

        productItems.forEach((item) => {
            const name = (item.dataset.name || '').toLowerCase();
            const barcode = (item.dataset.barcode || '').toLowerCase();
            const description = (item.dataset.description || '').toLowerCase();
            const brand = (item.dataset.brand || '').toLowerCase();
            const category = (item.dataset.category || '').toLowerCase();
            const unit = (item.dataset.unit || '').toLowerCase();
            const matches = query === '' || name.includes(query) || barcode.includes(query) || description.includes(query) || brand.includes(query) || category.includes(query) || unit.includes(query);

            item.style.display = matches ? 'flex' : 'none';
            item.style.setProperty('display', matches ? 'flex' : 'none', 'important'); // override any CSS specificity

            if (matches) {
                item.dataset.visibleIndex = visibleIndex;
                visibleIndex++;
            }
        });

        selectedProductIndex = 0;
        updateProductSelection();
    });
}

// Update product selection highlight
function updateProductSelection() {
    const items = Array.from(document.querySelectorAll('.product-item-cashier')).filter(item => item.style.display !== 'none');
    items.forEach((item, index) => {
        item.classList.toggle('selected', index === selectedProductIndex);
    });
    
    if (items[selectedProductIndex]) {
        items[selectedProductIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}

// Open ATM Card Transaction window
function openCardTransaction() {
    const cardWindow = window.open('/oro-store/transactions/card_transaction.php', '_blank', 'width=600,height=700');
    if (cardWindow) {
        cardWindow.focus();
    }
}
// Update receipt selection highlight
function updateReceiptSelection() {
    const rows = document.querySelectorAll('.receipt-item');
    rows.forEach((row, i) => {
        if (i === selectedReceiptIndex) {
            row.classList.add('selected');
            row.style.background = '#dbeafe';
            row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        } else {
            row.classList.remove('selected');
            row.style.background = '';
        }
    });
}


function openOtherTransaction() {
    const otherWindow = window.open('/oro-store/transactions/other_transaction.php', '_blank', 'width=1000,height=700');
    if (otherWindow) {
        otherWindow.focus();
    }
}
// Open quantity modal
function openQuantityModal(product) {
    currentSelectedProduct = product;
    document.getElementById('modal-product-name').textContent = product.name;
    document.getElementById('modal-stock').textContent = product.stock;
    document.getElementById('quantity-input').value = '1';
    
    // Remove max limit for individual products
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

// Confirm quantity and add to cart
function confirmQuantity() {
    const quantity = parseInt(document.getElementById('quantity-input').value);
    
    if (!quantity || quantity < 1) {
        alert('Please enter a valid quantity');
        return;
    }
    
    // Only check stock limit for pack products
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
            old_stock: currentSelectedProduct.stock,
            parent_product_id: currentSelectedProduct.parent_product_id || null,
            category: currentSelectedProduct.category || '',
            brand: currentSelectedProduct.brand || '',
            unit: currentSelectedProduct.unit || ''
        });
    }

    updateReceipt();
    closeQuantityModal();
    
    document.getElementById('cashier-search').value = '';
    document.getElementById('cashier-search').dispatchEvent(new Event('input'));
    document.getElementById('cashier-search').focus();
}

async function updateReceipt() {
    const receiptItems = document.getElementById('receipt-items');
    const receiptTotal = document.getElementById('receipt-total');

    if (cart.length === 0) {
        receiptItems.innerHTML = '<div class="empty-cart"><h3>Cart is Empty</h3><p>Select products from the left to add to cart</p></div>';
        receiptTotal.style.display = 'none';
        return;
    }

    // Fetch half-pack info for all cart items in parallel (cached after first call)
    const infos = await Promise.all(cart.map(item => getHalfPackInfo(item.id)));

    let html = '';

    // Mode banners — unchanged from original
    if (isReprintMode) {
        html += `<div style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:9px 14px;font-size:11px;font-weight:700;letter-spacing:.6px;">
            📋 REPRINT MODE (Transaction ID: #${reprintTransactionId || 'Loading...'})
        </div>`;
    } else if (isReEditMode) {
        html += `<div style="background:linear-gradient(135deg,#7c3aed,#5b21b6);color:#fff;padding:9px 14px;font-size:11px;font-weight:700;letter-spacing:.6px;">
            ✏️ RE-EDIT MODE — Transaction #${originalTransactionId}
        </div>`;
    }

html += `
    <table style="width:100%;border-collapse:collapse;table-layout:auto;">
        <thead>
            <tr style="background:#2d3748;">
                <th style="padding:8px 10px;text-align:left;color:#a0aec0;font-size:10px;text-transform:uppercase;letter-spacing:.7px;font-weight:600;border-bottom:2px solid #4a5568;min-width:120px;">Product</th>
                <th style="padding:8px 10px;text-align:center;color:#a0aec0;font-size:10px;text-transform:uppercase;letter-spacing:.7px;font-weight:600;border-bottom:2px solid #4a5568;white-space:nowrap;width:50px;">Qty</th>
                <th style="padding:8px 10px;text-align:right;color:#a0aec0;font-size:10px;text-transform:uppercase;letter-spacing:.7px;font-weight:600;border-bottom:2px solid #4a5568;white-space:nowrap;width:100px;">Unit Price</th>
                <th style="padding:8px 10px;text-align:right;color:#a0aec0;font-size:10px;text-transform:uppercase;letter-spacing:.7px;font-weight:600;border-bottom:2px solid #4a5568;white-space:nowrap;width:100px;">Line Total</th>
                <th style="padding:8px 10px;width:36px;border-bottom:2px solid #4a5568;"></th></tr>
            </thead>
            <tbody>
    `;

    let totalItems = 0;
    let subtotal = 0;

    cart.forEach((item, index) => {
        const info = infos[index];
        const { effectivePrice, isBulkRate } = computeEffectivePrice(item, info);
        const lineTotal = effectivePrice * item.quantity;

        totalItems += item.quantity;
        subtotal += lineTotal;

        const isSelected = (currentPanel === 'right' && index === selectedReceiptIndex);

        let unitPriceTd;
        if (isBulkRate) {
            unitPriceTd = `
                <td style="padding:9px 8px;text-align:right;border-bottom:1px solid #e2e8f0;white-space:nowrap;">
                    <div style="font-style:italic;font-size:10px;color:#a0aec0;white-space:nowrap;">₱${effectivePrice.toFixed(2)} × ${item.quantity} =</div>
                    <div style="font-weight:700;color:#7c3aed;font-size:12px;white-space:nowrap;">₱${lineTotal.toFixed(2)} <span style="font-size:9px;background:#ede9fe;color:#7c3aed;border-radius:3px;padding:1px 4px;">BULK</span></div>
                </td>`;
        } else {
            unitPriceTd = `
                <td style="padding:9px 8px;text-align:right;border-bottom:1px solid #e2e8f0;white-space:nowrap;">
                    <div style="font-weight:700;font-size:10px;color:#a0aec0;">₱${item.price.toFixed(2)} &times; ${item.quantity} =</div>
                    <div style="font-weight:700;color:#2b6cb0;font-size:12px;">₱${(item.price * item.quantity).toFixed(2)}</div>
                </td>`;
        }

        html += `
            <tr class="receipt-item" data-index="${index}"
                style="cursor:pointer;border-bottom:1px solid #e2e8f0;background:${isSelected ? '#dbeafe' : ''};transition:background .1s;-webkit-user-select:none;user-select:none;"
                onmouseenter="if(!this.classList.contains('selected'))this.style.background='#f0f7ff';"
                onmouseleave="if(!this.classList.contains('selected'))this.style.background='';"
                ontouchstart="startLongPress(${index})"
                ontouchend="cancelLongPress()"
                ontouchmove="cancelLongPress()"
                data-cart-index="${index}">
                <td style="padding:9px 8px;word-break:break-word;white-space:normal;line-height:1.3;font-size:12px;border-bottom:1px solid #e2e8f0;">
                    <div style="font-weight:600;color:#2d3748;">${item.name}</div>
                    <div style="font-weight:700;font-size:10px;color:#718096;margin-top:2px;">₱${item.price.toFixed(2)}</div>
                </td>
                <td style="padding:9px 8px;text-align:center;font-weight:700;color:#4a5568;font-size:12px;border-bottom:1px solid #e2e8f0;">${item.quantity}</td>
                ${unitPriceTd}
                <td style="padding:9px 8px;text-align:right;border-bottom:1px solid #e2e8f0;white-space:nowrap;">
                    <div style="font-weight:700;font-size:12px;color:#2563eb;">₱${lineTotal.toFixed(2)}</div>
                </td>
                <td style="padding:9px 8px;text-align:center;border-bottom:1px solid #e2e8f0;">
                    <button onclick="event.stopPropagation();deleteCartItem(${index})"
                        style="background:#fed7d7;color:#c53030;border:none;border-radius:3px;padding:3px 9px;cursor:pointer;font-size:11px;font-weight:700;"
                        onmouseenter="this.style.background='#feb2b2';"
                        onmouseleave="this.style.background='#fed7d7';">✕</button>
                </td>
            </tr>
        `;
    });

    html += `</tbody></table>`;
    receiptItems.innerHTML = html;
    receiptTotal.style.display = 'block';

    document.getElementById('total-items').textContent = totalItems;
    document.getElementById('subtotal').textContent = '₱' + subtotal.toFixed(2);
    document.getElementById('grand-total').textContent = '₱' + subtotal.toFixed(2);

    if (currentPanel === 'right') {
        updateReceiptSelection();
    }
}

// Update openConfirmModal to always use payment modal
function openConfirmModal() {
    if (cart.length === 0) {
        alert('Cart is empty');
        return;
    }
    
    // Always use payment modal for all transaction types
    openPaymentModal();
}

async function computeCartTotal() {
    const infos = await Promise.all(cart.map(item => getHalfPackInfo(item.id)));
    return cart.reduce((sum, item, index) => {
        const { effectivePrice } = computeEffectivePrice(item, infos[index]);
        return sum + (effectivePrice * item.quantity);
    }, 0);
}

// Close confirmation modal
function closeConfirmModal() {
    document.getElementById('confirm-modal').classList.remove('active');
}

// Update completeTransaction to handle re-edit mode with payment
var _txProcessing = false;
async function completeTransaction() {
    if (_txProcessing) return;
    _txProcessing = true;
    // Disable all complete buttons
    document.querySelectorAll('.btn-confirm, #complete-payment-btn').forEach(function(b){ b.disabled = true; b.style.opacity = '0.5'; });

    const total = await computeCartTotal();  // half-pack aware
    const amountPaidInput = document.getElementById('amount-paid-input');
    const amountPaid = parseFloat(amountPaidInput.value) || 0;
    
    if (amountPaid > 0 && amountPaid < total) {
        alert('Amount paid is less than total amount');
        return;
    }
    
    if (isReprintMode) {
        // Handle reprint
        printReceipt(true);
        closePaymentModal();
        
        setTimeout(() => {
            if (confirm('Reprint completed!\n\nClear cart and return to normal mode?')) {
                isReprintMode = false;
                reprintTransactionNumber = null;
                reprintTransactionId = null;
                cart = [];
                updateReceipt();
                setActivePanel('left');
                document.getElementById('cashier-search').focus();
            }
        }, 500);
        return;
    }
    
    if (isReEditMode) {
        // Handle re-edit with payment info
        const profit = cart.reduce((sum, item) => sum + item.profit, 0);
        const itemCount = cart.reduce((sum, item) => sum + item.quantity, 0);
        const change = amountPaid > 0 ? (amountPaid - total) : 0;

        const formData = new FormData();
        formData.append('action', 'reedit_transaction');
        formData.append('original_transaction_id', originalTransactionId);
        formData.append('original_items', JSON.stringify(originalTransactionItems));
        formData.append('new_items', JSON.stringify(cart));
        formData.append('total_amount', total);
        formData.append('total_profit', profit);
        formData.append('items_count', itemCount);
        formData.append('payment_method', 'cash');
        
        if (amountPaid > 0) {
            formData.append('amount_paid', amountPaid);
            formData.append('change_amount', change);
        } else {
            formData.append('amount_paid', total);
            formData.append('change_amount', 0);
        }

        fetch('/oro-store/cashier/cashier.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text();
        })
        .then(text => {
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('Response was not JSON:', text);
                throw new Error('Server returned invalid JSON. Check console for details.');
            }
            
            if (data.success) {
                // Close payment modal first
                closePaymentModal();
                
                // Build receipt data and print
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
                    itemCount: itemCount,
                    amountPaid: amountPaid > 0 ? amountPaid : total,
                    change: change,
                    date: new Date().toLocaleString(),
                    paymentMethod: 'cash',
                    storeName: STORE_INFO.storeName,
                    storeAddress: STORE_INFO.storeAddress,
                    cashierName: STORE_INFO.cashierName,
                    isReEdit: true,
                    transactionId: data.transaction_id,
                    parentTransactionId: data.parent_transaction_id,
                    transactionNumber: data.transaction_number
                };
                

                
                // Show success message
                alert('✅ Transaction Updated Successfully!\n\nOriginal Transaction: #' + data.parent_transaction_id + '\nNew Record ID: #' + data.transaction_id + '\nTransaction #: ' + data.transaction_number + '\n\nStock has been adjusted.');
                
                cart = [];
                isReEditMode = false;
                originalTransactionId = null;
                originalTransactionItems = [];
                updateReceipt();
                refreshAllStocks();
            } else {
                alert('Error updating transaction: ' + data.error);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error: ' + error.message);
        });
        return;
    } else {
        // Handle new transaction with payment
        if (amountPaid < total) {
            alert('Amount paid is less than total amount');
            return;
        }
        
        const change = amountPaid - total;
        const profit = cart.reduce((sum, item) => sum + item.profit, 0);
        const itemCount = cart.reduce((sum, item) => sum + item.quantity, 0);

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
            subtotal: total,
            itemCount: itemCount,
            profit: profit,
            amountPaid: amountPaid,
            change: change,
            date: new Date().toLocaleString(),
            paymentMethod: 'cash',
            storeName: STORE_INFO.storeName,
            storeAddress: STORE_INFO.storeAddress,
            cashierName: STORE_INFO.cashierName
        };
        
     // Save transaction to database
    const formData = new FormData();
    formData.append('action', 'complete_transaction');
    formData.append('items', JSON.stringify(cart));
    formData.append('total_amount', total);
    formData.append('subtotal', total);
    formData.append('total_profit', profit);
    formData.append('items_count', itemCount);
    formData.append('payment_method', 'cash');
    formData.append('amount_paid', amountPaid);
    formData.append('change_amount', change);

    fetch('/oro-store/cashier/cashier.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closePaymentModal();
            if (typeof OroCache !== 'undefined') { OroCache.invalidate('cashier_history'); OroCache.invalidate('cashier_queue'); OroCache.invalidate('kiosk_products'); }

            var soldItems = cart.map(function(i) { return { id: i.id, quantity: i.quantity }; });

            cart = [];
            updateReceipt();

            updateProductStocks(soldItems);

            if (typeof _activeKioskOrderId !== 'undefined' && _activeKioskOrderId) {
                var kfd = new FormData();
                kfd.append('action', 'complete_kiosk_order');
                kfd.append('order_id', _activeKioskOrderId);
                kfd.append('transaction_id', data.transaction_id);
                fetch('/oro-store/cashier/cashier.php', { method: 'POST', body: kfd });
                _activeKioskOrderId = null;
                sessionStorage.removeItem('_activeKioskOrderId');
            }

            if (typeof closeReceiptModal === 'function') closeReceiptModal();
            customAlert('Transaction completed successfully!\nTransaction ID: ' + data.transaction_id + '\nTransaction #: ' + data.transaction_number, 'success', function(){
                _txProcessing = false;
                refreshAllStocks();
            });
        } else {
            var errMsg = 'Error: ' + data.error;
            if (data.debug_steps) {
                console.error('Transaction debug steps:', data.debug_steps);
                errMsg += '\n\nDebug trace (check console for full details):\n';
                data.debug_steps.forEach(function(s) { errMsg += s.step + ' @ ' + s.time + (s.error ? ' ERROR: ' + s.error : '') + '\n'; });
            }
            alert(errMsg);
            _txProcessing = false;
            document.querySelectorAll('.btn-confirm, #complete-payment-btn').forEach(function(b){ b.disabled = false; b.style.opacity = '1'; });
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error completing transaction: ' + error.message);
        _txProcessing = false;
        document.querySelectorAll('.btn-confirm, #complete-payment-btn').forEach(function(b){ b.disabled = false; b.style.opacity = '1'; });
    });

        
    }
}

// Open edit modal
function openEditModal(index) {
    currentEditIndex = index;
    const item = cart[index];
    document.getElementById('edit-product-name').textContent = item.name;
    document.getElementById('edit-current-qty').textContent = item.quantity;
    document.getElementById('edit-quantity-input').value = item.quantity;
    
    // Remove max limit for individual products
    const isIndividual = item.parent_product_id && item.parent_product_id !== null;
    if (isIndividual) {
        document.getElementById('edit-quantity-input').removeAttribute('max');
    } else {
        document.getElementById('edit-quantity-input').max = item.old_stock;
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
    
    // Only check stock limit for pack products
    const item = cart[currentEditIndex];
    const isIndividual = item.parent_product_id && item.parent_product_id !== null;
    
    if (!isIndividual && newQuantity > item.old_stock) {
        alert('Quantity exceeds available stock');
        return;
    }

    cart[currentEditIndex].quantity = newQuantity;
    cart[currentEditIndex].subtotal = newQuantity * cart[currentEditIndex].price;
    cart[currentEditIndex].profit = (cart[currentEditIndex].price - cart[currentEditIndex].purchase_price) * newQuantity;

    updateReceipt();
    closeEditModal();
}

// Delete item from cart
function deleteCartItem(index) {
    customConfirm('Remove this item from cart?', function(){
        cart.splice(index, 1);
        if (selectedReceiptIndex >= cart.length) {
            selectedReceiptIndex = Math.max(0, cart.length - 1);
        }
        updateReceipt();
    });
}

// Click handlers for receipt items
document.addEventListener('click', function(e) {
    if (e.target.closest('.receipt-item')) {
        setActivePanel('right');
        const item = e.target.closest('.receipt-item');
        selectedReceiptIndex = parseInt(item.dataset.index);
        updateReceiptSelection();
    }
});

// Open transaction history modal
function openHistoryModal() {
    document.getElementById('history-modal').classList.add('active');
    loadTransactionHistory();
}

// Close transaction history modal
function closeHistoryModal() {
    document.getElementById('history-modal').classList.remove('active');
}
// Load transaction history
let allTransactions = []; // Store all transactions for filtering

function loadTransactionHistory() {
    if (typeof OroCache !== 'undefined') {
        var cached = OroCache.get('cashier_history');
        if (cached) {
            allTransactions = cached;
            displayTransactions(cached);
            setupHistorySearch();
            fetch('/oro-store/cashier/cashier.php?action=get_transactions')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    OroCache.set('cashier_history', data, 30);
                    if (data.length !== cached.length || (data[0] && cached[0] && data[0].id !== cached[0].id)) {
                        allTransactions = data;
                        displayTransactions(data);
                        setupHistorySearch();
                    }
                }).catch(function() {});
            return;
        }
    }
    fetch('/oro-store/cashier/cashier.php?action=get_transactions')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (typeof OroCache !== 'undefined') OroCache.set('cashier_history', data, 30);
            allTransactions = data;
            displayTransactions(data);
            setupHistorySearch();
        })
        .catch(function(error) {
            console.error('Error loading transactions:', error);
            alert('Error loading transaction history');
        });
}

// Display transactions in table with action buttons
function displayTransactions(transactions) {
    const tbody = document.getElementById('history-table-body');
    if (transactions.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 20px;">No transactions found</td></tr>';
        return;
    }

    let html = '';
    transactions.forEach(transaction => {
        const date = new Date(transaction.transaction_date).toLocaleString();
        
        // Determine status and color
        let statusColor = '#28a745';
        let statusText = 'COMPLETED';

        if (transaction.status === 'voided') {
            statusColor = '#dc3545';
            statusText = 'VOIDED';
        } else if (transaction.status === 'pending') {
            statusColor = '#f59e0b';
            statusText = 'PENDING';
        } else if (transaction.status === 'edited') {
            statusColor = '#6610f2';
            statusText = 'EDITED';
        } else if (transaction.has_children > 0) {
            statusColor = '#17a2b8';
            statusText = 'RE-EDITED';
        } else if (transaction.parent_transaction_id) {
            statusColor = '#6610f2';
            statusText = 'RE-EDIT';
        }
        
        const isEditable = transaction.status === 'completed' || transaction.status === 'pending';
        const isVoided = transaction.status === 'voided';
        
        const isGcashService = transaction._source === 'gcash';

        // Determine transaction type
        let typeBadge = '';
        let typeClass = '';
        if (isGcashService) {
            const gt = transaction.transaction_type;
            if (gt === 'cash_in') { typeBadge = '📱 GCASH IN'; }
            else if (gt === 'cash_out') { typeBadge = '📱 GCASH OUT'; }
            else if (gt === 'send_gcash') { typeBadge = '📱 SEND'; }
            else if (gt === 'bank_transfer') { typeBadge = '🏦 BANK'; }
            else { typeBadge = '📱 GCASH'; }
            typeClass = 'type-gcash';
        } else if (transaction.payment_method === 'delivery') {
            typeBadge = '🚚 DELIVERY';
            typeClass = 'type-delivery';
        } else if (transaction.payment_method === 'credit') {
            typeBadge = '💳 CREDIT';
            typeClass = 'type-credit';
        } else if (transaction.payment_method === 'gcash') {
            typeBadge = '📱 GCASH';
            typeClass = 'type-gcash';
        } else {
            typeBadge = '💰 SALE';
            typeClass = 'type-sale';
        }
        
        // Show parent transaction ID if this is a re-edit
        const displayId = transaction.parent_transaction_id || transaction.id;

        if (isGcashService) {
            const gcashFee = parseFloat(transaction.fee || 0);
            const gcashAmt = parseFloat(transaction.amount || 0);
            const acctName = transaction.gcash_acct_name || '';
            const custNum = transaction.customer_number || '';
            html += `
            <tr style="border-bottom: 1px solid #ddd; background: #f0f7ff;"
                data-transaction-id="gcash_${transaction.id}"
                data-transaction-number="${transaction.reference_number || ''}"
                data-type="gcash_service">
                <td style="padding: 10px;">#G${transaction.id}</td>
                <td style="padding: 10px; font-size: 11px;">${transaction.reference_number || 'N/A'}${acctName ? '<br><small style="color:#3b82f6;">' + acctName + '</small>' : ''}</td>
                <td style="padding: 10px; font-size: 12px;">${date}</td>
                <td style="padding: 10px; text-align: center;"><span class="type-badge ${typeClass}" style="font-size: 11px;">${typeBadge}</span></td>
                <td style="padding: 10px; text-align: right; font-weight: bold;">₱${parseFloat(transaction.total_amount).toFixed(2)}${gcashFee > 0 ? '<br><small style="color:#f59e0b;">fee ₱' + gcashFee.toFixed(2) + '</small>' : ''}</td>
                <td style="padding: 10px; text-align: center; font-size: 11px;">${custNum ? custNum : '-'}</td>
                <td style="padding: 10px; text-align: center;">
                    <span style="background-color: ${statusColor}; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: bold;">
                        ${statusText}
                    </span>
                </td>
                <td style="padding: 10px; text-align: center;">
                    <span style="color: #999; font-size: 12px;">-</span>
                </td>
            </tr>`;
        } else {
        html += `
            <tr style="border-bottom: 1px solid #ddd;"
                data-transaction-id="${transaction.id}"
                data-transaction-number="${transaction.transaction_number || ''}"
                data-type="${transaction.payment_method}">
                <td style="padding: 10px;">
                    #${displayId}
                    ${transaction.parent_transaction_id ? `<br><small style="color: #6610f2;">(Edit of #${transaction.parent_transaction_id})</small>` : ''}
                    ${transaction.has_children > 0 ? `<br><small style="color: #17a2b8;">(${transaction.has_children} edit${transaction.has_children > 1 ? 's' : ''})</small>` : ''}
                </td>
                <td style="padding: 10px;">${transaction.transaction_number || 'N/A'}</td>
                <td style="padding: 10px; font-size: 12px;">${date}</td>
                <td style="padding: 10px; text-align: center;"><span class="type-badge ${typeClass}" style="font-size: 11px;">${typeBadge}</span></td>
                <td style="padding: 10px; text-align: right; font-weight: bold;">₱${parseFloat(transaction.total_amount).toFixed(2)}</td>
                <td style="padding: 10px; text-align: center;">${transaction.total_items || transaction.item_count || 0}</td>
                <td style="padding: 10px; text-align: center;">
                    <span style="background-color: ${statusColor}; color: white; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: bold;">
                        ${statusText}
                    </span>
                </td>
                <td style="padding: 10px; text-align: center;">
                    ${isVoided ? `
                        <span style="color: #dc3545; font-size: 11px; font-weight: 700;">VOIDED</span>
                    ` : isEditable ? `
                        <div style="display: flex; gap: 4px; justify-content: center; flex-wrap: wrap;">
                            <button onclick="reprintTransaction(${transaction.id})"
                                    style="padding: 4px 8px; background-color: #6c757d; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 10px; white-space: nowrap;"
                                    title="Reprint receipt">
                                🖨️
                            </button>
                            <button onclick="reEditTransaction(${transaction.id})"
                                    style="padding: 4px 8px; background-color: #007bff; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 10px; white-space: nowrap;"
                                    title="Re-edit transaction">
                                ✏️
                            </button>
                            <button onclick="voidTransaction(${transaction.id}, '${(transaction.transaction_number||'').replace(/'/g,'')}')"
                                    style="padding: 4px 8px; background-color: #dc3545; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 10px; white-space: nowrap;"
                                    title="Void transaction">
                                ✕
                            </button>
                        </div>
                    ` : `
                        <span style="color: #999; font-size: 12px;">N/A</span>
                    `}
                </td>
            </tr>
        `;
        }
    });
    tbody.innerHTML = html;
}


// ✅ NEW FUNCTION: Reprint transaction - directly opens print
function reprintTransaction(transactionId) {
    // Fetch transaction details
    Promise.all([
        fetch(`/oro-store/cashier/cashier.php?action=get_transaction_details&transaction_id=${transactionId}`).then(r => r.json()),
        fetch(`/oro-store/cashier/cashier.php?action=get_transactions`).then(r => r.json())
    ])
    .then(([items, transactions]) => {
        const transaction = transactions.find(t => t.id === transactionId);
        
        if (!transaction) {
            alert('Transaction not found');
            return;
        }
        
        // Build receipt data from transaction
        const receiptData = {
            items: items.map(item => ({
                id: item.product_id,
                name: item.product_name,
                quantity: parseInt(item.quantity),
                price: parseFloat(item.price),
                purchase_price: parseFloat(item.purchase_price || 0),
                subtotal: parseFloat(item.subtotal),
                profit: parseFloat(item.profit || 0)
            })),
            total: parseFloat(transaction.total_amount),
            itemCount: items.reduce((sum, item) => sum + parseInt(item.quantity), 0),
            amountPaid: parseFloat(transaction.amount_paid || transaction.total_amount),
            change: parseFloat(transaction.change_amount || 0),
            date: new Date(transaction.transaction_date).toLocaleString(),
            paymentMethod: transaction.payment_method || 'cash',
            storeName: STORE_INFO.storeName,
            storeAddress: STORE_INFO.storeAddress,
            cashierName: STORE_INFO.cashierName,
            isReprint: true,
            transactionNumber: transaction.transaction_number,
            transactionId: transaction.id,
            parentTransactionId: transaction.parent_transaction_id || transaction.id
        };
        
        // Print via RawBT (thermal printer)
        if (typeof BTPrint !== 'undefined') {
            BTPrint.printReceipt({
                storeName: receiptData.storeName,
                storeAddress: receiptData.storeAddress,
                title: 'REPRINT',
                transactionNumber: receiptData.transactionNumber || '',
                date: receiptData.date,
                cashier: receiptData.cashierName,
                items: receiptData.items,
                total: receiptData.total,
                amountPaid: receiptData.amountPaid,
                change: receiptData.change
            });
        }

        // Show receipt modal
        showReceiptModal(receiptData);

        // Close history modal
        closeHistoryModal();
    })
    .catch(error => {
        console.error('Error loading transaction for reprint:', error);
        alert('Error loading transaction details');
    });
}

// ✅ NEW FUNCTION: Re-edit transaction - redirects based on payment method
function reEditTransaction(transactionId) {
    // Fetch transaction details
    Promise.all([
        fetch(`/oro-store/cashier/cashier.php?action=get_transaction_details&transaction_id=${transactionId}`).then(r => r.json()),
        fetch(`/oro-store/cashier/cashier.php?action=get_transactions`).then(r => r.json())
    ])
    .then(([items, transactions]) => {
        const transaction = transactions.find(t => t.id === transactionId);
        
        if (!transaction) {
            alert('Transaction not found');
            return;
        }
        
        // Check if cart has items
        if (cart.length > 0) {
            if (!confirm('Current cart will be cleared. Continue?')) {
                return;
            }
        }
        
        // Store re-edit data in sessionStorage
        const reEditData = {
            isReEditMode: true,
            originalTransactionId: transactionId,
            originalTransactionItems: items,
            transactionNumber: transaction.transaction_number,
            transactionInfo: transaction,
            cart: items.map(item => ({
                id: item.product_id,
                name: item.product_name,
                price: parseFloat(item.price),
                purchase_price: parseFloat(item.purchase_price),
                quantity: parseInt(item.quantity),
                subtotal: parseFloat(item.subtotal),
                profit: parseFloat(item.profit),
                old_stock: parseInt(item.current_stock) + parseInt(item.quantity),
                parent_product_id: item.parent_product_id || null
            }))
        };
        
        sessionStorage.setItem('reEditData', JSON.stringify(reEditData));
        
        // Close history modal
        closeHistoryModal();
        
        // Redirect based on payment method
        if (transaction.payment_method === 'delivery') {
            alert('✏️ RE-EDIT MODE\n\nRedirecting to Delivery page...');
            window.location.href = '/oro-store/delivery/delivery.php';
        } else if (transaction.payment_method === 'credit') {
            alert('✏️ RE-EDIT MODE\n\nRedirecting to Credit page...');
            window.location.href = '/oro-store/credit/credit.php';
        } else if (transaction.payment_method === 'angkat') {
            alert('✏️ RE-EDIT MODE\n\nRedirecting to Angkat page...');
            window.location.href = '/oro-store/angkat/angkat.php';
        } else {
            // For cash/gcash transactions, stay on cashier page
            isReEditMode = true;
            isReprintMode = false;
            originalTransactionId = transactionId;
            originalTransactionItems = JSON.parse(JSON.stringify(items));
            
            // Store transaction info
            window.currentTransactionInfo = transaction;
            reprintTransactionNumber = transaction.transaction_number;
            
            // Load items to cart
            cart = [];
            items.forEach(item => {
                cart.push({
                    id: item.product_id,
                    name: item.product_name,
                    price: parseFloat(item.price),
                    purchase_price: parseFloat(item.purchase_price),
                    quantity: parseInt(item.quantity),
                    subtotal: parseFloat(item.subtotal),
                    profit: parseFloat(item.profit),
                    old_stock: parseInt(item.current_stock) + parseInt(item.quantity),
                    parent_product_id: item.parent_product_id || null
                });
            });
            
            updateReceipt();
            
            // Switch to cart panel
            setActivePanel('right');
            selectedReceiptIndex = 0;
            updateReceiptSelection();
            
            alert('✏️ RE-EDIT MODE\n\n✓ Cart loaded with transaction items\n✓ You can add, edit, or remove items\n✓ Press Enter to save changes\n✓ Original Transaction #' + originalTransactionId + ' will be marked as "edited"');
        }
    })
    .catch(error => {
        console.error('Error loading transaction for re-edit:', error);
        alert('Error loading transaction details');
    });
}

// Void/cancel transaction
function voidTransaction(transactionId, transactionNumber) {
    if (!confirm('Void transaction #' + transactionNumber + '?\n\nThis will mark it as voided and restore stock. This cannot be undone.')) return;

    const fd = new FormData();
    fd.append('action', 'void_transaction');
    fd.append('transaction_id', transactionId);
    fetch('/oro-store/cashier/cashier.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                customAlert('Transaction #' + transactionNumber + ' has been voided.', 'success', function() {
                    loadTransactionHistory();
                });
            } else {
                alert('Error: ' + (data.error || 'Failed to void transaction'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

// Setup search functionality for history
function setupHistorySearch() {
    const searchInput = document.getElementById('history-search');
    if (!searchInput) return;
    
    searchInput.addEventListener('input', function() {
        const query = this.value.toLowerCase().trim();
        
        if (query === '') {
            displayTransactions(allTransactions);
            return;
        }
        
        const filtered = allTransactions.filter(transaction => {
            const id = transaction.id.toString();
            const transactionNumber = (transaction.transaction_number || '').toLowerCase();
            const type = (transaction.payment_method || '').toLowerCase();
            
            return id.includes(query) || 
                   transactionNumber.includes(query) || 
                   type.includes(query);
        });
        
        displayTransactions(filtered);
    });
    
    // Focus search on modal open
    searchInput.focus();
}

// Close details modal
function closeDetailsModal() {
    document.getElementById('details-modal').classList.remove('active');
    selectedTransactionId = null;
    selectedTransactionItems = [];
}

// Global state - add these to the existing global variables
let isReprintMode = false;
let reprintTransactionNumber = null;

// Update openPaymentModal to show transaction ID
async function openPaymentModal() {
    if (cart.length === 0) {
        alert('Cart is empty');
        return;
    }

    const total = await computeCartTotal();  // half-pack aware
    const itemCount = cart.reduce((sum, item) => sum + item.quantity, 0);

    document.getElementById('payment-total').textContent = '₱' + total.toFixed(2);
    document.getElementById('payment-items').textContent = itemCount;
    
    // Always reset and show the input fields
    const amountPaidInput = document.getElementById('amount-paid-input');
    const label = amountPaidInput.previousElementSibling;
    
    amountPaidInput.value = '';
    amountPaidInput.disabled = false;
    amountPaidInput.style.display = 'block';
    if (label) label.style.display = 'block';
    
    document.getElementById('change-display').style.display = 'none';
    document.getElementById('insufficient-warning').style.display = 'none';
    
    // Update title based on mode - show transaction ID
    if (isReprintMode) {
        document.getElementById('payment-modal-title').textContent = 'Reprint Receipt (Transaction ID: #' + (reprintTransactionId || 'Loading...') + ')';
    } else if (isReEditMode) {
        document.getElementById('payment-modal-title').textContent = 'Re-edit Transaction #' + originalTransactionId;
    } else {
        document.getElementById('payment-modal-title').textContent = 'Complete Transaction';
    }
    
    document.getElementById('payment-modal').classList.add('active');
    
    // Focus on amount paid input
    setTimeout(() => {
        amountPaidInput.focus();
    }, 100);
}

// Close payment modal
function closePaymentModal() {
    document.getElementById('payment-modal').classList.remove('active');
}


// Calculate change in real-time
document.addEventListener('DOMContentLoaded', function() {
    const amountPaidInput = document.getElementById('amount-paid-input');
    
    if (amountPaidInput) {
        amountPaidInput.addEventListener('input', async function() {
            const total = await computeCartTotal();  // half-pack aware
            const amountPaid = parseFloat(this.value) || 0;
            const change = amountPaid - total;
            
            var printBtn = document.getElementById('print-payment-btn');
            if (amountPaid >= total && amountPaid > 0) {
                document.getElementById('change-amount').textContent = '₱' + change.toFixed(2);
                document.getElementById('change-display').style.display = 'block';
                document.getElementById('insufficient-warning').style.display = 'none';
                document.getElementById('complete-payment-btn').disabled = false;
                document.getElementById('complete-payment-btn').style.opacity = '1';
                if (printBtn) { printBtn.disabled = false; printBtn.style.opacity = '1'; }
            } else if (amountPaid > 0) {
                document.getElementById('change-display').style.display = 'none';
                document.getElementById('insufficient-warning').style.display = 'block';
                document.getElementById('complete-payment-btn').disabled = true;
                document.getElementById('complete-payment-btn').style.opacity = '0.5';
                if (printBtn) { printBtn.disabled = true; printBtn.style.opacity = '0.5'; }
            } else {
                document.getElementById('change-display').style.display = 'none';
                document.getElementById('insufficient-warning').style.display = 'none';
                document.getElementById('complete-payment-btn').disabled = false;
                document.getElementById('complete-payment-btn').style.opacity = '1';
                if (printBtn) { printBtn.disabled = false; printBtn.style.opacity = '1'; }
            }
        });
    }
});
// Update printReceipt function
function printReceipt(isReprint = false) {
    if (cart.length === 0) {
        alert('Cart is empty. Add items before printing receipt.');
        return;
    }

    const total = cart.reduce((sum, item) => sum + item.subtotal, 0);
    let amountPaid = total;
    const amountPaidInput = document.getElementById('amount-paid-input');
    if (amountPaidInput && amountPaidInput.value) {
        amountPaid = parseFloat(amountPaidInput.value) || total;
    }
    
    const change = amountPaid - total;

    const receiptData = {
        items: cart.map(item => ({
            id: item.id,
            name: item.name,
            quantity: item.quantity,
            price: item.price,
            purchase_price: item.purchase_price || 0,
            subtotal: item.price * item.quantity,
            profit: (item.price - (item.purchase_price || 0)) * item.quantity,
            category: item.category || '',
            brand: item.brand || '',
            unit: item.unit || ''
        })),
        total: total,
        itemCount: cart.reduce((sum, item) => sum + item.quantity, 0),
        amountPaid: amountPaid,
        change: change,
        date: new Date().toLocaleString(),
        paymentMethod: 'cash',
        storeName: STORE_INFO.storeName,
        storeAddress: STORE_INFO.storeAddress,
        cashierName: STORE_INFO.cashierName,
        isReprint: isReprint,
        transactionNumber: isReprint ? reprintTransactionNumber : null,
        transactionId: isReprint ? reprintTransactionId : null,
        parentTransactionId: isReprint ? reprintTransactionId : null // ✅ For reprint, parent is itself
    };

    // Print via RawBT (thermal printer)
    if (typeof BTPrint !== 'undefined') {
        BTPrint.printReceipt({
            storeName: receiptData.storeName,
            storeAddress: receiptData.storeAddress,
            title: isReprint ? 'REPRINT' : 'PROOF OF PURCHASE',
            transactionNumber: receiptData.transactionNumber || '',
            date: receiptData.date,
            cashier: receiptData.cashierName,
            items: receiptData.items,
            total: receiptData.total,
            amountPaid: receiptData.amountPaid,
            change: receiptData.change
        });
    }

    // Show receipt modal instead of opening print page
    showReceiptModal(receiptData);
}


// Update printReceiptFromPaymentModal — prints then completes
function printReceiptFromPaymentModal() {
    const amountPaidInput = document.getElementById('amount-paid-input');
    const amountPaid = parseFloat(amountPaidInput.value) || 0;
    const total = cart.reduce((sum, item) => sum + item.subtotal, 0);

    if (amountPaid > 0 && amountPaid < total) {
        alert('Amount paid is less than total amount');
        return;
    }

    if (isReprintMode) {
        printReceipt(true);
    } else {
        printReceipt(false);
        // Complete the transaction after printing
        setTimeout(function() { completeTransaction(); }, 500);
    }
}

// Listen for transaction completion from receipt window
window.addEventListener('message', function(event) {
    if (event.data.action === 'transaction_completed') {
        cart = [];
        isReEditMode = false;
        originalTransactionId = null;
        originalTransactionItems = [];
        updateReceipt();
        
        alert('Transaction completed successfully! Transaction ID: ' + event.data.transaction_id);
        location.reload();
    }
});

// Open GCash window
function openGCash() {
    const gcashWindow = window.open('/oro-store/transactions/gcash.php', '_blank', 'width=600,height=700');
    if (gcashWindow) {
        gcashWindow.focus();
    }
}

// Open Add Stock window
function openAddStock() {
    const addStockWindow = window.open('/oro-store/stock/add_stock.php', '_blank', 'width=800,height=600');
    if (addStockWindow) {
        addStockWindow.focus();
    }
}

// Print receipt from modal
function printReceiptFromModal() {
    printReceipt();
}

// Logout function
function logout() {
    var msg = cart.length > 0 ? 'You have items in your cart. Are you sure you want to logout?' : 'Are you sure you want to logout?';
    customConfirm(msg, function(){
        window.location.href = '/oro-store/auth/logout.php';
    });
}

// Keyboard navigation
document.addEventListener('keydown', function(e) {
    // Let custom alert handle its own keys
    const alertOverlay = document.getElementById('custom-alert-overlay');
    if (alertOverlay && alertOverlay.style.display !== 'none') return;

    const paymentModalOpen = document.getElementById('payment-modal').classList.contains('active');
    if (paymentModalOpen) {
        // Check if amount is sufficient before allowing complete/print
        const total = cart.reduce((s, i) => s + i.subtotal, 0);
        const paid = parseFloat(document.getElementById('amount-paid-input').value) || 0;
        const sufficient = paid >= total;

        if (e.key === 'Enter') {
            e.preventDefault();
            if (sufficient) completeTransaction();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closePaymentModal();
        } else if (e.key === 'F1') {
            e.preventDefault();
            if (sufficient) printReceiptFromPaymentModal();
        }
        return;
    }

    if (e.key === 'F1') {
        e.preventDefault();
        printReceipt();
        return;
    }
    if (e.key === 'F2') {
        e.preventDefault();
        openGCash();
        return;
    }
    if (e.key === 'F3') { e.preventDefault(); scF3(); return; }
    if (e.key === 'F4') { e.preventDefault(); scF4(); return; }
    if (e.key === 'F5') { e.preventDefault(); scF5(); return; }
    if (e.key === 'F7') { e.preventDefault(); openCardTransaction(); return; }
    if (e.key === 'F8') { e.preventDefault(); if (typeof openKioskQueue === 'function') openKioskQueue(); return; }
    if (e.key === 'F9') { e.preventDefault(); window.location.href = '/oro-store/delivery/delivery_details.php'; return; }
    if (e.key === 'F11') { e.preventDefault(); openAddStock(); return; }
    if (e.key === 'F12') { e.preventDefault(); openHistoryModal(); return; }
    const quantityModalOpen = document.getElementById('quantity-modal').classList.contains('active');
    const confirmModalOpen = document.getElementById('confirm-modal').classList.contains('active');
    const editModalOpen = document.getElementById('edit-modal').classList.contains('active');
    const historyModalOpen = document.getElementById('history-modal').classList.contains('active');
    const detailsModalOpen = document.getElementById('details-modal').classList.contains('active');
    const receiptModalEl = document.getElementById('receipt-modal');
    const receiptModalOpen = receiptModalEl && receiptModalEl.classList.contains('active');
    const gcashRefEl = document.getElementById('gcash-ref-modal');
    const gcashRefOpen = gcashRefEl && gcashRefEl.classList.contains('active');
    const kioskQueueEl = document.getElementById('kiosk-queue-modal');
    const kioskQueueOpen = kioskQueueEl && kioskQueueEl.classList.contains('active');

    if (receiptModalOpen) {
        if (e.key === 'Escape' || e.key === 'Enter') { e.preventDefault(); closeReceiptModal(); }
        return;
    }

    if (gcashRefOpen) {
        if (e.key === 'Enter') { e.preventDefault(); confirmGcashProcess(); }
        else if (e.key === 'Escape') { e.preventDefault(); closeGcashRefModal(); }
        return;
    }

    if (kioskQueueOpen) {
        if (e.key === 'Escape') { e.preventDefault(); closeKioskQueue(); }
        return;
    }

    if (historyModalOpen) {
        if (e.key === 'Escape') {
            e.preventDefault();
            closeHistoryModal();
        }
        return;
    }

    if (detailsModalOpen) {
        if (e.key === 'Enter') {
            e.preventDefault();
            loadTransactionToCart();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closeDetailsModal();
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

    // Handle confirm modal keyboard events
    if (confirmModalOpen) {
        if (e.key === 'Enter') {
            e.preventDefault();
            completeTransaction();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closeConfirmModal();
        } else if (e.key === 'F1') {
            e.preventDefault();
            printReceiptFromModal();
        }
        return;
    }

    // Handle edit modal keyboard events
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
                // First Esc: clear the search
                searchInput.value = '';
                searchInput.dispatchEvent(new Event('input'));
                searchInput.focus();
            } else if (cart.length > 0) {
                // Second Esc (search already empty): offer to clear cart
                customConfirm('Clear cart?', function(){
                    cart = [];
                    isReEditMode = false;
                    originalTransactionId = null;
                    originalTransactionItems = [];
                    updateReceipt();
                    searchInput.focus();
                });
            }
        } else if (e.key === 'Home') {
            e.preventDefault();
            if (cart.length > 0) {
                setActivePanel('right');
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
            openConfirmModal();
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
            setActivePanel('left');
            document.getElementById('cashier-search').focus();
            updateProductSelection();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            setActivePanel('left');
            document.getElementById('cashier-search').focus();
            updateProductSelection();
        }
    }
});