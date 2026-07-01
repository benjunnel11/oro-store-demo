<meta name="theme-color" content="#6366f1">
<link rel="stylesheet" href="/oro-store-demo/core/responsive.css">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Oro Store">
<link rel="manifest" href="/oro-store-demo/manifest.json">
<link rel="apple-touch-icon" href="/oro-store-demo/icons/icon-192.svg">
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/oro-store-demo/sw.js').catch(() => {});
}
// Kiosk mode: block back button only
if (window.matchMedia('(display-mode: fullscreen)').matches || window.matchMedia('(display-mode: standalone)').matches) {
    history.pushState(null, '', location.href);
    window.addEventListener('popstate', function() {
        history.pushState(null, '', location.href);
    });
}
// Block Ctrl+scroll zoom and Ctrl+Plus/Minus zoom
document.addEventListener('wheel', function(e){ if(e.ctrlKey) e.preventDefault(); }, {passive:false});
document.addEventListener('keydown', function(e){ if(e.ctrlKey && (e.key==='+' || e.key==='-' || e.key==='=' || e.key==='0')) e.preventDefault(); });
</script>
<script src="/oro-store-demo/core/custom_alert.js"></script>
