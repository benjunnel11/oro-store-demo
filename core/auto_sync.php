<script>
(function(){
    if (localStorage.getItem('oro_auto_sync') !== '1') return;
    if (location.pathname.indexOf('connection.php') !== -1) return;

    setInterval(function(){
        fetch('/oro-store-demo/sync/http_sync.php?run=1').catch(function(){});
    }, 5 * 60 * 1000);

    // Auto-detect ZeroTier IP in background (once per hour)
    var lastCheck = localStorage.getItem('oro_zt_check') || '0';
    if (Date.now() - parseInt(lastCheck) > 3600000) {
        localStorage.setItem('oro_zt_check', Date.now().toString());
        fetch('/oro-store-demo/sync/detect_device.php').catch(function(){});
    }
})();
</script>
