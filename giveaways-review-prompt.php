<?php
/**
 * giveaways-review-prompt.php — let a customer leave a review from the plugin.
 *
 * CompKit is not on the WordPress.org plugin directory (the listing was
 * submitted and rejected), so there is no review page to send anyone to. The
 * store owner writes it here instead; it posts to the licence server,
 * authenticated by the licence key and domain the plugin already proves on
 * every validate call, and lands as `pending`. Nothing is published until it is
 * approved by hand in the Vault.
 *
 * COPY RULE: never mention "the website" to the customer. CompKit is one
 * product to them — the plugin they installed and the site they got it from are
 * the same thing, and making them think about which is which is our problem
 * leaking into their day. A review is just a review.
 *
 * The one thing that must survive that rule is the word *published*. An
 * in-dashboard box could reasonably be taken for private feedback to the
 * developer, so someone has to know their words can be shown publicly before
 * they write them, or they have not agreed to anything. It is also what
 * privacy-policy.html promises we tell them.
 *
 * TWO WAYS IN, and they are not the same thing:
 *
 *   The button — "Leave a review" in the CompKit page header. No thresholds at
 *   all. Someone who went looking has already decided, and the thresholds below
 *   are a guess about when to *ask*, not about who is allowed to answer.
 *
 *   The nag — shown at most once, and only when ALL of these hold:
 *     1. At least COMPKIT_REVIEW_MIN_REVEALS tickets actually revealed on this
 *        site. Not installs, not activations — reveals. Someone whose customers
 *        have played 60 games has an opinion worth asking for; someone who
 *        installed it yesterday does not.
 *     2. At least COMPKIT_REVIEW_MIN_DAYS since the FIRST reveal, so a single
 *        large launch order cannot trip it on day one.
 *     3. Not dismissed, and any snooze expired.
 *     4. An admin, on a CompKit screen.
 *
 * Deliberately NOT hooked to activation, update, or a timer. A prompt that
 * arrives before the plugin has done anything for you is an advert.
 *
 * The form itself is a modal in admin_footer, not an admin notice. As a notice
 * it flashed and vanished on the CompKit admin page, and a notice was the wrong
 * shape regardless: a deliberate click should not cost a page load.
 *
 * WHITE LABEL — DO NOT MIRROR THIS.
 *
 * The `require_once` for this file was added to `giveaways-add-ons.php`, which
 * is one of the forked core files. CLAUDE.md says a change to a forked file
 * "almost certainly needs mirroring" into `WhiteLabel Overwrites/`. This is the
 * exception, and it is deliberate: the Game Addons edition is sold as someone
 * else's product, and this notice names CompKit and links to a kriscreates.co.uk
 * address. Mirroring it would put our brand and our inbox inside a white-label
 * customer's admin.
 *
 * The fork simply omits the require, so the file is inert there. If you are
 * syncing the forks and notice this line "missing", it is missing on purpose —
 * leave it out.
 *
 * @package CompKit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const COMPKIT_REVIEW_MIN_REVEALS = 50;
const COMPKIT_REVIEW_MIN_DAYS    = 14;
const COMPKIT_REVIEW_SNOOZE_DAYS = 90;
const COMPKIT_REVIEW_EMAIL       = 'kris@kriscreates.co.uk';

/**
 * Count one reveal.
 *
 * The number is a "has this store had real use" signal, never a statistic and
 * never displayed, so an approximate count is fine. Coverage is not: a store
 * that runs one game must still reach the threshold.
 */
function compkit_review_count_reveal() {
    $count = (int) get_option( 'compkit_reveal_count', 0 );
    update_option( 'compkit_reveal_count', $count + 1, false );

    // Stamp the first reveal, so rule 2 has something to measure from.
    if ( ! get_option( 'compkit_first_reveal_at' ) ) {
        update_option( 'compkit_first_reveal_at', time(), false );
    }
}

