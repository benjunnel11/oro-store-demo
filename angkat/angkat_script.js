console.log('🚀 angkat_script.js loaded!');

let cart = [];
let currentPanel = 'left';
let selectedProductIndex = 0;
let selectedReceiptIndex = 0;
let currentSelectedProduct = null;
let currentEditIndex = null;
let autocompleteTimeout = null;
let selectedAutocompleteIndex = -1;
let autocompleteResults = [];

const ANGKAT_SCRIPT_VERSION = '2.0-DELIVERY-PARITY';
console.log('=== ANGKAT SCRIPT VERSION: ' + ANGKAT_SCRIPT_VERSION + ' ===');

function setActivePanel(panel) {
    currentPanel = panel;
    const lp = document.querySelector('.left-panel');
    const rp = document.querySelector('.right-panel');
    if (lp && rp) {
        lp.classList.toggle('panel-active', panel === 'left');
        rp.classList.toggle('panel-active', panel === 'right');
    }
}
function scEsc() { location.href = 'cashier.php'; }
function scHome() {
    if (currentPanel === 'left' && cart.length > 0) { setActivePanel('right'); }
    else { setActivePanel('left'); document.getElementById('cashier-search').focus(); }
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
function processAngkat() { openAngkatInfoModal(); }
function printReceipt() {
    if (document.getElementById('angkat-info-modal').classList.contains('active')) { printAngkatReceipt(); }
    else if (cart.length > 0) { openAngkatInfoModal(); }
}

// ─── DOMContentLoaded ─────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    loadProducts();
    setupSearch();
    setupRetailerAutocomplete();

    const savedCart = sessionStorage.getItem('angkatCart');
    if (savedCart) {
        try {
            cart = JSON.parse(savedCart);
            updateReceipt();
        } catch (e) {
            console.error('Error parsing saved cart:', e);
            sessionStorage.removeItem('angkatCart');
        }
    }

    setActivePanel('left');

    // Event delegation for dynamically loaded products
    document.getElementById('product-list').addEventListener('click', function(e) {
        const item = e.target.closest('.product-item-cashier');
        if (!item) return;
        const visibleItems = Array.from(document.querySelectorAll('.product-item-cashier')).filter(i => i.style.display !== 'none');
        const idx = visibleItems.indexOf(item);
        if (idx >= 0) {
            selectedProductIndex = idx;
            setActivePanel('left');
            updateProductSelection();
        }
    });
});

