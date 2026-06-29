let cart = [];
let currentPanel = 'left';
let selectedProductIndex = 0;
let selectedReceiptIndex = 0;
let currentSelectedProduct = null;
let currentEditIndex = null;
let lastPriorityNumber = null;

document.addEventListener('DOMContentLoaded', function () {
    loadProducts();
    setupSearch();
});

function _renderProducts(products) {
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
}

function loadProducts() {
    // Show cached products instantly, then refresh in background
    if (typeof OroCache !== 'undefined') {
        const cached = OroCache.get('kiosk_products');
        if (cached) {
            _renderProducts(cached);
            // Background refresh for fresh stock data
            fetch('/oro-store/products/get_products.php')
                .then(r => r.json())
                .then(products => {
                    OroCache.set('kiosk_products', products, 300);
                    // Only re-render if stock changed
                    const changed = products.some((p, i) => !cached[i] || cached[i].stock !== p.stock || cached[i].price !== p.price);
                    if (changed || products.length !== cached.length) _renderProducts(products);
                }).catch(() => {});
            return;
        }
    }
    fetch('/oro-store/products/get_products.php')
        .then(r => { if (!r.ok) throw new Error('Network error'); return r.json(); })
        .then(products => {
            if (typeof OroCache !== 'undefined') OroCache.set('kiosk_products', products, 300);
            _renderProducts(products);
        })
        .catch(err => { console.error('Error loading products:', err); alert('Error loading products. Please refresh.'); });
}

function esc(str) { const d = document.createElement('div'); d.textContent = str; return d.innerHTML; }

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

function updateReceiptSelection() {
    const items = document.querySelectorAll('.receipt-item');
    items.forEach((item, i) => item.classList.toggle('selected', i === selectedReceiptIndex));
    if (items[selectedReceiptIndex]) items[selectedReceiptIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

// Product click handler (event delegation)
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('product-list').addEventListener('click', function(e) {
        const item = e.target.closest('.product-item-cashier');
        if (!item) return;
        const visibleItems = Array.from(document.querySelectorAll('.product-item-cashier')).filter(i => i.style.display !== 'none');
        const idx = visibleItems.indexOf(item);
        if (idx >= 0) {
            selectedProductIndex = idx;
            currentPanel = 'left';
            updateProductSelection();
        }
    });
});

// Quantity modal
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
    updateReceipt();
    closeQuantityModal();
    document.getElementById('cashier-search').value = '';
    document.getElementById('cashier-search').dispatchEvent(new Event('input'));
    document.getElementById('cashier-search').focus();
}

var _lpTimer = null;
function startLongPress(idx) {
    _lpTimer = setTimeout(function () {
        selectedReceiptIndex = parseInt(idx);
        openEditModal(parseInt(idx));
    }, 500);
}
function cancelLongPress() {
    if (_lpTimer) { clearTimeout(_lpTimer); _lpTimer = null; }
}