/* There are TWO reveal paths, and hooking either one alone undercounts badly.
 *
 *   - Most games (grab claw, spin, slots, scratch card, race car, football)
 *     post to the shared `lty_reveal_instant_win` AJAX action.
 *   - Games with their own reveal AJAX (balloon pop, darts) instead fire the
 *     `compkit_reveal_ticket` action, which the email handler listens on.
 *
 * They are alternatives, not a sequence, so counting both cannot double-count a
 * single reveal. Hooking only the action would have meant a store running just
 * grab claw never reached the threshold and never saw this prompt at all.
 *
 * PRIORITY MATTERS on the AJAX hooks: the email handler registers
 * `ajax_reveal_email` at the default 10, and that method ends the request. A
 * callback at anything above 10 would never run. Hence 1.
 */
add_action( 'wp_ajax_lty_reveal_instant_win', 'compkit_review_count_reveal', 1 );
add_action( 'wp_ajax_nopriv_lty_reveal_instant_win', 'compkit_review_count_reveal', 1 );

// Late here, so a failure can never affect whether a win email sends.
add_action( 'compkit_reveal_ticket', 'compkit_review_count_reveal', 99, 0 );

/**
 * Should the prompt be shown to the current user on the current screen?
 */
function compkit_review_prompt_due() {

    /* Belt and braces on the white-label edition. The fork of
       giveaways-add-ons.php omits the require, so this file should never even
       load there — but that is protection by absence, and one mechanical
       fork-sync would undo it silently. The failure mode is putting the CompKit
       name and a kriscreates.co.uk address in front of a white-label customer,
       which is bad enough to be worth checking twice.

       Checked here rather than at file scope on purpose: game-addons-config.php
       may not have run when this file is required, but it certainly has by the
       time admin_notices fires. */
    if ( defined( 'GIVEAWAYS_WL_PLUGIN_NAME' ) ) {
        return false;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        return false;
    }

    if ( ! compkit_review_is_compkit_screen() ) {
        return false;
    }

    $state = get_option( 'compkit_review_prompt_state', '' );
    if ( 'done' === $state || 'never' === $state ) {
        return false;
    }

    $snoozed_until = (int) get_option( 'compkit_review_snoozed_until', 0 );
    if ( $snoozed_until && time() < $snoozed_until ) {
        return false;
    }

    /* Both thresholds are filterable so the prompt can actually be seen on
       demos without editing the database. Hardcoded constants made this
       untestable: a freshly updated site has 0 reveals and no first-reveal
       stamp, so the notice can never appear however long you wait, and there
       was no way to tell "correctly hidden" from "broken".

       To force it on a staging site, drop this in an mu-plugin:

           add_filter( 'compkit_review_min_reveals', '__return_zero' );
           add_filter( 'compkit_review_min_days',    '__return_zero' );

       Note that zero reveals still needs a first-reveal stamp to exist, so play
       one game after enabling the filters. That is deliberate — it keeps the
       filters a way to lower the bar, not a way to bypass the "has this store
       actually been used" question entirely. */
    $min_reveals = (int) apply_filters( 'compkit_review_min_reveals', COMPKIT_REVIEW_MIN_REVEALS );
    $min_days    = (int) apply_filters( 'compkit_review_min_days', COMPKIT_REVIEW_MIN_DAYS );

    if ( (int) get_option( 'compkit_reveal_count', 0 ) < $min_reveals ) {
        return false;
    }

    $first = (int) get_option( 'compkit_first_reveal_at', 0 );
    if ( ! $first || ( time() - $first ) < ( $min_days * DAY_IN_SECONDS ) ) {
        return false;
    }

    return true;
}

/**
 * Is the review form allowed to appear at all, thresholds aside?
 *
 * Split out from compkit_review_prompt_due() so the header button can offer the
 * form to someone who wants to write one early. The thresholds decide when we
 * *ask*; they must not decide whether someone who volunteers is allowed to.
 */
function compkit_review_can_review() {
    if ( defined( 'GIVEAWAYS_WL_PLUGIN_NAME' ) ) {
        return false;   // white-label edition — see the header of this file
    }
    return current_user_can( 'manage_options' );
}

