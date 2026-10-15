<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<h4>Import / Export</h4>
<p class="ir-prod-desc">Export this product's saved overrides, or import from a previously exported file. Only overridden values are included — blank fields inherit from global.</p>

<div style="display:flex;flex-direction:column;gap:10px;margin-top:10px;">

	<div>
		<strong style="display:block;font-size:12px;margin-bottom:6px;">Export</strong>
		<button type="button" class="button ir-ie-export-btn"
			data-payload="<?php echo esc_attr( wp_json_encode( $ie_export_payload ?? [] ) ); ?>"
			data-filename="<?php echo esc_attr( $ie_filename ?? 'ir-product-settings.json' ); ?>">&#11015; Download Overrides</button>
	</div>

	<div>
		<strong style="display:block;font-size:12px;margin-bottom:6px;">Import</strong>
		<input type="file" class="ir-ie-file-input" accept="application/json,.json" style="margin-bottom:6px;display:block;">
		<textarea class="ir-ie-textarea" rows="5" style="width:100%;font-family:monospace;font-size:11px;border:1.5px solid #e5e7eb;border-radius:6px;padding:6px;" placeholder="Paste exported JSON here"></textarea>
		<div style="display:flex;align-items:center;gap:8px;margin-top:6px;">
			<button type="button" class="button ir-ie-import-btn"
				data-action="ir_import_product_settings"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'ir_import_product_settings' ) ); ?>"
				data-product-id="<?php echo esc_attr( $product_id ?? 0 ); ?>">&#11014; Import</button>
			<span class="ir-ie-status" style="font-size:11px;color:#6b7280;"></span>
		</div>
	</div>

</div>

<script>
(function(){
	if ( window.__irProdIEBound ) return;
	window.__irProdIEBound = true;

	document.addEventListener('click', function(e){
		var exp = e.target.closest('.ir-ie-export-btn');
		if (exp) {
			var data = exp.getAttribute('data-payload');
			var filename = exp.getAttribute('data-filename') || 'ir-product-settings.json';
			var blob = new Blob([data], {type:'application/json'});
			var url  = URL.createObjectURL(blob);
			var a = document.createElement('a');
			a.href = url; a.download = filename;
			document.body.appendChild(a); a.click(); document.body.removeChild(a);
			URL.revokeObjectURL(url);
			return;
		}

		var imp = e.target.closest('.ir-ie-import-btn');
		if (imp) {
			var section   = imp.closest('.fbp-tab-section') || document;
			var fileInput = section.querySelector('.ir-ie-file-input');
			var textarea  = section.querySelector('.ir-ie-textarea');
			var status    = section.querySelector('.ir-ie-status');

			var send = function(jsonStr){
				if (!jsonStr) { status.textContent='Provide a file or paste JSON.'; status.style.color='#dc2626'; return; }
				if (!window.confirm('Import and overwrite this product\'s overrides?')) return;
				status.textContent='Importing…'; status.style.color='#6b7280';
				var body = new URLSearchParams();
				body.set('action',     imp.getAttribute('data-action'));
				body.set('nonce',      imp.getAttribute('data-nonce'));
				body.set('data',       jsonStr);
				body.set('product_id', imp.getAttribute('data-product-id') || '0');
				fetch(ajaxurl, {method:'POST', credentials:'same-origin', body:body})
					.then(function(r){ return r.json(); })
					.then(function(res){
						if (res && res.success) {
							status.textContent='Imported! Reloading…'; status.style.color='#16a34a';
							setTimeout(function(){ location.reload(); }, 600);
						} else {
							status.textContent=(res && res.data && res.data.message)||'Import failed.'; status.style.color='#dc2626';
						}
					})
					.catch(function(){ status.textContent='Network error.'; status.style.color='#dc2626'; });
			};

			var file = fileInput && fileInput.files && fileInput.files[0];
			if (file) {
				var reader = new FileReader();
				reader.onload = function(){ send(reader.result); };
				reader.readAsText(file);
			} else {
				send(textarea ? textarea.value.trim() : '');
			}
		}
	});
})();
</script>
