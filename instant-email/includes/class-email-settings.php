<?php
/**
 * Instant Reveal & Orders Email — settings store.
 *
 * Single place that knows every option key, its default, and how to read/save
 * the whole settings set. Shared by the admin page (UI + live preview) and the
 * email engine. Option names match the originals so existing data is preserved.
 *
 * @package CompKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CompKit_Email_Settings {

	const OPTION_ENABLED            = 'playkit_reveal_email_enabled';
	const OPTION_SUBJECT            = 'playkit_reveal_email_subject';
	const OPTION_MESSAGE            = 'playkit_reveal_email_message';
	const OPTION_DISCLAIMER_INSTANT     = 'playkit_email_disclaimer_instant';
	const OPTION_DISCLAIMER_NON_INSTANT = 'playkit_email_disclaimer_non_instant';
	const OPTION_TICKET_BG         = 'playkit_ticket_bg_color';
	const OPTION_TICKET_TEXT       = 'playkit_ticket_text_color';
	const OPTION_WIN_TICKET_BG     = 'playkit_win_ticket_bg_color';
	const OPTION_WIN_TICKET_TEXT   = 'playkit_win_ticket_text_color';
	const OPTION_COUPON_BTN_BG     = 'playkit_coupon_btn_bg';
	const OPTION_COUPON_BTN_TEXT   = 'playkit_coupon_btn_text';
	const OPTION_CLAIM_BTN_BG      = 'playkit_claim_btn_bg';
	const OPTION_CLAIM_BTN_TEXT    = 'playkit_claim_btn_text';
	const OPTION_PRIZE_HEADING     = 'playkit_prize_heading';
	const OPTION_NOTE_CREDIT       = 'playkit_prize_note_credit';
	const OPTION_NOTE_PHYSICAL     = 'playkit_prize_note_physical';
	const OPTION_BADGE_RADIUS      = 'playkit_badge_radius';
	const OPTION_BADGE_SIZE        = 'playkit_badge_size';
	const OPTION_COUPON_BTN_RADIUS = 'playkit_coupon_btn_radius';
	const OPTION_COUPON_BTN_SIZE   = 'playkit_coupon_btn_size';
	const OPTION_CLAIM_BTN_RADIUS  = 'playkit_claim_btn_radius';
	const OPTION_CLAIM_BTN_SIZE    = 'playkit_claim_btn_size';

	/**
	 * Default values for every setting.
	 *
	 * @return array<string,string>
	 */
	public static function defaults(): array {
		return [
			'enabled'                => 'yes',
			'subject'                => 'INSTANT WIN REVEALED ON *PRODUCT',
			'message'                => "<p>Hi {user_name},</p>\n<p><strong>Congratulations</strong>, you just revealed an Instant Win prize.</p>\n<p><strong>{prize_name}</strong></p>\n<p>{prize_image}</p>\n<p>{prize_desc}</p>\n<p><strong>Ticket Number:</strong> {ticket_number}</p>\n<p>Thanks for playing,<br>{site_name}</p>",
			'disclaimer_instant'     => 'Game replay available, prizes are not re-awarded. Good luck in the main competition draw. Below are your entry details.',
			'ticket_bg'              => '#2aa8f8',
			'ticket_text'            => '#000000',
			'win_ticket_bg'          => '#d4af37',
			'win_ticket_text'        => '#000000',
			'coupon_btn_bg'          => '#2aa8f8',
			'coupon_btn_text'        => '#ffffff',
			'claim_btn_bg'           => '#000000',
			'claim_btn_text'         => '#2aa8f8',
			'prize_heading'          => 'Your Instant Wins',
			'note_credit'            => '*Applied to account',
			'note_physical'          => 'Contact Us',
			'badge_radius'           => '8',
			'badge_size'             => '11',
			'coupon_btn_radius'      => '4',
			'coupon_btn_size'        => '13',
			'claim_btn_radius'       => '12',
			'claim_btn_size'         => '14',
		];
	}

	/**
	 * Current settings, each falling back to its default.
	 *
	 * @return array<string,string>
	 */
	public static function get(): array {
		$d = self::defaults();
		return [
			'enabled'                => get_option( self::OPTION_ENABLED, $d['enabled'] ),
			'subject'                => get_option( self::OPTION_SUBJECT, $d['subject'] ),
			'message'                => get_option( self::OPTION_MESSAGE, $d['message'] ),
			'disclaimer_instant'     => get_option( self::OPTION_DISCLAIMER_INSTANT, $d['disclaimer_instant'] ),
			'ticket_bg'              => get_option( self::OPTION_TICKET_BG, $d['ticket_bg'] ),
			'ticket_text'            => get_option( self::OPTION_TICKET_TEXT, $d['ticket_text'] ),
			'win_ticket_bg'          => get_option( self::OPTION_WIN_TICKET_BG, $d['win_ticket_bg'] ),
			'win_ticket_text'        => get_option( self::OPTION_WIN_TICKET_TEXT, $d['win_ticket_text'] ),
			'coupon_btn_bg'          => get_option( self::OPTION_COUPON_BTN_BG, $d['coupon_btn_bg'] ),
			'coupon_btn_text'        => get_option( self::OPTION_COUPON_BTN_TEXT, $d['coupon_btn_text'] ),
			'claim_btn_bg'           => get_option( self::OPTION_CLAIM_BTN_BG, $d['claim_btn_bg'] ),
			'claim_btn_text'         => get_option( self::OPTION_CLAIM_BTN_TEXT, $d['claim_btn_text'] ),
			'prize_heading'          => get_option( self::OPTION_PRIZE_HEADING, $d['prize_heading'] ),
			'note_credit'            => get_option( self::OPTION_NOTE_CREDIT, $d['note_credit'] ),
			'note_physical'          => get_option( self::OPTION_NOTE_PHYSICAL, $d['note_physical'] ),
			'badge_radius'           => get_option( self::OPTION_BADGE_RADIUS, $d['badge_radius'] ),
			'badge_size'             => get_option( self::OPTION_BADGE_SIZE, $d['badge_size'] ),
			'coupon_btn_radius'      => get_option( self::OPTION_COUPON_BTN_RADIUS, $d['coupon_btn_radius'] ),
			'coupon_btn_size'        => get_option( self::OPTION_COUPON_BTN_SIZE, $d['coupon_btn_size'] ),
			'claim_btn_radius'       => get_option( self::OPTION_CLAIM_BTN_RADIUS, $d['claim_btn_radius'] ),
			'claim_btn_size'         => get_option( self::OPTION_CLAIM_BTN_SIZE, $d['claim_btn_size'] ),
		];
	}

	/**
	 * Seed defaults once (kept for parity with the old maybe_set_defaults()).
	 */
	public static function maybe_set_defaults(): void {
		$d = self::defaults();
		$map = [
			self::OPTION_TICKET_BG            => $d['ticket_bg'],
			self::OPTION_TICKET_TEXT          => $d['ticket_text'],
			self::OPTION_WIN_TICKET_BG        => $d['win_ticket_bg'],
			self::OPTION_WIN_TICKET_TEXT      => $d['win_ticket_text'],
			self::OPTION_DISCLAIMER_INSTANT     => $d['disclaimer_instant'],
			self::OPTION_ENABLED              => $d['enabled'],
			self::OPTION_SUBJECT              => $d['subject'],
			self::OPTION_MESSAGE              => $d['message'],
		];
		foreach ( $map as $key => $default ) {
			if ( get_option( $key, '' ) === '' ) {
				update_option( $key, $default );
			}
		}
	}

	/**
	 * Save the posted settings (verifies the settings-form nonce first).
	 */
	public static function save_from_post(): void {
		if (
			! isset( $_POST['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'playkit_save_email' )
		) {
			return;
		}

		update_option( self::OPTION_ENABLED, isset( $_POST['enabled'] ) ? 'yes' : 'no' );
		update_option( self::OPTION_SUBJECT, sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) ) );
		update_option( self::OPTION_MESSAGE, wp_kses_post( wp_unslash( $_POST['message'] ?? '' ) ) );
		update_option( self::OPTION_DISCLAIMER_INSTANT, wp_kses_post( wp_unslash( $_POST['disclaimer_instant'] ?? '' ) ) );
		update_option( self::OPTION_TICKET_BG, sanitize_hex_color( wp_unslash( $_POST['ticket_bg'] ?? '' ) ) ?: '#2aa8f8' );
		update_option( self::OPTION_TICKET_TEXT, sanitize_hex_color( wp_unslash( $_POST['ticket_text'] ?? '' ) ) ?: '#000000' );
		update_option( self::OPTION_WIN_TICKET_BG, sanitize_hex_color( wp_unslash( $_POST['win_ticket_bg'] ?? '' ) ) ?: '#d4af37' );
		update_option( self::OPTION_WIN_TICKET_TEXT, sanitize_hex_color( wp_unslash( $_POST['win_ticket_text'] ?? '' ) ) ?: '#000000' );
		update_option( self::OPTION_COUPON_BTN_BG, sanitize_hex_color( wp_unslash( $_POST['coupon_btn_bg'] ?? '' ) ) ?: '#2aa8f8' );
		update_option( self::OPTION_COUPON_BTN_TEXT, sanitize_hex_color( wp_unslash( $_POST['coupon_btn_text'] ?? '' ) ) ?: '#ffffff' );
		update_option( self::OPTION_CLAIM_BTN_BG, sanitize_hex_color( wp_unslash( $_POST['claim_btn_bg'] ?? '' ) ) ?: '#000000' );
		update_option( self::OPTION_CLAIM_BTN_TEXT, sanitize_hex_color( wp_unslash( $_POST['claim_btn_text'] ?? '' ) ) ?: '#2aa8f8' );
		update_option( self::OPTION_PRIZE_HEADING, sanitize_text_field( wp_unslash( $_POST['prize_heading'] ?? '' ) ) );
		update_option( self::OPTION_NOTE_CREDIT, sanitize_text_field( wp_unslash( $_POST['note_credit'] ?? '' ) ) );
		update_option( self::OPTION_NOTE_PHYSICAL, sanitize_text_field( wp_unslash( $_POST['note_physical'] ?? '' ) ) );

		// Pixel sizes — clamp to sensible ranges. Nonce already verified above.
		$px = function ( $key, $min, $max, $fallback ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at the top of save_from_post()
			$v = isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : $fallback;
			return (string) max( $min, min( $max, $v ) );
		};
		update_option( self::OPTION_BADGE_RADIUS, $px( 'badge_radius', 0, 40, 8 ) );
		update_option( self::OPTION_BADGE_SIZE, $px( 'badge_size', 8, 28, 11 ) );
		update_option( self::OPTION_COUPON_BTN_RADIUS, $px( 'coupon_btn_radius', 0, 40, 4 ) );
		update_option( self::OPTION_COUPON_BTN_SIZE, $px( 'coupon_btn_size', 8, 28, 13 ) );
		update_option( self::OPTION_CLAIM_BTN_RADIUS, $px( 'claim_btn_radius', 0, 40, 12 ) );
		update_option( self::OPTION_CLAIM_BTN_SIZE, $px( 'claim_btn_size', 8, 32, 14 ) );
	}
}
