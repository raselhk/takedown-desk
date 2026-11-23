<?php
/**
 * Encryption key, secretbox encryption and keyed hashes.
 *
 * @package TakedownDesk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decodes a key written as base64 of 32 random bytes.
 *
 * @param string|null $b64 Base64 value, or null.
 * @return string|null 32 raw bytes, or null when the value is missing or not a valid key.
 */
function tkdesk_resolve_key( ?string $b64 ): ?string {
	if ( null === $b64 || '' === trim( $b64 ) ) {
		return null;
	}
	try {
		$raw = sodium_base642bin( trim( $b64 ), SODIUM_BASE64_VARIANT_ORIGINAL );
	} catch ( Exception $e ) {
		return null;
	}
	return SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $raw ) ? $raw : null;
}

/**
 * The active key: TKDESK_KEY when it is a valid key, else this site's own random key (option tkdesk_secret,
 * made once with random_bytes()). Never derived from the WordPress security keys or salts. Once TKDESK_KEY
 * holds the same key, the copy in the database is deleted.
 *
 * @throws RuntimeException When the database refuses to save a new key.
 * @return string 32 raw bytes.
 */
function tkdesk_key(): string {
	$constant = tkdesk_resolve_key( defined( 'TKDESK_KEY' ) ? (string) TKDESK_KEY : null );
	$stored   = tkdesk_resolve_key( (string) get_option( 'tkdesk_secret', '' ) );
	if ( null !== $constant ) {
		if ( null !== $stored && hash_equals( $stored, $constant ) ) {
			delete_option( 'tkdesk_secret' );
		}
		return $constant;
	}
	if ( null !== $stored ) {
		return $stored;
	}

	global $wpdb;
	// INSERT IGNORE, not add_option() (which overwrites on a duplicate): a key saved by a parallel request is
	// never replaced, since data encrypted with it would become unreadable. Then read back the key that won.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic first write of this plugin's own option.
	$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, 'tkdesk_secret', sodium_bin2base64( random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ), SODIUM_BASE64_VARIANT_ORIGINAL ), 'no' ) );
	wp_cache_delete( 'tkdesk_secret', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	$stored = tkdesk_resolve_key( (string) get_option( 'tkdesk_secret', '' ) );
	if ( null === $stored ) {
		// Only when the database refuses the write: never encrypt with a key that was not saved.
		throw new RuntimeException( 'Takedown Desk could not save its encryption key.' );
	}
	return $stored;
}

/**
 * Where the active key comes from.
 *
 * @return string constant (TKDESK_KEY in wp-config.php) | option (this site's random key, in the database).
 */
function tkdesk_key_source(): string {
	return null !== tkdesk_resolve_key( defined( 'TKDESK_KEY' ) ? (string) TKDESK_KEY : null ) ? 'constant' : 'option';
}

/**
 * The wp-config.php line that moves this site's key out of the database. It holds the key now in use, so
 * contact details saved so far stay readable. Shown only to people who manage the settings.
 *
 * @return string '' when TKDESK_KEY is already set.
 */
function tkdesk_key_line(): string {
	if ( 'constant' === tkdesk_key_source() ) {
		return '';
	}
	return "define( 'TKDESK_KEY', '" . sodium_bin2base64( tkdesk_key(), SODIUM_BASE64_VARIANT_ORIGINAL ) . "' );";
}

/**
 * Encrypts with secretbox.
 *
 * @param string      $plain Plain text.
 * @param string|null $key   32-byte key, null for the active key.
 * @return string base64( 24-byte nonce . box ).
 */
function tkdesk_encrypt( string $plain, ?string $key = null ): string {
	$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	return sodium_bin2base64( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ?? tkdesk_key() ), SODIUM_BASE64_VARIANT_ORIGINAL );
}

/**
 * Decrypts a value made by tkdesk_encrypt(). With no key given it tries the active key,
 * then TKDESK_KEY_OLD (a previous key, base64 like TKDESK_KEY).
 *
 * @param string|null $stored Stored value.
 * @param string|null $key    32-byte key, null for the active (and old) key.
 * @return string|WP_Error
 */
function tkdesk_decrypt( ?string $stored, ?string $key = null ) {
	$keys = array( $key ?? tkdesk_key() );
	$old  = null === $key && defined( 'TKDESK_KEY_OLD' ) ? tkdesk_resolve_key( (string) TKDESK_KEY_OLD ) : null;
	if ( null !== $old ) {
		$keys[] = $old;
	}

	try {
		$raw = sodium_base642bin( (string) $stored, SODIUM_BASE64_VARIANT_ORIGINAL );
	} catch ( Exception $e ) {
		$raw = '';
	}

	if ( strlen( $raw ) >= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		foreach ( $keys as $candidate ) {
			$plain = sodium_crypto_secretbox_open( $box, $nonce, $candidate );
			if ( false !== $plain ) {
				return $plain;
			}
		}
	}

	return new WP_Error( 'tkdesk_decrypt_failed', __( 'The stored data could not be decrypted.', 'takedown-desk' ), array( 'status' => 500 ) );
}

/**
 * A 32-byte subkey of the active key for one purpose (HKDF-SHA256), so hashes and signatures never
 * use the encryption key itself.
 *
 * @param string $info Purpose, e.g. tkdesk-hash-v1.
 * @return string 32 raw bytes.
 */
function tkdesk_subkey( string $info ): string {
	return hash_hkdf( 'sha256', tkdesk_key(), 32, $info );
}

/**
 * Keyed BLAKE2b hash of a value (email, IP), keyed with the tkdesk-hash-v1 subkey.
 *
 * @param string $value Value.
 * @return string 64 hex.
 */
function tkdesk_hash( string $value ): string {
	return bin2hex( sodium_crypto_generichash( $value, tkdesk_subkey( 'tkdesk-hash-v1' ), 32 ) );
}

/**
 * Whether a check text encrypted earlier (random nonce, like the contact data) still opens with the
 * active key alone. No hash or fingerprint of the key or the salts is ever stored or logged.
 *
 * @param string $option Option holding the check text.
 * @return bool True when the option is empty or no longer opens.
 */
function tkdesk_key_check_fails( string $option ): bool {
	$check = get_option( $option, '' );
	return ! is_string( $check ) || '' === $check || is_wp_error( tkdesk_decrypt( $check, tkdesk_key() ) );
}

/**
 * Whether the active key differs from the one first used on this site (option tkdesk_key_check).
 *
 * @return bool
 */
function tkdesk_key_changed(): bool {
	if ( '' === (string) get_option( 'tkdesk_key_check', '' ) ) {
		add_option( 'tkdesk_key_check', tkdesk_encrypt( 'tkdesk-key-check' ), '', false );
		return false;
	}
	return tkdesk_key_check_fails( 'tkdesk_key_check' );
}
