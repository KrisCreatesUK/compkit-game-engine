<?php
/**
 * Instant Reveal & Orders Email — settings screen.
 *
 * Matches the game settings pages: branded header, tabbed settings on the left,
 * a sticky live email preview on the right that updates as you type.
 *
 * @package CompKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CompKit_Email_Admin_Page {

	const PAGE_SLUG = 'playkit-reveal-email';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'menu' ], 99 );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public function menu(): void {
		add_submenu_page(
			'giveaways-addons',
			'Instant Reveal & Orders Email',
			'Instant Reveal & Orders Email',
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);
	}

	public function assets( $hook ): void {
		if ( ( sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) ) ) !== self::PAGE_SLUG ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
	}

	private function core_url(): string {
		return defined( 'GIVEAWAYS_URL' ) ? GIVEAWAYS_URL : plugin_dir_url( dirname( __DIR__, 1 ) );
	}

	private function logo_url(): string {
		return $this->core_url() . 'assets/img/compkit-logo-300.png';
	}

	private function icon_url(): string {
		// Single source of truth: the core plugin's /icons folder (same as the games).
		return $this->core_url() . 'icons/instant-reveal.svg';
	}

	public function render(): void {

		$saved     = false;
		$test_to   = '';
		if ( isset( $_POST['playkit_reveal_email_submit'] ) && check_admin_referer( 'playkit_save_email' ) ) {
			CompKit_Email_Settings::save_from_post();
			$saved = true;
		}
		if ( isset( $_POST['compkit_send_test'] ) && check_admin_referer( 'playkit_save_email' ) ) {
			CompKit_Email_Settings::save_from_post(); // persist current edits, then send a test
			$saved   = true;
			$test_to = $this->send_test_email();
		}

		$s = CompKit_Email_Settings::get();
		?>
		<div class="wrap ck-email-admin">

			<div class="ck-game-page-header">
				<img src="<?php echo esc_url( $this->logo_url() ); ?>" alt="CompKit" class="ck-game-header-logo">
				<?php /* Parked beside the back link, exactly like each game's version pill. */ ?>
				<?php if ( defined( 'COMPKIT_EMAIL_VERSION' ) ) : ?>
					<span class="ck-game-version" title="This module's version, independent of the plugin version">v<?php echo esc_html( COMPKIT_EMAIL_VERSION ); ?></span>
				<?php endif; ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=giveaways-addons' ) ); ?>" class="ck-back-link">&larr; All Games</a>
			</div>

			<div class="ck-email-title">
				<div class="ck-email-title-icon"></div>
				<div class="ck-email-title-body">
					<h1>Instant Reveal &amp; Orders Email</h1>
					<p>Holds WooCommerce order &amp; ticket emails until every instant-win game is played, then sends a styled reveal email per prize. The preview on the right updates as you edit.</p>
					<p class="description">If a customer never plays, the held emails are sent anyway <?php echo esc_html( human_time_diff( 0, (int) apply_filters( 'compkit_email_hold_timeout', COMPKIT_EMAIL_HOLD_TIMEOUT ) ) ); ?> after the order — nobody is left without their ticket numbers.</p>
				</div>
			</div>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
			<?php endif; ?>
			<?php if ( $test_to ) : ?>
				<div class="notice notice-success is-dismissible"><p>Test reveal email sent to <strong><?php echo esc_html( $test_to ); ?></strong>. Check your inbox (and spam).</p></div>
			<?php elseif ( isset( $_POST['compkit_send_test'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p>Test email could not be sent — check your site's email/SMTP setup.</p></div>
			<?php endif; ?>

			<form method="post" class="ck-page-body">
				<?php wp_nonce_field( 'playkit_save_email' ); ?>

				<!-- ── Settings column ── -->
				<div class="ck-settings-col">

					<nav class="fbp-tab-nav" data-tabgroup="email">
						<button type="button" class="fbp-tab-btn active" data-tab="messages">Order Confirmation Email</button>
						<button type="button" class="fbp-tab-btn" data-tab="colours">Ticket Colours</button>
						<button type="button" class="fbp-tab-btn" data-tab="prizes">Prize Table</button>
						<button type="button" class="fbp-tab-btn" data-tab="reveal">Reveal Email</button>
					</nav>

					<!-- Reveal email -->
					<section class="fbp-tab-section" data-tabgroup="email" data-tab="reveal">
						<label class="ck-toggle-row">
							<input type="checkbox" name="enabled" id="ck-em-enabled" <?php checked( $s['enabled'], 'yes' ); ?>>
							<span>Enable instant reveal emails &amp; the smart email delay</span>
						</label>

						<div class="ck-field">
							<label for="ck-em-subject">Email subject</label>
							<input type="text" class="regular-text" id="ck-em-subject" name="subject" value="<?php echo esc_attr( $s['subject'] ); ?>">
							<p class="ck-field-hint"><code>*PRODUCT</code> / <code>{product_name}</code> becomes the giveaway name.</p>
						</div>

						<div class="ck-field">
							<label for="ck-em-message">Reveal message</label>
							<textarea id="ck-em-message" name="message" rows="11" class="large-text ck-mono"><?php echo esc_textarea( $s['message'] ); ?></textarea>
							<p class="ck-field-hint">HTML allowed. Tags: <code>{site_name}</code> <code>{user_name}</code> <code>{ticket_number}</code> <code>{prize_name}</code> <code>{prize_desc}</code> <code>{prize_image}</code></p>
						</div>
					</section>

					<!-- Email message -->
					<section class="fbp-tab-section active" data-tabgroup="email" data-tab="messages">
						<div class="ck-field">
							<label for="ck-em-disc-instant">Order email message</label>
							<textarea id="ck-em-disc-instant" name="disclaimer_instant" rows="6" class="large-text"><?php echo esc_textarea( $s['disclaimer_instant'] ); ?></textarea>
							<p class="ck-field-hint">Shown in the customer order email for any order containing a game (a product in a game category). HTML allowed.</p>
						</div>
					</section>

					<!-- Ticket colours -->
					<section class="fbp-tab-section" data-tabgroup="email" data-tab="colours">
						<p class="ck-group-title">Normal ticket badge</p>
						<div class="ck-color-row">
							<?php $this->color_field( 'ticket_bg', 'Background', $s['ticket_bg'] ); ?>
							<?php $this->color_field( 'ticket_text', 'Text', $s['ticket_text'] ); ?>
						</div>
						<p class="ck-group-title">Winning ticket badge</p>
						<div class="ck-color-row">
							<?php $this->color_field( 'win_ticket_bg', 'Background', $s['win_ticket_bg'] ); ?>
							<?php $this->color_field( 'win_ticket_text', 'Text', $s['win_ticket_text'] ); ?>
						</div>
						<p class="ck-group-title">Badge shape &amp; size</p>
						<div class="ck-color-row">
							<?php $this->number_field( 'badge_radius', 'Corner radius', $s['badge_radius'], 0, 40 ); ?>
							<?php $this->number_field( 'badge_size', 'Text size', $s['badge_size'], 8, 28 ); ?>
						</div>
					</section>

					<!-- Prize table -->
					<section class="fbp-tab-section" data-tabgroup="email" data-tab="prizes">
						<div class="ck-field">
							<label for="ck-em-prize_heading">Prize table heading</label>
							<input type="text" class="regular-text" id="ck-em-prize_heading" name="prize_heading" value="<?php echo esc_attr( $s['prize_heading'] ); ?>">
							<p class="ck-field-hint">Shown above the Ticket&nbsp;# / Prize table.</p>
						</div>

						<p class="ck-group-title">Coupon “apply” button</p>
						<div class="ck-color-row">
							<?php $this->color_field( 'coupon_btn_bg', 'Background', $s['coupon_btn_bg'] ); ?>
							<?php $this->color_field( 'coupon_btn_text', 'Text', $s['coupon_btn_text'] ); ?>
						</div>
						<div class="ck-color-row">
							<?php $this->number_field( 'coupon_btn_radius', 'Corner radius', $s['coupon_btn_radius'], 0, 40 ); ?>
							<?php $this->number_field( 'coupon_btn_size', 'Text size', $s['coupon_btn_size'], 8, 28 ); ?>
						</div>

						<p class="ck-group-title">Product “claim” button</p>
						<div class="ck-color-row">
							<?php $this->color_field( 'claim_btn_bg', 'Background', $s['claim_btn_bg'] ); ?>
							<?php $this->color_field( 'claim_btn_text', 'Text', $s['claim_btn_text'] ); ?>
						</div>
						<div class="ck-color-row">
							<?php $this->number_field( 'claim_btn_radius', 'Corner radius', $s['claim_btn_radius'], 0, 40 ); ?>
							<?php $this->number_field( 'claim_btn_size', 'Text size', $s['claim_btn_size'], 8, 32 ); ?>
						</div>

						<div class="ck-field">
							<label for="ck-em-note_credit">Cash / credit note</label>
							<input type="text" class="regular-text" id="ck-em-note_credit" name="note_credit" value="<?php echo esc_attr( $s['note_credit'] ); ?>">
							<p class="ck-field-hint">Small note under cash, credit or wallet prizes.</p>
						</div>
						<div class="ck-field">
							<label for="ck-em-note_physical">Physical prize note</label>
							<input type="text" class="regular-text" id="ck-em-note_physical" name="note_physical" value="<?php echo esc_attr( $s['note_physical'] ); ?>">
							<p class="ck-field-hint">Small note under physical / other prizes (e.g. how to claim).</p>
						</div>
					</section>

					<div class="ck-save-bar">
						<button type="submit" name="playkit_reveal_email_submit" value="1" class="ck-btn-save">Save Settings</button>
						<button type="submit" name="compkit_send_test" value="1" class="ck-btn-test" title="Saves, then emails a sample reveal email to you">✉ Send me a test email</button>
					</div>
				</div>

				<!-- ── Live preview column ── -->
				<div class="ck-preview-col">
					<div class="ck-preview-panel">
						<div class="ck-preview-panel-hd">
							<span class="ck-preview-panel-title">&#128065; Live Preview</span>
						</div>
						<div class="ck-preview-panel-body">
							<div id="ck-email-preview"></div>
						</div>
						<div class="ck-preview-panel-ft">Live preview &bull; sample data</div>
					</div>
				</div>
			</form>
		</div>

		<?php $this->styles(); ?>
		<?php $this->preview_script(); ?>
		<?php
	}

	/**
	 * Send a sample reveal email to the current user so they can check it without
	 * setting up a competition. Returns the recipient on success, '' on failure.
	 */
	private function send_test_email(): string {
		$s    = CompKit_Email_Settings::get();
		$user = wp_get_current_user();
		$to   = ( $user && $user->user_email ) ? $user->user_email : get_option( 'admin_email' );
		if ( ! $to ) {
			return '';
		}

		$subject = str_replace( [ '*PRODUCT', '{product_name}' ], 'Summer Giveaway', $s['subject'] );
		$body    = strtr( $s['message'], [
			'{site_name}'     => get_bloginfo( 'name' ),
			'{user_name}'     => $user ? $user->display_name : 'there',
			'{ticket_number}' => 'A-1042',
			'{prize_name}'    => 'Weekend Getaway',
			'{prize_desc}'    => 'A two-night stay for two.',
			'{prize_image}'   => '',
		] );

		// Wrap in the WooCommerce email template (header/footer) just like the real email.
		$html = ( function_exists( 'WC' ) && WC()->mailer() )
			? WC()->mailer()->wrap_message( $subject, $body )
			: $body;

		$sent = wp_mail(
			$to,
			'[Test] ' . $subject,
			$html,
			[ 'Content-Type: text/html; charset=UTF-8' ]
		);

		return $sent ? $to : '';
	}

	private function number_field( string $name, string $label, string $value, int $min, int $max ): void {
		?>
		<div class="ck-field ck-num-field">
			<label for="ck-em-<?php echo esc_attr( $name ); ?>">
				<?php echo esc_html( $label ); ?>
				<span class="ck-num-out" id="ck-out-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $value ); ?>px</span>
			</label>
			<input type="range" class="ck-slider" id="ck-em-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>"
			       value="<?php echo esc_attr( $value ); ?>" min="<?php echo (int) $min; ?>" max="<?php echo (int) $max; ?>" step="1">
		</div>
		<?php
	}

	private function color_field( string $name, string $label, string $value ): void {
		?>
		<div class="ck-field ck-color-field">
			<label><?php echo esc_html( $label ); ?></label>
			<input type="text" class="ck-color-picker" name="<?php echo esc_attr( $name ); ?>"
			       id="ck-em-<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>"
			       data-default-color="<?php echo esc_attr( $value ); ?>">
		</div>
		<?php
	}

	private function styles(): void {
		?>
		<style>
		.ck-email-admin { max-width: none; margin-right: 20px; }
		.ck-game-page-header { display:flex; align-items:center; justify-content:space-between; padding:18px 0 16px; border-bottom:1px solid #e5e7eb; }
		/* Same pill the games use, copied rather than shared because this page
		   emits its own <style> block and loads none of theirs. */
		.ck-game-version { margin-left:auto; margin-right:10px; font-size:10px; font-weight:700; letter-spacing:.3px; color:#9ca3af; background:#f3f4f6; border:1px solid #e5e7eb; padding:3px 9px; border-radius:999px; white-space:nowrap; font-variant-numeric:tabular-nums; }
		.ck-game-header-logo { height:34px; width:auto; display:block; }
		.ck-back-link { font-size:12px; font-weight:700; color:#38d4c0; text-decoration:none; padding:7px 14px; border:1.5px solid rgba(56,212,192,.25); border-radius:999px; background:rgba(56,212,192,.06); transition:background .15s,border-color .15s; }
		.ck-back-link:hover { background:rgba(56,212,192,.12); border-color:rgba(56,212,192,.5); }
		.ck-email-title { display:flex; align-items:center; gap:16px; padding:14px 0 12px; border-bottom:1px solid #e5e7eb; margin-bottom:20px; }
		.ck-email-title-icon { width:44px; height:44px; flex-shrink:0; background-color:#38d4c0; -webkit-mask-repeat:no-repeat; mask-repeat:no-repeat; -webkit-mask-position:center; mask-position:center; -webkit-mask-size:contain; mask-size:contain; -webkit-mask-image:url('<?php echo esc_url( $this->icon_url() ); ?>'); mask-image:url('<?php echo esc_url( $this->icon_url() ); ?>'); }
		.ck-email-title-body { flex:1; }
		.ck-email-title h1 { font-size:15px; font-weight:600; color:#1d2327; margin:0 0 4px; padding:0; line-height:1.3; }
		.ck-email-title p { font-size:12px; color:#6b7280; margin:0; line-height:1.5; max-width:720px; }
		.ck-page-body { display:flex; gap:24px; align-items:flex-start; }
		.ck-settings-col { flex:1; min-width:0; background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:22px 24px; box-shadow:0 2px 8px rgba(0,0,0,.04); }
		.fbp-tab-nav { display:flex; flex-wrap:wrap; gap:4px; margin:0 0 20px; border-bottom:1px solid #f0f2f5; }
		.fbp-tab-btn { background:none; border:none; border-bottom:2px solid transparent; padding:8px 14px; font-size:13px; font-weight:600; color:#6b7280; cursor:pointer; }
		.fbp-tab-btn:hover { color:#111827; }
		.fbp-tab-btn.active { color:#0082F9; border-bottom-color:#0082F9; }
		.fbp-tab-section { display:none; }
		.fbp-tab-section.active { display:block; }
		.ck-field { margin-bottom:18px; }
		.ck-field > label { display:block; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#6b7280; margin-bottom:6px; }
		.ck-field input[type=text], .ck-field textarea { width:100%; box-sizing:border-box; }
		.ck-mono { font-family:ui-monospace,Menlo,Consolas,monospace; font-size:12.5px; }
		.ck-field-hint { font-size:12px; color:#9ca3af; margin:6px 0 0; }
		.ck-field-hint code { background:#f3f4f6; padding:1px 5px; border-radius:4px; font-size:11px; }
		.ck-toggle-row { display:flex; align-items:center; gap:10px; font-size:14px; font-weight:600; color:#111827; margin-bottom:20px; }
		.ck-group-title { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#6b7280; margin:4px 0 8px; }
		.ck-color-row { display:flex; gap:24px; margin-bottom:18px; flex-wrap:wrap; }
		.ck-num-field { margin-bottom:0; flex:1; min-width:170px; }
		.ck-num-field > label { display:flex; align-items:center; justify-content:space-between; margin-bottom:0; }
		.ck-num-out { font-size:12px; font-weight:700; color:#0082F9; background:#eff6ff; padding:1px 9px; border-radius:999px; text-transform:none; letter-spacing:0; }
		.ck-slider { width:100%; margin-top:10px; accent-color:#0082F9; cursor:pointer; }
		.ck-save-bar { border-top:1px solid #f0f2f5; padding-top:18px; margin-top:6px; display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
		.ck-btn-save { background:#0082F9; color:#fff; border:none; height:40px; padding:0 26px; border-radius:9px; font-size:14px; font-weight:700; cursor:pointer; }
		.ck-btn-save:hover { opacity:.9; }
		.ck-btn-test { background:#fff; color:#0082F9; border:1.5px solid #0082F9; height:40px; padding:0 20px; border-radius:9px; font-size:13px; font-weight:700; cursor:pointer; }
		.ck-btn-test:hover { background:#eff6ff; }
		/* Dev tool (remove before release) */
		.ck-preview-col { width:380px; flex-shrink:0; position:sticky; top:calc(var(--wp-admin--admin-bar--height,32px) + 16px); align-self:flex-start; }
		.ck-preview-panel { border:1.5px solid #e5e7eb; border-radius:14px; background:#fff; overflow:hidden; box-shadow:0 4px 24px rgba(0,0,0,.07); }
		.ck-preview-panel-hd { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid #f0f2f5; background:#fafafa; }
		.ck-preview-panel-title { font-size:13px; font-weight:700; color:#374151; }
		.ck-preview-panel-body { padding:18px; background:#eef1f5; max-height:70vh; overflow:auto; }
		.ck-preview-panel-ft { padding:10px 18px; border-top:1px solid #f0f2f5; font-size:11px; color:#9ca3af; text-align:center; }
		/* Email mock */
		.em-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#6b7280; margin:0 0 8px; }
		.ck-email-mock { background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.08); font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; margin-bottom:6px; }
		.ck-email-mock .em-head { background:#2c2c2c; color:#fff; padding:18px 20px; font-size:16px; font-weight:700; text-align:center; }
		.ck-email-mock .em-body { padding:20px; font-size:13px; color:#222; line-height:1.55; }
		.ck-email-mock .em-disc { text-align:center; font-size:12px; color:#666; margin:0 0 14px; line-height:1.4; }
		.ck-email-mock .em-badges { display:flex; flex-wrap:wrap; gap:8px; margin:14px 0; }
		.ck-email-mock .em-badge { padding:6px 12px; border-radius:6px; font-size:13px; font-weight:700; }
		.ck-email-mock .em-prize-img { max-width:140px; border-radius:8px; display:block; margin:8px 0; }
		.ck-email-mock .em-foot { padding:14px 20px; background:#f6f6f6; font-size:11px; color:#888; text-align:center; }
		@media (max-width:1100px){ .ck-page-body{flex-direction:column;} .ck-preview-col{width:100%;position:static;} }
		</style>
		<?php
	}

	private function preview_script(): void {
		$wc = [
			'img'   => (string) get_option( 'woocommerce_email_header_image', '' ),
			'base'  => (string) get_option( 'woocommerce_email_base_color', '#7f54b3' ),
			'text'  => (string) get_option( 'woocommerce_email_text_color', '#3c3c3c' ),
			'store' => (string) get_bloginfo( 'name' ),
		];
		?>
		<script>
		( function () {
			var WC = <?php echo wp_json_encode( $wc ); ?>;

			jQuery( function ( $ ) {
				$( '.ck-color-picker' ).each( function () {
					$( this ).wpColorPicker( { change: render, clear: render } );
				} );
			} );

			// The WooCommerce email header (image set in WooCommerce → Emails, else store name).
			function wcHeader() {
				var inner = WC.img
					? '<img src="' + WC.img + '" alt="" style="max-width:100%;max-height:64px;display:inline-block;">'
					: '<span style="color:#fff;font-size:18px;font-weight:700;letter-spacing:.3px;">' + esc( WC.store || 'Your Store' ) + '</span>';
				return '<div style="background:' + ( WC.base || '#7f54b3' ) + ';padding:24px 20px;text-align:center;">' + inner + '</div>';
			}

			function val( id ) { var el = document.getElementById( id ); return el ? el.value : ''; }
			function esc( s ) { return ( s || '' ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); }

			// HTML is allowed in the message/disclaimer fields, so render it as-is.
			function fillTags( html ) {
				return ( html || '' )
					.replace( /\{site_name\}/g, 'Your Store' )
					.replace( /\{user_name\}/g, 'Alex' )
					.replace( /\{ticket_number\}/g, 'A-1042' )
					.replace( /\{prize_name\}/g, 'Weekend Getaway' )
					.replace( /\{prize_desc\}/g, 'A two-night stay for two.' )
					.replace( /\{prize_image\}/g, prizeImg( 140 ) );
			}

			// Self-contained neutral prize thumbnail (no external request, no emoji).
			function prizeImg( size ) {
				size = size || 50;
				var h = size === 140 ? Math.round( size * 0.62 ) : size;
				return '<span style="display:inline-block;width:' + size + 'px;height:' + h +
					'px;border-radius:6px;background:#e9ecf2;border:1px solid #d8deea;vertical-align:middle;margin-right:6px;"></span>';
			}

			function bRad() { return val( 'ck-em-badge_radius' ) || '8'; }
			function bSize() { return val( 'ck-em-badge_size' ) || '11'; }

			// Ticket badge (matches the real email markup).
			function badge( num, bg, tx ) {
				return '<span style="display:inline-block;background:' + bg + ';color:' + tx +
					';padding:4px 9px;border-radius:' + bRad() + 'px;font-size:' + bSize() + 'px;font-weight:900;white-space:nowrap;margin:0 6px 6px 0;">' + esc( num ) + '</span>';
			}
			function tcell( num, wBg, wTx ) {
				return '<td style="vertical-align:middle;padding:6px 0;"><span style="display:inline-block;background:' + wBg + ';color:' + wTx +
					';padding:4px 8px;border-radius:' + bRad() + 'px;font-size:' + bSize() + 'px;font-weight:900;white-space:nowrap;">' + esc( num ) + '</span></td>';
			}
			function spacer() { return '<tr><td colspan="2" style="height:12px;line-height:12px;font-size:12px;">&nbsp;</td></tr>'; }

			// The "Ticket # / Prize" table, one row per prize type, exactly like the email.
			function prizeTable( wBg, wTx ) {
				var cBg  = val( 'ck-em-coupon_btn_bg' )  || '#2aa8f8';
				var cTx  = val( 'ck-em-coupon_btn_text' )|| '#ffffff';
				var clBg = val( 'ck-em-claim_btn_bg' )   || '#000000';
				var clTx = val( 'ck-em-claim_btn_text' ) || '#2aa8f8';
				var nCredit   = val( 'ck-em-note_credit' )   || '*Applied to account';
				var nPhysical = val( 'ck-em-note_physical' ) || 'Contact Us';
				var cR   = val( 'ck-em-coupon_btn_radius' ) || '4';
				var cFs  = val( 'ck-em-coupon_btn_size' )   || '13';
				var clR  = val( 'ck-em-claim_btn_radius' )  || '12';
				var clFs = val( 'ck-em-claim_btn_size' )    || '14';
				var rows = '';

				// Coupon
				rows += '<tr>' + tcell( 'A-1042', wBg, wTx ) +
					'<td style="vertical-align:middle;padding:6px 0;">' + prizeImg() +
					'<strong style="vertical-align:middle;">Discount Code</strong><br>' +
					'<small>Click to Apply Coupon: <a href="#" style="background:' + cBg + ';color:' + cTx +
					';padding:8px 12px;border-radius:' + cR + 'px;font-size:' + cFs + 'px;text-decoration:none;display:inline-block;font-weight:bold;">SAVE20</a></small>' +
					'</td></tr>' + spacer();

				// Product (claim button)
				rows += '<tr>' + tcell( 'A-1044', wBg, wTx ) +
					'<td style="vertical-align:middle;padding:6px 0;"><table cellpadding="0" cellspacing="0" border="0"><tr>' +
					'<td style="vertical-align:middle;padding-right:8px;">' + prizeImg() + '</td>' +
					'<td style="vertical-align:middle;"><a href="#" style="background:' + clBg + ';color:' + clTx + ';border:2px solid ' + clTx +
					';padding:10px 14px;border-radius:' + clR + 'px;text-decoration:none;font-weight:800;display:inline-block;text-align:center;line-height:1.2;">' +
					'<span style="display:block;font-size:' + clFs + 'px;">Free Mug</span><span style="display:block;font-size:10px;font-weight:600;">CLICK ME</span></a></td>' +
					'</tr></table></td></tr>' + spacer();

				// Cash / account credit
				rows += '<tr>' + tcell( 'A-1045', wBg, wTx ) +
					'<td style="vertical-align:middle;padding:6px 0;"><table cellpadding="0" cellspacing="0" border="0"><tr>' +
					'<td style="vertical-align:middle;padding-right:8px;">' + prizeImg() + '</td>' +
					'<td style="vertical-align:middle;"><strong>&pound;50 Account Credit</strong><br><span style="font-size:11px;color:#666;">' + esc( nCredit ) + '</span></td>' +
					'</tr></table></td></tr>' + spacer();

				// Physical
				rows += '<tr>' + tcell( 'A-1046', wBg, wTx ) +
					'<td style="vertical-align:middle;padding:6px 0;"><table cellpadding="0" cellspacing="0" border="0"><tr>' +
					'<td style="vertical-align:middle;padding-right:8px;">' + prizeImg() + '</td>' +
					'<td style="vertical-align:middle;"><strong>Signed Football Shirt</strong><br><span style="font-size:11px;color:#666;">' + esc( nPhysical ) + '</span></td>' +
					'</tr></table></td></tr>';

				return '<div style="margin-top:10px;"><table width="100%" cellpadding="0" cellspacing="0" border="0">' +
					'<thead><tr><th style="padding:8px 12px 8px 0;text-align:left;font-size:12px;">Ticket #</th>' +
					'<th style="padding:8px 0 8px 12px;text-align:left;font-size:12px;">Prize</th></tr></thead>' +
					'<tbody>' + rows + '</tbody></table></div>';
			}

			function render() {
				var enabled = document.getElementById( 'ck-em-enabled' );
				var subject = val( 'ck-em-subject' ).replace( /\*PRODUCT|\{product_name\}/g, 'Summer Giveaway' );
				var message = fillTags( val( 'ck-em-message' ) );
				var disc    = val( 'ck-em-disc-instant' );
				var tBg  = val( 'ck-em-ticket_bg' )  || '#2aa8f8';
				var tTx  = val( 'ck-em-ticket_text' )|| '#000000';
				var wBg  = val( 'ck-em-win_ticket_bg' )  || '#d4af37';
				var wTx  = val( 'ck-em-win_ticket_text' )|| '#000000';

				var off = ( enabled && ! enabled.checked )
					? '<div style="background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-size:11px;padding:6px 10px;border-radius:6px;margin-bottom:10px;text-align:center;">Emails are currently disabled — this is how they would look.</div>'
					: '';

				var heading = esc( val( 'ck-em-prize_heading' ) || 'Your Instant Wins' );
				var store   = esc( WC.store || 'Your Store' );

				// Six tickets in the order: two non-winners (normal badge) + four winners (winning badge).
				var allTickets =
					badge( 'A-1041', tBg, tTx ) + badge( 'A-1043', tBg, tTx ) +
					badge( 'A-1042', wBg, wTx ) + badge( 'A-1044', wBg, wTx ) +
					badge( 'A-1045', wBg, wTx ) + badge( 'A-1046', wBg, wTx );

				var orderEmail =
					wcHeader() +
					'<div class="em-body">' + off +
						( disc ? '<div class="em-disc">' + disc + '</div>' : '' ) +
						'<p style="font-size:13px;font-weight:700;margin:0 0 8px;">Your Tickets</p>' +
						'<div style="margin-bottom:6px;">' + allTickets + '</div>' +
						'<p style="font-size:14px;font-weight:800;margin:18px 0 4px;color:' + ( WC.text || '#222' ) + ';">' + heading + '</p>' +
						prizeTable( wBg, wTx ) +
					'</div>' +
					'<div class="em-foot">' + store + ' &bull; order confirmation</div>';

				var revealEmail =
					wcHeader() +
					'<h1 style="font-size:18px;font-weight:700;color:' + ( WC.text || '#222' ) + ';margin:20px 20px 0;">' + esc( subject ) + '</h1>' +
					'<div class="em-body">' + message + '</div>' +
					'<div class="em-foot">' + store + ' &bull; instant reveal email</div>';

				var box = document.getElementById( 'ck-email-preview' );
				if ( box ) {
					box.innerHTML =
						'<div class="em-label">Order &amp; tickets email</div>' +
						'<div class="ck-email-mock">' + orderEmail + '</div>' +
						'<div class="em-label" id="ck-preview-reveal" style="margin-top:18px;">Instant reveal email &mdash; sent per winning prize</div>' +
						'<div class="ck-email-mock">' + revealEmail + '</div>';
				}
			}

			document.addEventListener( 'input', function ( e ) {
				if ( ! e.target.closest || ! e.target.closest( '.ck-email-admin' ) ) { return; }
				// Update the slider's "Npx" readout.
				if ( e.target.classList && e.target.classList.contains( 'ck-slider' ) ) {
					var out = document.getElementById( 'ck-out-' + e.target.name );
					if ( out ) { out.textContent = e.target.value + 'px'; }
				}
				render();
			} );
			document.addEventListener( 'change', function ( e ) {
				if ( e.target.id === 'ck-em-enabled' ) { render(); }
			} );

			// Tab switching
			document.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.fbp-tab-btn' );
				if ( ! btn ) { return; }
				var tab = btn.getAttribute( 'data-tab' );
				document.querySelectorAll( '.fbp-tab-btn' ).forEach( function ( b ) { b.classList.remove( 'active' ); } );
				document.querySelectorAll( '.fbp-tab-section' ).forEach( function ( s ) { s.classList.remove( 'active' ); } );
				btn.classList.add( 'active' );
				var sec = document.querySelector( '.fbp-tab-section[data-tab="' + tab + '"]' );
				if ( sec ) { sec.classList.add( 'active' ); }

				// Scroll the preview to the matching email: reveal tab → reveal email, else top.
				var body = document.querySelector( '.ck-preview-panel-body' );
				if ( body ) {
					if ( tab === 'reveal' ) {
						var target = document.getElementById( 'ck-preview-reveal' );
						if ( target ) {
							var top = target.getBoundingClientRect().top - body.getBoundingClientRect().top + body.scrollTop - 10;
							body.scrollTo( { top: top, behavior: 'smooth' } );
						}
					} else {
						body.scrollTo( { top: 0, behavior: 'smooth' } );
					}
				}
			} );

			document.addEventListener( 'DOMContentLoaded', render );
			render();
		} )();
		</script>
		<?php
	}
}
