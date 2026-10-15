<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * EIWR_Admin_Page
 *
 * Global settings page for Instant Reveal. Tab nav + live preview.
 * Tab content lives in includes/views/global/tab-*.php, included via include_tab().
 */
class EIWR_Admin_Page {

	private static array $theme = [];

	/* ── Registration ─────────────────────────────────────── */

	public static function register(): void {
		add_action( 'admin_menu',            [ __CLASS__, 'add_menu'    ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue'     ] );
		add_action( 'admin_head',            [ __CLASS__, 'output_css'  ] );
		add_action( 'wp_ajax_ir_import_global_settings', [ __CLASS__, 'ajax_import_global_settings' ] );
	}

	/* ── Menu ─────────────────────────────────────────────── */

	public static function add_menu(): void {
		add_submenu_page(
			'giveaways-addons',
			'Instant Reveal Settings',
			'Instant Reveal Settings',
			'manage_woocommerce',
			'instant-reveal',
			[ __CLASS__, 'render' ]
		);
	}

	/* ── AJAX: Import global settings ─────────────────────── */

	public static function ajax_import_global_settings(): void {
		check_ajax_referer( 'ir_import_global_settings', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ] );
		}

		$raw  = wp_unslash( $_POST['data'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded; each field sanitized individually on save
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			wp_send_json_error( [ 'message' => 'Invalid JSON data.' ] );
		}
		if ( isset( $data['theme'] ) && is_array( $data['theme'] ) ) {
			$data = $data['theme'];
		}

		$clean = [];
		$valid_styles = [ 'giftbox', 'envelope', 'flipcard' ];
		if ( isset( $data['style'] ) && in_array( $data['style'], $valid_styles, true ) ) {
			$clean['style'] = $data['style'];
		}
		foreach ( [ 'primary', 'dark', 'table_header_bg', 'table_accent' ] as $k ) {
			if ( isset( $data[ $k ] ) && $data[ $k ] !== '' ) {
				$hex = sanitize_hex_color( $data[ $k ] );
				if ( $hex ) $clean[ $k ] = $hex;
			}
		}
		foreach ( [ 'ticket_title', 'instruction_text', 'win_heading', 'lose_text' ] as $k ) {
			if ( isset( $data[ $k ] ) ) {
				$clean[ $k ] = sanitize_text_field( $data[ $k ] );
			}
		}

		$merged = wp_parse_args( $clean, EIWR_Settings::get_theme() );
		EIWR_Settings::save( $merged );

		wp_send_json_success( [ 'message' => 'Settings imported successfully.' ] );
	}

	/* ── Enqueue assets ───────────────────────────────────── */

	public static function enqueue( string $hook ): void {
		if ( ( sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) ) ) !== 'instant-reveal' ) return; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_enqueue_script( 'jquery' );

		$js_file = EIWR_DIR . 'instant-reveal-admin-preview.js';
		wp_register_script( 'ir-admin-preview', file_exists( $js_file ) ? plugins_url( 'instant-reveal-admin-preview.js', $js_file ) : false, [ 'jquery' ], file_exists( $js_file ) ? (string) filemtime( $js_file ) : '1.0', true );
		wp_enqueue_script( 'ir-admin-preview' );
		wp_enqueue_style( 'eiwr-frontend-css', EIWR_URL . 'instant-reveal.css', [], eiwr_asset_version() );

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

	/* ── Render the settings page ─────────────────────────── */

