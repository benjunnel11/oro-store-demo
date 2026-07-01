<?php
function renderPageInfo($title, $sections) {
?>
<style>
.pi-btn{position:fixed;bottom:20px;left:20px;z-index:9000;width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none;font-size:18px;font-weight:900;cursor:pointer;box-shadow:0 4px 16px rgba(99,102,241,.35);transition:transform .2s,box-shadow .2s;font-family:'Segoe UI',sans-serif;}
.pi-btn:hover{transform:scale(1.1);box-shadow:0 6px 24px rgba(99,102,241,.45);}
.pi-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9001;justify-content:center;align-items:center;backdrop-filter:blur(3px);}
.pi-overlay.open{display:flex;}
.pi-modal{background:#fff;border-radius:16px;width:90%;max-width:480px;max-height:80vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);padding:0;}
.pi-head{padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;border-radius:16px 16px 0 0;z-index:1;}
.pi-head h3{font-size:15px;font-weight:800;color:#1e293b;}
.pi-close{background:none;border:none;font-size:20px;cursor:pointer;color:#94a3b8;padding:4px 8px;border-radius:6px;}
.pi-close:hover{background:#f1f5f9;color:#1e293b;}
.pi-body{padding:16px 20px 20px;}
.pi-sec{margin-bottom:16px;}
.pi-sec:last-child{margin-bottom:0;}
.pi-sec-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#6366f1;margin-bottom:8px;padding-bottom:4px;border-bottom:2px solid #eef2ff;}
.pi-row{display:flex;gap:8px;align-items:flex-start;padding:4px 0;font-size:12px;color:#475569;line-height:1.5;}
.pi-key{display:inline-block;background:#f1f5f9;color:#334155;font-family:'Consolas',monospace;font-size:11px;font-weight:700;padding:2px 7px;border-radius:4px;border:1px solid #e2e8f0;white-space:nowrap;min-width:40px;text-align:center;}
.pi-dot{width:5px;height:5px;border-radius:50%;background:#6366f1;flex-shrink:0;margin-top:6px;}
.pi-text{flex:1;}
</style>
<button class="pi-btn" onclick="document.getElementById('piOverlay').classList.add('open')" title="Page Info">?</button>
<div class="pi-overlay" id="piOverlay" onclick="if(event.target===this)this.classList.remove('open')">
<div class="pi-modal">
<div class="pi-head">
<h3><?php echo htmlspecialchars($title); ?></h3>
<button class="pi-close" onclick="document.getElementById('piOverlay').classList.remove('open')">&times;</button>
</div>
<div class="pi-body">
<?php foreach ($sections as $sec_title => $items): ?>
<div class="pi-sec">
<div class="pi-sec-title"><?php echo htmlspecialchars($sec_title); ?></div>
<?php foreach ($items as $item):
    if (is_array($item) && isset($item['key'])): ?>
<div class="pi-row"><span class="pi-key"><?php echo htmlspecialchars($item['key']); ?></span><span class="pi-text"><?php echo $item['desc']; ?></span></div>
<?php else: ?>
<div class="pi-row"><span class="pi-dot"></span><span class="pi-text"><?php echo $item; ?></span></div>
<?php endif; endforeach; ?>
</div>
<?php endforeach; ?>
</div>
</div>
</div>
<?php
}