<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>

<style>
.ir-ie-btn{display:inline-flex;align-items:center;gap:6px;height:38px;padding:0 18px;background:#38d4c0;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;transition:opacity .15s,transform .1s;}
.ir-ie-btn:hover{opacity:.88;}
.ir-ie-btn:active{transform:scale(.98);}
</style>

<h2>Import / Export</h2>
<p style="font-size:12px;color:#6b7280;margin:0 0 12px;">
<?php if ( ! empty( $ie_is_product ) ) : ?>
	Export this product's saved overrides to JSON, or import a previously exported file to restore them. Settings inherited from the global defaults are not included.
<?php else : ?>
	Export all global Instant Reveal settings to a JSON file, or import a previously exported file to restore them.
<?php endif; ?>
</p>

<div class="ck-settings-grid" style="padding:16px;margin-bottom:16px;">
	<h3 style="margin:0 0 6px;font-size:13px;font-weight:600;color:#374151;">Export</h3>
	<p style="font-size:11px;color:#9ca3af;margin:0 0 10px;">Downloads a JSON file you can keep as a backup or re-import later.</p>
	<button type="button" class="ir-ie-btn ir-ie-export-btn"
		data-payload="<?php echo esc_attr( wp_json_encode( $ie_export_payload ?? [] ) ); ?>"
		data-filename="<?php echo esc_attr( $ie_filename ?? 'instant-reveal-settings.json' ); ?>">&#11015; Download Export (.json)</button>
</div>

<div class="ck-settings-grid" style="padding:16px;">
	<h3 style="margin:0 0 6px;font-size:13px;font-weight:600;color:#374151;">Import</h3>
	<p style="font-size:11px;color:#9ca3af;margin:0 0 10px;">
		Choose a previously exported <code>.json</code> file or paste its contents below, then click Import.
		<strong>This will overwrite <?php echo ! empty( $ie_is_product ) ? "this product's saved overrides" : 'your global settings'; ?> and cannot be undone.</strong>
	</p>
	<div style="display:flex;flex-direction:column;gap:10px;">
		<input type="file" class="ir-ie-file-input" accept="application/json,.json">
		<textarea class="ck-text-input ir-ie-textarea" rows="8"
			style="max-width:none;width:100%;font-family:'SFMono-Regular',Consolas,monospace;font-size:11px;"
			placeholder="Paste exported JSON here"></textarea>
		<div style="display:flex;align-items:center;gap:10px;">
			<button type="button" class="ir-ie-btn ir-ie-import-btn"
				data-action="<?php echo esc_attr( ! empty( $ie_is_product ) ? 'ir_import_product_settings' : 'ir_import_global_settings' ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( ! empty( $ie_is_product ) ? 'ir_import_product_settings' : 'ir_import_global_settings' ) ); ?>"
				<?php if ( ! empty( $ie_is_product ) ) : ?>data-product-id="<?php echo esc_attr( $ie_product_id ?? 0 ); ?>"<?php endif; ?>
			>&#11014; Import Settings</button>
			<span class="ir-ie-status" style="font-size:12px;color:#6b7280;"></span>
		</div>
	</div>
</div>

<script>
(function(){
	if ( window.__irIEBound ) return;
	window.__irIEBound = true;

	document.addEventListener('click', function(e){
		var exp = e.target.closest('.ir-ie-export-btn');
		if (exp) {
			var data     = exp.getAttribute('data-payload');
			var filename = exp.getAttribute('data-filename') || 'instant-reveal-settings.json';
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
				if (!jsonStr) { status.textContent = 'Please choose a file or paste JSON first.'; status.style.color='#dc2626'; return; }
				if (!window.confirm('Import settings now? This cannot be undone.')) return;
				status.textContent = 'Importing…'; status.style.color='#6b7280';
				var body = new URLSearchParams();
				body.set('action', imp.getAttribute('data-action'));
				body.set('nonce',  imp.getAttribute('data-nonce'));
				body.set('data',   jsonStr);
				var pid = imp.getAttribute('data-product-id');
				if (pid) body.set('product_id', pid);
				fetch(ajaxurl, {method:'POST', credentials:'same-origin', body:body})
					.then(function(r){ return r.json(); })
					.then(function(res){
						if (res && res.success) {
							status.textContent = 'Imported! Reloading…'; status.style.color='#16a34a';
							setTimeout(function(){ location.reload(); }, 600);
						} else {
							status.textContent = (res && res.data && res.data.message) || 'Import failed.'; status.style.color='#dc2626';
						}
					})
					.catch(function(){ status.textContent='Import failed (network error).'; status.style.color='#dc2626'; });
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
