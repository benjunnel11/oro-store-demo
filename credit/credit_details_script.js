let selectedCardIndex = 0;
let selectedCreditIndex = 0;
let currentCustomerId = null;
let currentCustomerCredits = [];
let selectedCredits = new Set();
let isModalOpen = false;
let currentCreditForPartial = null;

document.addEventListener('DOMContentLoaded', function() {
    updateCardSelection();
});

function selectCard(index) {
    selectedCardIndex = index;
    updateCardSelection();
}

function updateCardSelection() {
    const cards = document.querySelectorAll('.customer-card');
    cards.forEach((card, index) => {
        card.classList.toggle('selected', index === selectedCardIndex);
    });
    
    if (cards[selectedCardIndex]) {
        cards[selectedCardIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}

function viewCustomerCredits(customerId) {
    currentCustomerId = customerId;
    selectedCredits.clear();
    
    fetch(`/oro-store-demo/credit/credit_details.php?action=get_customer_credits&customer_id=${customerId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            currentCustomerCredits = data;
            
            if (data.length === 0) {
                alert('No credits found for this customer');
                return;
            }
            
            const firstCredit = data[0];
            const customerName = escapeHtml(firstCredit.customer_name || '');
            
            document.getElementById('modal-title').textContent = `Credits - ${customerName}`;
            
            let html = '<div style="margin-bottom: 15px;">';
            html += `<div style="font-size: 16px; margin-bottom: 5px;"><strong>Customer:</strong> ${customerName}</div>`;
            html += `<div style="font-size: 14px; color: #666; margin-bottom: 5px;"><strong>Contact:</strong> ${escapeHtml(firstCredit.customer_contact || '')}</div>`;
            if (firstCredit.customer_address) {
                html += `<div style="font-size: 14px; color: #666;"><strong>Address:</strong> ${escapeHtml(firstCredit.customer_address)}</div>`;
            }
            html += '</div>';
            
            html += '<div style="border-top: 2px solid #333; padding-top: 15px;">';
            
            data.forEach((credit, index) => {
                const statusClass = credit.status;
                const isPaid = credit.status === 'paid';
                const transactionNumber = escapeHtml(credit.transaction_number || '');
                
                html += `
                    <div class="credit-item ${isPaid ? 'paid' : ''}" data-index="${index}" data-credit-id="${credit.id}">
                        <div style="display: flex; align-items: center; flex: 1;">
                            ${!isPaid ? '<input type="checkbox" class="credit-checkbox" onclick="toggleCreditSelection(event, ' + index + ')">' : '<div style="width: 35px;"></div>'}
                            <div class="credit-item-left">
                                <div class="credit-transaction">#${transactionNumber}</div>
                                <div class="credit-date">${formatDate(credit.transaction_date)}</div>
                                ${credit.status === 'partial' ? `<div style="font-size: 12px; color: #666; margin-top: 3px;">Paid: ₱${parseFloat(credit.amount_paid).toFixed(2)}</div>` : ''}
                            </div>
                        </div>
                        <div class="credit-item-right">
                            <div class="credit-amount">₱${parseFloat(credit.total_amount).toFixed(2)}</div>
                            <div class="credit-status status-${statusClass}">${statusClass.toUpperCase()}</div>
                            ${!isPaid ? `<div class="credit-due">Due: ₱${parseFloat(credit.amount_due).toFixed(2)}</div>` : ''}
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            
            document.getElementById('credits-content').innerHTML = html;
            updateCreditsSummary();
            
            document.getElementById('customer-credits-modal').classList.add('active');
            isModalOpen = true;
            selectedCreditIndex = 0;
            updateCreditSelection();
        })
        .catch(error => {
            console.error('Error loading credits:', error);
            alert('Error loading credits. Please try again.');
        });
}

function viewReceiptDetails() {
    const items = document.querySelectorAll('.credit-item');
    if (!items[selectedCreditIndex]) return;
    
    const credit = currentCustomerCredits[selectedCreditIndex];
    
    fetch(`/oro-store-demo/credit/credit_details.php?action=get_credit_details&credit_id=${credit.id}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert('Error: ' + data.error);
                return;
            }
            
            displayReceiptModal(data.credit, data.items);
        })
        .catch(error => {
            console.error('Error loading receipt:', error);
            alert('Error loading receipt details.');
        });
}

function displayReceiptModal(credit, items) {
    const modal = document.getElementById('receipt-view-modal');
    const content = document.getElementById('receipt-view-content');
    
    let html = `
        <div class="receipt-header">
            <div style="font-size: 24px; font-weight: bold;">ORO STORE</div>
            <div style="font-size: 14px; color: #666;">Credit Transaction Receipt</div>
        </div>
        
        <div class="receipt-info">
            <div class="receipt-info-row">
                <span>Transaction #:</span>
                <span><strong>${escapeHtml(credit.transaction_number)}</strong></span>
            </div>
            <div class="receipt-info-row">
                <span>Date:</span>
                <span>${formatDate(credit.transaction_date)}</span>
            </div>
            <div class="receipt-info-row">
                <span>Customer:</span>
                <span>${escapeHtml(credit.customer_name)}</span>
            </div>
            <div class="receipt-info-row">
                <span>Contact:</span>
                <span>${escapeHtml(credit.customer_contact)}</span>
            </div>
        </div>
        
        <div class="receipt-items">
            <div style="font-weight: bold; border-bottom: 2px solid #333; padding-bottom: 8px; margin-bottom: 10px;">ITEMS</div>
    `;
    
    items.forEach(item => {
        const itemTotal = parseFloat(item.quantity) * parseFloat(item.price);
        html += `
            <div class="receipt-item-row">
                <div>
                    <div style="font-weight: bold;">${escapeHtml(item.product_name)}</div>
                    <div style="font-size: 12px; color: #666;">${item.quantity} × ₱${parseFloat(item.price).toFixed(2)}</div>
                </div>
                <div style="font-weight: bold;">₱${itemTotal.toFixed(2)}</div>
            </div>
        `;
    });
    
    html += `
        </div>
        
        <div class="receipt-totals">
            <div class="receipt-total-row">
                <span>Total Amount:</span>
                <span style="font-weight: bold;">₱${parseFloat(credit.total_amount).toFixed(2)}</span>
            </div>
            <div class="receipt-total-row">
                <span>Amount Paid:</span>
                <span style="color: #28a745;">₱${parseFloat(credit.amount_paid).toFixed(2)}</span>
            </div>
            <div class="receipt-total-row" style="font-size: 18px; color: #dc3545;">
                <span>Amount Due:</span>
                <span style="font-weight: bold;">₱${parseFloat(credit.amount_due).toFixed(2)}</span>
            </div>
            <div class="receipt-total-row">
                <span>Status:</span>
                <span class="status-${credit.status}" style="font-weight: bold;">${credit.status.toUpperCase()}</span>
            </div>
        </div>
    `;
    
    content.innerHTML = html;
    modal.classList.add('active');
}

function closeReceiptModal() {
    document.getElementById('receipt-view-modal').classList.remove('active');
}

function reprintReceipt() {
    const items = document.querySelectorAll('.credit-item');
    if (!items[selectedCreditIndex]) return;
    
    const credit = currentCustomerCredits[selectedCreditIndex];
    
    fetch(`/oro-store-demo/credit/credit_details.php?action=get_credit_details&credit_id=${credit.id}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                alert('Error: ' + data.error);
                return;
            }
            
            openPrintWindow(data.credit, data.items);
        })
        .catch(error => {
            console.error('Error loading receipt:', error);
            alert('Error loading receipt for printing.');
        });
}