function updateReceipt() {
    const wrap = document.getElementById('receipt-items');
    const total = document.getElementById('receipt-total');
    if (!cart || cart.length === 0) {
        wrap.innerHTML = '<div class="empty-cart"><h3>Cart is Empty</h3><p>Select products to add to your order</p></div>';
        total.style.display = 'none';
        return;
    }
    let totalItems = 0, subtotal = 0;
    let html = '<table style="width:100%;border-collapse:collapse;table-layout:fixed;"><thead><tr style="background:#2d3748;">';
    html += '<th style="padding:8px;text-align:left;color:#a0aec0;font-size:10px;text-transform:uppercase;letter-spacing:.7px;font-weight:600;border-bottom:2px solid #4a5568;width:40%;">Product</th>';
    html += '<th style="padding:8px;text-align:center;color:#a0aec0;font-size:10px;text-transform:uppercase;letter-spacing:.7px;font-weight:600;border-bottom:2px solid #4a5568;width:10%;">Qty</th>';
    html += '<th style="padding:8px;text-align:right;color:#a0aec0;font-size:10px;text-transform:uppercase;letter-spacing:.7px;font-weight:600;border-bottom:2px solid #4a5568;width:25%;">Price</th>';
    html += '<th style="padding:8px;text-align:right;color:#a0aec0;font-size:10px;text-transform:uppercase;letter-spacing:.7px;font-weight:600;border-bottom:2px solid #4a5568;width:17%;">Total</th>';
    html += '<th style="padding:8px;text-align:center;border-bottom:2px solid #4a5568;width:8%;"></th>';
    html += '</tr></thead><tbody>';

    cart.forEach((item, index) => {
        totalItems += item.quantity;
        subtotal += item.subtotal;
        const isSelected = index === selectedReceiptIndex && currentPanel === 'right';
        const lineTotal = item.price * item.quantity;
        html += `<tr class="receipt-item" data-index="${index}"
            style="cursor:pointer;border-bottom:1px solid #e2e8f0;background:${isSelected ? '#dbeafe' : ''};transition:background .1s;-webkit-user-select:none;user-select:none;"
            onmouseenter="if(!this.classList.contains('selected'))this.style.background='#f0f7ff';"
            onmouseleave="if(!this.classList.contains('selected'))this.style.background='';"
            onclick="currentPanel='right';selectedReceiptIndex=${index};updateReceiptSelection();"
            ontouchstart="startLongPress(${index})"
            ontouchend="cancelLongPress()"
            ontouchmove="cancelLongPress()">
            <td style="padding:9px 8px;word-break:break-word;white-space:normal;line-height:1.3;font-size:12px;border-bottom:1px solid #e2e8f0;">
                <div style="font-weight:600;color:#2d3748;">${esc(item.name)}</div>
                <div style="font-weight:700;font-size:10px;color:#718096;margin-top:2px;">₱${item.price.toFixed(2)}</div>
            </td>
            <td style="padding:9px 8px;text-align:center;font-weight:700;color:#4a5568;font-size:12px;border-bottom:1px solid #e2e8f0;">${item.quantity}</td>
            <td style="padding:9px 8px;text-align:right;border-bottom:1px solid #e2e8f0;white-space:nowrap;">
                <div style="font-style:italic;font-size:10px;color:#a0aec0;">₱${item.price.toFixed(2)} × ${item.quantity} =</div>
                <div style="font-weight:700;color:#2b6cb0;font-size:12px;">₱${lineTotal.toFixed(2)}</div>
            </td>
            <td style="padding:9px 8px;text-align:right;border-bottom:1px solid #e2e8f0;white-space:nowrap;">
                <div style="font-weight:700;font-size:12px;color:#2563eb;">₱${lineTotal.toFixed(2)}</div>
            </td>
            <td style="padding:9px 8px;text-align:center;border-bottom:1px solid #e2e8f0;">
                <button onclick="event.stopPropagation();deleteCartItem(${index})"
                    style="background:#fed7d7;color:#c53030;border:none;border-radius:3px;padding:3px 9px;cursor:pointer;font-size:11px;font-weight:700;"
                    onmouseenter="this.style.background='#feb2b2';"
                    onmouseleave="this.style.background='#fed7d7';">✕</button>
            </td>
        </tr>`;
    });

    html += '</tbody></table>';
    wrap.innerHTML = html;
    total.style.display = 'block';
    document.getElementById('total-items').textContent = totalItems;
    document.getElementById('subtotal').textContent = '₱' + subtotal.toFixed(2);
    document.getElementById('grand-total').textContent = '₱' + subtotal.toFixed(2);
    if (currentPanel === 'right') updateReceiptSelection();
}

function deleteCartItem(index) {
    cart.splice(index, 1);
    if (selectedReceiptIndex >= cart.length) selectedReceiptIndex = Math.max(0, cart.length - 1);
    updateReceipt();
}

function openEditModal(index) {
    currentEditIndex = index;
    const item = cart[index];
    document.getElementById('edit-product-name').textContent = item.name;
    document.getElementById('edit-current-qty').textContent = item.quantity;
    document.getElementById('edit-quantity-input').value = item.quantity;
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
    cart[currentEditIndex].quantity = qty;
    cart[currentEditIndex].subtotal = qty * cart[currentEditIndex].price;
    cart[currentEditIndex].profit = (cart[currentEditIndex].price - cart[currentEditIndex].purchase_price) * qty;
    updateReceipt();
    closeEditModal();
}

function calculateTotal() {
    return cart.reduce((s, i) => s + i.subtotal, 0);
}