/**
 * Are we on a CompKit admin screen?
 *
 * Two prefixes, not one. The top-level page is registered under the slug
 * `giveaways-addons`, giving `toplevel_page_giveaways-addons`. Submenu screen
 * ids are built from the *parent menu title*, not the parent slug — "CompKit
 * Game Engine" sanitises to `compkit-game-engine`, so `instant-reveal` becomes
 * `compkit-game-engine_page_instant-reveal` and contains no "giveaways" at all.
 * Matching only one prefix silently limits this to a single screen.
 */
function compkit_review_is_compkit_screen() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen ) {
        return false;
    }
    $id = (string) $screen->id;
    return ( strpos( $id, 'giveaways' ) !== false || strpos( $id, 'compkit' ) !== false );
}

/**
 * The form and its modal shell.
 *
 * Rendered once into admin_footer on CompKit screens, hidden, and opened by
 * JavaScript. It was originally an admin notice; that flashed and vanished on
 * the CompKit page, and a notice is the wrong shape for this anyway — a
 * deliberate click should not cost a page load, and the form should not be
 * competing with WordPress's own update nags for attention.
 *
 * Self-contained: its own markup, styles and script, no enqueue and no build
 * step. Everything is prefixed ckrv- so nothing can collide with the admin
 * page's own CSS.
 */
