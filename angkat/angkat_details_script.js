let selectedCardIndex = 0;
let currentAngkatId = null;
let currentAngkatItems = [];
let currentAngkatInfo = null;

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    updateCardSelection();
    setupSearch();
});

function updateCardSelection() {
    const cards = document.querySelectorAll('.angkat-card');
    cards.forEach((card, index) => {
        card.classList.toggle('selected', index === selectedCardIndex);
    });
    if (cards[selectedCardIndex]) {
        cards[selectedCardIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}

function setupSearch() {
    const searchInput = document.getElementById('search-input');
    const cards = document.querySelectorAll('.angkat-card');
    searchInput.addEventListener('input', function() {
        const term = this.value.toLowerCase().trim();
        cards.forEach(card => {
            const tn = card.dataset.transactionNumber.toLowerCase();
            const r  = card.dataset.retailer.toLowerCase();
            card.classList.toggle('hidden', !(tn.includes(term) || r.includes(term)));
        });
        const visible = Array.from(cards).filter(c => !c.classList.contains('hidden'));
        if (visible.length > 0) { selectedCardIndex = 0; updateCardSelection(); }
    });
}

function escapeHtml(text) {
    const d = document.createElement('div');
    d.textContent = text;
    return d.innerHTML;
}

function getStatusColor(status) {
    return { active: '#ffc107', completed: '#28a745', cancelled: '#dc3545' }[status] || '#666';
}

// ─── View angkat details ───────────────────────────────────────────────────────
function viewAngkatDetails(angkatId) {
    currentAngkatId = angkatId;

    fetch(`/oro-store-demo/angkat/angkat_details.php?action=get_angkat_items&angkat_id=${angkatId}`)
        .then(r => { if (!r.ok) throw new Error('Network error'); return r.json(); })
        .then(data => {
            currentAngkatItems = data;
            if (!data.length) { alert('No items found for this angkat transaction'); return; }

            const first = data[0];
            currentAngkatInfo = {
                retailer_name:     first.retailer_name,
                retailer_contact:  first.retailer_contact,
                transaction_number: first.transaction_number,
                total_value:       parseFloat(first.total_value),
                total_cost:        parseFloat(first.total_cost),
                amount_collected:  parseFloat(first.amount_collected),
                status:            first.angkat_status
            };

            document.getElementById('modal-title').textContent = `Angkat #${first.transaction_number}`;

            // ── Retailer header ──────────────────────────────────────────────
            let html = `
                <div style="display:flex;gap:20px;align-items:flex-start;margin-bottom:14px;padding-bottom:14px;border-bottom:2px solid #e2e8f0;">
                    <div style="flex:1;">
                        <div style="font-size:16px;font-weight:700;color:#2d3748;margin-bottom:3px;">👤 ${escapeHtml(first.retailer_name)}</div>
                        ${first.retailer_contact ? `<div style="font-size:13px;color:#718096;">📞 ${escapeHtml(first.retailer_contact)}</div>` : ''}
                    </div>
                    <div style="background:${getStatusColor(first.angkat_status)};color:#fff;padding:4px 12px;border-radius:12px;font-size:12px;font-weight:700;">${first.angkat_status.toUpperCase()}</div>
                </div>`;

            // ── Settlement table ─────────────────────────────────────────────
            html += `
            <div style="overflow-x:auto;">
            <table id="settlement-table" style="width:100%;border-collapse:collapse;table-layout:auto;font-size:12px;">
            <thead>
              <tr style="background:#2d3748;color:#a0aec0;text-transform:uppercase;font-size:10px;letter-spacing:.6px;">
                <th style="padding:8px 10px;text-align:left;border-bottom:2px solid #4a5568;min-width:160px;">Product</th>
                <th style="padding:8px 6px;text-align:center;border-bottom:2px solid #4a5568;min-width:50px;">Qty</th>
                <th style="padding:8px 6px;text-align:center;border-bottom:2px solid #4a5568;min-width:60px;">Unit</th>
                <th style="padding:8px 6px;text-align:right;border-bottom:2px solid #4a5568;min-width:70px;">Price</th>
                <th colspan="2" style="padding:8px 6px;text-align:center;border-bottom:2px solid #4a5568;background:#1a365d;min-width:140px;">Whole</th>
                <th colspan="2" style="padding:8px 6px;text-align:center;border-bottom:2px solid #4a5568;background:#1c4532;min-width:140px;">Individual</th>
                <th style="padding:8px 6px;text-align:right;border-bottom:2px solid #4a5568;min-width:90px;">Sold Value</th>
              </tr>
              <tr style="background:#2d3748;color:#718096;font-size:10px;">
                <th style="border-bottom:2px solid #4a5568;"></th>
                <th style="border-bottom:2px solid #4a5568;"></th>
                <th style="border-bottom:2px solid #4a5568;"></th>
                <th style="border-bottom:2px solid #4a5568;"></th>
                <th style="padding:5px 6px;text-align:center;border-bottom:2px solid #4a5568;background:#2a4a7f;color:#90cdf4;">Sold</th>
                <th style="padding:5px 6px;text-align:center;border-bottom:2px solid #4a5568;background:#2a4a7f;color:#90cdf4;">Return</th>
                <th style="padding:5px 6px;text-align:center;border-bottom:2px solid #4a5568;background:#276749;color:#9ae6b4;">Sold</th>
                <th style="padding:5px 6px;text-align:center;border-bottom:2px solid #4a5568;background:#276749;color:#9ae6b4;">Return</th>
                <th style="border-bottom:2px solid #4a5568;"></th>
              </tr>
            </thead>
            <tbody id="settlement-tbody">`;

            data.forEach((item, idx) => {
                const qty          = item.quantity_given;
                const wholeSold    = item.quantity_sold;
                const wholeReturn  = item.quantity_returned;
                const wholePrice   = parseFloat(item.price);
                const costPrice    = parseFloat(item.cost_price);
                // Fetch units_per_pack from product data if available, fallback to item field
                const unitsPerPack = parseInt(item.units_per_pack || item.individual_pieces_per_pack || 1);
                const indPrice     = parseFloat(item.individual_price || (wholePrice / unitsPerPack));
                const maxIndUnits  = unitsPerPack; // max individual units per pack

                html += `
                <tr class="settlement-row" data-idx="${idx}"
                    data-qty="${qty}"
                    data-whole-price="${wholePrice}"
                    data-ind-price="${indPrice}"
                    data-units-per-pack="${unitsPerPack}"
                    style="border-bottom:1px solid #e2e8f0;">
                  <td style="padding:8px 10px;font-weight:600;color:#2d3748;word-break:break-word;">
                    ${escapeHtml(item.product_name)}
                    <div style="font-size:10px;font-weight:400;color:#a0aec0;margin-top:2px;">
                        Cost: ₱${costPrice.toFixed(2)}/whole
                    </div>
                  </td>
                  <td style="padding:8px 6px;text-align:center;font-weight:700;color:#4a5568;">${qty}</td>
                  <td style="padding:8px 6px;text-align:center;color:#553c9a;font-size:11px;">${escapeHtml(item.unit || 'pack')}</td>
                  <td style="padding:8px 6px;text-align:right;color:#2b6cb0;white-space:nowrap;">
                    ₱${wholePrice.toFixed(2)}<br>
                    <span style="font-size:10px;color:#a0aec0;">₱${indPrice.toFixed(2)}/unit</span>
                  </td>
                  <!-- Whole Sold -->
                  <td style="padding:6px;background:#ebf4ff;">
                    <input type="number" id="ws-${idx}" min="0" max="${qty}" value="${wholeSold}"
                           style="width:60px;padding:5px;border:2px solid #90cdf4;border-radius:4px;text-align:center;font-weight:700;font-size:12px;"
                           oninput="onWholeSoldChange(${idx})" onchange="onWholeSoldChange(${idx})">
                  </td>
                  <!-- Whole Return -->
                  <td style="padding:6px;background:#ebf4ff;">
                    <input type="number" id="wr-${idx}" min="0" max="${qty}" value="${wholeReturn}"
                           style="width:60px;padding:5px;border:2px solid #90cdf4;border-radius:4px;text-align:center;font-weight:700;font-size:12px;"
                           oninput="onWholeReturnChange(${idx})" onchange="onWholeReturnChange(${idx})">
                  </td>
                  <!-- Individual Sold -->
                  <td style="padding:6px;background:#f0fff4;">
                    <input type="number" id="is-${idx}" min="0" max="${unitsPerPack}" value="${item.individual_sold || 0}"
                           style="width:60px;padding:5px;border:2px solid #9ae6b4;border-radius:4px;text-align:center;font-weight:700;font-size:12px;"
                           oninput="onIndSoldChange(${idx})" onchange="onIndSoldChange(${idx})">
                  </td>
                  <!-- Individual Return -->
                  <td style="padding:6px;background:#f0fff4;">
                    <input type="number" id="ir-${idx}" min="0" max="${unitsPerPack}" value="${item.individual_returned || 0}"
                           style="width:60px;padding:5px;border:2px solid #9ae6b4;border-radius:4px;text-align:center;font-weight:700;font-size:12px;"
                           oninput="onIndReturnChange(${idx})" onchange="onIndReturnChange(${idx})">
                  </td>
                  <!-- Sold Value -->
                  <td style="padding:8px 6px;text-align:right;font-weight:700;color:#276749;white-space:nowrap;">
                    <span id="sv-${idx}">₱${(wholeSold * wholePrice).toFixed(2)}</span>
                  </td>
                </tr>`;
            });

            html += `</tbody>
            <tfoot>
              <tr style="background:#f7fafc;font-weight:700;border-top:2px solid #2d3748;">
                <td colspan="8" style="padding:10px;text-align:right;color:#2d3748;font-size:13px;">Total Sold Value:</td>
                <td style="padding:10px;text-align:right;color:#276749;font-size:14px;" id="total-sold-value">₱0.00</td>
              </tr>
            </tfoot>
            </table></div>`;

            document.getElementById('angkat-details-content').innerHTML = html;
            document.getElementById('angkat-details-modal').classList.add('active');

            // Initial calculation pass
            data.forEach((_, idx) => recalcRow(idx));
            recalcTotal();
        })
        .catch(err => { console.error(err); alert('Error loading angkat details.'); });
}

// ─── Row mutation helpers ──────────────────────────────────────────────────────

function getRow(idx) {
    return document.querySelector(`.settlement-row[data-idx="${idx}"]`);
}

function getVal(id) {
    const el = document.getElementById(id);
    return el ? (parseInt(el.value) || 0) : 0;
}

function setVal(id, val) {
    const el = document.getElementById(id);
    if (el) el.value = val;
}

function clamp(v, min, max) {
    return Math.max(min, Math.min(max, v));
}

// Called when Whole Sold changes
function onWholeSoldChange(idx) {
    const row = getRow(idx);
    const qty = parseInt(row.dataset.qty);
    let ws = clamp(getVal(`ws-${idx}`), 0, qty);
    setVal(`ws-${idx}`, ws);

    // Whole return fills remaining
    // But also account for individual: if ind is occupying a pack slot, reduce available
    const indSold   = getVal(`is-${idx}`);
    const indReturn = getVal(`ir-${idx}`);
    const unitsPerPack = parseInt(row.dataset.unitsPerPack);
    const indPacksUsed = (indSold + indReturn > 0) ? 1 : 0; // 1 pack slot used by individual
    const maxWhole = qty - indPacksUsed;

    ws = clamp(ws, 0, maxWhole);
    setVal(`ws-${idx}`, ws);

    const wr = clamp(maxWhole - ws, 0, maxWhole);
    setVal(`wr-${idx}`, wr);

    recalcRow(idx);
    recalcTotal();
}

// Called when Whole Return changes
function onWholeReturnChange(idx) {
    const row = getRow(idx);
    const qty = parseInt(row.dataset.qty);
    const unitsPerPack = parseInt(row.dataset.unitsPerPack);
    const indSold   = getVal(`is-${idx}`);
    const indReturn = getVal(`ir-${idx}`);
    const indPacksUsed = (indSold + indReturn > 0) ? 1 : 0;
    const maxWhole = qty - indPacksUsed;

    let wr = clamp(getVal(`wr-${idx}`), 0, maxWhole);
    setVal(`wr-${idx}`, wr);

    const ws = clamp(maxWhole - wr, 0, maxWhole);
    setVal(`ws-${idx}`, ws);

    recalcRow(idx);
    recalcTotal();
}

// Called when Individual Sold changes
function onIndSoldChange(idx) {
    const row = getRow(idx);
    const qty = parseInt(row.dataset.qty);
    const unitsPerPack = parseInt(row.dataset.unitsPerPack);

    let is = clamp(getVal(`is-${idx}`), 0, unitsPerPack);
    setVal(`is-${idx}`, is);

    // Individual return fills remaining individual units
    const ir = clamp(unitsPerPack - is, 0, unitsPerPack);
    setVal(`ir-${idx}`, ir);

    // If any individual units exist, that pack is "used" — reduce whole available by 1
    const indPacksUsed = (is + ir > 0) ? 1 : 0;
    const maxWhole = qty - indPacksUsed;

    // Adjust whole sold/return to not exceed new max
    let ws = clamp(getVal(`ws-${idx}`), 0, maxWhole);
    let wr = clamp(maxWhole - ws, 0, maxWhole);
    setVal(`ws-${idx}`, ws);
    setVal(`wr-${idx}`, wr);

    // Lock individual inputs if whole already maxed out AND no ind pack slot
    updateIndLock(idx);

    recalcRow(idx);
    recalcTotal();
}

// Called when Individual Return changes
function onIndReturnChange(idx) {
    const row = getRow(idx);
    const qty = parseInt(row.dataset.qty);
    const unitsPerPack = parseInt(row.dataset.unitsPerPack);

    let ir = clamp(getVal(`ir-${idx}`), 0, unitsPerPack);
    setVal(`ir-${idx}`, ir);

    const is = clamp(unitsPerPack - ir, 0, unitsPerPack);
    setVal(`is-${idx}`, is);

    const indPacksUsed = (is + ir > 0) ? 1 : 0;
    const maxWhole = qty - indPacksUsed;

    let ws = clamp(getVal(`ws-${idx}`), 0, maxWhole);
    let wr = clamp(maxWhole - ws, 0, maxWhole);
    setVal(`ws-${idx}`, ws);
    setVal(`wr-${idx}`, wr);

    updateIndLock(idx);

    recalcRow(idx);
    recalcTotal();
}

// Lock individual inputs when whole packs are fully accounted for without ind slot
function updateIndLock(idx) {
    const row = getRow(idx);
    const qty = parseInt(row.dataset.qty);
    const ws = getVal(`ws-${idx}`);
    const wr = getVal(`wr-${idx}`);
    const indSold   = getVal(`is-${idx}`);
    const indReturn = getVal(`ir-${idx}`);
    const indPacksUsed = (indSold + indReturn > 0) ? 1 : 0;
    const maxWhole = qty - indPacksUsed;

    // If whole already fills all slots and no individual pack is reserved, lock ind inputs
    const allWholeFilled = (ws + wr >= qty) && indPacksUsed === 0;

    ['is', 'ir'].forEach(prefix => {
        const el = document.getElementById(`${prefix}-${idx}`);
        if (el) {
            el.disabled = allWholeFilled;
            el.style.opacity = allWholeFilled ? '0.4' : '1';
            el.style.cursor  = allWholeFilled ? 'not-allowed' : 'text';
        }
    });
}

// Recalculate sold value for one row
function recalcRow(idx) {
    const row = getRow(idx);
    if (!row) return;
    const wholePrice   = parseFloat(row.dataset.wholePrice);
    const indPrice     = parseFloat(row.dataset.indPrice);
    const ws = getVal(`ws-${idx}`);
    const is = getVal(`is-${idx}`);
    const soldValue = (ws * wholePrice) + (is * indPrice);
    const el = document.getElementById(`sv-${idx}`);
    if (el) el.textContent = '₱' + soldValue.toFixed(2);
}

// Sum all row sold values
function recalcTotal() {
    let total = 0;
    document.querySelectorAll('.settlement-row').forEach(row => {
        const idx = parseInt(row.dataset.idx);
        const wholePrice = parseFloat(row.dataset.wholePrice);
        const indPrice   = parseFloat(row.dataset.indPrice);
        const ws = getVal(`ws-${idx}`);
        const is = getVal(`is-${idx}`);
        total += (ws * wholePrice) + (is * indPrice);
    });
    const el = document.getElementById('total-sold-value');
    if (el) el.textContent = '₱' + total.toFixed(2);
}

// ─── Save settlement ───────────────────────────────────────────────────────────
function saveSettlement() {
    if (!currentAngkatId) return;

    const rows = document.querySelectorAll('.settlement-row');
    const settlements = [];
    rows.forEach(row => {
        const idx = parseInt(row.dataset.idx);
        const item = currentAngkatItems[idx];
        settlements.push({
            item_id:          item.id,
            product_id:       item.product_id,
            whole_sold:       getVal(`ws-${idx}`),
            whole_returned:   getVal(`wr-${idx}`),
            ind_sold:         getVal(`is-${idx}`),
            ind_returned:     getVal(`ir-${idx}`),
            units_per_pack:   parseInt(row.dataset.unitsPerPack)
        });
    });

    const formData = new FormData();
    formData.append('action', 'save_settlement');
    formData.append('angkat_id', currentAngkatId);
    formData.append('settlements', JSON.stringify(settlements));

fetch('/oro-store-demo/angkat/angkat_details.php', { method: 'POST', body: formData })
    .then(r => r.text())
    .then(text => {
        console.log('Raw response:', text);
        let data;
        try { data = JSON.parse(text); } 
        catch(e) { alert('PHP Error:\n' + text.replace(/<[^>]+>/g, '')); return; }
            if (data.success) {
                alert('✅ Settlement saved successfully!');
                closeDetailsModal();
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

// ─── Modal helpers ─────────────────────────────────────────────────────────────
function closeDetailsModal() {
    document.getElementById('angkat-details-modal').classList.remove('active');
    currentAngkatId   = null;
    currentAngkatItems = [];
}

function showPaymentModal() {
    if (!currentAngkatInfo) return;
    const balance = currentAngkatInfo.total_value - currentAngkatInfo.amount_collected;
    document.getElementById('payment-total-value').textContent = '₱' + currentAngkatInfo.total_value.toFixed(2);
    document.getElementById('payment-collected').textContent   = '₱' + currentAngkatInfo.amount_collected.toFixed(2);
    document.getElementById('payment-balance').textContent     = '₱' + balance.toFixed(2);
    document.getElementById('payment-amount').value = '';
    document.getElementById('payment-amount').max   = balance;
    document.getElementById('payment-reference').value = '';
    document.getElementById('payment-notes').value     = '';
    document.getElementById('payment-modal').classList.add('active');
    document.getElementById('payment-amount').focus();
}

function closePaymentModal() {
    document.getElementById('payment-modal').classList.remove('active');
}

function confirmPayment() {
    const amount        = parseFloat(document.getElementById('payment-amount').value);
    const paymentMethod = document.getElementById('payment-method').value;
    const reference     = document.getElementById('payment-reference').value.trim();
    const notes         = document.getElementById('payment-notes').value.trim();

    if (!amount || amount <= 0) { alert('Please enter a valid amount'); return; }
    const balance = currentAngkatInfo.total_value - currentAngkatInfo.amount_collected;
    if (amount > balance) { alert('Amount cannot exceed balance due (₱' + balance.toFixed(2) + ')'); return; }

    const fd = new FormData();
    fd.append('action', 'record_payment');
    fd.append('angkat_id', currentAngkatId);
    fd.append('amount', amount);
    fd.append('payment_method', paymentMethod);
    if (reference) fd.append('reference', reference);
    if (notes)     fd.append('notes', notes);

    fetch('/oro-store-demo/angkat/angkat_details.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('Payment recorded! Amount: ₱' + amount.toFixed(2));
                closePaymentModal();
                closeDetailsModal();
                location.reload();
            } else { alert('Error: ' + data.error); }
        })
        .catch(err => alert('Error: ' + err));
}

function showCompleteConfirmation() {
    document.getElementById('complete-modal').classList.add('active');
}

function closeCompleteModal() {
    document.getElementById('complete-modal').classList.remove('active');
}

function confirmComplete() {
    const fd = new FormData();
    fd.append('action', 'mark_complete');
    fd.append('angkat_id', currentAngkatId);

    fetch('/oro-store-demo/angkat/angkat_details.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('Angkat transaction marked as complete!');
                closeCompleteModal();
                closeDetailsModal();
                location.reload();
            } else { alert('Error: ' + data.error); }
        })
        .catch(err => alert('Error: ' + err));
}

// ─── Card click / double-click ─────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.angkat-card').forEach(card => {
        let clicks = 0, timer = null;
        card.addEventListener('click', function () {
            clicks++;
            if (clicks === 1) {
                timer = setTimeout(() => { clicks = 0; }, 300);
            } else if (clicks === 2) {
                clearTimeout(timer); clicks = 0;
                viewAngkatDetails(parseInt(this.dataset.id));
            }
        });
    });
});

