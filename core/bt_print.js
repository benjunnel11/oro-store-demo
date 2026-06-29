/**
 * Thermal Printer via RawBT (Android) — auto-print, no dialog.
 * Falls back silently if RawBT is unavailable.
 */

var BTPrint = (function () {
    var ESC = '\x1B';
    var GS = '\x1D';
    var LF = '\x0A';

    var CMD = {
        INIT: ESC + '\x40',
        CENTER: ESC + '\x61\x01',
        LEFT: ESC + '\x61\x00',
        BOLD_ON: ESC + '\x45\x01',
        BOLD_OFF: ESC + '\x45\x00',
        NORMAL: ESC + '\x21\x00',
        DOUBLE_H: ESC + '\x21\x10',
        LARGE: GS + '\x21\x11',
        XLARGE: GS + '\x21\x22',
        CUT: GS + '\x56\x00',
        PARTIAL_CUT: GS + '\x56\x01',
        FEED3: LF + LF + LF
    };

    function line(str) { return str + LF; }
    function feed(n) { var s = ''; for (var i = 0; i < (n || 1); i++) s += LF; return s; }
    function dashes(n) { var s = ''; for (var i = 0; i < (n || 32); i++) s += '-'; return line(s); }
    function columns(left, right, width) {
        width = width || 32;
        left = String(left); right = String(right);
        var space = width - left.length - right.length;
        if (space < 1) space = 1;
        var pad = ''; for (var i = 0; i < space; i++) pad += ' ';
        return line(left + pad + right);
    }

    function toBase64(str) {
        var bytes = [];
        for (var i = 0; i < str.length; i++) bytes.push(str.charCodeAt(i) & 0xFF);
        var binary = '';
        for (var j = 0; j < bytes.length; j++) binary += String.fromCharCode(bytes[j]);
        return btoa(binary);
    }

    function sendToRawBT(data) {
        try {
            console.log('sendToRawBT data length:', data.length, 'chars');
            var b64 = toBase64(data);
            var url = 'rawbt:base64,' + b64;
            console.log('RawBT URL length:', url.length);
            var iframe = document.createElement('iframe');
            iframe.style.cssText = 'display:none;width:0;height:0;border:0;';
            iframe.src = url;
            document.body.appendChild(iframe);
            setTimeout(function () {
                try { document.body.removeChild(iframe); } catch (e) {}
            }, 5000);
        } catch (e) {
            // Silently fail — printer unavailable
        }
    }

    function canPrint() { return true; }

    // ── Priority Number ──
    function printPriority(number, storeName, subtitle, orderItems, orderTotal) {
        console.log('BTPrint.printPriority called:', { number: number, items: orderItems, total: orderTotal });
        var padded = String(number).padStart(3, '0');
        var now = new Date();
        var dateStr = now.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        var timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });

        var data = CMD.INIT + CMD.CENTER;
        data += CMD.BOLD_ON + line(storeName || 'ORO STORE') + CMD.BOLD_OFF + CMD.NORMAL;
        data += feed(1) + line('PRIORITY NUMBER') + feed(1);
        data += CMD.XLARGE + CMD.BOLD_ON + line(padded) + CMD.NORMAL + CMD.BOLD_OFF;
        if (subtitle) { data += feed(1) + line(subtitle); }
        data += feed(1) + line(dateStr + ' ' + timeStr);
        if (orderItems && orderItems.length) {
            data += dashes(32) + CMD.LEFT;
            data += CMD.BOLD_ON + line('ORDER DETAILS') + CMD.BOLD_OFF;
            orderItems.forEach(function(item) {
                var tags = [];
                if (item.unit) tags.push(item.unit.charAt(0).toUpperCase() + item.unit.slice(1));
                if (item.category) tags.push(item.category);
                if (item.brand) tags.push(item.brand);
                var tagStr = tags.length ? ' [' + tags.join('/') + ']' : '';
                data += CMD.BOLD_ON + line(item.name) + CMD.BOLD_OFF;
                data += line('  P' + parseFloat(item.price).toFixed(2) + '/ea' + tagStr);
                data += columns('  x' + item.quantity, 'P' + parseFloat(item.subtotal || item.price * item.quantity).toFixed(2), 32);
            });
            data += dashes(32);
            data += CMD.BOLD_ON + columns('TOTAL', 'P' + parseFloat(orderTotal || 0).toFixed(2), 32) + CMD.BOLD_OFF;
        }
        data += dashes(32) + CMD.CENTER;
        data += line('Please wait for your');
        data += line('number to be called.');
        data += line('Thank you!');
        data += CMD.FEED3 + CMD.PARTIAL_CUT;
        sendToRawBT(data);
    }

    // ── Priority Number + GCash details ──
    function printPriorityGCash(number, storeName, gcashType, customerNumber, amount, fee, total) {
        var padded = String(number).padStart(3, '0');
        var typeLabel = gcashType === 'cash_in' ? 'CASH IN' : 'CASH OUT';
        var now = new Date();
        var dateStr = now.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        var timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });

        var data = CMD.INIT + CMD.CENTER;
        data += CMD.BOLD_ON + line(storeName || 'ORO STORE') + CMD.BOLD_OFF + CMD.NORMAL;
        data += feed(1) + line('PRIORITY NUMBER') + feed(1);
        data += CMD.XLARGE + CMD.BOLD_ON + line(padded) + CMD.NORMAL + CMD.BOLD_OFF;
        data += feed(1);
        data += CMD.BOLD_ON + line('GCASH ' + typeLabel) + CMD.BOLD_OFF;
        data += dashes(32);
        data += CMD.LEFT;
        data += columns('GCash #:', customerNumber || '-', 32);
        data += columns('Amount:', 'P' + parseFloat(amount || 0).toFixed(2), 32);
        data += columns('Fee:', 'P' + parseFloat(fee || 0).toFixed(2), 32);
        data += CMD.BOLD_ON;
        data += columns('Total:', 'P' + parseFloat(total || 0).toFixed(2), 32);
        data += CMD.BOLD_OFF;
        data += dashes(32);
        data += CMD.CENTER;
        data += line(dateStr + ' ' + timeStr);
        data += feed(1);
        data += line('Please wait for your');
        data += line('number to be called.');
        data += line('Thank you!');
        data += CMD.FEED3 + CMD.PARTIAL_CUT;
        sendToRawBT(data);
    }

    // ── Receipt ──
    function printReceipt(opts) {
        var data = CMD.INIT + CMD.CENTER;
        data += CMD.BOLD_ON + line(opts.storeName || 'ORO STORE') + CMD.BOLD_OFF + CMD.NORMAL;
        if (opts.storeAddress) data += line(opts.storeAddress);
        data += dashes(32);
        if (opts.title) data += CMD.BOLD_ON + line(opts.title) + CMD.BOLD_OFF;
        if (opts.transactionNumber) data += line('TXN#: ' + opts.transactionNumber);
        data += line(opts.date || new Date().toLocaleString());
        if (opts.cashier) data += line('Cashier: ' + opts.cashier);
        data += dashes(32) + CMD.LEFT;
        if (opts.items && opts.items.length) {
            opts.items.forEach(function (item) {
                var tags = [];
                if (item.unit) tags.push(item.unit.charAt(0).toUpperCase() + item.unit.slice(1));
                if (item.category) tags.push(item.category);
                if (item.brand) tags.push(item.brand);
                var tagStr = tags.length ? ' [' + tags.join('/') + ']' : '';
                data += CMD.BOLD_ON + line(item.name) + CMD.BOLD_OFF;
                if (tagStr) data += line('  ' + tagStr);
                data += columns('  ' + item.quantity + ' x P' + parseFloat(item.price).toFixed(2), 'P' + parseFloat(item.subtotal || item.price * item.quantity).toFixed(2), 32);
            });
        }
        data += dashes(32);
        data += CMD.BOLD_ON + columns('TOTAL', 'P' + parseFloat(opts.total || 0).toFixed(2), 32) + CMD.BOLD_OFF;
        if (opts.amountPaid !== undefined) {
            data += columns('Paid', 'P' + parseFloat(opts.amountPaid).toFixed(2), 32);
            data += columns('Change', 'P' + parseFloat(opts.change || 0).toFixed(2), 32);
        }
        data += dashes(32) + CMD.CENTER + line('Thank you!');
        data += feed(1);
        data += line('THIS IS NOT AN OFFICIAL');
        data += line('RECEIPT. THIS SERVES AS');
        data += line('PROOF OF PURCHASE ONLY.');
        data += CMD.FEED3 + CMD.PARTIAL_CUT;
        sendToRawBT(data);
    }

    // ── Delivery Loading List — per-customer receipts + totals summary ──
    function printLoadingList(groups, productTotals, grandTotal, orderCount, storeName, batchNumber) {
        var now = new Date();
        var dateStr = now.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        var timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });

        var data = CMD.INIT;

        // Print each customer as a separate receipt
        groups.forEach(function(entry) {
            data += CMD.INIT + CMD.CENTER;
            data += CMD.BOLD_ON + line(storeName || 'ORO STORE') + CMD.BOLD_OFF + CMD.NORMAL;
            data += dashes(32);
            data += CMD.BOLD_ON + line('DELIVERY ORDER') + CMD.BOLD_OFF;
            data += line(dateStr + ' ' + timeStr);
            data += dashes(32);
            data += CMD.BOLD_ON + line(entry.name) + CMD.BOLD_OFF;
            data += dashes(32) + CMD.LEFT;

            var customerTotal = 0;
            entry.orders.forEach(function(order) {
                data += line('#' + order.transaction_number);
                order.items.forEach(function(item) {
                    data += CMD.BOLD_ON + line(item.product_name) + CMD.BOLD_OFF;
                    var sub = item.quantity * (item.price || 0);
                    if (item.price) {
                        data += columns('  ' + item.quantity + ' x P' + parseFloat(item.price).toFixed(2), 'P' + sub.toFixed(2), 32);
                    } else {
                        data += columns('  qty', 'x' + item.quantity, 32);
                    }
                    customerTotal += sub;
                });
                data += dashes(32);
            });

            if (customerTotal > 0) {
                data += CMD.BOLD_ON + columns('TOTAL', 'P' + customerTotal.toFixed(2), 32) + CMD.BOLD_OFF;
                data += dashes(32);
            }

            data += CMD.CENTER;
            data += line('THIS IS NOT AN OFFICIAL');
            data += line('RECEIPT. THIS SERVES AS');
            data += line('PROOF OF PURCHASE ONLY.');
            data += CMD.FEED3 + CMD.PARTIAL_CUT;
        });

        // Print product totals summary as final receipt
        data += CMD.INIT + CMD.CENTER;
        data += CMD.BOLD_ON + line(storeName || 'ORO STORE') + CMD.BOLD_OFF + CMD.NORMAL;
        data += dashes(32);
        data += CMD.BOLD_ON + line('LOADING SUMMARY') + CMD.BOLD_OFF;
        if (batchNumber) data += CMD.BOLD_ON + line(batchNumber) + CMD.BOLD_OFF;
        data += line(dateStr + ' ' + timeStr);
        data += line(orderCount + ' order(s) / ' + groups.length + ' customer(s)');
        data += dashes(32);
        data += CMD.BOLD_ON + CMD.CENTER + line('PRODUCT TOTALS') + CMD.BOLD_OFF;
        data += CMD.LEFT;
        productTotals.forEach(function(entry) {
            data += columns(entry[0], 'x' + entry[1], 32);
        });
        data += dashes(32);
        data += CMD.BOLD_ON + columns('TOTAL ITEMS', String(grandTotal), 32) + CMD.BOLD_OFF;
        data += CMD.FEED3 + CMD.PARTIAL_CUT;

        sendToRawBT(data);
    }

    return {
        canPrint: canPrint,
        printPriority: printPriority,
        printPriorityGCash: printPriorityGCash,
        printReceipt: printReceipt,
        printLoadingList: printLoadingList,
        sendToRawBT: sendToRawBT,
        CMD: CMD, columns: columns, line: line, dashes: dashes, feed: feed
    };
})();