function compkit_review_render_modal() {

	$nonce = wp_create_nonce( 'compkit_review_prompt' );
	$base  = admin_url( 'admin-post.php?action=compkit_review_prompt&_wpnonce=' . $nonce );

	/* Prefill from what WordPress already knows. Nobody should have to type
	   their own name to pay someone a compliment, and a prefilled field is
	   still editable — they publish under whatever is in the box. */
	$user       = wp_get_current_user();
	$guess_name = $user ? ( $user->first_name ?: $user->display_name ) : '';
	?>
	<div class="ckrv-overlay" id="ckrv-overlay" hidden>
	  <div class="ckrv-modal" role="dialog" aria-modal="true" aria-labelledby="ckrv-title">

		<button type="button" class="ckrv-close" id="ckrv-close" aria-label="<?php esc_attr_e( 'Close', 'compkit-game-engine' ); ?>">&times;</button>

		<h2 id="ckrv-title"><?php esc_html_e( 'Can we have a quick moment of your time?', 'compkit-game-engine' ); ?></h2>
		<p class="ckrv-lede">
		  <?php
		  /* "read before it is published" stays, in this shortened form.
		     Everything else about where a review goes has gone — the plugin and
		     the site are one product and the customer should not have to think
		     about the difference. But someone who writes a review must still
		     know it can be shown publicly, or they have not agreed to anything:
		     they might reasonably take an in-dashboard box for private feedback
		     to the developer. That word is also what privacy-policy.html
		     promises we tell them. */
		  esc_html_e( 'A couple of lines about how CompKit has been to run would mean a lot. Every review is read before it is published, and you can have yours taken down whenever you like.', 'compkit-game-engine' );
		  ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		  <input type="hidden" name="action" value="compkit_review_submit">
		  <?php wp_nonce_field( 'compkit_review_submit' ); ?>

		  <?php
		  /* Stars first, because it is the cheap answer. Someone who has clicked
		     four stars has already committed to the interaction and is far more
		     likely to write the sentence underneath than someone faced with an
		     empty box straight away.

		     Radios, not JavaScript. The value posts without script, it is
		     keyboard-navigable and screen-reader-legible for free, and the whole
		     visual is one CSS rule on :checked. The markup is in reverse order so
		     ~ can reach the stars to the left of the checked one. */
		  ?>
		  <fieldset class="ckrv-stars-wrap">
			<legend><?php esc_html_e( 'How would you rate it?', 'compkit-game-engine' ); ?></legend>
			<div class="ckrv-stars">
			  <?php
			  $ckrv_labels = [
				  5 => __( 'Excellent', 'compkit-game-engine' ),
				  4 => __( 'Good', 'compkit-game-engine' ),
				  3 => __( 'Okay', 'compkit-game-engine' ),
				  2 => __( 'Poor', 'compkit-game-engine' ),
				  1 => __( 'Bad', 'compkit-game-engine' ),
			  ];
			  foreach ( $ckrv_labels as $ckrv_v => $ckrv_label ) :
				  ?>
				  <input type="radio" id="ckrv-star-<?php echo (int) $ckrv_v; ?>" name="rating"
						 value="<?php echo (int) $ckrv_v; ?>" required>
				  <label for="ckrv-star-<?php echo (int) $ckrv_v; ?>"
						 title="<?php echo esc_attr( $ckrv_label ); ?>">
					<span class="screen-reader-text">
					  <?php
					  /* translators: 1: star count, 2: word for that rating. */
					  echo esc_html( sprintf( __( '%1$d stars - %2$s', 'compkit-game-engine' ), $ckrv_v, $ckrv_label ) );
					  ?>
					</span>
				  </label>
			  <?php endforeach; ?>
			</div>
		  </fieldset>

		  <textarea name="body" rows="5" required class="ckrv-body"
					placeholder="<?php esc_attr_e( 'e.g. Took an evening to set up, and entrants now play every ticket instead of skimming the email. Our repeat entries are noticeably up.', 'compkit-game-engine' ); ?>"></textarea>

		  <div class="ckrv-fields">
			<label><?php esc_html_e( 'Your name', 'compkit-game-engine' ); ?>
			  <input type="text" name="name" required value="<?php echo esc_attr( $guess_name ); ?>">
			</label>
			<label><?php esc_html_e( 'Store name', 'compkit-game-engine' ); ?>
			  <input type="text" name="store" value="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
			</label>
		  </div>

		  <p class="ckrv-small">
			<?php
			printf(
				/* translators: %s is an email address. */
				esc_html__( 'Your name and store name go with your review so it can be attributed. Nothing else does — no email address, no customer data. Prefer to just email? %s', 'compkit-game-engine' ),
				'<a href="mailto:' . esc_attr( COMPKIT_REVIEW_EMAIL ) . '">' . esc_html( COMPKIT_REVIEW_EMAIL ) . '</a>'
			);
			?>
		  </p>

		  <div class="ckrv-actions">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Send it', 'compkit-game-engine' ); ?></button>
			<button type="button" class="button ckrv-cancel"><?php esc_html_e( 'Cancel', 'compkit-game-engine' ); ?></button>
			<?php // Offered here so the nag can be silenced from the one place it appears. ?>
			<a class="ckrv-never" href="<?php echo esc_url( $base . '&decision=never' ); ?>"><?php esc_html_e( 'Do not ask me again', 'compkit-game-engine' ); ?></a>
		  </div>
		</form>
	  </div>
	</div>

	<style>
	  .ckrv-overlay{position:fixed;inset:0;z-index:100050;display:flex;align-items:center;justify-content:center;
		background:rgba(10,12,20,.62);padding:20px}
	  .ckrv-overlay[hidden]{display:none}
	  .ckrv-modal{position:relative;width:100%;max-width:560px;max-height:90vh;overflow:auto;
		background:#fff;border-radius:10px;padding:26px 28px;box-shadow:0 18px 50px rgba(0,0,0,.35)}
	  .ckrv-modal h2{margin:0 0 10px;font-size:1.35rem}
	  .ckrv-lede{margin:0 0 18px;color:#50575e;line-height:1.6}

	  /* Stars. Rendered right-to-left so the sibling combinator can light up
	     every star to the LEFT of the hovered/checked one — CSS has no
	     previous-sibling selector, so the row is reversed in the DOM and flipped
	     back visually. The inputs stay in the layout as 1px boxes rather than
	     display:none, or the focus ring has nothing to sit on. */
	  .ckrv-stars-wrap{border:0;padding:0;margin:0 0 16px}
	  .ckrv-stars-wrap legend{padding:0;margin:0 0 6px;font-weight:600}
	  .ckrv-stars{display:inline-flex;flex-direction:row-reverse;justify-content:flex-end}
	  .ckrv-stars input{position:absolute;width:1px;height:1px;opacity:0;margin:0}
	  .ckrv-stars label{cursor:pointer;font-size:30px;line-height:1;padding:0 2px;color:#dcdcde}
	  .ckrv-stars label::before{content:"\2605"}
	  .ckrv-stars label:hover,
	  .ckrv-stars label:hover ~ label,
	  .ckrv-stars input:checked ~ label{color:#f0b429}
	  .ckrv-stars input:focus-visible + label{outline:2px solid #2271b1;outline-offset:2px;border-radius:3px}

	  .ckrv-body{width:100%;margin-bottom:14px}
	  .ckrv-fields{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px}
	  .ckrv-fields label{display:flex;flex-direction:column;gap:4px;font-weight:600;flex:1 1 200px}
	  .ckrv-fields input{font-weight:400}
	  .ckrv-small{color:#646970;font-size:.85rem;line-height:1.5;margin:0 0 18px}
	  .ckrv-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
	  .ckrv-never{margin-left:auto;color:#8c8f94;font-size:.85rem;text-decoration:underline}
	  .ckrv-close{position:absolute;top:10px;right:12px;border:0;background:none;cursor:pointer;
		font-size:26px;line-height:1;color:#787c82}
	  .ckrv-close:hover{color:#1d2327}
	</style>

	<script>
	(function () {
	  var overlay = document.getElementById('ckrv-overlay');
	  if (!overlay) return;
	  var lastFocus = null;

	  function openModal() {
		lastFocus = document.activeElement;
		overlay.hidden = false;
		var t = overlay.querySelector('textarea');
		if (t) t.focus();
		document.addEventListener('keydown', onKey, true);
	  }
	  function closeModal() {
		overlay.hidden = true;
		document.removeEventListener('keydown', onKey, true);
		if (lastFocus && lastFocus.focus) lastFocus.focus();
	  }
	  function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); closeModal(); } }

	  /* Delegated, because the opener lives in the page header printed by
		 giveaways-admin.php - a separate file that must not have to know how
		 this modal is wired. */
	  document.addEventListener('click', function (e) {
		if (!e.target.closest) return;
		if (e.target.closest('[data-ckrv-open]')) { e.preventDefault(); openModal(); return; }
		if (e.target.closest('.ckrv-cancel'))     { e.preventDefault(); closeModal(); return; }
		if (e.target === overlay)                 { closeModal(); }
	  });

	  var x = document.getElementById('ckrv-close');
	  if (x) x.addEventListener('click', closeModal);

	  /* Deep link, so a ?compkit_review=open URL still works. */
	  try {
		if (new URLSearchParams(location.search).get('compkit_review') === 'open') openModal();
	  } catch (err) {}
	})();
	</script>
	<?php
}

/**
 * Put the modal on every CompKit screen, so the header button always has
 * something to open.
 */
add_action( 'admin_footer', function () {
	if ( ! compkit_review_can_review() || ! compkit_review_is_compkit_screen() ) {
		return;
	}
	compkit_review_render_modal();
} );

/**
 * The nag. Threshold-gated, shown at most once - and now just a short line with
 * a button, because the form itself lives in the modal.
 */
add_action( 'admin_notices', function () {
	if ( ! compkit_review_prompt_due() ) {
		return;
	}
	?>
	<div class="notice notice-info compkit-review-notice">
		<p><strong><?php esc_html_e( 'Your customers have played a fair few reveals now.', 'compkit-game-engine' ); ?></strong></p>
		<p><?php esc_html_e( 'Would you write a couple of lines about how it has been to run? Every review is read before it is published.', 'compkit-game-engine' ); ?></p>
		<p>
			<button type="button" class="button button-primary" data-ckrv-open><?php esc_html_e( 'Leave a review', 'compkit-game-engine' ); ?></button>
		</p>
	</div>
	<?php
} );

/**
 * Send the review to the licence server.
 *
 * Authenticated by the same licence key and domain the plugin already proves on
 * every validate call. The server stores it as pending and nothing reaches the
 * marketing site until it is approved by hand.
 */
add_action( 'admin_post_compkit_review_submit', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to do that.', 'compkit-game-engine' ), '', [ 'response' => 403 ] );
    }
    check_admin_referer( 'compkit_review_submit' );

    $body   = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
    $name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
    $store  = isset( $_POST['store'] ) ? sanitize_text_field( wp_unslash( $_POST['store'] ) ) : '';
    $rating = isset( $_POST['rating'] ) ? (int) $_POST['rating'] : 0;
    if ( $rating < 1 || $rating > 5 ) {
        $rating = 0;   // 0 means "not given"; the server treats it the same way
    }

    $back = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=giveaways-addons' );

    if ( '' === trim( $body ) ) {
        wp_safe_redirect( add_query_arg( 'compkit_review', 'empty', $back ) );
        exit;
    }

    /* Any licence this site holds will do — the server only needs to confirm
       the site is a real customer, not which game they bought. */
    $keys    = get_option( 'giveaways_license_keys', [] );
    $license = '';
    $product = '';
    if ( is_array( $keys ) ) {
        foreach ( $keys as $module => $key ) {
            if ( is_string( $key ) && '' !== trim( $key ) ) {
                $license = trim( $key );
                $product = (string) $module;
                break;
            }
        }
    }

    if ( '' === $license ) {
        // Free-only site. Nothing to prove identity with, so send them to email.
        wp_safe_redirect( add_query_arg( 'compkit_review', 'nolicence', $back ) );
        exit;
    }

    $response = wp_remote_post(
        GIVEAWAYS_LICENCE_SERVER . '/api/review-submit.php',
        [
            'timeout'   => 15,
            'sslverify' => true,
            'body'      => [
                'license' => $license,
                'domain'  => giveaways_get_domain(),
                'name'    => $name,
                'store'   => $store,
                'body'    => $body,
                'rating'  => $rating,
                'product' => $product,
                'version' => defined( 'GIVEAWAYS_VERSION' ) ? GIVEAWAYS_VERSION : '',
            ],
        ]
    );

    $ok = ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );

    if ( $ok ) {
        // Treated as answered either way — we asked, they responded.
        update_option( 'compkit_review_prompt_state', 'done', false );
        if ( function_exists( 'compkit_log' ) ) {
            compkit_log( 'Review submitted', [ 'domain' => giveaways_get_domain() ] );
        }
    }

    wp_safe_redirect( add_query_arg( 'compkit_review', $ok ? 'sent' : 'failed', $back ) );
    exit;
} );

/**
 * Confirm what happened. "Received", never "published" — the customer must not
 * be left thinking their words are already on the site.
 */
add_action( 'admin_notices', function () {
    if ( ! isset( $_GET['compkit_review'] ) || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $state = sanitize_key( wp_unslash( $_GET['compkit_review'] ) );

    $messages = [
        'sent'      => [ 'success', __( 'Thank you — that has reached me. Every review is read before it is published, and you can ask for yours to come down at any time.', 'compkit-game-engine' ) ],
        'failed'    => [ 'error',   __( 'That did not send — the licence server could not be reached. Nothing was lost; try again in a minute.', 'compkit-game-engine' ) ],
        'empty'     => [ 'warning', __( 'Nothing was written, so nothing was sent.', 'compkit-game-engine' ) ],
        'nolicence' => [ 'warning', __( 'There is no licence key on this site, so the review could not be confirmed as coming from a customer. Email it instead and it will still be very welcome.', 'compkit-game-engine' ) ],
    ];

    if ( ! isset( $messages[ $state ] ) ) {
        return;
    }

    printf(
        '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
        esc_attr( $messages[ $state ][0] ),
        esc_html( $messages[ $state ][1] )
    );
} );

/**
 * Record the decision.
 *
 * "Sure" is treated as done and never asks again — whether or not they actually
 * send the email. Chasing someone who already said yes is worse than missing a
 * quote.
 */
add_action( 'admin_post_compkit_review_prompt', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to do that.', 'compkit-game-engine' ), '', [ 'response' => 403 ] );
    }
    check_admin_referer( 'compkit_review_prompt' );

    $decision = isset( $_GET['decision'] ) ? sanitize_key( wp_unslash( $_GET['decision'] ) ) : '';

    if ( 'never' === $decision ) {
        update_option( 'compkit_review_prompt_state', 'never', false );
    } else {
        update_option(
            'compkit_review_snoozed_until',
            time() + ( COMPKIT_REVIEW_SNOOZE_DAYS * DAY_IN_SECONDS ),
            false
        );
    }

    wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
    exit;
} );