// ─── Keyboard navigation ───────────────────────────────────────────────────────
document.addEventListener('keydown', function (e) {
    const paymentOpen  = document.getElementById('payment-modal').classList.contains('active');
    const completeOpen = document.getElementById('complete-modal').classList.contains('active');
    const detailsOpen  = document.getElementById('angkat-details-modal').classList.contains('active');

    if (e.key === 'F8') { e.preventDefault(); window.location.href = '/oro-store-demo/angkat/angkat.php'; return; }
    if (e.key === 'F3') { e.preventDefault(); window.location.href = '/oro-store-demo/cashier/cashier.php'; return; }

    if (paymentOpen) {
        if (e.key === 'Enter')  { e.preventDefault(); confirmPayment(); }
        else if (e.key === 'Escape') { e.preventDefault(); closePaymentModal(); }
        return;
    }
    if (completeOpen) {
        if (e.key === 'Enter')  { e.preventDefault(); confirmComplete(); }
        else if (e.key === 'Escape') { e.preventDefault(); closeCompleteModal(); }
        return;
    }
    if (detailsOpen) {
        if (e.key === 'Escape') { e.preventDefault(); closeDetailsModal(); }
        return;
    }

    const cards = document.querySelectorAll('.angkat-card:not(.hidden)');
    if (!cards.length) return;

    if (e.key === 'ArrowDown' || e.key === 'ArrowRight') {
        e.preventDefault();
        selectedCardIndex = Math.min(selectedCardIndex + 1, cards.length - 1);
        updateCardSelection();
    } else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') {
        e.preventDefault();
        selectedCardIndex = Math.max(selectedCardIndex - 1, 0);
        updateCardSelection();
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (cards[selectedCardIndex]) viewAngkatDetails(parseInt(cards[selectedCardIndex].dataset.id));
    } else if (e.key === 'Escape') {
        e.preventDefault();
        const si = document.getElementById('search-input');
        if (si.value) { si.value = ''; si.dispatchEvent(new Event('input')); si.focus(); }
    }
});