// Submit kiosk order
function submitKioskOrder() {
    if (cart.length === 0) { alert('Cart is empty'); return; }

    const total = calculateTotal();
    const profit = cart.reduce((s, i) => s + i.profit, 0);
    const itemCount = cart.reduce((s, i) => s + i.quantity, 0);

    const formData = new FormData();
    formData.append('action', 'submit_kiosk_order');
    formData.append('items', JSON.stringify(cart));
    formData.append('total_amount', total);
    formData.append('total_profit', profit);
    formData.append('items_count', itemCount);
    formData.append('payment_method', 'cash');

    fetch('/oro-store/kiosk/kiosk.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (typeof OroCache !== 'undefined') OroCache.invalidate('kiosk_products');
                lastPriorityNumber = data.priority_number;
                var orderItems = cart.slice();
                var orderTotal = total;
                cart = [];
                updateReceipt();
                showPriorityNumber(data.priority_number, null, orderItems, orderTotal);
            } else {
                alert('Error: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

var _priorityTimer = null;
var _lastOrderItems = null;
var _lastOrderTotal = 0;
var _lastGcashInfo = null;

function showPriorityNumber(num, gcashInfo, orderItems, orderTotal) {
    _lastOrderItems = orderItems || null;
    _lastOrderTotal = orderTotal || 0;
    _lastGcashInfo = gcashInfo || null;
    var padded = String(num).padStart(3, '0');
    document.getElementById('priority-number').textContent = padded;

    // Details area
    var detailsEl = document.getElementById('priority-gcash-details');
    if (detailsEl) {
        if (gcashInfo) {
            var typeLabel = gcashInfo.type === 'cash_in' ? 'Cash In' : 'Cash Out';
            detailsEl.innerHTML =
                '<div style="font-size:13px;font-weight:700;color:#3b82f6;margin-bottom:6px;">GCash ' + typeLabel + '</div>' +
                '<div style="font-size:12px;color:#475569;">GCash #: <strong>' + (gcashInfo.customerNumber || '-') + '</strong></div>' +
                '<div style="font-size:12px;color:#475569;">Amount: <strong>₱' + parseFloat(gcashInfo.amount || 0).toFixed(2) + '</strong></div>' +
                '<div style="font-size:12px;color:#475569;">Fee: <strong>₱' + parseFloat(gcashInfo.fee || 0).toFixed(2) + '</strong></div>' +
                '<div style="font-size:14px;font-weight:800;color:#1e293b;margin-top:4px;">Total: ₱' + parseFloat(gcashInfo.total || 0).toFixed(2) + '</div>';
            detailsEl.style.display = 'block';
        } else if (orderItems && orderItems.length) {
            var html = '<div style="font-size:13px;font-weight:700;color:#2563eb;margin-bottom:8px;">Your Order</div>';
            orderItems.forEach(function(item) {
                var tags = [];
                if (item.unit) tags.push(item.unit.charAt(0).toUpperCase() + item.unit.slice(1));
                if (item.category) tags.push(item.category);
                if (item.brand) tags.push(item.brand);
                var tagStr = tags.length ? ' <span style="font-size:10px;color:#94a3b8;">(' + esc(tags.join(' / ')) + ')</span>' : '';
                html += '<div style="display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#475569;padding:3px 0;border-bottom:1px solid #e2e8f0;">' +
                    '<div><span style="font-weight:600;color:#1e293b;">' + esc(item.name) + '</span>' + tagStr +
                    '<div style="font-size:10px;color:#94a3b8;">₱' + parseFloat(item.price).toFixed(2) + '/ea × ' + item.quantity + '</div></div>' +
                    '<span style="font-weight:700;color:#1e293b;">₱' + parseFloat(item.subtotal).toFixed(2) + '</span>' +
                '</div>';
            });
            html += '<div style="display:flex;justify-content:space-between;font-size:14px;font-weight:800;color:#1e293b;margin-top:6px;padding-top:6px;border-top:1px solid #bfdbfe;">' +
                '<span>Total</span><span>₱' + parseFloat(orderTotal || 0).toFixed(2) + '</span></div>';
            detailsEl.innerHTML = html;
            detailsEl.style.display = 'block';
        } else {
            detailsEl.style.display = 'none';
        }
    }

    document.getElementById('priority-modal').classList.add('active');

    startAutoRedirect();

    var store = document.getElementById('priority-store').textContent;
    try {
        if (typeof BTPrint !== 'undefined') {
            if (gcashInfo) {
                BTPrint.printPriorityGCash(parseInt(padded), store, gcashInfo.type, gcashInfo.customerNumber, gcashInfo.amount, gcashInfo.fee, gcashInfo.total);
            } else {
                BTPrint.printPriority(parseInt(padded), store, null, orderItems, orderTotal);
            }
        }
    } catch (e) { /* printer unavailable, continue */ }
}

function startAutoRedirect() {
    clearAutoRedirect();
    var seconds = 10;
    var countEl = document.getElementById('priority-countdown');
    if (countEl) countEl.textContent = seconds;
    _priorityTimer = setInterval(function () {
        seconds--;
        if (countEl) countEl.textContent = seconds;
        if (seconds <= 0) {
            clearAutoRedirect();
            closePriorityModal();
        }
    }, 1000);
}

function clearAutoRedirect() {
    if (_priorityTimer) { clearInterval(_priorityTimer); _priorityTimer = null; }
}

function closePriorityModal() {
    clearAutoRedirect();
    document.getElementById('priority-modal').classList.remove('active');
    lastPriorityNumber = null;
    cart = [];
    updateReceipt();
    document.getElementById('cashier-search').value = '';
    document.getElementById('cashier-search').focus();
}

function printPriorityNumber() {
    var num = document.getElementById('priority-number').textContent;
    var store = document.getElementById('priority-store').textContent;
    try {
        if (typeof BTPrint !== 'undefined') BTPrint.printPriority(parseInt(num), store);
    } catch (e) { /* silent */ }
}

function openGCash() {
    location.href = '/oro-store/transactions/gcash.php';
}

// Double-tap product handler
var _lastClickTime = 0, _lastClickId = '';
function handleProductTap(el) {
    var now = Date.now(), id = el.dataset.id;
    if (now - _lastClickTime < 400 && _lastClickId === id) {
        var visible = Array.from(document.querySelectorAll('.product-item-cashier')).filter(function(i){return i.style.display!=='none';});
        var idx = visible.indexOf(el);
        if (idx >= 0) selectedProductIndex = idx;
        simulateKey('Enter');
        _lastClickTime = 0; _lastClickId = '';
    } else {
        _lastClickTime = now; _lastClickId = id;
    }
}
function simulateKey(key) { document.dispatchEvent(new KeyboardEvent('keydown', {key: key, bubbles: true})); }

// Keyboard navigation
document.addEventListener('keydown', function (e) {
    if (e.key === 'F2') { e.preventDefault(); openGCash(); return; }

    const alertOverlay = document.getElementById('custom-alert-overlay');
    if (alertOverlay && alertOverlay.style.display !== 'none') return;

    const priorityOpen = document.getElementById('priority-modal').classList.contains('active');
    const quantityModalOpen = document.getElementById('quantity-modal').classList.contains('active');
    const editModalOpen = document.getElementById('edit-modal').classList.contains('active');

    if (priorityOpen) {
        if (e.key === 'Enter') { e.preventDefault(); printPriorityNumber(); }
        else if (e.key === 'Escape') { e.preventDefault(); closePriorityModal(); }
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
        if (e.key === 'ArrowDown') { e.preventDefault(); selectedProductIndex = Math.min(selectedProductIndex + 1, visible.length - 1); updateProductSelection(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); selectedProductIndex = Math.max(selectedProductIndex - 1, 0); updateProductSelection(); }
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
        } else if (e.key === 'Home') {
            e.preventDefault();
            if (cart.length > 0) { currentPanel = 'right'; selectedReceiptIndex = 0; updateReceiptSelection(); }
        }
    } else if (currentPanel === 'right') {
        const items = document.querySelectorAll('.receipt-item');
        if (e.key === 'ArrowDown') { e.preventDefault(); selectedReceiptIndex = Math.min(selectedReceiptIndex + 1, items.length - 1); updateReceiptSelection(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); selectedReceiptIndex = Math.max(selectedReceiptIndex - 1, 0); updateReceiptSelection(); }
        else if (e.key === 'Enter') { e.preventDefault(); submitKioskOrder(); }
        else if (e.key === 'Delete') { e.preventDefault(); if (items[selectedReceiptIndex]) deleteCartItem(parseInt(items[selectedReceiptIndex].dataset.index)); }
        else if (e.key === 'Insert') { e.preventDefault(); if (items[selectedReceiptIndex]) openEditModal(parseInt(items[selectedReceiptIndex].dataset.index)); }
        else if (e.key === 'Home' || e.key === 'Escape') { e.preventDefault(); currentPanel = 'left'; document.getElementById('cashier-search').focus(); updateProductSelection(); }
    }
});
