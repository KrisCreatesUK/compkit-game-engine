<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class EIWR_Settings {

	const OPTION_KEY = 'instant_reveal_global_theme';

	public static function defaults(): array {
		return [
			'style'            => 'giftbox',
			'primary'          => '#A3EA41',
			'dark'             => '#6aa52b',
			'table_header_bg'  => '#2aa8f8',
			'table_accent'     => '#0d5fa9',
			'ticket_title'     => 'Your Tickets',
			'instruction_text' => 'Click to reveal your prize',
			'win_heading'      => 'Congratulations, you have won:',
			'lose_text'        => 'Sorry, better luck next time',
		];
	}

	public static function get_theme(): array {
		self::migrate_legacy();
		$defaults = self::defaults();
		$saved    = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $saved ) ) $saved = [];
		$merged = wp_parse_args( $saved, $defaults );
		foreach ( [ 'primary', 'dark', 'table_header_bg', 'table_accent' ] as $k ) {
			if ( empty( $merged[ $k ] ) ) $merged[ $k ] = $defaults[ $k ];
		}
		return $merged;
	}

	/* Alias kept for legacy callers */
	public static function get(): array {
		return self::get_theme();
	}

	public static function migrate_legacy(): void {
		if ( get_option( 'instant_reveal_legacy_migrated' ) ) return;
		update_option( 'instant_reveal_legacy_migrated', 1 );

		$old = get_option( 'eiwr_appearance_settings', [] );
		if ( ! is_array( $old ) || empty( $old ) ) return;

		$existing = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $existing ) ) $existing = [];

		/* Only port keys that are not yet set in the new option */
		$merged = wp_parse_args( $existing, $old );
		update_option( self::OPTION_KEY, $merged );
	}

	public static function save( array $data ): void {
		update_option( self::OPTION_KEY, $data );
	}

	public static function lighten_hex( string $hex, float $amount = 0.22 ): string {
		if ( ! preg_match( '/^#([0-9a-f]{6})$/i', $hex, $m ) ) return $hex;
		$r = hexdec( substr( $m[1], 0, 2 ) );
		$g = hexdec( substr( $m[1], 2, 2 ) );
		$b = hexdec( substr( $m[1], 4, 2 ) );
		return sprintf(
			'#%02x%02x%02x',
			min( 255, (int) round( $r + ( 255 - $r ) * $amount ) ),
			min( 255, (int) round( $g + ( 255 - $g ) * $amount ) ),
			min( 255, (int) round( $b + ( 255 - $b ) * $amount ) )
		);
	}

	/* Resolved settings for a specific product — _ir_ meta overrides global. */
	public static function get_product_settings( int $product_id ): array {
		$global = self::get_theme();
		$meta   = function ( string $key, $fallback ) use ( $product_id ) {
			$val = get_post_meta( $product_id, '_ir_' . $key, true );
			return ( $val !== '' && $val !== false ) ? $val : $fallback;
		};
		return [
			'style'            => $meta( 'style',            $global['style'] ),
			'primary'          => $meta( 'primary',          $global['primary'] ),
			'dark'             => $meta( 'dark',             $global['dark'] ),
			'table_header_bg'  => $meta( 'table_header_bg',  $global['table_header_bg'] ),
			'table_accent'     => $meta( 'table_accent',     $global['table_accent'] ),
			'ticket_title'     => $meta( 'ticket_title',     $global['ticket_title'] ),
			'instruction_text' => $meta( 'instruction_text', $global['instruction_text'] ),
			'win_heading'      => $meta( 'win_heading',      $global['win_heading'] ),
			'lose_text'        => $meta( 'lose_text',        $global['lose_text'] ),
		];
	}

	/* CSS custom properties for the --instant-reveal-* namespace (admin preview + frontend). */
	public static function build_css_vars_from( array $settings ): string {
		$primary = $settings['primary']         ?: '#A3EA41';
		$dark    = $settings['dark']            ?: '#6aa52b';
		$body    = self::lighten_hex( $primary, 0.22 );
		$thead   = $settings['table_header_bg'] ?: '#2aa8f8';
		$accent  = $settings['table_accent']    ?: '#0d5fa9';

		$p = esc_attr( $primary );
		$b = esc_attr( $body );
		$d = esc_attr( $dark );
		$h = esc_attr( $thead );
		$a = esc_attr( $accent );

		/* Emit both the new canonical vars and legacy --eiwr-* so existing instant-reveal.css keeps working */
		return "--instant-reveal-primary:{$p};--instant-reveal-body:{$b};--instant-reveal-dark:{$d};--instant-reveal-table-header:{$h};--instant-reveal-table-accent:{$a};"
		     . "--eiwr-primary:{$p};--eiwr-body:{$b};--eiwr-dark:{$d};--eiwr-table-header:{$h};--eiwr-table-accent:{$a};";
	}

	public static function build_css_vars(): string {
		return self::build_css_vars_from( self::get_theme() );
	}
}
