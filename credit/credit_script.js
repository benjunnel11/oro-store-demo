console.log('🚀 credit_script.js loaded!');

let cart = [];
let currentPanel = 'left';
let selectedProductIndex = 0;
let selectedReceiptIndex = 0;
let currentSelectedProduct = null;
let currentEditIndex = null;
let autocompleteTimeout = null;
let selectedAutocompleteIndex = -1;
let autocompleteResults = [];

// Re-edit mode variables
let isReEditMode = false;
let originalTransactionId = null;
let originalTransactionItems = [];

const CREDIT_SCRIPT_VERSION = '9.0-HALFPACK';
console.log('=== CREDIT SCRIPT VERSION: ' + CREDIT_SCRIPT_VERSION + ' ===');

// ─── DOMContentLoaded ─────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    loadProducts();
    setupSearch();
    setupCustomerAutocomplete();

    // PRIORITY 1: re-edit mode
    const reEditData = sessionStorage.getItem('reEditData');
    if (reEditData) {
        try {
            const data = JSON.parse(reEditData);
            if (data.isReEditMode) {
                isReEditMode            = true;
                originalTransactionId   = data.originalTransactionId;
                originalTransactionItems = data.originalTransactionItems;
                window.currentTransactionInfo = data.transactionInfo;
                cart = data.cart;
                sessionStorage.setItem('creditCart', JSON.stringify(cart));
                sessionStorage.removeItem('reEditData');
                updateReceipt();
                setTimeout(() => {
                    alert('✏️ RE-EDIT MODE\n\n✓ Cart loaded with ' + cart.length + ' item(s)\n✓ Add, edit, or remove items\n✓ Complete the form to save\n✓ Original Transaction #' + originalTransactionId + ' → marked "edited"');
                }, 500);
                return;
            }
        } catch (e) {
            console.error('Error loading re-edit data:', e);
            sessionStorage.removeItem('reEditData');
        }
    }

    // PRIORITY 2: creditCart
    const savedCreditCart = sessionStorage.getItem('creditCart');
    if (savedCreditCart) {
        cart = JSON.parse(savedCreditCart);
        updateReceipt();
        return;
    }

    // PRIORITY 3: deliveryCart (from cashier F5)
    const savedDeliveryCart = sessionStorage.getItem('deliveryCart');
    if (savedDeliveryCart) {
        cart = JSON.parse(savedDeliveryCart);
        sessionStorage.setItem('creditCart', JSON.stringify(cart));
        sessionStorage.removeItem('deliveryCart');
        updateReceipt();
    }
});

// ─── Customer autocomplete ────────────────────────────────────────────────────
function setupCustomerAutocomplete() {
    const nameInput  = document.getElementById('customer-name');
    const dropdown   = document.getElementById('autocomplete-dropdown');
    if (!nameInput || !dropdown) return;

    nameInput.addEventListener('input', function () {
        const term = this.value.trim();
        clearTimeout(autocompleteTimeout);
        selectedAutocompleteIndex = -1;
        if (term.length < 2) { dropdown.style.display = 'none'; autocompleteResults = []; return; }

        autocompleteTimeout = setTimeout(() => {
            fetch('/oro-store-demo/credit/credit.php?action=search_customers&search=' + encodeURIComponent(term))
                .then(r => r.json())
                .then(customers => {
                    autocompleteResults = customers;
                    if (!customers.length) { dropdown.style.display = 'none'; return; }
                    dropdown.innerHTML = customers.map((c, i) => `
                        <div class="autocomplete-item ${i === selectedAutocompleteIndex ? 'selected' : ''}" data-index="${i}">
                            <strong>${esc(c.name || '')}</strong>
                            <small>${esc(c.contact_number || 'No contact')} · ${esc(c.address || 'No address')}</small>
                        </div>`).join('');
                    dropdown.style.display = 'block';
                    dropdown.querySelectorAll('.autocomplete-item').forEach(el => {
                        el.addEventListener('click', () => selectAutocompleteItem(parseInt(el.dataset.index)));
                    });
                })
                .catch(() => { dropdown.style.display = 'none'; });
        }, 300);
    });

    nameInput.addEventListener('keydown', function (e) {
        if (dropdown.style.display === 'none' || !autocompleteResults.length) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); selectedAutocompleteIndex = Math.min(selectedAutocompleteIndex + 1, autocompleteResults.length - 1); updateAutocompleteHighlight(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); selectedAutocompleteIndex = Math.max(selectedAutocompleteIndex - 1, 0); updateAutocompleteHighlight(); }
        else if (e.key === 'Enter' && selectedAutocompleteIndex >= 0) { e.preventDefault(); selectAutocompleteItem(selectedAutocompleteIndex); }
        else if (e.key === 'Escape') { e.preventDefault(); dropdown.style.display = 'none'; selectedAutocompleteIndex = -1; }
    });

    document.addEventListener('click', function (e) {
        if (!nameInput.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.style.display = 'none'; selectedAutocompleteIndex = -1;
        }
    });
}

