<?php
/**
 * Private upload routing + admin-only download proxy.
 *
 * Applicant ID / business-proof uploads land in
 *   {wp-uploads}/partner-program-private/
 * which we lock down with a generated `.htaccess` (Apache) plus an empty
 * `index.html` to defeat directory listing. Filenames are randomized on the
 * way in so URLs aren't guessable even if the deny rules ever fail.
 *
 * Admins view uploads through `admin-post.php?action=pp_download_private&id=X`,
 * which checks the manage cap, the nonce, and the per-attachment `_pp_private`
 * meta flag before streaming the file. The original `_wp_attached_file` meta
 * stores the random filename, but the download is served with the original
 * uploaded filename (preserved on the attachment post title) so the admin
 * sees what the applicant actually submitted.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Application;

use PartnerProgram\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class PrivateUploads {

	public const DIR_BASENAME = 'partner-program-private';
	public const META_FLAG    = '_pp_private';
	public const META_ORIG    = '_pp_original_filename';
	public const META_PATH    = '_pp_private_path';
	public const NONCE_ACTION = 'pp_download_private';

	public function register(): void {
		add_action( 'admin_post_' . self::NONCE_ACTION, [ $this, 'handle_download' ] );
		// Remove the relocated off-webroot file when its attachment is deleted.
		add_action( 'delete_attachment', [ __CLASS__, 'cleanup_private_file' ] );
	}

	/**
	 * Run a callback while uploads are routed into the private subdir.
	 * The callback receives a `unique_filename_callback` it should pass to
	 * `media_handle_upload` / `wp_handle_upload`.
	 *
	 * @template T
	 * @param  callable(callable):T $cb
	 * @return T
	 */
	public static function with_private_dir( callable $cb ) {
		self::ensure_dir_protected();

		$basename = self::DIR_BASENAME;
		$reroute  = static function ( $dirs ) use ( $basename ) {
			if ( ! is_array( $dirs ) ) {
				return $dirs;
			}
			$subdir         = '/' . $basename . ( isset( $dirs['subdir'] ) ? (string) $dirs['subdir'] : '' );
			$dirs['subdir'] = $subdir;
			$dirs['path']   = $dirs['basedir'] . $subdir;
			$dirs['url']    = $dirs['baseurl'] . $subdir;
			return $dirs;
		};

		$rename = static function ( $dir, $name, $ext ) {
			unset( $dir, $name );
			$ext = $ext ? strtolower( ltrim( (string) $ext, '.' ) ) : '';
			return wp_generate_password( 24, false, false ) . ( $ext ? '.' . $ext : '' );
		};

		add_filter( 'upload_dir', $reroute );
		// Don't generate web-served thumbnails of a private ID/KYC image —
		// otherwise the resized copies leak the document even after the
		// original is relocated off the web root.
		add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 99 );
		try {
			return $cb( $rename );
		} finally {
			remove_filter( 'upload_dir', $reroute );
			remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 99 );
		}
	}

	/**
	 * Stamp the meta we use to gate the download proxy and to recover the
	 * original filename. Caller should invoke this immediately after
	 * media_handle_upload returns a successful attachment id.
	 */
	public static function mark_private( int $attachment_id, string $original_filename ): void {
		update_post_meta( $attachment_id, self::META_FLAG, '1' );
		if ( '' !== $original_filename ) {
			update_post_meta( $attachment_id, self::META_ORIG, sanitize_file_name( $original_filename ) );
		}
		self::relocate_off_webroot( $attachment_id );
	}

	/**
	 * Move the uploaded file out of the web-served uploads tree into a
	 * directory ABOVE the web root, so it can't be fetched directly on servers
	 * that ignore .htaccess (e.g. Nginx/Kinsta). The download proxy reads the
	 * recorded absolute path. If no writable off-webroot dir exists we leave
	 * the file in the .htaccess-protected uploads subdir (no regression).
	 */
	private static function relocate_off_webroot( int $attachment_id ): void {
		$src = get_attached_file( $attachment_id );
		if ( ! $src || ! is_readable( $src ) ) {
			return;
		}
		$ext_dir = self::external_basedir();
		if ( null === $ext_dir ) {
			return;
		}
		$dest = trailingslashit( $ext_dir ) . wp_basename( $src );
		if ( file_exists( $dest ) ) {
			$dest = trailingslashit( $ext_dir ) . wp_generate_password( 12, false, false ) . '-' . wp_basename( $src );
		}
		if ( @rename( $src, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.rename
			update_post_meta( $attachment_id, self::META_PATH, $dest );
		}
	}

	/**
	 * A directory above the web root for private files, created and deny-listed.
	 * Returns null when its parent isn't writable, in which case callers keep
	 * the file in the uploads subdir behind .htaccess.
	 */
	public static function external_basedir(): ?string {
		$parent = dirname( untrailingslashit( ABSPATH ) );
		if ( '' === $parent || ! is_dir( $parent ) || ! wp_is_writable( $parent ) ) {
			return null;
		}
		$dir = trailingslashit( $parent ) . self::DIR_BASENAME;
		if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		}
		return $dir;
	}

	/**
	 * Remove the relocated off-webroot file when its attachment is deleted —
	 * WordPress only knows about the (now-empty) uploads path.
	 */
	public static function cleanup_private_file( int $attachment_id ): void {
		$path = (string) get_post_meta( $attachment_id, self::META_PATH, true );
		if ( '' !== $path && is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_system_delete_unlink
		}
	}

	public static function get_proxy_url( int $attachment_id ): string {
		return wp_nonce_url(
			add_query_arg(
				[ 'action' => self::NONCE_ACTION, 'id' => $attachment_id ],
				admin_url( 'admin-post.php' )
			),
			self::NONCE_ACTION . '_' . $attachment_id
		);
	}

	public function handle_download(): void {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( self::NONCE_ACTION . '_' . $id );

		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Permission denied.', 'partner-program' ), '', [ 'response' => 403 ] );
		}

		if ( $id <= 0 ) {
			wp_die( esc_html__( 'Invalid file.', 'partner-program' ), '', [ 'response' => 400 ] );
		}

		$attachment = get_post( $id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			wp_die( esc_html__( 'File not found.', 'partner-program' ), '', [ 'response' => 404 ] );
		}

		// Sanity gate: this proxy refuses to serve anything that wasn't
		// flagged by the application form. Stops a manage-cap user from
		// using it as a generic media reader.
		if ( '1' !== (string) get_post_meta( $id, self::META_FLAG, true ) ) {
			wp_die( esc_html__( 'File not allowed.', 'partner-program' ), '', [ 'response' => 403 ] );
		}

		// Prefer the relocated off-webroot path; fall back to the WP-managed
		// uploads path for files stored before the relocation logic existed.
		$path = (string) get_post_meta( $id, self::META_PATH, true );
		if ( '' === $path ) {
			$path = (string) get_attached_file( $id );
		}
		if ( ! $path || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'File missing on disk.', 'partner-program' ), '', [ 'response' => 404 ] );
		}

		$original = (string) get_post_meta( $id, self::META_ORIG, true );
		if ( '' === $original ) {
			$original = basename( (string) get_post_meta( $id, '_wp_attached_file', true ) ?: 'file' );
		}

		$mime = (string) get_post_mime_type( $id );
		if ( '' === $mime ) {
			$mime = 'application/octet-stream';
		}

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . (string) ( filesize( $path ) ?: 0 ) );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $original ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		exit;
	}

	/**
	 * Idempotent: creates the private subdir, drops a deny-all `.htaccess`
	 * for Apache, and an empty `index.html` to defeat directory listing on
	 * any server that ignores the htaccess.
	 */
	public static function ensure_dir_protected(): void {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return;
		}
		$dir = trailingslashit( (string) $uploads['basedir'] ) . self::DIR_BASENAME;
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$body = "# Partner Program private uploads — deny direct access.\n"
				. "# Managed by the partner-program plugin; will be recreated if removed.\n"
				. "<IfModule mod_authz_core.c>\n"
				. "    Require all denied\n"
				. "</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n"
				. "    <IfModule mod_access_compat.c>\n"
				. "        Order allow,deny\n"
				. "        Deny from all\n"
				. "    </IfModule>\n"
				. "</IfModule>\n";
			@file_put_contents( $htaccess, $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		}
		$index = $dir . '/index.html';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		}
	}
}
