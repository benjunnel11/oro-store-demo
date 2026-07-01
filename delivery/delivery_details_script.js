let selectedCardIndex = 0;
let selectedProductIndex = 0;
let currentDeliveryId = null;
let currentDeliveryItems = [];
let currentItemId = null;
let isModalOpen = false;
let multiSelectedDeliveries = new Map(); // Store multiple selected deliveries

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    updateCardSelection();
});

// Select card
function selectCard(index) {
    selectedCardIndex = index;
    updateCardSelection();
}

// Update card selection highlight
function updateCardSelection() {
    const cards = document.querySelectorAll('.delivery-card');
    cards.forEach((card, index) => {
        card.classList.toggle('selected', index === selectedCardIndex);
    });
    
    if (cards[selectedCardIndex]) {
        cards[selectedCardIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}

// Toggle multi-selection for currently selected card
function toggleMultiSelection() {
    const cards = document.querySelectorAll('.delivery-card');
    const card = cards[selectedCardIndex];
    
    if (!card) return;
    
    const deliveryId = parseInt(card.dataset.id);
    const status = card.dataset.status;
    const amount = parseFloat(card.dataset.amount);
    const transactionNumber = card.dataset.transactionNumber;
    const recipient = card.dataset.recipient;
    
    // Toggle selection
    if (multiSelectedDeliveries.has(deliveryId)) {
        // Deselect
        multiSelectedDeliveries.delete(deliveryId);
        card.classList.remove('multi-selected');
    } else {
        // Select
        multiSelectedDeliveries.set(deliveryId, {
            id: deliveryId,
            status: status,
            amount: amount,
            transactionNumber: transactionNumber,
            recipient: recipient,
            index: selectedCardIndex
        });
        card.classList.add('multi-selected');
    }
    
    updateSidePanel();
}

// Update side panel with selected deliveries
function updateSidePanel() {
    const sidePanel = document.getElementById('side-panel');
    const listContainer = document.getElementById('selected-deliveries-list');
    const selectionCount = document.getElementById('selection-count');
    const totalDeliveriesCount = document.getElementById('total-deliveries-count');
    const processableCount = document.getElementById('processable-count');
    const totalAmount = document.getElementById('total-amount');
    const processBtn = document.getElementById('process-btn');
    
    const count = multiSelectedDeliveries.size;
    
    if (count === 0) {
        sidePanel.classList.remove('active');
        return;
    }
    
    sidePanel.classList.add('active');
    selectionCount.textContent = count;
    
    // Build list HTML
    let html = '';
    let total = 0;
    let processableItems = 0;
    
    multiSelectedDeliveries.forEach((delivery, id) => {
        const canProcess = delivery.status === 'pending' || delivery.status === 'lacking';
        if (canProcess) processableItems++;
        total += delivery.amount;
        
        html += `
            <div class="selected-card-mini ${delivery.status === 'completed' ? 'completed' : ''}">
                <button class="remove-btn" onclick="removeSelection(${id})">&times;</button>
                <div style="font-weight: bold; margin-bottom: 5px;">#${delivery.transactionNumber}</div>
                <div style="font-size: 14px; color: #666; margin-bottom: 5px;">${delivery.recipient}</div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span class="status-badge status-${delivery.status}" style="font-size: 10px; padding: 2px 6px;">
                        ${delivery.status.toUpperCase()}
                    </span>
                    <span style="font-weight: bold;">₱${delivery.amount.toFixed(2)}</span>
                </div>
                ${delivery.status === 'completed' ? '<div style="color: #6c757d; font-size: 11px; margin-top: 5px;">⚠️ Cannot process (completed)</div>' : ''}
            </div>
        `;
    });
    
    listContainer.innerHTML = html;
    totalDeliveriesCount.textContent = count;
    processableCount.textContent = processableItems;
    totalAmount.textContent = '₱' + total.toFixed(2);
    
    // Enable/disable process button
    if (processableItems > 0) {
        processBtn.disabled = false;
        processBtn.textContent = `Process ${processableItems} Deliver${processableItems > 1 ? 'ies' : 'y'}`;
    } else {
        processBtn.disabled = true;
        processBtn.textContent = 'No Processable Deliveries';
    }
}

// Remove selection
function removeSelection(deliveryId) {
    multiSelectedDeliveries.delete(deliveryId);
    
    const cards = document.querySelectorAll('.delivery-card');
    cards.forEach(card => {
        if (parseInt(card.dataset.id) === deliveryId) {
            card.classList.remove('multi-selected');
        }
    });
    
    updateSidePanel();
}

// Clear all selections
function clearAllSelections() {
    multiSelectedDeliveries.clear();
    
    const cards = document.querySelectorAll('.delivery-card');
    cards.forEach(card => {
        card.classList.remove('multi-selected');
    });
    
    updateSidePanel();
}

// Close side panel
function closeSidePanel() {
    document.getElementById('side-panel').classList.remove('active');
}

// Process selected deliveries
function processSelectedDeliveries() {
    const processableDeliveries = Array.from(multiSelectedDeliveries.values())
        .filter(d => d.status === 'pending' || d.status === 'lacking');
    
    if (processableDeliveries.length === 0) {
        alert('No processable deliveries selected');
        return;
    }
    
    if (!confirm(`Process ${processableDeliveries.length} deliver${processableDeliveries.length > 1 ? 'ies' : 'y'} as complete?`)) {
        return;
    }
    
    // Process each delivery
    const promises = processableDeliveries.map(delivery => {
        const formData = new FormData();
        formData.append('action', 'mark_complete');
        formData.append('delivery_id', delivery.id);
        
        return fetch('/oro-store-demo/delivery/delivery_details.php', {
            method: 'POST',
            body: formData
        }).then(response => response.json());
    });
    
    Promise.all(promises)
        .then(results => {
            const successful = results.filter(r => r.success).length;
            const failed = results.filter(r => !r.success).length;
            
            if (failed > 0) {
                alert(`Processed ${successful} deliveries. ${failed} failed.`);
            } else {
                alert(`Successfully processed ${successful} deliver${successful > 1 ? 'ies' : 'y'}!`);
            }
            
            clearAllSelections();
            location.reload();
        })
        .catch(error => {
            alert('Error processing deliveries: ' + error);
        });
}

// View delivery details
function viewDeliveryDetails(deliveryId) {
    currentDeliveryId = deliveryId;
    
    fetch(`/oro-store-demo/delivery/delivery_details.php?action=get_delivery&delivery_id=${deliveryId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            currentDeliveryItems = data;
            
            if (data.length === 0) {
                alert('No items found for this delivery');
                return;
            }
            
            const firstItem = data[0];
            const transactionNumber = escapeHtml(firstItem.transaction_number || '');
            const transactionId = firstItem.transaction_id;
            const recipientName = escapeHtml(firstItem.recipient_name || '');
            const recipientAddress = escapeHtml(firstItem.recipient_address || '');
            const deliveryStatus = firstItem.delivery_status || 'pending';
            
            document.getElementById('modal-title').textContent = `Delivery #${transactionNumber}`;
            
            let html = '<div style="margin-bottom: 15px;">';
            html += `<div style="font-size: 16px; margin-bottom: 5px;"><strong>Transaction ID:</strong> ${transactionId}</div>`;
            html += `<div style="font-size: 16px; margin-bottom: 5px;"><strong>Recipient:</strong> ${recipientName}</div>`;
            html += `<div style="font-size: 14px; color: #666; margin-bottom: 10px;"><strong>Address:</strong> ${recipientAddress}</div>`;
            html += `<div style="font-size: 14px; color: #666;"><strong>Status:</strong> <span style="color: ${getStatusColor(deliveryStatus)}; font-weight: bold;">${deliveryStatus.toUpperCase()}</span></div>`;
            html += '</div>';
            
            html += '<div style="border-top: 2px solid #333; padding-top: 15px;">';
            html += '<h3 style="margin-bottom: 10px;">Products</h3>';
            
            data.forEach((item, index) => {
                const productName = escapeHtml(item.product_name || '');
                const lackingBadge = item.quantity_lacking > 0 ? 
                    `<span class="lacking-badge">-${item.quantity_lacking} LACKING</span>` : '';
                
                html += `
                    <div class="product-item-detail" data-index="${index}" data-item-id="${item.id}">
                        <div>
                            <div style="font-weight: bold; margin-bottom: 5px;">${productName} ${lackingBadge}</div>
                            <div style="font-size: 13px; color: #666;">
                                Ordered: ${item.quantity_ordered} | 
                                Delivered: <span style="color: #28a745; font-weight: bold;">${item.quantity_delivered}</span>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            
            const total = parseFloat(data[0].total_amount || 0);
            html += `
                <div style="border-top: 2px solid #333; padding-top: 15px; margin-top: 15px;">
                    <div style="display: flex; justify-content: space-between; font-size: 18px; font-weight: bold;">
                        <span>TOTAL:</span>
                        <span>₱${total.toFixed(2)}</span>
                    </div>
                </div>
            `;
            
            // Add placeholder for other receipts
            html += `
                <div id="other-receipts-section" style="margin-top: 20px;">
                    <div style="text-align: center; color: #999; font-style: italic;">Loading other receipts...</div>
                </div>
            `;
            
            document.getElementById('delivery-details-content').innerHTML = html;
            document.getElementById('delivery-details-modal').classList.add('active');
            isModalOpen = true;
            selectedProductIndex = 0;
            updateProductSelection();
            
            // Load other receipts for the same recipient
            loadOtherReceipts(recipientName, transactionId);
        })
        .catch(error => {
            console.error('Error loading delivery details:', error);
            alert('Error loading delivery details. Please try again.');
        });
}

// Load other receipts for the same recipient
function loadOtherReceipts(recipientName, currentTransactionId) {
    fetch(`/oro-store-demo/delivery/delivery_details.php?action=get_recipient_receipts&recipient_name=${encodeURIComponent(recipientName)}&current_transaction_id=${currentTransactionId}`)
        .then(response => response.json())
        .then(receipts => {
            const container = document.getElementById('other-receipts-section');
            
            if (!receipts || receipts.length === 0) {
                container.style.display = 'none';
                return;
            }
            
            let receiptsHTML = `
                <div style="border-top: 2px solid #ddd; padding-top: 15px; margin-top: 15px;">
                    <h3 style="margin-bottom: 10px; color: #555;">Other Receipts for ${escapeHtml(recipientName)}</h3>
                    <div style="background: #f8f9fa; padding: 12px; border-radius: 8px; font-size: 12px; color: #666; font-style: italic; margin-bottom: 10px;">
                        📋 Reference only - Not clickable
                    </div>
                    <div style="display: grid; gap: 8px;">
            `;
            
            receipts.forEach(receipt => {
                const date = new Date(receipt.transaction_date);
                const formattedDate = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                const status = receipt.delivery_status || 'pending';
                
                // Get status badge color and text
                let statusColor, statusBgColor, statusText;
                switch(status) {
                    case 'completed':
                        statusColor = '#fff';
                        statusBgColor = '#28a745';
                        statusText = 'COMPLETED';
                        break;
                    case 'pending':
                        statusColor = '#000';
                        statusBgColor = '#ffc107';
                        statusText = 'PENDING';
                        break;
                    case 'lacking':
                        statusColor = '#fff';
                        statusBgColor = '#dc3545';
                        statusText = 'LACKING';
                        break;
                    default:
                        statusColor = '#fff';
                        statusBgColor = '#6c757d';
                        statusText = status.toUpperCase();
                }
                
                receiptsHTML += `
                    <div style="background: white; padding: 12px; border: 1px solid #ddd; border-radius: 6px; cursor: default; user-select: none;">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 6px;">
                            <div style="flex: 1;">
                                <div style="font-weight: bold; color: #333; font-size: 14px; margin-bottom: 4px;">
                                    Transaction ID: ${receipt.transaction_id} | #${escapeHtml(receipt.transaction_number)}
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="font-size: 11px; color: #999;">
                                        ${formattedDate}
                                    </span>
                                    <span style="background: ${statusBgColor}; color: ${statusColor}; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: bold;">
                                        ${statusText}
                                    </span>
                                </div>
                            </div>
                            <div style="font-weight: bold; color: #007bff; font-size: 15px; margin-left: 10px;">
                                ₱${parseFloat(receipt.total_amount).toFixed(2)}
                            </div>
                        </div>
                    </div>
                `;
            });
            
            receiptsHTML += `
                    </div>
                </div>
            `;
            
            container.innerHTML = receiptsHTML;
        })
        .catch(error => {
            console.error('Error loading other receipts:', error);
            const container = document.getElementById('other-receipts-section');
            if (container) {
                container.style.display = 'none';
            }
        });
}

// Helper function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Update product selection in modal
function updateProductSelection() {
    const products = document.querySelectorAll('.product-item-detail');
    products.forEach((item, index) => {
        item.classList.toggle('selected', index === selectedProductIndex);
    });
    
    if (products[selectedProductIndex]) {
        products[selectedProductIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}

// Get status color
function getStatusColor(status) {
    switch(status) {
        case 'pending': return '#ffc107';
        case 'completed': return '#28a745';
        case 'lacking': return '#dc3545';
        default: return '#666';
    }
}

// Close details modal
function closeDetailsModal() {
    document.getElementById('delivery-details-modal').classList.remove('active');
    isModalOpen = false;
    currentDeliveryId = null;
    currentDeliveryItems = [];
}

// Open lacking modal
function openLackingModal() {
    const products = document.querySelectorAll('.product-item-detail');
    if (!products[selectedProductIndex]) return;
    
    const item = currentDeliveryItems[selectedProductIndex];
    currentItemId = item.id;
    
    document.getElementById('lacking-product-name').textContent = item.product_name;
    document.getElementById('lacking-ordered-qty').textContent = item.quantity_ordered;
    document.getElementById('lacking-input').value = item.quantity_lacking || 0;
    document.getElementById('lacking-input').max = item.quantity_ordered;
    
    document.getElementById('lacking-modal').classList.add('active');
    document.getElementById('lacking-input').focus();
}

// Close lacking modal
function closeLackingModal() {
    document.getElementById('lacking-modal').classList.remove('active');
    currentItemId = null;
}

// Confirm lacking
function confirmLacking() {
    const lackingQty = parseInt(document.getElementById('lacking-input').value);
    
    if (lackingQty < 0) {
        alert('Invalid quantity');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'mark_lacking');
    formData.append('item_id', currentItemId);
    formData.append('lacking_qty', lackingQty);
    
    fetch('/oro-store-demo/delivery/delivery_details.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeLackingModal();
            viewDeliveryDetails(currentDeliveryId);
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        alert('Error: ' + error);
    });
}

// Open edit quantity modal
function openEditQtyModal() {
    const products = document.querySelectorAll('.product-item-detail');
    if (!products[selectedProductIndex]) return;
    
    const item = currentDeliveryItems[selectedProductIndex];
    currentItemId = item.id;
    
    document.getElementById('edit-product-name').textContent = item.product_name;
    document.getElementById('edit-ordered-qty').textContent = item.quantity_ordered;
    document.getElementById('edit-qty-input').value = item.quantity_delivered;
    document.getElementById('edit-qty-input').max = item.quantity_ordered;
    
    document.getElementById('edit-qty-modal').classList.add('active');
    document.getElementById('edit-qty-input').focus();
}

// Close edit quantity modal
function closeEditQtyModal() {
    document.getElementById('edit-qty-modal').classList.remove('active');
    currentItemId = null;
}

// Confirm edit quantity
function confirmEditQty() {
    const newQty = parseInt(document.getElementById('edit-qty-input').value);
    
    if (newQty < 0) {
        alert('Invalid quantity');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'update_quantity');
    formData.append('item_id', currentItemId);
    formData.append('new_qty', newQty);
    
    fetch('/oro-store-demo/delivery/delivery_details.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeEditQtyModal();
            viewDeliveryDetails(currentDeliveryId);
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        alert('Error: ' + error);
    });
}

// Show complete confirmation
function showCompleteConfirmation() {
    document.getElementById('complete-modal').classList.add('active');
}

// Close complete modal
function closeCompleteModal() {
    document.getElementById('complete-modal').classList.remove('active');
}

// Confirm complete
function confirmComplete() {
    const formData = new FormData();
    formData.append('action', 'mark_complete');
    formData.append('delivery_id', currentDeliveryId);
    
    fetch('/oro-store-demo/delivery/delivery_details.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Delivery marked as complete!');
            closeCompleteModal();
            closeDetailsModal();
            location.reload();
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
    const lackingModalOpen = document.getElementById('lacking-modal').classList.contains('active');
    const editQtyModalOpen = document.getElementById('edit-qty-modal').classList.contains('active');
    const completeModalOpen = document.getElementById('complete-modal').classList.contains('active');
    const detailsModalOpen = document.getElementById('delivery-details-modal').classList.contains('active');
    
    // F1 - Toggle multi-selection for current card
    if (e.key === 'F1') {
        e.preventDefault();
        if (!isModalOpen) {
            toggleMultiSelection();
        }
        return;
    }
    
    // F2 - Process selected deliveries
    if (e.key === 'F2') {
        e.preventDefault();
        if (!isModalOpen && multiSelectedDeliveries.size > 0) {
            processSelectedDeliveries();
        }
        return;
    }
    
    // F3 - Back to Cashier
    if (e.key === 'F3') {
        e.preventDefault();
        window.location.href = '/oro-store-demo/cashier/cashier.php';
        return;
    }
    
    // F4 - Print selected deliveries
    if (e.key === 'F4') {
        e.preventDefault();
        if (multiSelectedDeliveries.size > 0) {
            printSelectedDeliveries();
        } else {
            alert('No deliveries selected. Please select deliveries first.');
        }
        return;
    }
    
    // F5 - View products of selected deliveries
    if (e.key === 'F5') {
        e.preventDefault();
        if (multiSelectedDeliveries.size > 0) {
            viewSelectedProducts();
        } else {
            alert('No deliveries selected. Please select deliveries first.');
        }
        return;
    }
    // Handle lacking modal
    if (lackingModalOpen) {
        if (e.key === 'Enter') {
            e.preventDefault();
            confirmLacking();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closeLackingModal();
        }
        return;
    }
    
    // Handle edit quantity modal
    if (editQtyModalOpen) {
        if (e.key === 'Enter') {
            e.preventDefault();
            confirmEditQty();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closeEditQtyModal();
        }
        return;
    }
    
    // Handle complete modal
    if (completeModalOpen) {
        if (e.key === 'Enter') {
            e.preventDefault();
            confirmComplete();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closeCompleteModal();
        }
        return;
    }
    
    // Handle details modal
    if (detailsModalOpen) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectedProductIndex = Math.min(selectedProductIndex + 1, currentDeliveryItems.length - 1);
            updateProductSelection();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedProductIndex = Math.max(selectedProductIndex - 1, 0);
            updateProductSelection();
        } else if (e.key === 'Home') {
            e.preventDefault();
            openLackingModal();
        } else if (e.key === 'Insert') {
            e.preventDefault();
            openEditQtyModal();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            showCompleteConfirmation();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closeDetailsModal();
        }
        return;
    }
    
    // Main page navigation
    const cards = document.querySelectorAll('.delivery-card');
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
            const deliveryId = parseInt(cards[selectedCardIndex].dataset.id);
            viewDeliveryDetails(deliveryId);
        }
    } else if (e.key === 'Escape') {
        e.preventDefault();
        location.reload();
    }
});

function printSelectedDeliveries() {
    if (multiSelectedDeliveries.size === 0) {
        alert('No deliveries selected');
        return;
    }
    
    const deliveries = Array.from(multiSelectedDeliveries.values());
    const printWindow = window.open('/oro-store-demo/print/print_delivery_details.php', '_blank');
    
    printWindow.addEventListener('load', function() {
        printWindow.postMessage({ deliveries: deliveries }, '*');
    });
}

// View products of selected deliveries
async function viewSelectedProducts() {
    // Check if modal already exists
    if (document.getElementById('products-view-modal')) {
        return; // Modal is already open, don't open another one
    }
    
    const modal = document.createElement('div');
    modal.className = 'modal active';
    modal.id = 'products-view-modal';
    modal.style.display = 'flex';
    
    let modalContent = `
        <div class="modal-content" style="max-width: 95%; width: 1400px; max-height: 90vh;">
            <h2>Products - Selected Deliveries</h2>
            <div style="display: grid; grid-template-columns: 1fr 400px; gap: 20px; margin-top: 20px;">
                <div style="overflow-y: auto; max-height: 70vh; border: 2px solid #ddd; border-radius: 8px; padding: 15px;">
                    <h3 style="margin-top: 0;">By Receipt</h3>
                    <div id="receipts-container">Loading...</div>
                </div>
                <div style="overflow-y: auto; max-height: 70vh; border: 2px solid #ddd; border-radius: 8px; padding: 15px; background: #f9f9f9;">
                    <h3 style="margin-top: 0;">Total Products Summary</h3>
                    <div id="totals-container">Loading...</div>
                </div>
            </div>
            <div class="modal-buttons" style="margin-top: 20px;">
                <button class="btn-cancel" onclick="closeProductsModal()">Close (Esc)</button>
            </div>
        </div>
    `;
    
    modal.innerHTML = modalContent;
    document.body.appendChild(modal);
    
    // Load products data
    try {
        const productsData = {};
        const receiptsHTML = [];
        
        for (const [deliveryId, delivery] of multiSelectedDeliveries) {
            const response = await fetch(`/oro-store-demo/delivery/delivery_details.php?action=get_delivery&delivery_id=${deliveryId}`);
            const items = await response.json();
            
            if (items.length > 0) {
                const transactionNumber = items[0].transaction_number;
                const transactionId = items[0].transaction_id;
                const recipient = items[0].recipient_name;
                
                let receiptHTML = `
                    <div style="margin-bottom: 25px; padding: 15px; background: white; border: 1px solid #ddd; border-radius: 8px;">
                        <h4 style="margin: 0 0 10px 0; color: #007bff;">Transaction ID: ${transactionId} | #${transactionNumber}</h4>
                        <p style="margin: 0 0 10px 0; font-size: 14px; color: #666;">Recipient: ${recipient}</p>
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f0f0f0;">
                                    <th style="padding: 8px; text-align: left; border: 1px solid #ddd;">Product</th>
                                    <th style="padding: 8px; text-align: center; border: 1px solid #ddd;">Ordered</th>
                                    <th style="padding: 8px; text-align: center; border: 1px solid #ddd;">Delivered</th>
                                    <th style="padding: 8px; text-align: center; border: 1px solid #ddd;">Lacking</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                items.forEach(item => {
                    receiptHTML += `
                        <tr>
                            <td style="padding: 8px; border: 1px solid #ddd;">${item.product_name}</td>
                            <td style="padding: 8px; text-align: center; border: 1px solid #ddd;">${item.quantity_ordered}</td>
                            <td style="padding: 8px; text-align: center; border: 1px solid #ddd;">${item.quantity_delivered}</td>
                            <td style="padding: 8px; text-align: center; border: 1px solid #ddd;">${item.quantity_lacking}</td>
                        </tr>
                    `;
                    
                    // Aggregate for totals
                    if (!productsData[item.product_name]) {
                        productsData[item.product_name] = {
                            ordered: 0,
                            delivered: 0,
                            lacking: 0
                        };
                    }
                    productsData[item.product_name].ordered += item.quantity_ordered;
                    productsData[item.product_name].delivered += item.quantity_delivered;
                    productsData[item.product_name].lacking += item.quantity_lacking;
                });
                
                receiptHTML += `
                            </tbody>
                        </table>
                    </div>
                `;
                receiptsHTML.push(receiptHTML);
            }
        }
        
        // Build totals HTML
        let totalsHTML = `
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #e0e0e0;">
                        <th style="padding: 8px; text-align: left; border: 1px solid #999;">Product</th>
                        <th style="padding: 8px; text-align: center; border: 1px solid #999;">Total Ordered</th>
                        <th style="padding: 8px; text-align: center; border: 1px solid #999;">Total Delivered</th>
                        <th style="padding: 8px; text-align: center; border: 1px solid #999;">Total Lacking</th>
                    </tr>
                </thead>
                <tbody>
        `;
        
        for (const [productName, quantities] of Object.entries(productsData)) {
            totalsHTML += `
                <tr>
                    <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">${productName}</td>
                    <td style="padding: 8px; text-align: center; border: 1px solid #ddd;">${quantities.ordered}</td>
                    <td style="padding: 8px; text-align: center; border: 1px solid #ddd; color: green;">${quantities.delivered}</td>
                    <td style="padding: 8px; text-align: center; border: 1px solid #ddd; color: ${quantities.lacking > 0 ? 'red' : 'inherit'};">${quantities.lacking}</td>
                </tr>
            `;
        }
        
        totalsHTML += `
                </tbody>
            </table>
        `;
        
        document.getElementById('receipts-container').innerHTML = receiptsHTML.join('');
        document.getElementById('totals-container').innerHTML = totalsHTML;
        
    } catch (error) {
        document.getElementById('receipts-container').innerHTML = `<p style="color: red;">Error loading products: ${error.message}</p>`;
        document.getElementById('totals-container').innerHTML = '';
    }
}

function closeProductsModal() {
    const modal = document.getElementById('products-view-modal');
    if (modal) {
        modal.remove();
    }
}

// Also add Escape key handler for the products modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const productsModal = document.getElementById('products-view-modal');
        if (productsModal) {
            closeProductsModal();
        }
    }
});