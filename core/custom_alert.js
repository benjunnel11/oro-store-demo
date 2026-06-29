// Custom styled alert/confirm to replace browser defaults
(function(){
    // Create modal container
    var overlay = document.createElement('div');
    overlay.id = 'custom-alert-overlay';
    overlay.style.cssText = 'display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.6);z-index:99999;justify-content:center;align-items:center;backdrop-filter:blur(4px);';

    var box = document.createElement('div');
    box.id = 'custom-alert-box';
    box.style.cssText = 'background:#1e293b;border-radius:16px;padding:28px 32px;max-width:400px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.5);text-align:center;animation:alertPop .2s ease;';

    var iconEl = document.createElement('div');
    iconEl.id = 'custom-alert-icon';
    iconEl.style.cssText = 'font-size:48px;margin-bottom:12px;';

    var titleEl = document.createElement('div');
    titleEl.id = 'custom-alert-title';
    titleEl.style.cssText = 'font-size:18px;font-weight:800;color:#f1f5f9;margin-bottom:8px;';

    var msgEl = document.createElement('div');
    msgEl.id = 'custom-alert-msg';
    msgEl.style.cssText = 'font-size:13px;color:#94a3b8;line-height:1.6;margin-bottom:20px;white-space:pre-line;text-align:left;';

    var btnWrap = document.createElement('div');
    btnWrap.id = 'custom-alert-buttons';
    btnWrap.style.cssText = 'display:flex;gap:10px;justify-content:center;';

    box.appendChild(iconEl);
    box.appendChild(titleEl);
    box.appendChild(msgEl);
    box.appendChild(btnWrap);
    overlay.appendChild(box);

    // Add animation
    var style = document.createElement('style');
    style.textContent = '@keyframes alertPop{from{transform:scale(.9);opacity:0}to{transform:scale(1);opacity:1}}';
    document.head.appendChild(style);

    // Append overlay as soon as body exists
    function appendOverlay() {
        if (document.body && !overlay.parentNode) document.body.appendChild(overlay);
    }
    appendOverlay();
    document.addEventListener('DOMContentLoaded', appendOverlay);
    // Extra fallback
    setTimeout(appendOverlay, 100);

    function makeBtn(text, color, onClick) {
        var btn = document.createElement('button');
        btn.textContent = text;
        btn.style.cssText = 'padding:12px 28px;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;min-width:100px;color:#fff;background:' + color + ';';
        btn.onclick = onClick;
        return btn;
    }

    function detectType(msg) {
        var m = (msg || '').toLowerCase();
        if (m.includes('success') || m.includes('complete') || m.includes('saved')) return 'success';
        if (m.includes('error') || m.includes('fail') || m.includes('denied') || m.includes('wrong')) return 'error';
        if (m.includes('warning') || m.includes('careful') || m.includes('sure')) return 'warning';
        if (m.includes('re-edit') || m.includes('loaded') || m.includes('mode')) return 'info';
        return 'info';
    }

    var icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️', confirm: '❓' };
    var colors = { success: '#22c55e', error: '#dc2626', warning: '#f59e0b', info: '#6366f1', confirm: '#6366f1' };
    var titles = { success: 'Success', error: 'Error', warning: 'Warning', info: 'Notice', confirm: 'Confirm' };

    function showAlert(msg, type, onOk) {
        type = type || detectType(msg);
        iconEl.textContent = icons[type] || icons.info;
        titleEl.textContent = titles[type] || 'Notice';
        titleEl.style.color = colors[type] || '#f1f5f9';
        msgEl.textContent = msg;
        btnWrap.innerHTML = '';
        btnWrap.appendChild(makeBtn('OK', colors[type] || '#6366f1', function(){
            overlay.style.display = 'none';
            if (onOk) onOk();
        }));
        overlay.style.display = 'flex';
        btnWrap.querySelector('button').focus();
    }

    function showConfirm(msg, onYes, onNo) {
        iconEl.textContent = icons.confirm;
        titleEl.textContent = 'Confirm';
        titleEl.style.color = colors.confirm;
        msgEl.textContent = msg;
        btnWrap.innerHTML = '';
        btnWrap.appendChild(makeBtn('Cancel', '#475569', function(){
            overlay.style.display = 'none';
            if (onNo) onNo();
        }));
        btnWrap.appendChild(makeBtn('Confirm', '#6366f1', function(){
            overlay.style.display = 'none';
            if (onYes) onYes();
        }));
        overlay.style.display = 'flex';
    }

    // Override native alert
    window._nativeAlert = window.alert;
    window._nativeConfirm = window.confirm;

    window.alert = function(msg) {
        if (!overlay.parentNode) appendOverlay();
        if (!overlay.parentNode) { window._nativeAlert(msg); return; }
        showAlert(msg);
    };

    // confirm() stays native (synchronous requirement)
    window.confirm = function(msg) {
        return window._nativeConfirm(msg);
    };

    // Async confirm for new code
    window.customConfirm = showConfirm;
    window.customAlert = showAlert;
})();