	public static function render(): void {
		self::$theme = EIWR_Settings::get_theme();
		$message     = '';

		if ( isset( $_POST['ir_settings_nonce'] ) && check_admin_referer( 'ir_save_settings', 'ir_settings_nonce' ) ) {
			if ( isset( $_POST['ir_settings_reset'] ) ) {
				delete_option( EIWR_Settings::OPTION_KEY );
				self::$theme = EIWR_Settings::get_theme();
				$message     = 'Settings reset to defaults.';
			} elseif ( isset( $_POST['ir_settings_submit'] ) ) {
				self::handle_save();
				self::$theme = EIWR_Settings::get_theme();
				$message     = 'Settings saved.';
			}
		}

		$theme    = self::$theme;
		$ctx      = self::build_ctx( $theme );
		$css_vars = EIWR_Settings::build_css_vars_from( $theme );

		$tabs = [
			'style'         => [ 'label' => 'Reveal Style',   'view' => 'tab-style' ],
			'colours'       => [ 'label' => 'Colours',        'view' => 'tab-colours' ],
			'text'          => [ 'label' => 'Text Content',   'view' => 'tab-text' ],
			'import-export' => [ 'label' => 'Import / Export','view' => 'tab-import-export' ],
		];

		$ie_ctx = [
			'ie_export_payload' => [
				'type'    => 'instant_reveal_global_settings',
				'version' => 1,
				'theme'   => $theme,
			],
			'ie_filename'   => 'instant-reveal-global-' . gmdate( 'Y-m-d' ) . '.json',
			'ie_is_product' => false,
			'ie_product_id' => 0,
		];
		?>
		<div class="wrap ck-game-admin ir-game-admin" style="--game-accent:#38d4c0;">
		<div class="ck-page-container">

			<div class="ck-game-page-header">
				<?php if ( defined( 'GIVEAWAYS_URL' ) ) : ?>
					<img src="<?php echo esc_url( GIVEAWAYS_URL . 'assets/img/' . ( defined( 'GIVEAWAYS_WL_LOGO_FILE' ) ? GIVEAWAYS_WL_LOGO_FILE : 'compkit-logo-300.png' ) ); ?>"
					     alt="<?php echo esc_attr( defined( 'GIVEAWAYS_WL_PLUGIN_NAME' ) ? GIVEAWAYS_WL_PLUGIN_NAME : 'CompKit Game Engine' ); ?>"
					     class="ck-game-header-logo">
				<?php endif; ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=giveaways-addons' ) ); ?>" class="ck-back-link">&larr; All Games</a>
			</div>

			<div class="ir-page-title">
				<div class="ir-page-title-icon"></div>
				<div class="ir-page-title-body">
					<h1>Instant Reveal Settings</h1>
					<p>Global settings apply to all products unless overridden per product. Add products to the <strong>Instant Reveal</strong> category or use the shortcode <code>[instant_reveal]</code>.</p>
				</div>
			</div>

			<?php if ( $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<div class="ck-page-body">

				<div class="ck-settings-col">
					<form method="post" class="ir-settings-form">
						<?php wp_nonce_field( 'ir_save_settings', 'ir_settings_nonce' ); ?>

						<div class="ir-tab-row-wrap">
							<div class="ir-tab-navs">
								<nav class="fbp-tab-nav" data-tabgroup="ir-global">
									<?php $first = true; foreach ( $tabs as $slug => $info ) :
										$active = $first ? ' active' : ''; $first = false; ?>
										<button type="button" class="fbp-tab-btn<?php echo esc_attr( $active ); ?>"
										        data-tab="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $info['label'] ); ?></button>
									<?php endforeach; ?>
								</nav>
							</div>
						</div>

						<div id="ir-tabgroup-global">
							<?php $first = true; foreach ( $tabs as $slug => $info ) :
								$active = $first ? ' active' : ''; $first = false; ?>
								<div class="fbp-tab-section<?php echo esc_attr( $active ); ?>"
								     data-tab="<?php echo esc_attr( $slug ); ?>"
								     data-tabgroup="ir-global">
									<?php self::include_tab( $info['view'], $ctx, $slug === 'import-export' ? $ie_ctx : [] ); ?>
								</div>
							<?php endforeach; ?>
						</div>

						<div class="ck-save-bar">
							<button type="submit" name="ir_settings_reset" value="1" class="ck-btn-reset"
							        onclick="return confirm('Reset all Instant Reveal settings to defaults? This cannot be undone.');">Reset to Defaults</button>
							<button type="submit" name="ir_settings_submit" value="1" class="ck-btn-save">Save Settings</button>
						</div>
					</form>
				</div><!-- /.ck-settings-col -->

				<div class="ck-preview-col">
					<div class="ck-preview-panel" id="ir-preview-panel">
						<div class="ck-preview-panel-hd">
							<span class="ck-preview-panel-title">&#128065; Live Preview</span>
							<button type="button" class="ck-preview-desktop-btn" id="ir-preview-desktop-btn">View Desktop</button>
						</div>
						<div class="ck-preview-panel-body">
							<div class="ir-fo-site-bar">
								<span class="ir-fo-site-name">&#127942; Competition Shop</span>
								<span class="ir-fo-site-nav">Home &bull; Shop &bull; Account</span>
							</div>
							<div class="ir-fo-page-content">
								<div class="ir-fo-page-hd"><h1 class="ir-fo-h1">Order received</h1></div>
								<div class="ir-fo-order-info">
									<p class="ir-fo-notice">Thank you. Your order has been received.</p>
									<ul class="ir-fo-overview">
										<li><small>Order number</small><strong>12847</strong></li>
										<li><small>Date</small><strong>22 June 2025</strong></li>
										<li><small>Email</small><strong>customer@email.com</strong></li>
										<li><small>Total</small><strong>&pound;29.99</strong></li>
										<li><small>Payment</small><strong>Credit Card</strong></li>
									</ul>
									<section class="ir-fo-details">
										<h2 class="ir-fo-h2">Order details</h2>
										<table class="ir-fo-table">
											<thead><tr><th>Product</th><th>Total</th></tr></thead>
											<tbody>
												<tr>
													<td>Summer Prize Draw &times; <strong>3</strong></td>
													<td>&pound;29.99</td>
												</tr>
											</tbody>
											<tfoot>
												<tr><th>Subtotal:</th><td>&pound;29.99</td></tr>
												<tr><th>Total:</th><td><strong>&pound;29.99</strong></td></tr>
											</tfoot>
										</table>
									</section>
									<h2 class="ir-fo-h2 ir-fo-game-heading">Your Instant Win Result</h2>
								</div>
								<div id="ir-prev-container" style="<?php echo esc_attr( $css_vars ); ?>">
									<?php self::render_preview_scene( $theme ); ?>
								</div>
							</div>
						</div>
						<div class="ck-preview-panel-ft">Live preview &bull; sample data</div>
					</div>
				</div><!-- /.ck-preview-col -->

			</div><!-- /.ck-page-body -->

		</div><!-- /.ck-page-container -->
		</div>
		<?php
	}

	/* ── Preview scene ────────────────────────────────────── */

	public static function render_preview_scene( array $t ): void {
		$style = $t['style'] ?? 'giftbox';
		$title = $t['ticket_title']     ?: 'Your Tickets';
		$instr = $t['instruction_text'] ?: 'Click to reveal your prize';
		$win_h = $t['win_heading']      ?: 'Congratulations, you have won:';
		$lose  = $t['lose_text']        ?: 'Sorry, better luck next time';
		?>
		<div class="enhanced-instant-win-reveal eiwr-admin-preview" data-lose-text="<?php echo esc_attr( $lose ); ?>">

			<div class="ticket-wrap">
				<div class="ticket-title" id="irpv-ticket-title"><?php echo esc_html( $title ); ?></div>
				<div class="ticket-list">
					<span class="ticket-badge">4746</span>
					<span class="ticket-badge">9486</span>
					<span class="ticket-badge">7236</span>
				</div>
			</div>

			<div class="gift-reveal-container">
				<div class="eiwr-giftbox" data-reveal="giftbox"<?php echo $style !== 'giftbox' ? ' style="display:none"' : ''; ?>>
					<div class="eiwr-gift-lid"></div>
					<div class="eiwr-gift-body"></div>
					<div class="eiwr-gift-bow"></div>
				</div>
				<div class="eiwr-envelope" data-reveal="envelope"<?php echo $style !== 'envelope' ? ' style="display:none"' : ''; ?>>
					<div class="eiwr-env-body">
						<div class="eiwr-env-flap"></div>
						<div class="eiwr-env-letter">&#x2709;</div>
					</div>
				</div>
				<div class="eiwr-flipcard" data-reveal="flipcard"<?php echo $style !== 'flipcard' ? ' style="display:none"' : ''; ?>>
					<div class="eiwr-flipcard-inner">
						<div class="eiwr-flipcard-back">
							<div class="eiwr-card-pattern"></div>
							<div class="eiwr-card-qmark">?</div>
						</div>
						<div class="eiwr-flipcard-front">
							<div class="eiwr-card-icon">&#127873;</div>
						</div>
					</div>
				</div>
			</div>

			<p class="eiwr-instruction"><?php echo esc_html( $instr ); ?></p>

			<div class="instant-win-message hidden">
				<p class="instant-win-title" id="irpv-win-heading"><strong><?php echo esc_html( $win_h ); ?></strong></p>
				<table class="instant-win-table">
					<thead><tr><th>Ticket #</th><th>Prize</th></tr></thead>
					<tbody>
						<tr><td>4746</td><td>Weekend Getaway</td></tr>
					</tbody>
				</table>
			</div>

		</div>
		<?php
	}

	/* ── Save handler ─────────────────────────────────────── */

	private static function handle_save(): void {
		$hex = function( string $key, string $fallback ) {
			$v = sanitize_hex_color( wp_unslash( $_POST[ 'ir_' . $key ] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by caller
			return $v ?: $fallback;
		};
		$txt = function( string $key, string $fallback = '' ) {
			return sanitize_text_field( wp_unslash( $_POST[ 'ir_' . $key ] ?? $fallback ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by caller
		};

		$defaults     = EIWR_Settings::defaults();
		$valid_styles = [ 'giftbox', 'envelope', 'flipcard' ];
		$raw_style    = $txt( 'style', 'giftbox' );

		EIWR_Settings::save( [
			'style'            => in_array( $raw_style, $valid_styles, true ) ? $raw_style : 'giftbox',
			'primary'          => $hex( 'primary',         $defaults['primary'] ),
			'dark'             => $hex( 'dark',            $defaults['dark'] ),
			'table_header_bg'  => $hex( 'table_header_bg', $defaults['table_header_bg'] ),
			'table_accent'     => $hex( 'table_accent',    $defaults['table_accent'] ),
			'ticket_title'     => $txt( 'ticket_title',     $defaults['ticket_title'] ),
			'instruction_text' => $txt( 'instruction_text', $defaults['instruction_text'] ),
			'win_heading'      => $txt( 'win_heading',      $defaults['win_heading'] ),
			'lose_text'        => $txt( 'lose_text',        $defaults['lose_text'] ),
		] );
	}

	/* ── Build view-helper context ────────────────────────── */

	public static function build_ctx( array $theme ): array {

		$cr = function( string $key, string $label, string $desc = '' ) use ( $theme ): void {
			$fld    = 'ir_' . $key;
			$saved  = $theme[ $key ] ?? '';
			$is_t   = ( $saved === 'transparent' || $saved === '' );
			$swatch = $is_t ? '#000000' : $saved;
			echo '<div class="ck-setting-row">';
			echo '<div class="ck-setting-label"><span class="ck-setting-name">' . esc_html( $label ) . '</span>';
			if ( $desc ) echo '<span class="ck-setting-desc">' . esc_html( $desc ) . '</span>';
			echo '</div><div class="ck-setting-control"><div class="ck-color-field">';
			echo '<input type="hidden" name="' . esc_attr( $fld ) . '" class="ck-color-value" value="' . esc_attr( $saved ) . '">';
			echo '<input type="color" id="' . esc_attr( $fld ) . '" value="' . esc_attr( $swatch ) . '" class="ck-color-input">';
			echo '<code class="ck-color-hex">' . esc_html( $saved ) . '</code>';
			echo '</div></div></div>';
		};

		$tr = function( string $key, string $label, string $placeholder = '', string $desc = '' ) use ( $theme ): void {
			$fld = 'ir_' . $key;
			$val = $theme[ $key ] ?? '';
			echo '<div class="ck-setting-row">';
			echo '<div class="ck-setting-label"><span class="ck-setting-name">' . esc_html( $label ) . '</span>';
			if ( $desc ) echo '<span class="ck-setting-desc">' . esc_html( $desc ) . '</span>';
			echo '</div><div class="ck-setting-control">';
			echo '<input type="text" id="' . esc_attr( $fld ) . '" name="' . esc_attr( $fld ) . '" value="' . esc_attr( $val )
			   . '" placeholder="' . esc_attr( $placeholder ) . '" class="ck-text-input" style="max-width:340px;">';
			echo '</div></div>';
		};

		return compact( 'theme', 'cr', 'tr' );
	}

	/* ── Include a tab view ───────────────────────────────── */

	public static function include_tab( string $view, array $ctx, array $extra = [] ): void {
		extract( array_merge( $ctx, $extra ), EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		include EIWR_DIR . 'includes/views/global/' . $view . '.php';
	}

	/* ── Output admin CSS ─────────────────────────────────── */

	public static function output_css(): void {
		if ( ( sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) ) ) !== 'instant-reveal' ) return; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// Single source of truth: the core plugin's /icons folder (same as the games).
		$icon_url = ( defined( 'GIVEAWAYS_URL' ) ? GIVEAWAYS_URL : EIWR_URL ) . 'icons/instant-reveal.svg';
		?>
		<style>
		.ck-game-admin *{box-sizing:border-box;}
		.ck-page-container{max-width:1440px;margin:0 auto;}
		.ck-game-page-header{display:flex;align-items:center;justify-content:space-between;padding:24px 0 18px;border-bottom:1px solid #e5e7eb;}
		.ck-game-header-logo{height:40px;width:auto;display:block;}
		.ck-back-link{font-size:12px;font-weight:700;color:#38d4c0;text-decoration:none;padding:7px 14px;border:1.5px solid rgba(56,212,192,.25);border-radius:999px;background:rgba(56,212,192,.06);transition:background .15s,border-color .15s;}
		.ck-back-link:hover{background:rgba(56,212,192,.12);border-color:rgba(56,212,192,.5);}
		.ir-page-title{display:flex;align-items:center;gap:18px;padding:14px 0 12px;border-bottom:1px solid #e5e7eb;margin-bottom:16px;}
		.ir-page-title-icon{width:56px;height:56px;flex-shrink:0;-webkit-mask-repeat:no-repeat;mask-repeat:no-repeat;-webkit-mask-position:center;mask-position:center;-webkit-mask-size:contain;mask-size:contain;background-color:#38d4c0;-webkit-mask-image:url('<?php echo esc_url( $icon_url ); ?>');mask-image:url('<?php echo esc_url( $icon_url ); ?>');}
		.ir-page-title-body{flex:1;}
		.ir-page-title h1{font-size:15px;font-weight:600;color:#1d2327;margin:0 0 4px;padding:0;font-family:inherit;line-height:1.3;}
		.ir-page-title p{font-size:12px;color:#6b7280;margin:0;line-height:1.5;}
		.ir-page-title p code{background:#f3f4f6;color:#374151;padding:1px 5px;border-radius:3px;font-size:11px;font-family:monospace;}
		.ir-game-admin h2{display:flex!important;align-items:center;gap:14px;font-size:11px!important;font-weight:700!important;text-transform:uppercase!important;letter-spacing:1.2px!important;color:#9ca3af!important;margin:32px 0 6px!important;padding:0!important;border:none!important;background:none!important;text-shadow:none!important;}
		.ir-game-admin h2::after{content:'';flex:1;height:1px;background:#e5e7eb;}
		.ck-settings-grid{border:1.5px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;}
		.ck-setting-row{display:grid;grid-template-columns:220px 1fr;align-items:center;padding:20px 0 20px 24px;border-bottom:1px solid #f3f4f6;}
		.ck-setting-row:last-child{border-bottom:none;}
		.ck-setting-row:hover{background:#fafafa;}
		.ck-setting-label{padding-right:20px;align-self:start;padding-top:8px;}
		.ck-setting-name{display:block;font-size:13px;font-weight:600;color:#374151;}
		.ck-setting-desc{display:block;font-size:12px;color:#9ca3af;margin-top:3px;line-height:1.45;}
		.ck-setting-control{display:flex;align-items:center;min-height:44px;flex-wrap:wrap;gap:12px;padding-right:20px;}
		.ck-text-input{height:36px;padding:0 12px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;color:#374151;background:#fff;transition:border-color .15s;outline:none;width:100%;}
		.ck-text-input:focus{border-color:#38d4c0;box-shadow:0 0 0 3px rgba(56,212,192,.12);}
		.ck-color-field{display:flex;align-items:center;gap:10px;}
		.ck-color-input{-webkit-appearance:none;appearance:none;width:44px;height:44px;border:2px solid #e5e7eb;border-radius:10px;cursor:pointer;padding:3px;background:none;transition:border-color .15s;}
		.ck-color-input::-webkit-color-swatch-wrapper{padding:0;}
		.ck-color-input::-webkit-color-swatch{border:none;border-radius:6px;}
		.ck-color-input:hover{border-color:#9ca3af;}
		.ck-color-hex{font-size:11px;font-family:'SFMono-Regular',Consolas,monospace;color:#374151;background:#f9fafb;border:1.5px solid #e5e7eb;padding:3px 8px;border-radius:6px;letter-spacing:.5px;min-width:76px;}
		.ir-tab-row-wrap{display:flex;align-items:flex-start;gap:12px;margin-bottom:4px;}
		.ir-tab-navs{flex:1;min-width:0;}
		.ir-game-admin .fbp-tab-nav{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:20px;}
		.ir-game-admin .fbp-tab-btn{font-size:11px;font-weight:600;padding:5px 12px;border-radius:20px;border:1.5px solid #e5e7eb;background:#f9fafb;color:#6b7280;cursor:pointer;transition:all .15s;white-space:nowrap;}
		.ir-game-admin .fbp-tab-btn:hover{border-color:#38d4c0!important;color:#38d4c0!important;background:#f0fdfa!important;}
		.ir-game-admin .fbp-tab-btn.active{background:#38d4c0!important;border-color:#38d4c0!important;color:#fff!important;}
		.ir-game-admin .fbp-tab-section{display:none;}
		.ir-game-admin .fbp-tab-section.active{display:block;}
		.ck-save-bar{display:flex;align-items:center;justify-content:flex-end;padding:24px 0;margin-top:12px;border-top:1px solid #e5e7eb;}
		.ck-btn-save{height:42px;padding:0 36px;background:#38d4c0;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;transition:opacity .15s,transform .1s;}
		.ck-btn-save:hover{opacity:.88;}
		.ck-btn-save:active{transform:scale(.98);}
		.ck-btn-reset{height:42px;padding:0 20px;background:#f3f4f6;color:#374151;border:1.5px solid #e5e7eb;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;margin-right:10px;transition:background .15s,border-color .15s;}
		.ck-btn-reset:hover{background:#fee2e2;border-color:#fca5a5;color:#b91c1c;}
		.ck-page-body{display:flex;align-items:flex-start;gap:28px;}
		.ck-settings-col{flex:1;min-width:0;}
		.ck-preview-col{width:340px;flex-shrink:0;position:sticky;top:calc(var(--wp-admin--admin-bar--height,32px) + 8px);align-self:start;}
		@media(max-width:1100px){.ck-page-body{flex-direction:column;}.ck-preview-col{width:100%;position:static;}}
		.ck-preview-panel{border:1.5px solid #e5e7eb;border-radius:14px;background:#fff;overflow:clip;box-shadow:0 4px 24px rgba(0,0,0,.07);}
		.ck-preview-panel-hd{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #f0f2f5;background:#fafafa;}
		.ck-preview-panel-title{font-size:13px;font-weight:700;color:#374151;}
		.ck-preview-panel-body{padding:6px 14px 12px;}
		.ck-preview-panel-ft{padding:10px 18px;border-top:1px solid #f0f2f5;background:#fafafa;font-size:11px;color:#9ca3af;text-align:center;}
		/* Admin preview overrides — scope real frontend styles to the preview panel */
		.eiwr-admin-preview{margin:0 !important;}
		.eiwr-admin-preview .ticket-wrap{margin-bottom:16px;}
		.eiwr-admin-preview .ticket-title{font-size:13px;margin-bottom:8px;}
		.eiwr-admin-preview .ticket-list{gap:8px;padding:2px 10px;} /* padding for punch-hole circles */
		.eiwr-admin-preview .ticket-badge{font-size:13px;padding:7px 14px;min-width:36px;}
		.eiwr-admin-preview .gift-reveal-container{margin:16px 0 10px;justify-content:center;}
		.eiwr-admin-preview .eiwr-instruction{font-size:12px;margin-top:8px;}
		.eiwr-admin-preview .instant-win-title{font-size:14px;margin-bottom:10px;}
		.eiwr-admin-preview .instant-win-table{max-width:100%;}
		.eiwr-admin-preview .instant-win-table th,.eiwr-admin-preview .instant-win-table td{padding:10px 14px;font-size:13px;}

		/* Desktop preview button */
		.ck-preview-desktop-btn{height:30px;padding:0 12px;background:#f3f4f6;border:1.5px solid #e5e7eb;border-radius:8px;font-size:12px;font-weight:600;color:#374151;cursor:pointer;user-select:none;}
		.ck-preview-desktop-btn:hover{background:#e5e7eb;}
		.ck-preview-desktop-btn--active{background:#1e40af!important;border-color:#1e40af!important;color:#fff!important;}

		/* Fake order page — hidden by default, revealed in desktop mode */
		.ir-fo-site-bar{display:none;align-items:center;justify-content:space-between;padding:10px 20px;background:#1a1a2e;color:#fff;}
		.ir-fo-site-name{font-size:14px;font-weight:700;letter-spacing:.5px;}
		.ir-fo-site-nav{display:none;font-size:11px;opacity:.65;}
		.ir-fo-page-hd{display:none;}
		.ir-fo-order-info{display:none;}
		.ir-fo-page-content{padding:0;}

		/* Desktop overlay */
		.ck-preview-desktop-mode{position:fixed!important;top:32px!important;right:0!important;width:680px!important;height:calc(100vh - 32px)!important;z-index:99999!important;border-radius:0!important;overflow:hidden!important;display:flex!important;flex-direction:column!important;box-shadow:0 0 0 9999px rgba(0,0,0,.55)!important;}
		.ck-preview-desktop-mode .ck-preview-panel-hd{flex-shrink:0;}
		.ck-preview-desktop-mode .ck-preview-panel-body{flex:1!important;min-height:0!important;overflow-y:auto!important;padding:0!important;background:#f0f0f0!important;}
		.ck-preview-desktop-mode .ck-preview-panel-ft{flex-shrink:0;}
		.ck-preview-desktop-mode .ir-fo-site-bar{display:flex!important;position:sticky;top:0;z-index:5;}
		.ck-preview-desktop-mode .ir-fo-site-nav{display:inline!important;}
		.ck-preview-desktop-mode .ir-fo-page-content{background:#fff;}
		.ck-preview-desktop-mode .ir-fo-page-hd{display:block!important;padding:22px 28px 0;}
		.ck-preview-desktop-mode .ir-fo-h1{font-size:22px!important;font-weight:700!important;margin:0 0 0!important;color:#1a1a2e!important;line-height:1.2!important;padding:0!important;border:none!important;background:none!important;}
		.ck-preview-desktop-mode .ir-fo-order-info{display:block!important;padding:16px 28px 0;}
		.ck-preview-desktop-mode .ir-fo-notice{background:#d1fae5;border-left:4px solid #10b981;padding:12px 16px;border-radius:4px;font-size:13px;font-weight:600;color:#065f46;margin:0 0 18px;}
		.ck-preview-desktop-mode .ir-fo-overview{display:flex;list-style:none;margin:0 0 20px;padding:0;border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;}
		.ck-preview-desktop-mode .ir-fo-overview li{flex:1;padding:10px 10px;border-right:1px solid #e5e7eb;display:flex;flex-direction:column;gap:3px;}
		.ck-preview-desktop-mode .ir-fo-overview li:last-child{border-right:none;}
		.ck-preview-desktop-mode .ir-fo-overview small{font-size:9px;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;line-height:1.2;}
		.ck-preview-desktop-mode .ir-fo-overview strong{font-size:12px;color:#1f2937;font-weight:700;line-height:1.3;}
		.ck-preview-desktop-mode .ir-fo-h2{font-size:15px!important;font-weight:700!important;color:#111!important;margin:0 0 12px!important;padding:0!important;border:none!important;background:none!important;text-transform:none!important;letter-spacing:normal!important;display:block!important;}
		.ck-preview-desktop-mode .ir-fo-h2::after{display:none!important;}
		.ck-preview-desktop-mode .ir-fo-game-heading{margin:22px 0 0!important;padding-bottom:12px;border-bottom:1px solid #e5e7eb;}
		.ck-preview-desktop-mode .ir-fo-details{margin-bottom:0;}
		.ck-preview-desktop-mode .ir-fo-table{width:100%;border-collapse:collapse;margin-bottom:20px;font-size:13px;}
		.ck-preview-desktop-mode .ir-fo-table th,.ck-preview-desktop-mode .ir-fo-table td{padding:10px 14px;text-align:left;border-bottom:1px solid #e5e7eb;}
		.ck-preview-desktop-mode .ir-fo-table thead th{background:#f9fafb;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;}
		.ck-preview-desktop-mode .ir-fo-table tfoot th,.ck-preview-desktop-mode .ir-fo-table tfoot td{background:#f9fafb;font-size:12px;color:#374151;}
		.ck-preview-desktop-mode #ir-prev-container{padding:0 28px 32px;}
		/* Full-size game in desktop mode */
		.ck-preview-desktop-mode .eiwr-admin-preview .ticket-title{font-size:18px!important;margin-bottom:14px!important;}
		.ck-preview-desktop-mode .eiwr-admin-preview .ticket-badge{font-size:16px!important;padding:10px 24px!important;}
		.ck-preview-desktop-mode .eiwr-admin-preview .gift-reveal-container{margin:36px 0 16px!important;}
		.ck-preview-desktop-mode .eiwr-admin-preview .eiwr-instruction{font-size:14px!important;margin-top:12px!important;}
		.ck-preview-desktop-mode .eiwr-admin-preview .instant-win-title{font-size:20px!important;margin-bottom:14px!important;}
		.ck-preview-desktop-mode .eiwr-admin-preview .instant-win-table th,.ck-preview-desktop-mode .eiwr-admin-preview .instant-win-table td{padding:14px 20px!important;font-size:14px!important;}
		</style>
		<?php
	}
}