function openPrintWindow(credit, items) {
    const printWindow = window.open('', '_blank', 'width=800,height=600');
    
    const receiptData = {
        transactionId: credit.transaction_number,
        date: formatDate(credit.transaction_date),
        customerName: credit.customer_name,
        customerContact: credit.customer_contact,
        customerAddress: credit.customer_address,
        items: items.map(item => ({
            name: item.product_name,
            quantity: parseFloat(item.quantity),
            price: parseFloat(item.price),
            subtotal: parseFloat(item.quantity) * parseFloat(item.price)
        })),
        itemCount: items.length,
        total: parseFloat(credit.total_amount),
        amountPaid: parseFloat(credit.amount_paid),
        amountDue: parseFloat(credit.amount_due),
        status: credit.status,
        paymentMethod: 'credit',
        isReprint: true
    };
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Reprint Receipt - #${credit.transaction_number}</title>
            <link rel="stylesheet" href="print_receipt.css">
            <style>
                .reprint-indicator {
                    background-color: #ffc107;
                    color: #000;
                    padding: 8px;
                    text-align: center;
                    font-weight: bold;
                    margin-bottom: 15px;
                    border-radius: 4px;
                }
            </style>
        </head>
        <body>
            <div class="receipt-container">
                <div id="receipt-content"></div>
            </div>
            <div class="no-print" style="text-align: center; margin-top: 20px;">
                <button onclick="window.print()" style="padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px;">Print Receipt</button>
                <button onclick="window.close()" style="padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; margin-left: 10px;">Close</button>
            </div>
        </body>
        </html>
    `);
    
    printWindow.document.close();
    
    setTimeout(() => {
        const content = printWindow.document.getElementById('receipt-content');
        content.innerHTML = generateReceiptHTML(receiptData);
        
        setTimeout(() => {
            printWindow.print();
        }, 300);
    }, 100);
}

function generateReceiptHTML(data) {
    return `
        <div class="receipt-header">
            <div class="store-logo">🏪</div>
            <div class="store-name">ORO STORE</div>
            <div class="store-tagline">Your Trusted Partner</div>
        </div>
        
        <div class="reprint-indicator">
            ⚠️ REPRINT - CREDIT TRANSACTION
        </div>
        
        <div class="transaction-id">#${data.transactionId}</div>
        
        <div class="transaction-type type-credit">CREDIT RECEIPT</div>
        
        <div class="transaction-info">
            <div><span class="info-label">Date:</span> <span class="info-value">${data.date}</span></div>
            <div><span class="info-label">Customer:</span> <span class="info-value">${data.customerName}</span></div>
            <div><span class="info-label">Contact:</span> <span class="info-value">${data.customerContact}</span></div>
            <div><span class="info-label">Items:</span> <span class="info-value">${data.itemCount}</span></div>
            <div><span class="info-label">Status:</span> <span class="info-value">${data.status.toUpperCase()}</span></div>
        </div>
        
        <div class="items-section">
            <div class="items-header">ITEMS</div>
            ${data.items.map(item => `
                <div class="item-row">
                    <div class="item-name">${item.name}</div>
                    <div class="item-details">
                        <span class="item-qty-price">${item.quantity} × ₱${item.price.toFixed(2)}</span>
                        <span style="font-weight: bold;">₱${item.subtotal.toFixed(2)}</span>
                    </div>
                </div>
            `).join('')}
        </div>
        
        <div class="totals-section">
            <div class="total-row subtotal">
                <span>Total Amount:</span>
                <span>₱${data.total.toFixed(2)}</span>
            </div>
            <div class="total-row">
                <span>Amount Paid:</span>
                <span style="color: #28a745;">₱${data.amountPaid.toFixed(2)}</span>
            </div>
            <div class="total-row grand-total" style="color: #dc3545;">
                <span>AMOUNT DUE:</span>
                <span>₱${data.amountDue.toFixed(2)}</span>
            </div>
        </div>
        
        <div class="receipt-footer">
            <div class="thank-you">★ THANK YOU! ★</div>
            <div class="footer-message">Please come again</div>
            <div style="margin-top: 12px; font-size: 9px; color: #999;">
                This is a reprinted copy of your credit receipt
            </div>
        </div>
    `;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateString) {
    const date = new Date(dateString);
    const options = { year: 'numeric', month: 'short', day: 'numeric' };
    return date.toLocaleDateString('en-US', options);
}

function toggleCreditSelection(event, index) {
    event.stopPropagation();
    
    const credit = currentCustomerCredits[index];
    
    if (credit.status === 'paid') {
        return;
    }
    
    if (selectedCredits.has(credit.id)) {
        selectedCredits.delete(credit.id);
    } else {
        selectedCredits.add(credit.id);
    }
    
    updateCreditsSummary();
    updateVisualSelection();
}

function updateCreditSelection() {
    const items = document.querySelectorAll('.credit-item');
    items.forEach((item, index) => {
        item.classList.toggle('selected-credit', index === selectedCreditIndex);
    });
    
    if (items[selectedCreditIndex]) {
        items[selectedCreditIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}

function updateVisualSelection() {
    const items = document.querySelectorAll('.credit-item');
    items.forEach(item => {
        const creditId = parseInt(item.dataset.creditId);
        const checkbox = item.querySelector('.credit-checkbox');
        if (checkbox) {
            checkbox.checked = selectedCredits.has(creditId);
        }
    });
}

function updateCreditsSummary() {
    let totalCredits = 0;
    let totalDue = 0;
    let selectedCount = 0;
    let selectedDue = 0;
    
    currentCustomerCredits.forEach(credit => {
        totalCredits++;
        totalDue += parseFloat(credit.amount_due);
        
        if (selectedCredits.has(credit.id)) {
            selectedCount++;
            selectedDue += parseFloat(credit.amount_due);
        }
    });
    
    const summaryHtml = `
        <div class="summary-row">
            <span>Total Credits:</span>
            <span>${totalCredits}</span>
        </div>
        <div class="summary-row">
            <span>Total Due:</span>
            <span>₱${totalDue.toFixed(2)}</span>
        </div>
        <div class="summary-row" style="margin-top: 10px; padding-top: 10px; border-top: 2px solid rgba(255,255,255,0.3);">
            <span>Selected Credits:</span>
            <span>${selectedCount}</span>
        </div>
        <div class="summary-row grand-total">
            <span>SELECTED TOTAL:</span>
            <span>₱${selectedDue.toFixed(2)}</span>
        </div>
    `;
    
    document.getElementById('credits-summary').innerHTML = summaryHtml;
}

function closeCreditsModal() {
    document.getElementById('customer-credits-modal').classList.remove('active');
    isModalOpen = false;
    currentCustomerId = null;
    currentCustomerCredits = [];
    selectedCredits.clear();
}

function markSelectedAsPaid() {
    if (selectedCredits.size === 0) {
        alert('No credits selected');
        return;
    }
    
    const selectedDue = Array.from(selectedCredits).reduce((sum, creditId) => {
        const credit = currentCustomerCredits.find(c => c.id === creditId);
        return sum + (credit ? parseFloat(credit.amount_due) : 0);
    }, 0);
    
    if (!confirm(`Mark ${selectedCredits.size} credit(s) as paid?\nTotal: ₱${selectedDue.toFixed(2)}`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'mark_paid');
    formData.append('credit_ids', JSON.stringify(Array.from(selectedCredits)));
    
    fetch('/oro-store-demo/credit/credit_details.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Credits marked as paid successfully!');
            closeCreditsModal();
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        alert('Error: ' + error);
    });
}

function openPartialPaymentModal() {
    const items = document.querySelectorAll('.credit-item');
    if (!items[selectedCreditIndex]) return;
    
    const credit = currentCustomerCredits[selectedCreditIndex];
    
    if (credit.status === 'paid') {
        alert('This credit is already fully paid');
        return;
    }
    
    currentCreditForPartial = credit;
    
    document.getElementById('partial-credit-info').textContent = `Transaction #${credit.transaction_number}`;
    document.getElementById('partial-balance').textContent = `₱${parseFloat(credit.amount_due).toFixed(2)}`;
    document.getElementById('payment-amount-input').value = '';
    document.getElementById('payment-amount-input').max = credit.amount_due;
    
    document.getElementById('partial-payment-modal').classList.add('active');
    document.getElementById('payment-amount-input').focus();
}

