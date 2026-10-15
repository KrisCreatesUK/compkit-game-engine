<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * EIWR_Product_Panel
 *
 * Per-product Instant Reveal settings in the WooCommerce product data panel.
 * Uses _ir_ meta prefix. Falls back to global via EIWR_Settings::get_product_settings().
 */
class EIWR_Product_Panel {

	public static function register(): void {
		add_filter( 'lty_product_lottery_tab',                  [ __CLASS__, 'add_tab'      ] );
		add_action( 'woocommerce_product_data_panels',          [ __CLASS__, 'render_panel' ] );
		add_action( 'admin_enqueue_scripts',                    [ __CLASS__, 'enqueue'      ] );
		add_action( 'admin_head',                               [ __CLASS__, 'output_css'   ] );
		add_action( 'woocommerce_process_product_meta_lottery', [ __CLASS__, 'save'         ] );
		add_action( 'wp_ajax_ir_import_product_settings',       [ __CLASS__, 'ajax_import_product_settings' ] );
	}

	/* ── Tab ───────────────────────────────────────────────── */

	public static function add_tab( array $tabs ): array {
		$tabs['instant_reveal'] = [
			'label'    => 'Instant Reveal',
			'target'   => 'instant_reveal_tab',
			'class'    => [ 'show_if_lottery' ],
			'priority' => 80,
		];
		return $tabs;
	}

	/* ── AJAX: Import product settings ────────────────────── */

