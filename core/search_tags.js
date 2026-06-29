// Reusable search tag/autocomplete system for product lists
// Works on: cashier, delivery, angkat, credit, add_stock
(function(){
    var searchInput = document.getElementById('cashier-search') || document.getElementById('search-input');
    var sugBox = document.getElementById('search-suggestions') || document.getElementById('stock-suggestions');
    var tagsBox = document.getElementById('search-tags') || document.getElementById('stock-search-tags');
    if (!searchInput || !sugBox || !tagsBox) return;

    var activeTags = [];

    function buildSuggestions() {
        var sug = new Set();
        document.querySelectorAll('.product-item-cashier, .product-item-stock').forEach(function(item){
            var unit = (item.dataset.unit||'').trim();
            var brand = (item.dataset.brand||'').trim();
            var category = (item.dataset.category||'').trim();
            var isChild = unit !== '';
            if (unit) sug.add(unit.charAt(0).toUpperCase() + unit.slice(1));
            if (brand) sug.add(brand);
            if (category && !isChild) sug.add(category);
        });
        return Array.from(sug);
    }

    // Rebuild suggestions after products load (for JS-rendered pages)
    var allSuggestions = [];
    var rebuildTimer = setInterval(function(){
        var items = document.querySelectorAll('.product-item-cashier, .product-item-stock');
        if (items.length > 0) {
            allSuggestions = buildSuggestions();
            clearInterval(rebuildTimer);
        }
    }, 500);
    setTimeout(function(){ clearInterval(rebuildTimer); }, 10000);

    searchInput.addEventListener('input', function(){
        var val = this.value.toLowerCase().trim();
        if (val.length < 1) { sugBox.style.display = 'none'; return; }
        if (allSuggestions.length === 0) allSuggestions = buildSuggestions();
        var matches = allSuggestions.filter(function(s){ return s.toLowerCase().includes(val) && activeTags.indexOf(s) === -1; });
        if (matches.length === 0) { sugBox.style.display = 'none'; return; }
        sugBox.innerHTML = '';
        matches.slice(0, 8).forEach(function(m){
            var div = document.createElement('div');
            div.textContent = m;
            div.style.cssText = 'padding:10px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid #f1f5f9;-webkit-user-select:none;user-select:none;';
            div.onmouseenter = function(){ this.style.background='#f0f7ff'; };
            div.onmouseleave = function(){ this.style.background=''; };
            div.onclick = function(e){ e.stopPropagation(); addTag(m); };
            div.ontouchend = function(e){ e.preventDefault(); e.stopPropagation(); addTag(m); };
            sugBox.appendChild(div);
        });
        sugBox.style.display = 'block';
    });

    function addTag(text) {
        if (activeTags.indexOf(text) !== -1) return;
        activeTags.push(text);
        renderTags();
        searchInput.value = '';
        sugBox.style.display = 'none';
        filterByTags();
        searchInput.focus();
    }

    function removeTag(text) {
        activeTags = activeTags.filter(function(t){ return t !== text; });
        renderTags();
        filterByTags();
        searchInput.focus();
    }

    function renderTags() {
        tagsBox.innerHTML = '';
        activeTags.forEach(function(tag){
            var span = document.createElement('span');
            span.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:4px 10px;background:#6366f1;color:#fff;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;-webkit-user-select:none;user-select:none;';
            span.innerHTML = tag + ' <span style="font-size:10px;opacity:0.7;margin-left:2px;">✕</span>';
            span.onclick = function(){ removeTag(tag); };
            tagsBox.appendChild(span);
        });
    }

    function filterByTags() {
        var query = searchInput.value.toLowerCase().trim();
        var items = document.querySelectorAll('.product-item-cashier, .product-item-stock');
        var vi = 0;
        items.forEach(function(item){
            var isChild = (item.dataset.unit||'').trim() !== '';
            var searchable = (item.dataset.name||'')+' '+(item.dataset.brand||'')+' '+(item.dataset.unit||'')+' '+(item.dataset.barcode||'')+' '+(item.dataset.description||'');
            if (!isChild) searchable += ' '+(item.dataset.category||'');
            var all = searchable.toLowerCase();

            var tagMatch = activeTags.length === 0 || activeTags.every(function(t){ return all.includes(t.toLowerCase()); });
            var textMatch = query === '' || all.includes(query);
            var show = tagMatch && textMatch;

            item.style.setProperty('display', show ? 'flex' : 'none', 'important');
            if (show) { item.dataset.visibleIndex = vi; vi++; }
        });
        if (typeof selectedProductIndex !== 'undefined') selectedProductIndex = 0;
        if (typeof updateProductSelection === 'function') updateProductSelection();
    }

    // Run tag filter after the page's own search filter
    searchInput.addEventListener('input', function(){ setTimeout(filterByTags, 50); });

    document.addEventListener('click', function(e){
        if (!e.target.closest('.search-container') && !e.target.closest('.search-section')) sugBox.style.display = 'none';
    });
})();
