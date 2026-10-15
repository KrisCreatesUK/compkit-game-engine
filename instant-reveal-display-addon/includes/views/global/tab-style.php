<?php
if ( ! defined( 'ABSPATH' ) ) exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template partial; vars injected via extract(), HTML class attrs misidentified as PHP class declarations

?>
<h2>Reveal Style</h2>
<p class="ck-setting-desc" style="margin:0 0 16px;font-size:12px;color:#6b7280;">
	Choose the animation shown to customers before they click to reveal their result.
</p>

<div class="ir-style-cards">
<?php
$current = $theme['style'] ?? 'giftbox';
$styles  = [
	'giftbox'  => [ 'label' => 'Gift Box',  'desc' => 'Lid lifts open on click' ],
	'envelope' => [ 'label' => 'Envelope',  'desc' => 'Flap opens to reveal'    ],
	'flipcard' => [ 'label' => 'Flip Card', 'desc' => '3D card flip reveal'      ],
];
foreach ( $styles as $val => $info ) :
	$active = ( $current === $val ) ? ' ir-style-card--active' : '';
	?>
	<label class="ir-style-card ir-style-card-<?php echo esc_attr( $val ); ?><?php echo esc_attr( $active ); ?>">
		<input type="radio" name="ir_style" value="<?php echo esc_attr( $val ); ?>"
		       <?php checked( $current, $val ); ?> style="position:absolute;opacity:0;pointer-events:none;">
		<span class="ir-mini-icon ir-mini-<?php echo esc_attr( $val ); ?>">
			<?php if ( $val === 'giftbox' ) : ?>
				<span class="ir-mini-bow"></span>
				<span class="ir-mini-lid"></span>
				<span class="ir-mini-body"></span>
			<?php elseif ( $val === 'envelope' ) : ?>
				<span class="ir-mini-env-body">
					<span class="ir-mini-env-flap"></span>
					<span class="ir-mini-env-letter"></span>
				</span>
			<?php else : ?>
				<span class="ir-mini-card">
					<span class="ir-mini-card-pat"></span>
					<span class="ir-mini-card-q">?</span>
				</span>
			<?php endif; ?>
		</span>
		<span class="ir-style-card-label"><?php echo esc_html( $info['label'] ); ?></span>
		<span class="ir-style-card-desc"><?php echo esc_html( $info['desc'] ); ?></span>
	</label>
<?php endforeach; ?>
</div>

<style>
.ir-style-cards{display:flex;gap:12px;flex-wrap:wrap;padding:4px 0 8px;}
.ir-style-card{position:relative;display:flex;flex-direction:column;align-items:center;gap:8px;
	padding:18px 22px;border:2px solid #e5e7eb;border-radius:14px;background:#fff;
	cursor:pointer;transition:border-color .15s,box-shadow .15s,background .15s;min-width:110px;text-align:center;user-select:none;}
.ir-style-card:hover{border-color:var(--game-accent,#38d4c0);background:#f0fdf4;}
.ir-style-card--active{border-color:var(--game-accent,#38d4c0)!important;background:#f0fdfa!important;box-shadow:0 0 0 3px rgba(56,212,192,.18)!important;}
.ir-style-card-label{font-size:13px;font-weight:700;color:#374151;}
.ir-style-card-desc{font-size:11px;color:#9ca3af;line-height:1.4;}

/* ── Mini game element icons ── */
.ir-mini-icon{display:block;position:relative;width:48px;height:56px;flex-shrink:0;}

/* Gift box */
.ir-mini-giftbox .ir-mini-bow{display:block;position:absolute;width:12px;height:12px;border-radius:50%;
	background:#fff;border:2px solid var(--eiwr-primary,#A3EA41);top:0;left:50%;transform:translateX(-50%);z-index:3;}
.ir-mini-giftbox .ir-mini-lid{display:block;position:absolute;top:10px;left:50%;transform:translateX(-50%);
	width:44px;height:14px;background:var(--eiwr-primary,#A3EA41);border-radius:4px 4px 0 0;z-index:2;}
.ir-mini-giftbox .ir-mini-body{display:block;position:absolute;bottom:0;left:50%;transform:translateX(-50%);
	width:40px;height:30px;background:var(--eiwr-body,#B9EE6C);border-radius:0 0 5px 5px;}

/* Envelope */
.ir-mini-env-body{display:block;position:absolute;bottom:0;left:0;right:0;height:42px;
	background:var(--eiwr-body,#B9EE6C);border-radius:4px;overflow:hidden;}
.ir-mini-env-flap{display:block;position:absolute;top:0;left:0;right:0;height:26px;
	background:var(--eiwr-primary,#A3EA41);clip-path:polygon(0 0,50% 72%,100% 0);}
.ir-mini-env-letter{display:block;position:absolute;bottom:6px;left:50%;transform:translateX(-50%);
	width:26px;height:18px;background:#fff;border-radius:2px;opacity:.9;}

/* Flip card */
.ir-mini-card{display:flex;align-items:center;justify-content:center;
	position:absolute;top:4px;left:50%;transform:translateX(-50%);
	width:34px;height:48px;background:var(--eiwr-primary,#A3EA41);border-radius:6px;
	box-shadow:0 3px 8px rgba(0,0,0,.18);}
.ir-mini-card-pat{position:absolute;inset:4px;border:2px solid rgba(255,255,255,.45);border-radius:3px;}
.ir-mini-card-q{font-size:20px;font-weight:900;color:#fff;position:relative;z-index:1;line-height:1;}
</style>