	public static function ajax_import_product_settings(): void {
		check_ajax_referer( 'ir_import_product_settings', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		$product_id = absint( $_POST['product_id'] ?? 0 );
		if ( ! $product_id ) {
			wp_send_json_error( [ 'message' => 'No product ID.' ] );
		}

		$raw  = wp_unslash( $_POST['data'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded; each field sanitized individually on save
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			wp_send_json_error( [ 'message' => 'Invalid JSON data.' ] );
		}

		$valid_styles = [ '', 'giftbox', 'envelope', 'flipcard' ];
		$style = isset( $data['style'] ) && in_array( $data['style'], $valid_styles, true ) ? $data['style'] : '';
		update_post_meta( $product_id, '_ir_style', $style );

		foreach ( [ 'primary', 'dark', 'table_header_bg', 'table_accent' ] as $k ) {
			$hex = isset( $data[ $k ] ) ? ( sanitize_hex_color( $data[ $k ] ) ?: '' ) : '';
			update_post_meta( $product_id, '_ir_' . $k, $hex );
		}
		foreach ( [ 'ticket_title', 'instruction_text', 'win_heading', 'lose_text' ] as $k ) {
			$val = isset( $data[ $k ] ) ? sanitize_text_field( $data[ $k ] ) : '';
			update_post_meta( $product_id, '_ir_' . $k, $val );
		}

		wp_send_json_success( [ 'message' => 'Imported successfully.' ] );
	}

	/* ── Enqueue ───────────────────────────────────────────── */

	public static function enqueue( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== 'product' ) return;

		wp_enqueue_script( 'jquery' );

		$js_file = EIWR_DIR . 'instant-reveal-admin-preview.js';
		wp_register_script( 'ir-admin-preview', file_exists( $js_file ) ? plugins_url( 'instant-reveal-admin-preview.js', $js_file ) : false, [ 'jquery' ], file_exists( $js_file ) ? (string) filemtime( $js_file ) : '1.0', true );
		wp_enqueue_script( 'ir-admin-preview' );
		wp_enqueue_style( 'eiwr-frontend-css', EIWR_URL . 'instant-reveal.css', [], eiwr_asset_version() );
		wp_add_inline_script( 'ir-admin-preview', self::panel_js() );

		$t = EIWR_Settings::get_theme();
		wp_localize_script( 'ir-admin-preview', 'irpvSaved', [
			'primary'     => $t['primary']          ?: '#A3EA41',
			'dark'        => $t['dark']             ?: '#6aa52b',
			'tableHeader' => $t['table_header_bg']  ?: '#2aa8f8',
			'tableAccent' => $t['table_accent']     ?: '#0d5fa9',
			'style'       => $t['style']            ?: 'giftbox',
			'ticketTitle' => $t['ticket_title']     ?: 'Your Tickets',
			'instruction' => $t['instruction_text'] ?: 'Click to reveal your prize',
			'winHeading'  => $t['win_heading']      ?: 'Congratulations, you have won:',
			'loseText'    => $t['lose_text']        ?: 'Sorry, better luck next time',
		] );
	}

	/* ── Output panel CSS (admin_head, product edit screen only) ── */

	public static function output_css(): void {
		if ( ! function_exists( 'get_current_screen' ) ) return;
		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== 'product' ) return;
		echo '<style>' . self::panel_css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/* ── Panel JS ──────────────────────────────────────────── */

	private static function panel_js(): string {
		ob_start();
		?>
		jQuery(function($){

			/* ── Colour field sync (.ck-color-input / .ck-color-hex / .ck-color-value) ── */
			$(document).on('input change', '#instant_reveal_tab .ck-color-input', function(){
				var f = $(this).closest('.ck-color-field');
				f.find('.ck-color-value').val(this.value).trigger('input');
				f.find('.ck-color-hex').val(this.value);
			});
			$(document).on('input change', '#instant_reveal_tab .ck-color-hex', function(){
				var hex = this.value.trim();
				if (!/^#[0-9a-fA-F]{6}$/.test(hex)) return;
				var f = $(this).closest('.ck-color-field');
				f.find('.ck-color-input').val(hex);
				f.find('.ck-color-value').val(hex).trigger('input');
			});

			/* ── "Use Global" buttons (.ir-use-global) ── */
			$(document).on('click', '.ir-use-global', function(){
				var id   = $(this).data('target');
				var val  = $(this).data('global');
				var type = $(this).data('type');
				var el   = document.getElementById(id);
				if (!el) return;
				if (type === 'color') {
					var field = $(el).closest('.ck-color-field');
					field.find('.ck-color-input,.ck-color-hex').val(val);
					$(el).val('').trigger('input').trigger('change');
				} else {
					$(el).val('').trigger('input').trigger('change');
				}
			});

			/* ── Live preview drawer toggle ── */
			function irSyncDrawerTab(){
				var open = $('#ir-prod-drawer').hasClass('open');
				$('.ir-drawer-tab-icon').toggle(!open);
				$('.ir-drawer-tab-close').toggle(open);
			}
			irSyncDrawerTab();
			$(document).on('click', '#ir-prod-drawer-tab', function(e){
				e.preventDefault();
				$('#ir-prod-drawer').toggleClass('open');
				irSyncDrawerTab();
			});

			/* ── Auto-open the preview drawer when the Instant Reveal tab is opened ── */
			$(document).on('click', '.product_data_tabs a[href="#instant_reveal_tab"]', function(){
				setTimeout(function(){
					$('#ir-prod-drawer').addClass('open');
					irSyncDrawerTab();
				}, 50);
			});
			if (location.hash === '#instant_reveal_tab' || $('.product_data_tabs li.instant_reveal_options_tab').hasClass('active')) {
				$('#ir-prod-drawer').addClass('open');
				irSyncDrawerTab();
			}

			/* ── Live preview field sync ── */
			var container = document.getElementById('ir-prod-prev-container');
			if (container) {
				var saved = window.irpvSaved || {};
				var currentProdStyle = (saved.style || 'giftbox');

				/* Init game interaction (irpvInitGame is defined in instant-reveal-admin-preview.js) */
				if (typeof irpvInitGame === 'function') irpvInitGame(container);

				function lightenHex(hex, amount){
					if (!hex || hex.length < 7) return hex;
					var r = parseInt(hex.slice(1,3),16), g = parseInt(hex.slice(3,5),16), b = parseInt(hex.slice(5,7),16);
					r = Math.min(255, Math.round(r + (255-r)*amount));
					g = Math.min(255, Math.round(g + (255-g)*amount));
					b = Math.min(255, Math.round(b + (255-b)*amount));
					return '#' + r.toString(16).padStart(2,'0') + g.toString(16).padStart(2,'0') + b.toString(16).padStart(2,'0');
				}

				function pv(key, fallback){
					var el = document.getElementById('ir_' + key);
					var v  = el ? String(el.value).trim() : '';
					return v !== '' ? v : (fallback || '');
				}

				function updatePreview(){
					var primary = pv('primary',         saved.primary);
					var dark    = pv('dark',            saved.dark);
					var thead   = pv('table_header_bg', saved.tableHeader);
					var taccent = pv('table_accent',    saved.tableAccent);
					var body    = lightenHex(primary, 0.22);

					/* CSS vars — both namespaces */
					[
						['--instant-reveal-primary', primary], ['--instant-reveal-body', body],
						['--instant-reveal-dark', dark], ['--instant-reveal-table-header', thead],
						['--instant-reveal-table-accent', taccent],
						['--eiwr-primary', primary], ['--eiwr-body', body],
						['--eiwr-dark', dark], ['--eiwr-table-header', thead],
						['--eiwr-table-accent', taccent]
					].forEach(function(p){ container.style.setProperty(p[0], p[1]); });

					/* Update mini style-card icons */
					document.querySelectorAll('#instant_reveal_tab .ir-style-cards').forEach(function(el){
						el.style.setProperty('--eiwr-primary', primary);
						el.style.setProperty('--eiwr-body',    body);
						el.style.setProperty('--eiwr-dark',    dark);
					});

					/* Text elements */
					var el;
					el = container.querySelector('#irpv-ticket-title');
					if (el) el.textContent = pv('ticket_title', saved.ticketTitle);

					container.querySelectorAll('.eiwr-instruction').forEach(function(e){
						e.textContent = pv('instruction_text', saved.instruction);
					});

					el = container.querySelector('#irpv-win-heading strong');
					if (el) el.textContent = pv('win_heading', saved.winHeading);

					/* Style switching */
					var styleSel = document.getElementById('ir_style');
					var style = (styleSel && styleSel.value) ? styleSel.value : saved.style;
					if (style !== currentProdStyle) {
						currentProdStyle = style;
						if (typeof irpvSwitchStyle === 'function') irpvSwitchStyle(container, style);
					}
				}

				document.addEventListener('input',  function(e){ if (!e.target.closest('#ir-prod-prev-container')) updatePreview(); });
				document.addEventListener('change', function(e){ if (!e.target.closest('#ir-prod-prev-container')) updatePreview(); });
				updatePreview();
			}

		});
		<?php
		return ob_get_clean();
	}

	/* ── Panel CSS ─────────────────────────────────────────── */

	private static function panel_css(): string {
		return '
#instant_reveal_tab{padding:24px;}
#instant_reveal_tab .ck-game-admin{padding:14px 0 24px;}
#instant_reveal_tab .ir-prod-intro{font-size:12px;color:#6b7280;margin:0 0 20px;}
#instant_reveal_tab .ck-game-admin h2{margin:24px 0 12px!important;font-size:11px!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:1.2px!important;color:#9ca3af!important;border:none!important;background:none!important;padding:0!important;}
#instant_reveal_tab .ck-game-admin h2:first-of-type{margin-top:6px!important;}
#instant_reveal_tab .fbp-tab-nav{margin-bottom:16px;}
#instant_reveal_tab .fbp-tab-section{padding:16px 0 8px;}
#instant_reveal_tab .ck-settings-grid{margin:0 0 16px;border:1.5px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;}
.ck-settings-grid--2col{display:grid;grid-template-columns:1fr 1fr;}
.ck-settings-grid--2col .ck-setting-row{grid-template-columns:1fr;padding:12px 14px;align-items:start;}
.ck-settings-grid--2col .ck-setting-row:nth-child(odd){border-right:1px solid #f3f4f6;}
.ck-settings-grid--2col .ck-setting-label{padding:0 0 6px;align-self:auto;padding-top:0;}
.ck-settings-grid--2col .ck-setting-control{min-height:unset;padding-right:0;}
.ck-settings-grid--2col .ck-setting-row:nth-last-child(-n+2):nth-child(odd),.ck-settings-grid--2col .ck-setting-row:nth-last-child(1){border-bottom:none;}
.ck-setting-row{display:grid;grid-template-columns:220px 1fr;align-items:center;padding:20px 0 20px 24px;border-bottom:1px solid #f3f4f6;}
.ck-setting-row:last-child{border-bottom:none;}
.ck-setting-row:hover{background:#fafafa;}
.ck-setting-label{padding-right:20px;align-self:start;padding-top:8px;}
.ck-setting-name{display:block;font-size:13px;font-weight:600;color:#374151;}
.ck-setting-desc{display:block;font-size:12px;color:#9ca3af;margin-top:3px;line-height:1.45;}
.ck-setting-control{display:flex;align-items:center;min-height:44px;flex-wrap:wrap;gap:12px;padding-right:20px;}
.ck-text-input{height:36px;padding:0 12px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;color:#374151;background:#fff;transition:border-color .15s;outline:none;width:100%;max-width:340px;}
.ck-text-input:focus{border-color:#38d4c0;box-shadow:0 0 0 3px rgba(56,212,192,.12);}
.ck-color-field{display:flex;align-items:center;gap:10px;}
.ck-color-input{-webkit-appearance:none;appearance:none;width:44px;height:44px;border:2px solid #e5e7eb;border-radius:10px;cursor:pointer;padding:3px;background:none;transition:border-color .15s;}
.ck-color-input::-webkit-color-swatch-wrapper{padding:0;}
.ck-color-input::-webkit-color-swatch{border:none;border-radius:6px;}
.ck-color-input:hover{border-color:#9ca3af;}
.ck-color-hex{height:36px;font-size:11px;font-family:"SFMono-Regular",Consolas,monospace;color:#374151;background:#f9fafb;border:1.5px solid #e5e7eb;padding:0 8px;border-radius:6px;letter-spacing:.5px;min-width:76px;}
.ck-select{height:36px;padding:0 10px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;color:#374151;background:#fff;min-width:200px;cursor:pointer;outline:none;transition:border-color .15s;}
.ck-btn{height:36px;padding:0 14px;background:#f3f4f6;border:1.5px solid #e5e7eb;border-radius:8px;font-size:12px;font-weight:600;color:#374151;cursor:pointer;white-space:nowrap;}
.ck-btn:hover{background:#e5e7eb;}
.ck-btn-ghost{background:none;border:1.5px solid #e5e7eb;color:#6b7280;}
.ck-btn-ghost:hover{border-color:var(--game-accent,#38d4c0);color:var(--game-accent,#38d4c0);}
.ir-global-swatch{display:inline-block;width:10px;height:10px;border-radius:2px;vertical-align:-1px;border:1px solid #ddd;}
#instant_reveal_tab .fbp-tab-nav{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:20px;}
#instant_reveal_tab .fbp-tab-btn{font-size:11px;font-weight:600;padding:5px 12px;border-radius:20px;border:1.5px solid #e5e7eb;background:#f9fafb;color:#6b7280;cursor:pointer;transition:all .15s;white-space:nowrap;}
#instant_reveal_tab .fbp-tab-btn:hover{border-color:#38d4c0!important;color:#38d4c0!important;background:#f0fdfa!important;}
#instant_reveal_tab .fbp-tab-btn.active{background:#38d4c0!important;border-color:#38d4c0!important;color:#fff!important;}
#instant_reveal_tab .fbp-tab-section{display:none;}
#instant_reveal_tab .fbp-tab-section.active{display:block;}
#ir-prod-drawer{position:fixed;top:32px;right:-400px;width:380px;height:calc(100vh - 32px);background:#f5f5f5;z-index:99999;transition:right .3s ease;box-shadow:-4px 0 24px rgba(0,0,0,.3);display:flex;flex-direction:column;padding:0!important;margin:0!important;}
#ir-prod-drawer.open{right:0;}
.ir-drawer-tab{position:absolute;left:-44px;top:50%;transform:translateY(-50%);width:44px;padding:14px 0;background:var(--game-accent,#38d4c0);color:#fff;border-radius:10px 0 0 10px;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:-3px 0 14px rgba(56,212,192,.4);user-select:none;transition:background .15s;}
.ir-drawer-tab:hover{background:#2bb9a7;}
.ir-drawer-tab-icon{width:28px;height:28px;flex-shrink:0;background:#fff;-webkit-mask-repeat:no-repeat;-webkit-mask-position:center;-webkit-mask-size:contain;mask-repeat:no-repeat;mask-position:center;mask-size:contain;}
.ir-drawer-tab-close{display:none;font-size:22px;line-height:1;}
#ir-prod-drawer.open .ir-drawer-tab-icon{display:none;}
#ir-prod-drawer.open .ir-drawer-tab-close{display:block;}
.ir-drawer-body{flex:1;overflow-y:auto;overflow-x:hidden;padding:14px;}
.irpv-wrap{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;padding:10px 0;}
.irpv-ticket-title-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;}
.irpv-ticket-title-row>span{font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.6px;}
.irpv-ticket-badges{display:flex;gap:4px;}
.irpv-badge{font-size:10px;font-weight:700;background:var(--instant-reveal-primary,#A3EA41);color:#fff;padding:2px 8px;border-radius:20px;}
.irpv-reveal-area{display:flex;flex-direction:column;align-items:center;padding:12px 0 8px;}
.irpv-instruction{font-size:11px;color:var(--instant-reveal-dark,#6aa52b);margin:6px 0 0;text-align:center;}
.irpv-giftbox{display:flex;flex-direction:column;align-items:center;width:56px;}
.irpv-giftbox-lid{width:60px;height:18px;border-radius:4px 4px 0 0;}
.irpv-giftbox-body{width:56px;height:38px;border-radius:0 0 6px 6px;display:flex;align-items:center;justify-content:center;}
.irpv-giftbox-ribbon{display:block;width:8px;height:100%;border-radius:3px;}
.irpv-envelope{position:relative;width:72px;height:48px;}
.irpv-envelope-flap{position:absolute;top:0;left:0;width:0;height:0;border-left:36px solid transparent;border-right:36px solid transparent;border-bottom:28px solid;}
.irpv-envelope-body{position:absolute;bottom:0;left:0;width:72px;height:40px;border:2px solid;}
.irpv-flipcard{width:52px;height:72px;}
.irpv-flipcard-face{width:100%;height:100%;border-radius:8px;}
.irpv-result-win,.irpv-result-lose{padding:6px 0;}
.irpv-prize-table{width:100%;border-collapse:collapse;font-size:11px;}
.irpv-prize-table th,.irpv-prize-table td{padding:4px 8px;text-align:left;}
.irpv-prize-table th{font-size:10px;font-weight:700;text-transform:uppercase;}
.irpv-prize-table tbody tr:nth-child(even){background:#f9fafb;}
';
	}

	/* ── Field helpers ─────────────────────────────────────── */

	private static function build_prod_helpers( int $product_id, array $g ): array {

		$read_meta = function( string $key ) use ( $product_id ): string {
			$val = get_post_meta( $product_id, '_ir_' . $key, true );
			return ( $val === false ) ? '' : (string) $val;
		};

		$use_global_btn = function( string $target_id, string $global_val, string $type = 'text' ): string {
			return '<button type="button" class="ck-btn ck-btn-ghost ir-use-global" data-target="' . esc_attr( $target_id ) . '" data-global="' . esc_attr( $global_val ) . '" data-type="' . esc_attr( $type ) . '" title="Use Global Default">&#8617; Use Global</button>';
		};

		$pc = function( string $key, string $label, string $desc = '' ) use ( $read_meta, $g, $use_global_btn ): void {
			$meta_key   = '_ir_' . $key;
			$id         = 'ir_' . $key;
			$global_val = (string) ( $g[ $key ] ?? '#000000' );
			$val        = $read_meta( $key );
			$display    = $val !== '' ? $val : $global_val;
			?>
			<div class="ck-setting-row">
				<div class="ck-setting-label">
					<span class="ck-setting-name"><?php echo esc_html( $label ); ?></span>
					<span class="ck-setting-desc"><span class="ir-global-swatch" style="background:<?php echo esc_attr( $global_val ); ?>;"></span> Global: <?php echo esc_html( $global_val ); ?><?php if ( $desc ) echo ' — ' . esc_html( $desc ); ?></span>
				</div>
				<div class="ck-setting-control">
					<div class="ck-color-field">
						<input type="hidden" class="ck-color-value" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $meta_key ); ?>" value="<?php echo esc_attr( $val ); ?>">
						<input type="color" class="ck-color-input" value="<?php echo esc_attr( $display ); ?>">
						<input type="text" class="ck-color-hex" maxlength="7" value="<?php echo esc_attr( $display ); ?>" spellcheck="false" autocomplete="off">
					</div>
					<?php echo $use_global_btn( $id, $global_val, 'color' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- internal markup ?>
				</div>
			</div>
			<?php
		};

		$pt = function( string $key, string $label, string $desc = '' ) use ( $read_meta, $g, $use_global_btn ): void {
			$meta_key   = '_ir_' . $key;
			$id         = 'ir_' . $key;
			$global_val = (string) ( $g[ $key ] ?? '' );
			$val        = $read_meta( $key );
			?>
			<div class="ck-setting-row">
				<div class="ck-setting-label">
					<span class="ck-setting-name"><?php echo esc_html( $label ); ?></span>
					<span class="ck-setting-desc">Global: &ldquo;<?php echo esc_html( $global_val ); ?>&rdquo;<?php if ( $desc ) echo ' — ' . esc_html( $desc ); ?></span>
				</div>
				<div class="ck-setting-control">
					<input type="text" class="ck-text-input" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $meta_key ); ?>" value="<?php echo esc_attr( $val ); ?>" placeholder="<?php echo esc_attr( $global_val ); ?>">
					<?php echo $use_global_btn( $id, $global_val, 'text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- internal markup ?>
				</div>
			</div>
			<?php
		};

		return compact( 'pc', 'pt', 'read_meta' );
	}

	/* ── Render the product panel ──────────────────────────── */

	public static function render_panel(): void {
		global $post;
		$product_id = (int) ( $post->ID ?? 0 );
		if ( ! $product_id ) return;

		$g         = EIWR_Settings::get_theme();
		$effective = EIWR_Settings::get_product_settings( $product_id );
		$css_vars  = EIWR_Settings::build_css_vars_from( $effective );

		$h         = self::build_prod_helpers( $product_id, $g );
		$pc        = $h['pc'];
		$pt        = $h['pt'];
		$read_meta = $h['read_meta'];

		// Single source of truth: the core plugin's /icons folder (same as the games).
		$icon_url = esc_url( ( defined( 'GIVEAWAYS_URL' ) ? GIVEAWAYS_URL : EIWR_URL ) . 'icons/instant-reveal.svg' );

		$tabs = [
			'style'         => 'Style',
			'colours'       => 'Colours',
			'text'          => 'Text',
			'import-export' => 'Import / Export',
		];

		$saved_style  = $read_meta( 'style' );
		$valid_styles = [ 'giftbox' => '&#127873; Gift Box', 'envelope' => '&#9993;&#65039; Envelope', 'flipcard' => '&#127183; Flip Card' ];

		/* Import/Export payload for the product tab */
		$ie_prod_data = [];
		foreach ( array_merge(
			[ 'style' ],
			[ 'primary', 'dark', 'table_header_bg', 'table_accent' ],
			[ 'ticket_title', 'instruction_text', 'win_heading', 'lose_text' ]
		) as $k ) {
			$saved = get_post_meta( $product_id, '_ir_' . $k, true );
			if ( $saved !== '' && $saved !== false ) {
				$ie_prod_data[ $k ] = $saved;
			}
		}
		$ie_export_payload = $ie_prod_data;
		$ie_filename       = 'ir-product-' . $product_id . '.json';

		?>
		<div id="instant_reveal_tab" class="panel woocommerce_options_panel lty_lottery_product_tab">
		<div class="ck-game-admin ir-game-admin" style="--game-accent:#38d4c0;">

			<?php wp_nonce_field( 'ir_product_save', 'ir_product_nonce' ); ?>

			<p class="ir-prod-intro">Override the global Instant Reveal settings for this product. Leave a field on its current value to keep using the global default — click <strong>Use Global</strong> to reset it.</p>

			<nav class="fbp-tab-nav" data-tabgroup="irprod">
				<?php $first = true; foreach ( $tabs as $slug => $label ) :
					$active = $first ? ' active' : ''; $first = false; ?>
					<button type="button" class="fbp-tab-btn<?php echo esc_attr( $active ); ?>" data-tab="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></button>
				<?php endforeach; ?>
			</nav>

			<div id="ir-prodtabgroup">

				<!-- STYLE TAB -->
				<div class="fbp-tab-section active" data-tab="style" data-tabgroup="irprod">
					<h2>Reveal Style</h2>
					<div class="ck-settings-grid">
						<div class="ck-setting-row">
							<div class="ck-setting-label">
								<span class="ck-setting-name">Reveal Style</span>
								<span class="ck-setting-desc">Global: <?php echo esc_html( ucfirst( $g['style'] ?? 'giftbox' ) ); ?></span>
							</div>
							<div class="ck-setting-control">
								<select id="ir_style" name="_ir_style" class="ck-select">
									<option value="" <?php selected( $saved_style, '' ); ?>>&mdash; Use Global &mdash;</option>
									<?php foreach ( $valid_styles as $val => $label ) : ?>
										<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $saved_style, $val ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
					</div>
				</div>

				<!-- COLOURS TAB -->
				<div class="fbp-tab-section" data-tab="colours" data-tabgroup="irprod">
					<h2>Colours</h2>
					<div class="ck-settings-grid ck-settings-grid--2col">
						<?php
						$pc( 'primary',         'Primary Colour',         'Ticket badges, gift lid, envelope flap &amp; flip card face' );
						$pc( 'dark',            'Accent Colour',          'Reveal instruction text — usually a darker shade of primary' );
						$pc( 'table_header_bg', 'Table Header Background', 'Background of the prize table header row' );
						$pc( 'table_accent',    'Table Accent Colour',    'Win heading text &amp; ticket number column' );
						?>
					</div>
				</div>

				<!-- TEXT TAB -->
				<div class="fbp-tab-section" data-tab="text" data-tabgroup="irprod">
					<h2>Text Content</h2>
					<div class="ck-settings-grid ck-settings-grid--2col">
						<?php
						$pt( 'ticket_title',     'Ticket Section Title', 'Heading shown above the customer\'s ticket numbers' );
						$pt( 'instruction_text', 'Reveal Instruction',   'Text beneath the animation prompting the customer to click' );
						$pt( 'win_heading',      'Win Heading',          'Title shown above the prize table when the customer has won' );
						$pt( 'lose_text',        'No Win Message',       'Shown when the customer\'s ticket did not win an instant prize' );
						?>
					</div>
				</div>

				<!-- IMPORT/EXPORT TAB -->
				<div class="fbp-tab-section" data-tab="import-export" data-tabgroup="irprod">
					<h2>Import / Export</h2>
					<?php include EIWR_DIR . 'includes/views/product/tab-import-export.php'; ?>
				</div>

			</div><!-- /#ir-prodtabgroup -->

			<div id="ir-prod-drawer">
				<div class="ir-drawer-tab" id="ir-prod-drawer-tab">
					<span class="ir-drawer-tab-icon" style="-webkit-mask-image:url('<?php echo esc_url( $icon_url ); ?>');mask-image:url('<?php echo esc_url( $icon_url ); ?>');"></span>
					<span class="ir-drawer-tab-close">&times;</span>
				</div>
				<div class="ir-drawer-body">
					<div id="ir-prod-prev-container" style="<?php echo esc_attr( $css_vars ); ?>">
						<?php EIWR_Admin_Page::render_preview_scene( $effective ); ?>
					</div>
				</div>
			</div>

		</div><!-- /.ck-game-admin -->
		</div><!-- /#instant_reveal_tab -->
		<?php
	}

	/* ── Save ──────────────────────────────────────────────── */

	public static function save( int $post_id ): void {
		if (
			! isset( $_POST['ir_product_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ir_product_nonce'] ) ), 'ir_product_save' )
		) return;

		/* Style */
		$valid_styles = [ '', 'giftbox', 'envelope', 'flipcard' ];
		$style        = sanitize_text_field( wp_unslash( $_POST['_ir_style'] ?? '' ) );
		if ( ! in_array( $style, $valid_styles, true ) ) $style = '';
		update_post_meta( $post_id, '_ir_style', $style );

		/* Colours */
		foreach ( [ 'primary', 'dark', 'table_header_bg', 'table_accent' ] as $key ) {
			$raw = wp_unslash( $_POST[ '_ir_' . $key ] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_hex_color() on next line
			$val = $raw !== '' ? ( sanitize_hex_color( $raw ) ?: '' ) : '';
			update_post_meta( $post_id, '_ir_' . $key, $val );
		}

		/* Text */
		foreach ( [ 'ticket_title', 'instruction_text', 'win_heading', 'lose_text' ] as $key ) {
			$val = sanitize_text_field( wp_unslash( $_POST[ '_ir_' . $key ] ?? '' ) );
			update_post_meta( $post_id, '_ir_' . $key, $val );
		}

		/* Migrate any old _eiwr_* meta written by the previous class version */
		static $migrated = false;
		if ( ! $migrated ) {
			$migrated = true;
			$legacy_map = [
				'style' => '_eiwr_style', 'primary' => '_eiwr_primary', 'dark' => '_eiwr_dark',
				'table_header_bg' => '_eiwr_table_header_bg', 'table_accent' => '_eiwr_table_accent',
				'ticket_title' => '_eiwr_ticket_title', 'instruction_text' => '_eiwr_instruction_text',
				'win_heading' => '_eiwr_win_heading', 'lose_text' => '_eiwr_lose_text',
			];
			foreach ( $legacy_map as $new_key => $old_key ) {
				$old_val = get_post_meta( $post_id, $old_key, true );
				if ( $old_val !== '' && $old_val !== false ) {
					$new_val = get_post_meta( $post_id, '_ir_' . $new_key, true );
					if ( $new_val === '' || $new_val === false ) {
						update_post_meta( $post_id, '_ir_' . $new_key, $old_val );
					}
					delete_post_meta( $post_id, $old_key );
				}
			}
		}
	}
}