function closePartialPaymentModal() {
    document.getElementById('partial-payment-modal').classList.remove('active');
    currentCreditForPartial = null;
}

function confirmPartialPayment() {
    const paymentAmount = parseFloat(document.getElementById('payment-amount-input').value);
    
    if (!paymentAmount || paymentAmount <= 0) {
        alert('Please enter a valid payment amount');
        return;
    }
    
    if (paymentAmount > currentCreditForPartial.amount_due) {
        alert('Payment amount exceeds remaining balance');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'partial_payment');
    formData.append('credit_id', currentCreditForPartial.id);
    formData.append('payment_amount', paymentAmount);
    
    fetch('/oro-store-demo/credit/credit_details.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Payment recorded successfully!');
            closePartialPaymentModal();
            viewCustomerCredits(currentCustomerId);
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        alert('Error: ' + error);
    });
}

// Keyboard navigation
document.addEventListener('keydown', function(e) {
    const receiptModalOpen = document.getElementById('receipt-view-modal')?.classList.contains('active');
    const partialPaymentModalOpen = document.getElementById('partial-payment-modal').classList.contains('active');
    const creditsModalOpen = document.getElementById('customer-credits-modal').classList.contains('active');
    
    // Receipt view modal
    if (receiptModalOpen) {
        if (e.key === 'Escape') {
            e.preventDefault();
            closeReceiptModal();
        } else if (e.key === 'F1') {
            e.preventDefault();
            closeReceiptModal();
            reprintReceipt();
        }
        return;
    }
    
    // Partial payment modal
    if (partialPaymentModalOpen) {
        if (e.key === 'Enter') {
            e.preventDefault();
            confirmPartialPayment();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closePartialPaymentModal();
        }
        return;
    }
    
    // Customer credits modal
    if (creditsModalOpen) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectedCreditIndex = Math.min(selectedCreditIndex + 1, currentCustomerCredits.length - 1);
            updateCreditSelection();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedCreditIndex = Math.max(selectedCreditIndex - 1, 0);
            updateCreditSelection();
        } else if (e.key === ' ') {
            e.preventDefault();
            const credit = currentCustomerCredits[selectedCreditIndex];
            if (credit && credit.status !== 'paid') {
                if (selectedCredits.has(credit.id)) {
                    selectedCredits.delete(credit.id);
                } else {
                    selectedCredits.add(credit.id);
                }
                updateCreditsSummary();
                updateVisualSelection();
            }
        } else if (e.key === 'p' || e.key === 'P') {
            e.preventDefault();
            openPartialPaymentModal();
        } else if (e.key === 'v' || e.key === 'V') {
            e.preventDefault();
            viewReceiptDetails();
        } else if (e.key === 'F1') {
            e.preventDefault();
            reprintReceipt();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            markSelectedAsPaid();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closeCreditsModal();
        }
        return;
    }
    
    // Main page navigation
    const cards = document.querySelectorAll('.customer-card');
    const totalCards = cards.length;
    
    if (totalCards === 0) return;
    
    if (e.key === 'ArrowDown' || e.key === 'ArrowRight') {
        e.preventDefault();
        selectedCardIndex = Math.min(selectedCardIndex + 1, totalCards - 1);
        updateCardSelection();
    } else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') {
        e.preventDefault();
        selectedCardIndex = Math.max(selectedCardIndex - 1, 0);
        updateCardSelection();
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (cards[selectedCardIndex]) {
            const customerId = parseInt(cards[selectedCardIndex].dataset.id);
            viewCustomerCredits(customerId);
        }
    } else if (e.key === 'Escape') {
        e.preventDefault();
        location.reload();
    }
});