function updateAutocompleteHighlight() {
    document.querySelectorAll('#autocomplete-dropdown .autocomplete-item').forEach((el, i) => {
        el.classList.toggle('selected', i === selectedAutocompleteIndex);
    });
    const sel = document.querySelector('#autocomplete-dropdown .autocomplete-item.selected');
    if (sel) sel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

function selectAutocompleteItem(index) {
    if (index < 0 || index >= autocompleteResults.length) return;
    const c = autocompleteResults[index];
    document.getElementById('customer-name').value    = c.name || '';
    document.getElementById('customer-contact').value = c.contact_number || '';
    document.getElementById('customer-address').value = c.address || '';
    document.getElementById('autocomplete-dropdown').style.display = 'none';
    selectedAutocompleteIndex = -1;
    document.getElementById('customer-contact').focus();
}

// ─── Load products ────────────────────────────────────────────────────────────
function loadProducts() {
    fetch('/oro-store-demo/products/get_products.php')
        .then(r => { if (!r.ok) throw new Error('Network error'); return r.json(); })
        .then(products => {
            const list = document.getElementById('product-list');
            list.innerHTML = products.map((p, i) => {
                const oos = p.stock <= 0;
                const isChild = !!(p.individual_sell_unit);
                const tags = [];
                if (p.individual_sell_unit) tags.push(`<span style="font-size:9px;font-weight:600;padding:1px 5px;border-radius:3px;background:#fef3c7;color:#92400e;">${esc(p.individual_sell_unit.charAt(0).toUpperCase()+p.individual_sell_unit.slice(1))}</span>`);
                if (p.category_name && !isChild) tags.push(`<span style="font-size:9px;font-weight:600;padding:1px 5px;border-radius:3px;background:#ede9fe;color:#7c3aed;">${esc(p.category_name)}</span>`);
                if (p.brand_name) tags.push(`<span style="font-size:9px;font-weight:600;padding:1px 5px;border-radius:3px;background:#dbeafe;color:#1d4ed8;">${esc(p.brand_name)}</span>`);
                const tagHtml = tags.length ? ` <span style="display:inline-flex;gap:3px;margin-left:4px;vertical-align:middle;">${tags.join('')}</span>` : '';
                return `<li class="product-item-cashier ${oos ? 'out-of-stock' : ''}"
                    data-id="${p.id}" data-name="${esc(p.name || '')}"
                    data-price="${p.price}" data-purchase-price="${p.purchase_price}"
                    data-stock="${p.stock}" data-brand="${p.brand_name||''}" data-category="${p.category_name||''}" data-unit="${p.individual_sell_unit||''}" data-parent-product-id="${p.parent_product_id || ''}" data-index="${i}"
                    onclick="handleProductTap(this)" style="-webkit-user-select:none;user-select:none;">
                    <span class="product-name-cashier">${esc(p.name || '')}${tagHtml}${oos ? ' <span style="color:#dc3545;font-size:11px;font-weight:bold;">(OUT OF STOCK)</span>' : ''}</span>
                    <span class="product-stock-cashier" style="${oos ? 'color:#dc3545;font-weight:bold;' : ''}">Stock: ${p.stock}</span>
                    <span class="product-price-cashier">₱${parseFloat(p.price).toFixed(2)}</span>
                </li>`;
            }).join('');
            updateProductSelection();
        })
        .catch(err => { console.error('Error loading products:', err); alert('Error loading products. Please refresh.'); });
}

function esc(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

// ─── Search ───────────────────────────────────────────────────────────────────
function setupSearch() {
    document.getElementById('cashier-search').addEventListener('input', function () {
        const q = this.value.toLowerCase();
        let vi = 0;
        document.querySelectorAll('.product-item-cashier').forEach(item => {
            const isChild = (item.dataset.unit||'').trim() !== '';
            let searchable = (item.dataset.name||'')+' '+(item.dataset.brand||'')+' '+(item.dataset.unit||'');
            if (!isChild) searchable += ' '+(item.dataset.category||'');
            const match = q === '' || searchable.toLowerCase().includes(q);
            item.style.display = match ? 'flex' : 'none';
            if (match) item.dataset.visibleIndex = vi++;
        });
        selectedProductIndex = 0;
        updateProductSelection();
    });
}

function updateProductSelection() {
    const visible = Array.from(document.querySelectorAll('.product-item-cashier')).filter(i => i.style.display !== 'none');
    visible.forEach((item, i) => item.classList.toggle('selected', i === selectedProductIndex));
    if (visible[selectedProductIndex]) visible[selectedProductIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

// updateReceiptSelection — overridden by inline script in credit.php
function updateReceiptSelection() {
    const rows = document.querySelectorAll('.credit-row');
    rows.forEach((row, i) => {
        row.classList.toggle('selected', i === selectedReceiptIndex);
        if (i === selectedReceiptIndex) row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });
}

// ─── Quantity modal ───────────────────────────────────────────────────────────
function openQuantityModal(product) {
    currentSelectedProduct = product;
    document.getElementById('modal-product-name').textContent = product.name;
    document.getElementById('quantity-input').value = '1';
    const isInd = product.parent_product_id && product.parent_product_id !== '';
    if (isInd) {
        document.getElementById('quantity-input').removeAttribute('max');
        document.getElementById('modal-stock').innerHTML = product.stock + ' <span style="color:#28a745;font-weight:bold;">(Auto-opens packs)</span>';
    } else {
        document.getElementById('quantity-input').max = product.stock;
        document.getElementById('modal-stock').textContent = product.stock;
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
    const qty = parseInt(document.getElementById('quantity-input').value);
    if (!qty || qty < 1) { alert('Please enter a valid quantity'); return; }
    const isInd = currentSelectedProduct.parent_product_id && currentSelectedProduct.parent_product_id !== '';
    if (!isInd && qty > currentSelectedProduct.stock) { alert('Quantity exceeds available stock'); return; }

    const idx = cart.findIndex(i => i.id === currentSelectedProduct.id);
    if (idx !== -1) {
        cart[idx].quantity += qty;
        cart[idx].subtotal  = cart[idx].quantity * cart[idx].price;
        cart[idx].profit    = (cart[idx].price - cart[idx].purchase_price) * cart[idx].quantity;
    } else {
        cart.push({
            id: currentSelectedProduct.id, name: currentSelectedProduct.name,
            price: currentSelectedProduct.price, purchase_price: currentSelectedProduct.purchase_price,
            quantity: qty, subtotal: qty * currentSelectedProduct.price,
            profit: (currentSelectedProduct.price - currentSelectedProduct.purchase_price) * qty,
            parent_product_id: currentSelectedProduct.parent_product_id || null,
            category: currentSelectedProduct.category || '',
            brand: currentSelectedProduct.brand || '',
            unit: currentSelectedProduct.unit || ''
        });
    }
    sessionStorage.setItem('creditCart', JSON.stringify(cart));
    updateReceipt();
    closeQuantityModal();
    document.getElementById('cashier-search').value = '';
    document.getElementById('cashier-search').dispatchEvent(new Event('input'));
    document.getElementById('cashier-search').focus();
}

// ─── updateReceipt — overridden by inline script ──────────────────────────────
function updateReceipt() {
    const wrap  = document.getElementById('receipt-items');
    const total = document.getElementById('receipt-total');
    if (!wrap || !total) return;
    if (!cart || cart.length === 0) {
        wrap.innerHTML = '<div class="empty-cart"><h3>Cart is Empty</h3><p>Add products for credit sale</p></div>';
        total.style.display = 'none';
        return;
    }
    wrap.innerHTML = '<div style="padding:16px;text-align:center;color:#a0aec0;font-size:12px;">Loading…</div>';
}

// ─── Delete / edit cart items ─────────────────────────────────────────────────
function deleteCartItem(index) {
    customConfirm('Remove this item from cart?', function(){
        cart.splice(index, 1);
        if (selectedReceiptIndex >= cart.length) selectedReceiptIndex = Math.max(0, cart.length - 1);
        sessionStorage.setItem('creditCart', JSON.stringify(cart));
        updateReceipt();
    });
}

function openEditModal(index) {
    currentEditIndex = index;
    const item = cart[index];
    document.getElementById('edit-product-name').textContent = item.name;
    document.getElementById('edit-current-qty').textContent  = item.quantity;
    document.getElementById('edit-quantity-input').value     = item.quantity;
    const isInd = item.parent_product_id && item.parent_product_id !== null;
    if (isInd) document.getElementById('edit-quantity-input').removeAttribute('max');
    else document.getElementById('edit-quantity-input').max = item.stock || 999;
    document.getElementById('edit-modal').classList.add('active');
    document.getElementById('edit-quantity-input').focus();
}

function closeEditModal() {
    document.getElementById('edit-modal').classList.remove('active');
    currentEditIndex = null;
}

function confirmEdit() {
    const qty = parseInt(document.getElementById('edit-quantity-input').value);
    if (!qty || qty < 1) { alert('Please enter a valid quantity'); return; }
    const item = cart[currentEditIndex];
    const isInd = item.parent_product_id && item.parent_product_id !== null;
    if (!isInd && item.stock && qty > item.stock) { alert('Quantity exceeds available stock'); return; }
    cart[currentEditIndex].quantity = qty;
    cart[currentEditIndex].subtotal = qty * cart[currentEditIndex].price;
    cart[currentEditIndex].profit   = (cart[currentEditIndex].price - cart[currentEditIndex].purchase_price) * qty;
    sessionStorage.setItem('creditCart', JSON.stringify(cart));
    updateReceipt();
    closeEditModal();
}

// ─── Customer info modal ──────────────────────────────────────────────────────
function openCreditInfoModal() {
    if (cart.length === 0) { alert('Cart is empty'); return; }
    document.getElementById('credit-info-modal').classList.add('active');
    document.getElementById('customer-name').focus();
}

function closeCreditInfoModal() {
    document.getElementById('credit-info-modal').classList.remove('active');
    document.getElementById('autocomplete-dropdown').style.display = 'none';
}

// calculateTotal — overridden by inline script to be async + half-pack aware.
// This synchronous fallback is only used before the override loads.
function calculateTotal() {
    return cart.reduce((s, i) => s + i.subtotal, 0);
}

// ─── Complete credit transaction — async to support awaiting calculateTotal ───
async function completeCreditTransaction() {
    const customerName    = document.getElementById('customer-name').value.trim();
    const customerContact = document.getElementById('customer-contact').value.trim();
    const customerAddress = document.getElementById('customer-address').value.trim();
    if (!customerName || !customerContact) { alert('Please fill in customer name and contact number'); return; }
    if (cart.length === 0) { alert('Cart is empty'); return; }

    // calculateTotal is overridden to be async; always await it
    const total     = await Promise.resolve(calculateTotal());
    const profit    = cart.reduce((s, i) => s + i.profit, 0);
    const itemCount = cart.reduce((s, i) => s + i.quantity, 0);
    const formData  = new FormData();

    if (isReEditMode) {
        formData.append('action', 'reedit_transaction');
        formData.append('original_transaction_id', originalTransactionId);
        formData.append('original_items', JSON.stringify(originalTransactionItems));
        formData.append('new_items', JSON.stringify(cart));
    } else {
        formData.append('action', 'complete_credit');
        formData.append('items', JSON.stringify(cart));
    }
    formData.append('total_amount', total);
    formData.append('total_profit', profit);
    formData.append('items_count', itemCount);
    formData.append('customer_name', customerName);
    formData.append('customer_contact', customerContact);
    formData.append('customer_address', customerAddress);

    fetch('/oro-store-demo/credit/credit.php', { method: 'POST', body: formData })
        .then(r => r.text().then(t => { try { return JSON.parse(t); } catch (e) { throw new Error('Invalid JSON response'); } }))
        .then(data => {
            if (data.success) {
                var msg = isReEditMode
                    ? 'Credit Transaction Updated!\n\nOriginal: #' + originalTransactionId + '\nNew ID: #' + data.transaction_id + '\nTxn #: ' + data.transaction_number
                    : 'Credit transaction created!\nTransaction #: ' + data.transaction_number;
                sessionStorage.removeItem('creditCart');
                sessionStorage.removeItem('reEditData');
                cart = []; isReEditMode = false; originalTransactionId = null; originalTransactionItems = [];
                updateReceipt();
                closeCreditInfoModal();
                document.getElementById('customer-name').value    = '';
                document.getElementById('customer-contact').value = '';
                document.getElementById('customer-address').value = '';
                customAlert(msg, 'success', function(){ location.reload(); });
            } else {
                alert('Error: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

// ─── Print ────────────────────────────────────────────────────────────────────
function printReceipt() {
    if (cart.length === 0) { alert('Cart is empty. Add items before printing.'); return; }
    openCreditInfoModal();
}

async function printReceiptFromModal() {
    const customerName    = document.getElementById('customer-name').value.trim();
    const customerContact = document.getElementById('customer-contact').value.trim();
    const customerAddress = document.getElementById('customer-address').value.trim();
    if (!customerName || !customerContact) { alert('Please fill in customer name and contact number'); return; }
    if (cart.length === 0) { alert('Cart is empty'); return; }

    const total = await Promise.resolve(calculateTotal());

    const receiptData = {
        items: cart.map(i => ({
            id: i.id, name: i.name, quantity: i.quantity, price: i.price,
            purchase_price: i.purchase_price || 0,
            subtotal: i.price * i.quantity,
            profit: (i.price - (i.purchase_price || 0)) * i.quantity
        })),
        total,
        itemCount: cart.reduce((s, i) => s + i.quantity, 0),
        date: new Date().toLocaleString(),
        paymentMethod: 'credit',
        creditInfo: { customerName, customerContact, customerAddress },
        storeName: STORE_INFO.storeName, storeAddress: STORE_INFO.storeAddress, cashierName: STORE_INFO.cashierName
    };

    if (typeof BTPrint !== 'undefined') {
        BTPrint.printReceipt({
            storeName: receiptData.storeName,
            storeAddress: receiptData.storeAddress,
            title: 'CREDIT RECEIPT',
            date: receiptData.date,
            cashier: receiptData.cashierName,
            items: receiptData.items,
            total: receiptData.total
        });
    }

    setTimeout(() => completeCreditTransaction(), 500);
}

// ─── Keyboard navigation ──────────────────────────────────────────────────────
document.addEventListener('keydown', function (e) {
    if (e.key === 'F1') {
        e.preventDefault();
        document.getElementById('credit-info-modal').classList.contains('active') ? printReceiptFromModal() : printReceipt();
        return;
    }
    if (e.key === 'F2') { e.preventDefault(); window.open('/oro-store-demo/transactions/gcash.php', '_blank', 'width=600,height=700'); return; }
    if (e.key === 'F3') { e.preventDefault(); if (cart.length > 0) sessionStorage.setItem('deliveryCart', JSON.stringify(cart)); window.location.href = '/oro-store-demo/delivery/delivery.php'; return; }
    if (e.key === 'F5') { e.preventDefault(); if (cart.length > 0) sessionStorage.setItem('angkatCart', JSON.stringify(cart)); window.location.href = '/oro-store-demo/angkat/angkat.php'; return; }
    if (e.key === 'F7') { e.preventDefault(); window.open('/oro-store-demo/transactions/card_transaction.php', '_blank', 'width=600,height=700'); return; }
    if (e.key === 'F9') { e.preventDefault(); window.location.href = '/oro-store-demo/delivery/delivery_details.php'; return; }

    if (e.key === 'F11') { e.preventDefault(); window.open('/oro-store-demo/stock/add_stock.php', '_blank', 'width=800,height=600'); return; }

    const alertOverlay = document.getElementById('custom-alert-overlay');
    if (alertOverlay && alertOverlay.style.display !== 'none') return;

    const creditModalOpen    = document.getElementById('credit-info-modal').classList.contains('active');
    const quantityModalOpen  = document.getElementById('quantity-modal').classList.contains('active');
    const editModalOpen      = document.getElementById('edit-modal').classList.contains('active');

    if (creditModalOpen) {
        const dropVisible = document.getElementById('autocomplete-dropdown').style.display !== 'none';
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && !dropVisible) { e.preventDefault(); completeCreditTransaction(); }
        else if (e.key === 'Escape' && !dropVisible) { e.preventDefault(); closeCreditInfoModal(); }
        return;
    }
    if (quantityModalOpen) {
        if (e.key === 'Enter') { e.preventDefault(); confirmQuantity(); }
        else if (e.key === 'Escape') { e.preventDefault(); closeQuantityModal(); }
        return;
    }
    if (editModalOpen) {
        if (e.key === 'Enter') { e.preventDefault(); confirmEdit(); }
        else if (e.key === 'Escape') { e.preventDefault(); closeEditModal(); }
        return;
    }

    if (currentPanel === 'left') {
        const visible = Array.from(document.querySelectorAll('.product-item-cashier')).filter(i => i.style.display !== 'none');
        if (e.key === 'ArrowDown')  { e.preventDefault(); selectedProductIndex = Math.min(selectedProductIndex + 1, visible.length - 1); updateProductSelection(); }
        else if (e.key === 'ArrowUp')   { e.preventDefault(); selectedProductIndex = Math.max(selectedProductIndex - 1, 0); updateProductSelection(); }
        else if (e.key === 'Enter') {
            e.preventDefault();
            if (visible[selectedProductIndex]) {
                const el = visible[selectedProductIndex];
                openQuantityModal({ id: parseInt(el.dataset.id), name: el.dataset.name, price: parseFloat(el.dataset.price), purchase_price: parseFloat(el.dataset.purchasePrice), stock: parseInt(el.dataset.stock), parent_product_id: el.dataset.parentProductId || null, category: el.dataset.category || '', brand: el.dataset.brand || '', unit: el.dataset.unit || '' });
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            const si = document.getElementById('cashier-search');
            if (si.value !== '') { si.value = ''; si.dispatchEvent(new Event('input')); si.focus(); }
            else if (cart.length > 0) { customConfirm('Clear cart and return to cashier?', function(){ sessionStorage.removeItem('creditCart'); window.location.href = '/oro-store-demo/cashier/cashier.php'; }); }
            else window.location.href = '/oro-store-demo/cashier/cashier.php';
        } else if (e.key === 'Home') {
            e.preventDefault();
            if (cart.length > 0) { setActivePanel('right'); selectedReceiptIndex = 0; updateReceiptSelection(); }
        }
    } else if (currentPanel === 'right') {
        if (e.key === 'Enter') { e.preventDefault(); openCreditInfoModal(); }
        else if (e.key === 'Home') { e.preventDefault(); setActivePanel('left'); document.getElementById('cashier-search').focus(); updateProductSelection(); }
        else if (e.key === 'Escape') { e.preventDefault(); setActivePanel('left'); document.getElementById('cashier-search').focus(); updateProductSelection(); }
        // ArrowDown/Up/Delete/Insert handled by capture-phase listener in credit.php
    }
});

console.log('✅ credit_script.js v' + CREDIT_SCRIPT_VERSION + ' loaded');