// ─── Retailer autocomplete ────────────────────────────────────────────────────
function setupRetailerAutocomplete() {
    const nameInput  = document.getElementById('retailer-name');
    const dropdown   = document.getElementById('autocomplete-dropdown');
    if (!nameInput || !dropdown) return;

    nameInput.addEventListener('input', function () {
        const term = this.value.trim();
        clearTimeout(autocompleteTimeout);
        selectedAutocompleteIndex = -1;
        if (term.length < 2) { dropdown.style.display = 'none'; autocompleteResults = []; return; }

        autocompleteTimeout = setTimeout(() => {
            fetch('/oro-store-demo/angkat/angkat.php?action=search_retailers&search=' + encodeURIComponent(term))
                .then(r => r.json())
                .then(retailers => {
                    autocompleteResults = retailers;
                    if (!retailers.length) { dropdown.style.display = 'none'; return; }
                    dropdown.innerHTML = retailers.map((r, i) => `
                        <div class="autocomplete-item ${i === selectedAutocompleteIndex ? 'selected' : ''}" data-index="${i}">
                            <strong>${esc(r.retailer_name || '')}</strong>
                            <small>${esc(r.retailer_contact || 'No contact')}</small>
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
    const r = autocompleteResults[index];
    document.getElementById('retailer-name').value    = r.retailer_name || '';
    document.getElementById('retailer-contact').value = r.retailer_contact || '';
    document.getElementById('autocomplete-dropdown').style.display = 'none';
    selectedAutocompleteIndex = -1;
    document.getElementById('retailer-contact').focus();
}

// ─── Load products (uses discounted_price as the selling price) ───────────────
function loadProducts() {
    fetch('/oro-store-demo/products/get_products.php')
        .then(r => { if (!r.ok) throw new Error('Network error'); return r.json(); })
        .then(products => {
            const list = document.getElementById('product-list');
            list.innerHTML = products.map((p, i) => {
                const oos = p.stock <= 0;
                // Use discounted_price if available and different, otherwise use price
                const displayPrice = (p.discounted_price && parseFloat(p.discounted_price) > 0)
                    ? parseFloat(p.discounted_price)
                    : parseFloat(p.price);
                const isChild = !!(p.individual_sell_unit);
                const tags = [];
                if (p.individual_sell_unit) tags.push(`<span style="font-size:9px;font-weight:600;padding:1px 5px;border-radius:3px;background:#fef3c7;color:#92400e;">${esc(p.individual_sell_unit.charAt(0).toUpperCase()+p.individual_sell_unit.slice(1))}</span>`);
                if (p.category_name && !isChild) tags.push(`<span style="font-size:9px;font-weight:600;padding:1px 5px;border-radius:3px;background:#ede9fe;color:#7c3aed;">${esc(p.category_name)}</span>`);
                if (p.brand_name) tags.push(`<span style="font-size:9px;font-weight:600;padding:1px 5px;border-radius:3px;background:#dbeafe;color:#1d4ed8;">${esc(p.brand_name)}</span>`);
                const tagHtml = tags.length ? ` <span style="display:inline-flex;gap:3px;margin-left:4px;vertical-align:middle;">${tags.join('')}</span>` : '';
                return `<li class="product-item-cashier ${oos ? 'out-of-stock' : ''}"
                    data-id="${p.id}"
                    data-name="${esc(p.name || '')}"
                    data-price="${displayPrice}"
                    data-original-price="${p.price}"
                    data-purchase-price="${p.purchase_price}"
                    data-stock="${p.stock}"
                    data-brand="${p.brand_name||''}"
                    data-category="${p.category_name||''}"
                    data-unit="${p.individual_sell_unit||''}"
                    data-parent-product-id="${p.parent_product_id || ''}"
                    data-index="${i}"
                    onclick="handleProductTap(this)"
                    style="-webkit-user-select:none;user-select:none;">
                    <span class="product-name-cashier">${esc(p.name || '')}${tagHtml}${oos ? ' <span style="color:#dc3545;font-size:11px;font-weight:bold;">(OUT OF STOCK)</span>' : ''}</span>
                    <span class="product-stock-cashier" style="${oos ? 'color:#dc3545;font-weight:bold;' : ''}">Stock: ${p.stock}</span>
                    <span class="product-price-cashier">₱${displayPrice.toFixed(2)}</span>
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

function fmt(n) {
    return '₱' + parseFloat(n).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// ─── Search (name + barcode + description, with !important to override CSS) ───
function setupSearch() {
    document.getElementById('cashier-search').addEventListener('input', function () {
        const q = this.value.toLowerCase().trim();
        let vi = 0;
        document.querySelectorAll('.product-item-cashier').forEach(item => {
            const isChild = (item.dataset.unit||'').trim() !== '';
            let searchable = (item.dataset.name||'')+' '+(item.dataset.brand||'')+' '+(item.dataset.unit||'');
            if (!isChild) searchable += ' '+(item.dataset.category||'');
            const match = q === '' || searchable.toLowerCase().includes(q);
            item.style.setProperty('display', match ? 'flex' : 'none', 'important');
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
    const rows = document.querySelectorAll('.angkat-row');
    rows.forEach((row, i) => {
        const cells = row.querySelectorAll('td');
        if (i === selectedReceiptIndex) {
            row.classList.add('selected');
            row.style.background = '#dbeafe';
            cells.forEach(c => c.style.background = '#dbeafe');
            row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        } else {
            row.classList.remove('selected');
            row.style.background = '';
            cells.forEach(c => c.style.background = '');
        }
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
        cart[idx].cost      = cart[idx].quantity * cart[idx].purchase_price;
        cart[idx].profit    = cart[idx].subtotal - cart[idx].cost;
    } else {
        const price    = parseFloat(currentSelectedProduct.price);
        const costPrice = parseFloat(currentSelectedProduct.purchase_price);
        cart.push({
            id: currentSelectedProduct.id,
            name: currentSelectedProduct.name,
            price: price,
            purchase_price: costPrice,
            quantity: qty,
            subtotal: qty * price,
            cost: qty * costPrice,
            profit: qty * (price - costPrice),
            parent_product_id: currentSelectedProduct.parent_product_id || null,
            category: currentSelectedProduct.category || '',
            brand: currentSelectedProduct.brand || '',
            unit: currentSelectedProduct.unit || ''
        });
    }

    sessionStorage.setItem('angkatCart', JSON.stringify(cart));
    updateReceipt();
    closeQuantityModal();
    document.getElementById('cashier-search').value = '';
    document.getElementById('cashier-search').dispatchEvent(new Event('input'));
    document.getElementById('cashier-search').focus();
}

// ─── updateReceipt — full table layout matching delivery/credit ───────────────
function updateReceipt() {
    const wrap  = document.getElementById('receipt-items');
    const total = document.getElementById('receipt-total');
    if (!wrap || !total) return;

    if (!cart || cart.length === 0) {
        wrap.innerHTML = '<div class="empty-cart"><h3>Cart is Empty</h3><p>Add products for angkat / consignment</p></div>';
        total.style.display = 'none';
        return;
    }

    function td(content, css) {
        const el = document.createElement('td');
        el.style.cssText = 'display:table-cell!important;padding:9px 8px;vertical-align:middle;border-bottom:1px solid #e2e8f0;font-size:12px;' + (css || '');
        el.innerHTML = content;
        return el;
    }

    const frag = document.createDocumentFragment();

    const table = document.createElement('table');
    table.style.cssText = 'width:100%;border-collapse:collapse;table-layout:fixed;';

    // thead
    const thead = document.createElement('thead');
    const hRow  = document.createElement('tr');
    hRow.style.cssText = 'background:#2d3748;';
    [
        { label: 'Product',    w: '28%', align: 'left'   },
        { label: 'Qty',        w: '6%',  align: 'center' },
        { label: 'Unit Price', w: '20%', align: 'right'  },
        { label: 'Line Total', w: '18%', align: 'right'  },
        { label: '',           w: '8%',  align: 'center' },
    ].forEach(h => {
        const th = document.createElement('th');
        th.style.cssText = 'display:table-cell!important;padding:8px 8px;text-align:' + h.align
            + ';color:#a0aec0;font-size:10px;text-transform:uppercase;letter-spacing:.7px;'
            + 'font-weight:600;border-bottom:2px solid #4a5568;white-space:nowrap;width:' + h.w + ';';
        th.textContent = h.label;
        hRow.appendChild(th);
    });
    thead.appendChild(hRow);
    table.appendChild(thead);

    const tbody = document.createElement('tbody');
    let totItems = 0, totSubtotal = 0, totCost = 0;

    cart.forEach((item, index) => {
        const qty       = item.quantity;
        const unitPrice = parseFloat(item.price);
        const unitCost  = parseFloat(item.purchase_price);
        const lineValue = unitPrice * qty;
        const lineCost  = unitCost  * qty;
        const lineProfit = lineValue - lineCost;

        totItems    += qty;
        totSubtotal += lineValue;
        totCost     += lineCost;

        const tr = document.createElement('tr');
        tr.className     = 'angkat-row';
        tr.dataset.index = index;
        tr.style.cssText = 'cursor:pointer;border-bottom:1px solid #e2e8f0;transition:background .1s;';

        tr.addEventListener('mouseenter', () => { if (!tr.classList.contains('selected')) tr.style.background = '#f0f7ff'; });
        tr.addEventListener('mouseleave', () => { if (!tr.classList.contains('selected')) tr.style.background = ''; });
        tr.addEventListener('click', (e) => {
            if (e.target.closest('button')) return;
            setActivePanel('right');
            selectedReceiptIndex = index;
            updateReceiptSelection();
        });
        tr.ontouchstart = function(){ startLongPress(index); };
        tr.ontouchend = function(){ cancelLongPress(); };
        tr.ontouchmove = function(){ cancelLongPress(); };
        tr.style.cssText += '-webkit-user-select:none;user-select:none;';

        // Product name + unit price below
        tr.appendChild(td(
            `<div style="font-weight:600;color:#2d3748;word-break:break-word;white-space:normal;line-height:1.3;">${esc(item.name)}</div>
             <div style="font-weight:700;font-size:10px;color:#718096;margin-top:2px;">Unit Price: ${fmt(unitPrice)}</div>`,
            'word-break:break-word;'
        ));

        // Qty
        tr.appendChild(td(qty, 'text-align:center;font-weight:700;color:#4a5568;'));

        // Unit Price col: equation + total
        tr.appendChild(td(
            `<div style="text-align:right;">
                <div style="font-weight:700;font-size:10px;color:#a0aec0;white-space:nowrap;">${fmt(unitPrice)} &times; ${qty} =</div>
                <div style="font-weight:700;color:#2b6cb0;white-space:nowrap;">${fmt(lineValue)}</div>
             </div>`,
            'white-space:nowrap;'
        ));



        // Line Total: unit price × qty = total
        tr.appendChild(td(
            `<div style="text-align:right;">
                <div style="font-weight:700;font-size:10px;color:#a0aec0;white-space:nowrap;">${fmt(unitPrice)} &times; ${qty} =</div>
                <div style="font-weight:700;color:#276749;white-space:nowrap;">${fmt(lineValue)}</div>
             </div>`,
            'white-space:nowrap;'
        ));

        // Delete button
        const delTd  = document.createElement('td');
        delTd.style.cssText = 'display:table-cell!important;padding:9px 8px;vertical-align:middle;text-align:center;border-bottom:1px solid #e2e8f0;';
        const delBtn = document.createElement('button');
        delBtn.textContent = '✕';
        delBtn.style.cssText = 'background:#fed7d7;color:#c53030;border:none;border-radius:3px;padding:3px 9px;cursor:pointer;font-size:11px;font-weight:700;transition:background .12s;';
        delBtn.addEventListener('mouseenter', () => delBtn.style.background = '#feb2b2');
        delBtn.addEventListener('mouseleave', () => delBtn.style.background = '#fed7d7');
        delBtn.addEventListener('click', (e) => { e.stopPropagation(); deleteCartItem(index); });
        delTd.appendChild(delBtn);
        tr.appendChild(delTd);

        tbody.appendChild(tr);
    });

    table.appendChild(tbody);
    frag.appendChild(table);

    wrap.innerHTML = '';
    wrap.appendChild(frag);

    total.style.display = 'block';
    document.getElementById('total-items').textContent   = totItems;
    document.getElementById('subtotal').textContent      = fmt(totSubtotal);
    document.getElementById('total-cost').textContent    = fmt(totCost);
    document.getElementById('expected-profit').textContent = fmt(totSubtotal - totCost);

    if (currentPanel === 'right') updateReceiptSelection();
}

// ─── Delete / edit cart items ─────────────────────────────────────────────────
function deleteCartItem(index) {
    customConfirm('Remove this item from cart?', function(){
        cart.splice(index, 1);
        if (selectedReceiptIndex >= cart.length) selectedReceiptIndex = Math.max(0, cart.length - 1);
        sessionStorage.setItem('angkatCart', JSON.stringify(cart));
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
    cart[currentEditIndex].cost     = qty * cart[currentEditIndex].purchase_price;
    cart[currentEditIndex].profit   = cart[currentEditIndex].subtotal - cart[currentEditIndex].cost;
    sessionStorage.setItem('angkatCart', JSON.stringify(cart));
    updateReceipt();
    closeEditModal();
}

// ─── Angkat info modal ────────────────────────────────────────────────────────
function openAngkatInfoModal() {
    if (cart.length === 0) { alert('Cart is empty'); return; }
    document.getElementById('angkat-info-modal').classList.add('active');
    document.getElementById('retailer-name').focus();
}

function closeAngkatInfoModal() {
    document.getElementById('angkat-info-modal').classList.remove('active');
    document.getElementById('autocomplete-dropdown').style.display = 'none';
}

function calculateTotal()     { return cart.reduce((s, i) => s + i.subtotal, 0); }
function calculateTotalCost() { return cart.reduce((s, i) => s + i.cost,     0); }

// ─── Complete angkat transaction ───────────────────────────────────────────────
function completeAngkatTransaction() {
    const retailerName    = document.getElementById('retailer-name').value.trim();
    const retailerContact = document.getElementById('retailer-contact').value.trim();
    if (!retailerName) { alert('Please enter retailer name'); return; }
    if (cart.length === 0) { alert('Cart is empty'); return; }

    const totalValue = calculateTotal();
    const totalCost  = calculateTotalCost();
    const itemCount  = cart.reduce((s, i) => s + i.quantity, 0);

    const formData = new FormData();
    formData.append('action', 'complete_angkat');
    formData.append('items', JSON.stringify(cart));
    formData.append('total_amount', totalValue);
    formData.append('total_cost', totalCost);
    formData.append('items_count', itemCount);
    formData.append('retailer_name', retailerName);
    formData.append('retailer_contact', retailerContact);

    fetch('/oro-store-demo/angkat/angkat.php', { method: 'POST', body: formData })
        .then(r => r.text().then(t => { try { return JSON.parse(t); } catch (e) { throw new Error('Invalid JSON response'); } }))
        .then(data => {
            if (data.success) {
                sessionStorage.removeItem('angkatCart');
                cart = [];
                updateReceipt();
                closeAngkatInfoModal();
                document.getElementById('retailer-name').value    = '';
                document.getElementById('retailer-contact').value = '';
                customAlert('Angkat transaction created!\nTransaction #: ' + data.transaction_number, 'success', function(){ location.reload(); });
            } else {
                alert('Error: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

// ─── Print ────────────────────────────────────────────────────────────────────
function printAngkatReceipt() {
    const retailerName    = document.getElementById('retailer-name').value.trim();
    const retailerContact = document.getElementById('retailer-contact').value.trim();
    if (!retailerName) { alert('Please enter retailer name'); return; }
    if (cart.length === 0) { alert('Cart is empty'); return; }

    const receiptData = {
        items: cart.map(i => ({
            id: i.id, name: i.name, quantity: i.quantity, price: i.price,
            purchase_price: i.purchase_price || 0,
            subtotal: i.price * i.quantity,
            cost: i.purchase_price * i.quantity
        })),
        total: calculateTotal(),
        totalCost: calculateTotalCost(),
        itemCount: cart.reduce((s, i) => s + i.quantity, 0),
        date: new Date().toLocaleString(),
        paymentMethod: 'angkat',
        angkatInfo: { retailerName, retailerContact },
        storeName: STORE_INFO.storeName,
        storeAddress: STORE_INFO.storeAddress,
        cashierName: STORE_INFO.cashierName
    };

    if (typeof BTPrint !== 'undefined') {
        BTPrint.printReceipt({
            storeName: receiptData.storeName,
            storeAddress: receiptData.storeAddress,
            title: 'ANGKAT ORDER',
            date: receiptData.date,
            cashier: receiptData.cashierName,
            items: receiptData.items,
            total: receiptData.total
        });
    }

    setTimeout(() => completeAngkatTransaction(), 500);
}

// ─── Keyboard navigation ──────────────────────────────────────────────────────
// Capture-phase listener for right-panel row navigation (arrows, Del, Insert)
document.addEventListener('keydown', function (e) {
    if (typeof currentPanel === 'undefined' || currentPanel !== 'right') return;
    const anyModalOpen =
        document.getElementById('angkat-info-modal')?.classList.contains('active') ||
        document.getElementById('quantity-modal')?.classList.contains('active') ||
        document.getElementById('edit-modal')?.classList.contains('active');
    if (anyModalOpen) return;

    const rows = document.querySelectorAll('.angkat-row');
    if (!rows.length) return;

    if (e.key === 'ArrowDown') {
        e.preventDefault(); e.stopImmediatePropagation();
        selectedReceiptIndex = Math.min(selectedReceiptIndex + 1, rows.length - 1);
        updateReceiptSelection();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault(); e.stopImmediatePropagation();
        selectedReceiptIndex = Math.max(selectedReceiptIndex - 1, 0);
        updateReceiptSelection();
    } else if (e.key === 'Delete') {
        e.preventDefault(); e.stopImmediatePropagation();
        if (rows[selectedReceiptIndex]) deleteCartItem(parseInt(rows[selectedReceiptIndex].dataset.index));
    } else if (e.key === 'Insert') {
        e.preventDefault(); e.stopImmediatePropagation();
        if (rows[selectedReceiptIndex]) openEditModal(parseInt(rows[selectedReceiptIndex].dataset.index));
    }
}, true);

// Bubble-phase listener for everything else
document.addEventListener('keydown', function (e) {
    if (e.key === 'F1') {
        e.preventDefault();
        document.getElementById('angkat-info-modal').classList.contains('active') ? printAngkatReceipt() : (cart.length > 0 ? openAngkatInfoModal() : null);
        return;
    }
    if (e.key === 'F2') { e.preventDefault(); window.open('/oro-store-demo/transactions/gcash.php', '_blank', 'width=600,height=700'); return; }
    if (e.key === 'F3') { e.preventDefault(); if (cart.length > 0) sessionStorage.setItem('deliveryCart', JSON.stringify(cart)); window.location.href = '/oro-store-demo/delivery/delivery.php'; return; }
    if (e.key === 'F4') { e.preventDefault(); window.location.href = '/oro-store-demo/credit/credit.php'; return; }
    if (e.key === 'F7') { e.preventDefault(); window.open('/oro-store-demo/transactions/card_transaction.php', '_blank', 'width=600,height=700'); return; }
    if (e.key === 'F9') { e.preventDefault(); window.location.href = '/oro-store-demo/delivery/delivery_details.php'; return; }

    if (e.key === 'F11') { e.preventDefault(); window.open('/oro-store-demo/stock/add_stock.php', '_blank', 'width=800,height=600'); return; }

    const alertOverlay = document.getElementById('custom-alert-overlay');
    if (alertOverlay && alertOverlay.style.display !== 'none') return;

    const angkatModalOpen   = document.getElementById('angkat-info-modal').classList.contains('active');
    const quantityModalOpen = document.getElementById('quantity-modal').classList.contains('active');
    const editModalOpen     = document.getElementById('edit-modal').classList.contains('active');

    if (angkatModalOpen) {
        const dropVisible = document.getElementById('autocomplete-dropdown').style.display !== 'none';
        if (e.key === 'Enter' && !dropVisible) { e.preventDefault(); completeAngkatTransaction(); }
        else if (e.key === 'Escape' && !dropVisible) { e.preventDefault(); closeAngkatInfoModal(); }
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
                openQuantityModal({
                    id: parseInt(el.dataset.id),
                    name: el.dataset.name,
                    price: parseFloat(el.dataset.price),
                    purchase_price: parseFloat(el.dataset.purchasePrice),
                    stock: parseInt(el.dataset.stock),
                    parent_product_id: el.dataset.parentProductId || null,
                    category: el.dataset.category || '',
                    brand: el.dataset.brand || '',
                    unit: el.dataset.unit || ''
                });
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            const si = document.getElementById('cashier-search');
            if (si.value !== '') { si.value = ''; si.dispatchEvent(new Event('input')); si.focus(); }
            else if (cart.length > 0) { customConfirm('Clear cart and return to cashier?', function(){ sessionStorage.removeItem('angkatCart'); window.location.href = '/oro-store-demo/cashier/cashier.php'; }); }
            else window.location.href = '/oro-store-demo/cashier/cashier.php';
        } else if (e.key === 'Home') {
            e.preventDefault();
            if (cart.length > 0) { setActivePanel('right'); selectedReceiptIndex = 0; updateReceiptSelection(); }
        }
    } else if (currentPanel === 'right') {
        if (e.key === 'Enter') { e.preventDefault(); openAngkatInfoModal(); }
        else if (e.key === 'Home') { e.preventDefault(); setActivePanel('left'); document.getElementById('cashier-search').focus(); updateProductSelection(); }
        else if (e.key === 'Escape') { e.preventDefault(); setActivePanel('left'); document.getElementById('cashier-search').focus(); updateProductSelection(); }
    }
});

console.log('✅ angkat_script.js v' + ANGKAT_SCRIPT_VERSION + ' loaded');