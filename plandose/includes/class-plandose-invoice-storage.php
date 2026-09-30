<?php
/**
 * Where invoice files live and how they are touched on disk: the WP_Filesystem
 * wrapper, resolution and validation of the (optionally custom) invoice
 * directory, magic-byte sniffing, and file deletion.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plandose_Invoice_Storage {

	const ALLOWED_INVOICE_MIMES = array( 'application/pdf', 'image/jpeg', 'image/png', 'image/webp' );

	/**
	 * Mapping of allowed invoice MIME types to the exact file extensions
	 * accepted for that MIME (see validate_invoice_upload()).
	 *
	 * IMPORTANT: wp_check_filetype_and_ext() does NOT reliably sniff real
	 * file content. For images it cross-checks with getimagesize(), but for
	 * application/pdf it essentially maps the *extension* to a MIME type —
	 * so a file called invoice.pdf containing anything at all would pass it.
	 * That is why validate_invoice_upload() additionally calls
	 * content_matches_mime(), which inspects the actual bytes. This map is
	 * the third layer: it closes the gap where a file's content matches an
	 * allowed MIME but its extension doesn't.
	 */
	const ALLOWED_INVOICE_EXTENSIONS_BY_MIME = array(
		'application/pdf' => array( 'pdf' ),
		'image/jpeg'       => array( 'jpg', 'jpeg' ),
		'image/png'        => array( 'png' ),
		'image/webp'       => array( 'webp' ),
	);

	/**
	 * Ownership marker written into a custom invoice directory. uninstall.php
	 * uses this exact marker as one of several fail-closed ownership checks.
	 * Custom storage is never recursively deleted: only database-recorded
	 * invoice files and exact PlanDose support files are removed, and the
	 * directory itself is removed only when it is empty.
	 */
	const INVOICE_DIR_MARKER_NAME    = '.plandose-invoice-storage';
	const INVOICE_DIR_MARKER_CONTENT = 'plandose-invoice-storage-v1';

	/**
	 * Best-effort debug log for filesystem operations. A failure here is
	 * either non-critical or already surfaced to the user via a
	 * transient-backed admin notice (see handle_invoice_upload()). This
	 * only adds an error_log() breadcrumb, and only when WP_DEBUG is on.
	 */
	public static function log_fs_error( $context, $path ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- failure logged for the site admin (debug.log), not shown to users.
			error_log( sprintf( '[PlanDose] %s failed for: %s', $context, $path ) );
		}
	}

	/**
	 * Lazily initialize and return the WP_Filesystem abstraction, used in
	 * place of raw @-silenced file_put_contents()/unlink()/chmod()/copy()
	 * calls throughout this class.
	 *
	 * On the overwhelming majority of hosts WP_Filesystem() resolves to the
	 * 'direct' method transparently (no credentials prompt) because
	 * wp-content/uploads is already writable by the PHP process — which is
	 * a precondition for this plugin to work at all, since every invoice
	 * lives under wp_upload_dir(). The rare host that forces FTP/SSH
	 * credentials for filesystem writes would prompt for those via
	 * request_filesystem_credentials(), which has no sane place to surface
	 * itself from inside an AJAX/admin-post handler — so on THAT class of
	 * host WP_Filesystem() returns null here and every caller below falls
	 * back to direct PHP calls plus a log entry rather than
	 * hard-failing invoice storage entirely.
	 *
	 * @return WP_Filesystem_Base|null
	 */
	private static function filesystem() {
		global $wp_filesystem;

		if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
			return $wp_filesystem;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		return WP_Filesystem() && $wp_filesystem instanceof WP_Filesystem_Base ? $wp_filesystem : null;
	}

	/**
	 * Write $contents to $path via WP_Filesystem, falling back to a
	 * @-silenced file_put_contents() only if WP_Filesystem is unavailable
	 * (see filesystem() above). Logs on failure either way.
	 */
	private static function fs_put_contents( $path, $contents, $context ) {
		$fs = self::filesystem();
		$ok = $fs
			? $fs->put_contents( $path, $contents, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 )
			: @file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors -- fallback when WP_Filesystem is unavailable; failure is logged below.

		if ( ! $ok ) {
			self::log_fs_error( $context, $path );
		}

		return (bool) $ok;
	}

	/**
	 * Delete $path via WP_Filesystem, falling back to @-silenced unlink()
	 * only if WP_Filesystem is unavailable. Logs on failure either way.
	 */
	public static function fs_delete( $path, $context ) {
		$fs = self::filesystem();
		$ok = $fs
			? $fs->delete( $path )
			: @unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors -- fallback when WP_Filesystem is unavailable; failure is logged below.

		if ( ! $ok ) {
			self::log_fs_error( $context, $path );
		}

		return (bool) $ok;
	}

	/**
	 * chmod $path via WP_Filesystem, falling back to @-silenced chmod()
	 * only if WP_Filesystem is unavailable. Logs on failure either way.
	 */
	public static function fs_chmod( $path, $mode, $context ) {
		$fs = self::filesystem();
		$ok = $fs
			? $fs->chmod( $path, $mode )
			: @chmod( $path, $mode ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- WP_Filesystem is used above; this is a guarded fallback when it is unavailable.

		if ( ! $ok ) {
			self::log_fs_error( $context, $path );
		}

		return (bool) $ok;
	}

	/**
	 * Copy $source to $target via WP_Filesystem, falling back to
	 * @-silenced copy() only if WP_Filesystem is unavailable. Logs on
	 * failure either way.
	 */
	public static function fs_copy( $source, $target, $context ) {
		$fs = self::filesystem();
		$ok = $fs
			? $fs->copy( $source, $target, true )
			: @copy( $source, $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- fallback when WP_Filesystem is unavailable; failure is logged below.

		if ( ! $ok ) {
			self::log_fs_error( $context, $source );
		}

		return (bool) $ok;
	}

	/**
	 * Absolute path to the protected directory where invoice files live.
	 *
	 * Invoices carry AFM/tax IDs, phone numbers and pharmacy identity, so
	 * they are deliberately kept OUT of the WordPress Media Library —
	 * Media Library attachments get a public, directly-linkable URL. Instead,
	 * files are stored here under a randomized filename and served only
	 * through handle_view_invoice(), which is capability + nonce gated.
	 *
	 * The .htaccess/web.config below only affect Apache/IIS. Whether the
	 * folder is really unreachable is established by probe_privacy(), which
	 * asks the live web server for a canary file (any server, Nginx included).
	 */
	public static function invoice_dir() {
		$dir = self::configured_invoice_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$is_custom = self::using_custom_invoice_dir();
		$existed   = is_dir( $dir );

		if ( ! $existed && ! wp_mkdir_p( $dir ) ) {
			self::log_fs_error( 'Creating invoice directory', $dir );
			return new WP_Error( 'plandose_invoice_dir_create_failed', __( 'Δεν ήταν δυνατή η δημιουργία του ασφαλούς φακέλου τιμολογίων.', 'plandose' ) );
		}

		if ( ! is_writable( $dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Checking a real server path before writing invoice files; WP_Filesystem->is_writable() is not reliable for arbitrary absolute paths.
			self::log_fs_error( 'Invoice directory is not writable', $dir );
			return new WP_Error( 'plandose_invoice_dir_not_writable', __( 'Ο ασφαλής φάκελος τιμολογίων δεν είναι εγγράψιμος από τον server.', 'plandose' ) );
		}

		// For a CUSTOM directory, refuse to adopt a pre-existing directory
		// without a valid ownership marker unless it is completely empty.
		// Filename-only allowlists are not sufficient here: an existing
		// .htaccess, web.config or index.php may belong to another application.
		// This check runs before PlanDose writes any protection or marker files,
		// so a refused folder is left untouched. The default uploads subfolder
		// is plugin-created and is handled separately.
		if ( $is_custom && $existed ) {
			$marker_path      = trailingslashit( $dir ) . self::INVOICE_DIR_MARKER_NAME;
			$has_valid_marker = self::marker_is_valid( $marker_path );

			if ( ! $has_valid_marker && ! self::custom_dir_can_be_claimed( $dir ) ) {
				return new WP_Error(
					'plandose_invoice_dir_not_exclusive',
					__( 'Ο φάκελος PLANDOSE_INVOICE_DIR περιέχει ήδη άλλα αρχεία και δεν μπορεί να χρησιμοποιηθεί ως αποκλειστικός φάκελος τιμολογίων PlanDose. Χρησιμοποιήστε έναν νέο, κενό, αποκλειστικό φάκελο.', 'plandose' )
				);
			}
		}

		self::fs_chmod( $dir, 0750, 'chmod on invoice directory' );

		// Files still holding an older PlanDose template are brought
		// up to date; a file with any other content was edited by someone
		// else and is left alone (diagnostics reports it).
		foreach ( self::protection_file_templates() as $name => $contents ) {
			$path = trailingslashit( $dir ) . $name;

			if ( file_exists( $path ) ) {
				if ( 'outdated' === self::protection_file_state( $path, $name ) ) {
					self::fs_put_contents( $path, $contents, 'Refreshing invoice directory protection file' );
				}
				continue;
			}

			if ( ! self::fs_put_contents( $path, $contents, 'Creating invoice directory protection file' ) ) {
				return new WP_Error(
					'plandose_invoice_protection_failed',
					__( 'Δεν ήταν δυνατή η δημιουργία των αρχείων προστασίας του φακέλου τιμολογίων.', 'plandose' )
				);
			}
		}

		// Ownership marker (see INVOICE_DIR_MARKER_NAME). This is what makes it
		// safe for uninstall.php to remove a custom directory: only a directory
		// carrying this exact marker is ever cleaned up. It is only written for
		// custom directories (the default uploads subfolder is plugin-owned by
		// construction and uninstall handles it separately), and writing it is
		// FATAL on failure — the plugin will not use a custom directory whose
		// ownership it cannot record.
		if ( $is_custom ) {
			$marker_path = trailingslashit( $dir ) . self::INVOICE_DIR_MARKER_NAME;

			if ( ! file_exists( $marker_path )
				&& ! self::fs_put_contents( $marker_path, self::INVOICE_DIR_MARKER_CONTENT, 'Creating invoice directory ownership marker' )
			) {
				return new WP_Error(
					'plandose_invoice_marker_failed',
					__( 'Δεν ήταν δυνατή η ασφαλής πιστοποίηση ιδιοκτησίας του φακέλου τιμολογίων.', 'plandose' )
				);
			}
		}

		return $dir;
	}

	/**
	 * The invoice folder for READING only: the same folder selection as
	 * invoice_dir() (configured_invoice_dir(), including the custom-folder
	 * validation), but nothing is created, chmod'ed or rewritten and the
	 * folder need not be writable. A read-only mount or a folder whose owner
	 * changed after a server move must still serve its invoices.
	 *
	 * A custom folder must carry the valid ownership marker: without it the
	 * folder was never PlanDose's (invoice_dir() would refuse or claim it),
	 * and a lookup there must not pass for «the invoice is missing».
	 *
	 * @return string|WP_Error Absolute directory path (no trailing slash), or WP_Error.
	 */
	public static function readable_invoice_dir() {
		$dir = self::configured_invoice_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		if ( ! is_dir( $dir ) ) {
			return new WP_Error( 'plandose_invoice_dir_missing', __( 'Ο ασφαλής φάκελος τιμολογίων δεν βρέθηκε στον server.', 'plandose' ) );
		}

		if ( ! is_readable( $dir ) ) {
			self::log_fs_error( 'Invoice directory is not readable', $dir );
			return new WP_Error( 'plandose_invoice_dir_not_readable', __( 'Ο ασφαλής φάκελος τιμολογίων δεν είναι αναγνώσιμος από τον server.', 'plandose' ) );
		}

		if ( self::using_custom_invoice_dir() && ! self::marker_is_valid( trailingslashit( $dir ) . self::INVOICE_DIR_MARKER_NAME ) ) {
			return new WP_Error(
				'plandose_invoice_dir_not_exclusive',
				__( 'Ο φάκελος PLANDOSE_INVOICE_DIR δεν φέρει το αρχείο ιδιοκτησίας του PlanDose, οπότε δεν διαβάζονται τιμολόγια από αυτόν. Ελέγξτε ότι η σταθερά δείχνει τον σωστό φάκελο τιμολογίων.', 'plandose' )
			);
		}

		return $dir;
	}

	/**
	 * Current contents of the protection files PlanDose writes into the
	 * invoice folder.
	 *
	 * web.config (IIS) uses requestFiltering, which is part of IIS core: an
	 * <authorization> rule does nothing where the URL Authorization
	 * module is not installed. allowUnlisted="false" with no listed
	 * extension refuses every file.
	 *
	 * .htaccess carries no «Options» line: where AllowOverride lacks Options,
	 * Apache answers 500 for the whole folder, the privacy probe reads that
	 * as unverified and production uploads are refused. The deny rules
	 * already stop directory listings.
	 *
	 * @return array<string,string> name => contents.
	 */
	public static function protection_file_templates() {
		return array(
			'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <directoryBrowse enabled=\"false\" />\n    <security>\n      <requestFiltering>\n        <fileExtensions allowUnlisted=\"false\">\n          <clear />\n        </fileExtensions>\n      </requestFiltering>\n    </security>\n  </system.webServer>\n</configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'index.html' => "\n",
		);
	}

	/**
	 * Earlier PlanDose versions of the protection files. A file holding one
	 * of these is PlanDose's own and may be rewritten with the current
	 * template.
	 *
	 * @return array<string,string[]>
	 */
	private static function legacy_protection_file_templates() {
		return array(
			'.htaccess'  => array(
				"Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			),
			'web.config' => array(
				"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <security>\n      <authorization>\n        <remove users=\"*\" roles=\"\" verbs=\"\" />\n        <add accessType=\"Deny\" users=\"*\" />\n      </authorization>\n    </security>\n  </system.webServer>\n</configuration>\n",
			),
		);
	}

	/**
	 * State of one protection file: 'current', 'outdated' (an older PlanDose
	 * template), 'foreign' (other content — not ours to rewrite), or
	 * 'missing'.
	 *
	 * @param string $path Absolute path.
	 * @param string $name Protection file name.
	 * @return string
	 */
	public static function protection_file_state( $path, $name ) {
		$templates = self::protection_file_templates();

		if ( ! isset( $templates[ $name ] ) ) {
			return 'foreign';
		}

		if ( ! is_file( $path ) || is_link( $path ) ) {
			return file_exists( $path ) ? 'foreign' : 'missing';
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- small local protection file (.htaccess/index.php); WP_Filesystem may be unavailable here.

		if ( ! is_string( $contents ) ) {
			return 'foreign';
		}

		$normalized = str_replace( "\r\n", "\n", $contents );

		if ( $normalized === $templates[ $name ] ) {
			return 'current';
		}

		$legacy = self::legacy_protection_file_templates();

		if ( isset( $legacy[ $name ] ) && in_array( $normalized, $legacy[ $name ], true ) ) {
			return 'outdated';
		}

		return 'foreign';
	}

	/**
	 * Whether a pre-existing custom directory may be claimed as PlanDose
	 * storage.
	 *
	 * A directory without a valid PlanDose ownership marker is claimable only
	 * when it is completely empty. Existing protection-looking files such as
	 * .htaccess, web.config, index.php or index.html are not assumed to belong
	 * to PlanDose because they may contain configuration or code owned by
	 * another application.
	 *
	 * @param string $dir Directory path.
	 * @return bool
	 */
	public static function custom_dir_can_be_claimed( $dir ) {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return false;
		}

		$entries = scandir( $dir );

		if ( false === $entries ) {
			return false;
		}

		$entries = array_diff( $entries, array( '.', '..' ) );

		return empty( $entries );
	}

	/**
	 * Whether the ownership marker at the given path exists with exactly the
	 * expected sentinel content.
	 *
	 * @param string $marker_path Absolute path to the marker file.
	 * @return bool
	 */
	private static function marker_is_valid( $marker_path ) {
		if ( ! is_file( $marker_path ) ) {
			return false;
		}

		$marker = file_get_contents( $marker_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- small local marker file; WP_Filesystem may be unavailable here.

		return is_string( $marker ) && self::INVOICE_DIR_MARKER_CONTENT === trim( $marker );
	}

	/**
	 * Conservatively remove a custom invoice directory's contents during
	 * uninstall: delete ONLY the plugin's own known files — first the invoice
	 * files recorded in the database, then, only if nothing else is left, the
	 * protection/marker files — and remove the directory itself only if it is
	 * then empty. Foreign files and any
	 * unknown subdirectories are never touched, and an unknown entry keeps the
	 * directory in place. This guarantees that even if a marker ended up in a
	 * shared folder, unrelated data is never deleted.
	 *
	 * @param string   $dir                 Directory (must already be marker-verified by the caller).
	 * @param string[] $known_invoice_files Basenames of invoice files recorded in the database.
	 * @return bool True if the directory itself was removed.
	 */
	public static function delete_custom_dir_contents( $dir, array $known_invoice_files ) {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return false;
		}

		$known = array();

		foreach ( $known_invoice_files as $filename ) {
			$safe = sanitize_file_name( basename( (string) $filename ) );

			if ( '' !== $safe && $safe === (string) $filename ) {
				$known[ $safe ] = true;
			}
		}

		$protection = array();

		foreach ( self::uninstall_protection_files() as $name ) {
			$protection[ $name ] = true;
		}

		$entries = scandir( $dir );

		if ( false === $entries ) {
			return false;
		}

		// Pass 1: the invoice files recorded in the database. Only ever
		// unlink known, regular files. Never recurse, never follow links,
		// never delete unknown entries or subdirectories.
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = trailingslashit( $dir ) . $entry;

			if ( isset( $known[ $entry ] ) && is_file( $path ) && ! is_link( $path ) ) {
				// Fail closed: a file that cannot be removed simply stays,
				// and the directory with it.
				self::fs_delete( $path, 'Deleting known custom invoice storage file during uninstall' );
			}
		}

		// Canaries of a privacy probe that was killed before its own
		// cleanup hold only a random token. The plugin is inactive during
		// uninstall, so none of them can still be in use: all go, and they
		// no longer keep the folder (and its protection files) in place.
		self::delete_stale_canaries( $dir, 0 );

		// Pass 2: the protection and marker files go ONLY when nothing
		// else is left. Any invoice the database does not list (orphans
		// from before 1.14.1, an interrupted upload) would otherwise stay
		// behind in wp-content/uploads with its .htaccess deny rule removed
		// — downloadable by direct URL, AFM and all.
		if ( ! self::only_protection_files_left( $dir, $protection ) ) {
			return false;
		}

		foreach ( array_keys( $protection ) as $name ) {
			$path = trailingslashit( $dir ) . $name;

			if ( is_file( $path ) && ! is_link( $path ) ) {
				self::fs_delete( $path, 'Deleting invoice directory protection file during uninstall' );
			}
		}

		$remaining = scandir( $dir );

		if ( false === $remaining ) {
			self::log_fs_error( 'Reading custom invoice directory after uninstall cleanup', $dir );
			return false;
		}

		$remaining = array_diff( $remaining, array( '.', '..' ) );

		if ( empty( $remaining ) ) {
			$fs = self::filesystem();
			$ok = $fs
				? $fs->rmdir( $dir )
				: @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- fallback when WP_Filesystem is unavailable; failure is logged below.

			if ( ! $ok ) {
				self::log_fs_error( 'Removing empty custom invoice directory during uninstall', $dir );
			}

			return (bool) $ok;
		}

		return false;
	}

	/**
	 * Names of the files PlanDose writes into an invoice directory to
	 * protect it (deny rules, blank indexes) or to mark it as its own.
	 *
	 * @return string[]
	 */
	public static function uninstall_protection_files() {
		return array( '.htaccess', 'web.config', 'index.php', 'index.html', self::INVOICE_DIR_MARKER_NAME );
	}

	/**
	 * Whether a directory holds nothing but the given protection files.
	 * Any other entry — a file, a link, a subdirectory — returns false, and
	 * so does a directory that cannot be read (fail closed). Leftover
	 * privacy-probe canaries are deleted by the caller before this check.
	 *
	 * @param string              $dir        Directory.
	 * @param array<string,bool>  $protection Protection file names as keys.
	 * @return bool
	 */
	private static function only_protection_files_left( $dir, array $protection ) {
		$entries = scandir( $dir );

		if ( false === $entries ) {
			return false;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			if ( ! isset( $protection[ $entry ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether invoice storage has been redirected outside the public uploads
	 * tree via the PLANDOSE_INVOICE_DIR constant (defined in wp-config.php).
	 * This is the recommended setup: files outside the web root have no
	 * public URL, so no web-server rule has to be correct for them to stay
	 * private.
	 */
	public static function using_custom_invoice_dir() {
		return defined( 'PLANDOSE_INVOICE_DIR' ) && '' !== trim( (string) PLANDOSE_INVOICE_DIR );
	}

	/**
	 * Whether the invoice directory actually in use sits inside the public
	 * web tree — the default uploads subfolder, OR a PLANDOSE_INVOICE_DIR
	 * that was set to a folder under ABSPATH / wp-content / uploads /
	 * DOCUMENT_ROOT.
	 *
	 * A path heuristic only: it can say "inside", never prove "private".
	 * probe_privacy() is what establishes that.
	 *
	 * @since 1.15.1
	 * @return bool
	 */
	public static function invoice_dir_is_public() {
		if ( ! self::using_custom_invoice_dir() ) {
			return true;
		}

		$custom = untrailingslashit( wp_normalize_path( trim( (string) PLANDOSE_INVOICE_DIR ) ) );

		return self::path_is_in_public_tree( $custom );
	}

	/**
	 * Whether a path is (or would be) inside a directory the web server
	 * publishes. Compares both the path as written and, where it exists,
	 * its realpath(), against both forms of each public root, so a symlink
	 * in either direction cannot hide the relationship.
	 *
	 * Deliberately NOT included: dirname( ABSPATH ). On typical hosting that
	 * is the account's home (/home/account), which is exactly where a
	 * correctly placed private folder lives.
	 *
	 * @since 1.15.1
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public static function path_is_in_public_tree( $path ) {
		$path = untrailingslashit( wp_normalize_path( (string) $path ) );

		if ( '' === $path ) {
			return false;
		}

		$candidates = array( $path );
		$real_path  = self::resolve_path( $path );

		if ( '' !== $real_path ) {
			$candidates[] = $real_path;
		}

		foreach ( self::public_roots() as $root ) {
			foreach ( $candidates as $candidate ) {
				if ( $candidate === $root || 0 === strpos( $candidate . '/', $root . '/' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * realpath() for a path that may not exist yet.
	 *
	 * realpath() fails on a missing path, so a folder not yet created under a
	 * symlinked parent (/home/account/link/invoices, where "link" points into
	 * the web root) would go unrecognised until the first upload creates it.
	 * Resolve the deepest EXISTING ancestor instead and re-append the rest.
	 *
	 * @since 1.15.1
	 * @param string $path Absolute, normalized path.
	 * @return string Resolved normalized path, or '' if nothing resolves.
	 */
	private static function resolve_path( $path ) {
		$path = untrailingslashit( wp_normalize_path( (string) $path ) );
		$tail = array();

		while ( '' !== $path ) {
			$real = realpath( $path );

			if ( false !== $real ) {
				$resolved = untrailingslashit( wp_normalize_path( $real ) );

				return $tail ? $resolved . '/' . implode( '/', array_reverse( $tail ) ) : $resolved;
			}

			$parent = untrailingslashit( wp_normalize_path( dirname( $path ) ) );

			if ( $parent === $path ) {
				break;
			}

			$tail[] = basename( $path );
			$path   = $parent;
		}

		return '';
	}

	/**
	 * Normalized directories whose contents a web server may serve directly.
	 *
	 * @since 1.15.1
	 * @return string[]
	 */
	private static function public_roots() {
		$raw = array( ABSPATH );

		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$raw[] = WP_CONTENT_DIR;
		}

		$upload_dir = wp_upload_dir( null, false );

		if ( is_array( $upload_dir ) && empty( $upload_dir['error'] ) && ! empty( $upload_dir['basedir'] ) ) {
			$raw[] = (string) $upload_dir['basedir'];
		}

		$docroot = self::document_root();

		if ( '' !== $docroot ) {
			$raw[] = $docroot;
		}

		$roots = array();

		foreach ( $raw as $dir ) {
			$dir = (string) $dir;

			if ( '' === trim( $dir ) ) {
				continue;
			}

			$roots[] = untrailingslashit( wp_normalize_path( $dir ) );
			$real    = realpath( $dir );

			if ( false !== $real ) {
				$roots[] = untrailingslashit( wp_normalize_path( $real ) );
			}
		}

		// A root that normalizes to '' (i.e. '/') would match every path.
		return array_values( array_unique( array_filter( $roots ) ) );
	}

	/**
	 * Resolve the base invoice directory path (without creating it).
	 *
	 * If the PLANDOSE_INVOICE_DIR constant is defined (in wp-config.php), that
	 * absolute path is used verbatim — the recommended setup is a directory
	 * OUTSIDE the public web root, so invoices can never be reached by a
	 * direct URL regardless of Apache/Nginx configuration. This fails CLOSED:
	 * if a custom directory is configured but unusable, an error is returned
	 * rather than silently falling back to public uploads (which would defeat
	 * the point of configuring private storage).
	 *
	 * IMPORTANT: setting or changing PLANDOSE_INVOICE_DIR only affects where
	 * NEW invoices are written and where the plugin LOOKS for existing ones.
	 * Files already stored under the old location must be moved manually.
	 *
	 * When the constant is not set, the historical protected subfolder of the
	 * WordPress uploads directory is used.
	 *
	 * @return string|WP_Error Absolute directory path (no trailing slash), or WP_Error.
	 */
	public static function configured_invoice_dir() { // Public, so `wp plandose check` can report the path without creating anything.
		if ( self::using_custom_invoice_dir() ) {
			$custom = untrailingslashit( wp_normalize_path( trim( (string) PLANDOSE_INVOICE_DIR ) ) );

			$validation = self::validate_custom_invoice_dir( $custom );

			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			return $custom;
		}

		// $create_dir = false. invoice_dir() creates what it needs;
		// read-only callers (diagnostics, CLI) must not create folders.
		$upload_dir = wp_upload_dir( null, false );

		if ( ! empty( $upload_dir['error'] ) ) {
			// WordPress's text names server paths — administrators only.
			if ( ! self::may_see_technical_detail() ) {
				return new WP_Error( 'plandose_invoice_upload_dir_error', __( 'Δεν ήταν δυνατή η πρόσβαση στον φάκελο uploads. Ο διαχειριστής του site βλέπει τις λεπτομέρειες στο PlanDose → Διαγνωστικά.', 'plandose' ) );
			}

			return new WP_Error(
				'plandose_invoice_upload_dir_error',
				sprintf(
					/* translators: %s: WordPress upload directory error. */
					__( 'Δεν ήταν δυνατή η πρόσβαση στον φάκελο uploads: %s', 'plandose' ),
					(string) $upload_dir['error']
				)
			);
		}

		$base_dir = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';

		if ( '' === $base_dir ) {
			return new WP_Error( 'plandose_invoice_upload_dir_missing', __( 'Ο φάκελος uploads δεν είναι διαθέσιμος.', 'plandose' ) );
		}

		return untrailingslashit( trailingslashit( $base_dir ) . 'plandose-invoices' );
	}

	/**
	 * Validate a custom (PLANDOSE_INVOICE_DIR) path before the plugin will
	 * use it for storage. Rejects configurations that would be dangerous —
	 * especially ones that could later cause uninstall.php to recursively
	 * delete files that don't belong to the plugin.
	 *
	 * The path must be absolute, must not be a symlink, must not be (or sit
	 * at) a critical system/WordPress directory, and must be deep enough that
	 * it can't be a broad shared location like "/", "/home" or "/home/user".
	 *
	 * @param string $custom Normalized, untrailingslashit'd candidate path.
	 * @return true|WP_Error
	 */
	public static function validate_custom_invoice_dir( $custom ) {
		if ( '' === $custom || ! path_is_absolute( $custom ) ) {
			return new WP_Error(
				'plandose_invoice_custom_dir_invalid',
				__( 'Η σταθερά PLANDOSE_INVOICE_DIR πρέπει να είναι μια απόλυτη διαδρομή φακέλου.', 'plandose' )
			);
		}

		// A symlinked storage directory is refused: it makes ownership and
		// safe deletion ambiguous (the real target could be anywhere).
		if ( is_link( $custom ) ) {
			return new WP_Error(
				'plandose_invoice_custom_dir_symlink',
				__( 'Η διαδρομή PLANDOSE_INVOICE_DIR δεν επιτρέπεται να είναι symbolic link.', 'plandose' )
			);
		}

		// Must not coincide with a critical directory. Compare both the raw
		// normalized paths and, where the target exists, their realpath()s.
		$protected = self::protected_directories();
		$candidates = array( $custom );
		$real_custom = realpath( $custom );

		if ( false !== $real_custom ) {
			$candidates[] = wp_normalize_path( $real_custom );
		}

		foreach ( $candidates as $candidate ) {
			if ( in_array( untrailingslashit( $candidate ), $protected, true ) ) {
				return new WP_Error(
					'plandose_invoice_custom_dir_protected',
					__( 'Η διαδρομή PLANDOSE_INVOICE_DIR δεν επιτρέπεται να είναι κρίσιμος φάκελος του συστήματος ή του WordPress.', 'plandose' )
				);
			}
		}

		// Reject overly broad paths. A dedicated invoice folder should be at
		// least a few levels deep (e.g. /home/account/private/plandose-invoices),
		// never something like "/", "/home" or "/home/account".
		$segments = array_values( array_filter( explode( '/', trim( $custom, '/' ) ) ) );

		if ( count( $segments ) < 3 ) {
			return new WP_Error(
				'plandose_invoice_custom_dir_too_broad',
				__( 'Η διαδρομή PLANDOSE_INVOICE_DIR είναι υπερβολικά γενική· χρησιμοποιήστε έναν αποκλειστικό, βαθύτερο φάκελο.', 'plandose' )
			);
		}

		return true;
	}

	/**
	 * Absolute, normalized directories that invoice storage must never be.
	 * Used both to validate a custom PLANDOSE_INVOICE_DIR and (mirrored in
	 * uninstall.php) to gate deletion.
	 *
	 * @return string[]
	 */
	public static function protected_directories() {
		$paths = array(
			ABSPATH,
			dirname( ABSPATH ),
			defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '',
			'/',
		);

		$upload_dir = wp_upload_dir( null, false );

		if ( is_array( $upload_dir ) && empty( $upload_dir['error'] ) && ! empty( $upload_dir['basedir'] ) ) {
			$paths[] = (string) $upload_dir['basedir'];
		}

		$normalized = array();

		foreach ( $paths as $path ) {
			if ( '' === (string) $path ) {
				continue;
			}

			$normalized[] = untrailingslashit( wp_normalize_path( (string) $path ) );

			$real = realpath( $path );

			if ( false !== $real ) {
				$normalized[] = untrailingslashit( wp_normalize_path( $real ) );
			}
		}

		return array_values( array_unique( array_filter( $normalized ) ) );
	}

	/**
	 * Whether a directory is provably an owned PlanDose invoice directory that
	 * is safe to delete recursively (used by uninstall.php). Requires the path
	 * to pass validate_custom_invoice_dir(), to be a real non-symlinked
	 * directory, and to contain the exact ownership marker. Fails CLOSED: any
	 * doubt returns false.
	 *
	 * @param string $dir Candidate directory (any form; normalized internally).
	 * @return bool
	 */
	public static function custom_invoice_dir_is_deletable( $dir ) {
		$dir = untrailingslashit( wp_normalize_path( (string) $dir ) );

		if ( is_wp_error( self::validate_custom_invoice_dir( $dir ) ) ) {
			return false;
		}

		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return false;
		}

		return self::marker_is_valid( trailingslashit( $dir ) . self::INVOICE_DIR_MARKER_NAME );
	}

	/**
	 * Read the first $length bytes of a file, or '' on failure.
	 *
	 * Used only for magic-byte inspection, so it never reads more than a
	 * few bytes and never loads the file into memory.
	 *
	 * @param string $path   Absolute path to an existing, readable file.
	 * @param int    $length Number of leading bytes to read.
	 * @return string
	 */
	private static function read_head( $path, $length ) {
		$length = max( 1, (int) $length );

		// Reading a handful of leading bytes off a local uploaded temp file for
		// magic-byte validation; WP_Filesystem offers no partial read and is not
		// guaranteed available here. A read failure returns '' below (fails closed).
		$head = @file_get_contents( $path, false, null, 0, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- local temp file, partial read; failure handled below.

		return is_string( $head ) ? $head : '';
	}

	/**
	 * Best-effort real MIME type of a file, sniffed from its contents.
	 *
	 * Returns '' when the fileinfo extension is unavailable or cannot
	 * determine a type, so callers must treat '' as "unknown" rather than
	 * as a pass.
	 *
	 * @param string $path Absolute path to an existing, readable file.
	 * @return string Lowercased MIME type, or '' if it could not be determined.
	 */
	private static function sniff_real_mime( $path ) {
		if ( ! function_exists( 'finfo_open' ) || ! defined( 'FILEINFO_MIME_TYPE' ) ) {
			return '';
		}

		// finfo_file() emits a PHP warning (and returns false) when the path
		// does not exist or is unreadable. Checking first keeps a missing
		// file a silent, fail-closed '' instead of noise in the error log.
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		$finfo = finfo_open( FILEINFO_MIME_TYPE );

		if ( false === $finfo ) {
			return '';
		}

		$mime = finfo_file( $finfo, $path );
		finfo_close( $finfo );

		return ( is_string( $mime ) && '' !== $mime ) ? strtolower( trim( $mime ) ) : '';
	}

	/**
	 * Verify that a file's ACTUAL contents match the MIME type WordPress
	 * reported for it.
	 *
	 * This exists because wp_check_filetype_and_ext() only performs real
	 * content validation for images; for application/pdf it maps the
	 * filename extension to a type. Without this check, any file renamed
	 * to invoice.pdf would be accepted and stored.
	 *
	 * Fails CLOSED: if the type cannot be established, the file is
	 * rejected rather than accepted on trust.
	 *
	 * @param string $path Absolute path to an existing, readable file.
	 * @param string $mime The MIME type claimed for it (already whitelisted).
	 * @return bool
	 */
	public static function content_matches_mime( $path, $mime ) {
		return self::content_matches_mime_sniffed( $path, $mime, self::sniff_real_mime( $path ) );
	}

	/**
	 * The decision of content_matches_mime() for a given fileinfo result
	 * ('' when fileinfo is unavailable). Public for tests.
	 *
	 * @param string $path    Absolute path to an existing, readable file.
	 * @param string $mime    Claimed MIME type.
	 * @param string $sniffed What fileinfo reported.
	 * @return bool
	 */
	public static function content_matches_mime_sniffed( $path, $mime, $sniffed ) {
		$mime    = strtolower( (string) $mime );
		$sniffed = strtolower( trim( (string) $sniffed ) );

		// "application/octet-stream" is fileinfo saying it does not
		// know (an old magic database, a PDF with leading junk); the
		// signature checks below decide instead of rejecting outright.
		if ( '' !== $sniffed && 'application/octet-stream' !== $sniffed ) {
			return $sniffed === $mime;
		}

		/*
		 * fileinfo is missing or undecided. Fall back to the format's own
		 * signature rather than skipping the check: a PDF always begins
		 * with "%PDF-", and images are validated by getimagesize(), which
		 * reads the real header.
		 */
		if ( 'application/pdf' === $mime ) {
			return 0 === strpos( self::read_head( $path, 5 ), '%PDF-' );
		}

		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A non-image returns false (handled); the warning is noise.

		if ( ! is_array( $info ) || empty( $info['mime'] ) ) {
			return false;
		}

		return strtolower( (string) $info['mime'] ) === $mime;
	}

	/**
	 * Resolve an invoice filename inside the protected directory and reject
	 * malformed or path-traversal values.
	 *
	 * For reading only (download, existence checks, hashing): the folder is
	 * resolved with readable_invoice_dir(), so it need not be writable.
	 * Writers use invoice_dir().
	 *
	 * @return string|WP_Error
	 */
	public static function invoice_path( $entry ) {
		$raw      = (string) $entry;
		$basename = basename( $raw );
		$filename = sanitize_file_name( $basename );

		if ( '' === $filename || $basename !== $raw || $filename !== $basename || self::is_reserved_name( $filename ) ) {
			return new WP_Error( 'plandose_invoice_bad_filename', __( 'Μη έγκυρη εγγραφή αρχείου τιμολογίου.', 'plandose' ) );
		}

		$dir = self::readable_invoice_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		return trailingslashit( $dir ) . $filename;
	}

	/**
	 * The directory's own protection/marker files are never an
	 * invoice. Compared case-insensitively for case-insensitive disks.
	 * Only these names are blocked: older stored names (e.g.
	 * "….unnamed-file.pdf", wp_unique_filename() suffixes) vary in form.
	 *
	 * @param string $name Bare filename.
	 * @return bool
	 */
	private static function is_reserved_name( $name ) {
		return in_array( strtolower( (string) $name ), array_map( 'strtolower', self::uninstall_protection_files() ), true );
	}

	/**
	 * A bare file extension ("pdf"), lower-case and reduced to
	 * letters and digits.
	 *
	 * sanitize_file_name() must NOT be used for this: WordPress turns a
	 * name that is nothing but a known extension into "unnamed-file.pdf".
	 *
	 * @param string $ext Extension, without the dot.
	 * @return string
	 */
	public static function clean_extension( $ext ) {
		return strtolower( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $ext ) );
	}

	/**
	 * Delete the on-disk files for a set of invoice entries (an invoice an
	 * administrator deleted, or a deleted account's invoices). Nothing
	 * deletes invoices without an administrator asking for it.
	 * Legacy numeric/Media-Library
	 * entries are left alone — those are Media Library attachments, not
	 * ours to delete. Only call this once any DB write
	 * that depends on these entries no longer being referenced has
	 * actually succeeded.
	 */
	public static function delete_invoice_files( array $entries, $dir ) {
		foreach ( $entries as $entry ) {
			if ( ! $entry || is_numeric( $entry ) ) {
				continue;
			}

			// The same rule as invoice_path() and uninstall — an
			// entry that is not already a clean bare filename is not one
			// PlanDose wrote, so it is skipped rather than "cleaned" into
			// the name of some other file in the folder.
			$name = sanitize_file_name( basename( (string) $entry ) );

			if ( '' === $name || $name !== (string) $entry || self::is_reserved_name( $name ) ) {
				continue;
			}

			$path = trailingslashit( $dir ) . $name;

			if ( file_exists( $path ) ) {
				self::fs_delete( $path, 'Deleting invoice file on administrator request' );
			}
		}
	}

	/* --------------------------------------------------------------------
	 * Verified-private storage (live HTTP probe)
	 * ------------------------------------------------------------------ */

	/** Non-autoloaded option caching the last probe_privacy() result. */
	const PRIVACY_OPTION = 'plandose_invoice_privacy';

	/** A 'private' or 'public' verdict is re-checked after a day. */
	const PRIVACY_TTL = 86400;

	/**
	 * How long a run without DOCUMENT_ROOT (php-cli cron) may keep
	 * the web server's «outside the web root» verdict (see privacy_status()).
	 */
	const PRIVACY_CLI_KEEP_MAX = 604800; // 7 days.

	/** An 'unverified' verdict (loopback failed, …) is retried sooner. */
	const PRIVACY_UNVERIFIED_TTL = 600;

	/**
	 * Canary files are named plandose-probe-<24 random>.<ext>, one per
	 * invoice extension, and plandose-probe-control-<24 random>.pdf.
	 */
	const CANARY_PREFIX = 'plandose-probe-';

	/** Exactly the names probe_privacy() writes (see is_canary_name()). */
	const CANARY_PATTERN = '/^plandose-probe-(control-)?[A-Za-z0-9]{24}\\.(pdf|jpg|jpeg|png|webp)$/';

	/**
	 * A canary left behind longer than this belongs to a probe that was
	 * killed (time limit, fatal) before its finally block could delete it.
	 */
	const CANARY_STALE_AFTER = 3600;

	/**
	 * A valid 1×1 image per image extension, written in front of the
	 * token: a CDN / image optimizer that answers 4xx for a file that is
	 * not a real image must not read as «refused», while it serves real
	 * invoice images. The token after the image data is ignored by
	 * decoders; one that re-encodes the image drops it (→ 'unverified').
	 */
	const CANARY_IMAGES = array(
		'jpg'  => '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDABALDA4MChAODQ4SERATGCgaGBYWGDEjJR0oOjM9PDkzODdASFxOQERXRTc4UG1RV19iZ2hnPk1xeXBkeFxlZ2P/2wBDARESEhgVGC8aGi9jQjhCY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2P/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDz+iiigD//2Q==',
		'png'  => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQI12NgYGAAAAAEAAEnNCcKAAAAAElFTkSuQmCC',
		'webp' => 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAsBMJaQAA3AA/vgfgAA=',
	);

	/** @var string|null DOCUMENT_ROOT override (`wp plandose check --docroot=`). */
	private static $docroot_override = null;

	/** @var bool Whether this request already ran a live probe. */
	private static $probed_this_request = false;

	/**
	 * Use this path as the web server's document root instead of
	 * $_SERVER['DOCUMENT_ROOT'], which is empty under WP-CLI.
	 *
	 * @param string|null $path Absolute path, or null to clear.
	 */
	public static function set_document_root_override( $path ) {
		$path                   = null === $path ? '' : trim( (string) $path );
		self::$docroot_override = '' === $path ? null : $path;
	}

	/**
	 * The web server's document root as far as PHP can tell, or ''.
	 *
	 * @return string
	 */
	public static function document_root() {
		if ( null !== self::$docroot_override ) {
			return self::$docroot_override;
		}

		if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) );
		}

		return '';
	}

	/**
	 * Production unless the site says otherwise. wp_get_environment_type()
	 * itself defaults to 'production' when WP_ENVIRONMENT_TYPE is unset.
	 *
	 * @return bool
	 */
	public static function is_production() {
		$production = ! function_exists( 'wp_get_environment_type' ) || 'production' === wp_get_environment_type();

		/**
		 * Filters whether invoice uploads get the production rule (storage
		 * must be verified private). For a staging copy that reports
		 * 'production', and for tests.
		 *
		 * @param bool $production Whether this is a production site.
		 */
		return (bool) apply_filters( 'plandose_invoice_storage_is_production', $production );
	}

	/**
	 * Whether the administrator deliberately accepted storage whose privacy
	 * could not be verified (wp-config.php:
	 * define( 'PLANDOSE_ALLOW_UNVERIFIED_INVOICE_DIR', true );). Never
	 * overrides a folder the probe found public.
	 *
	 * @return bool
	 */
	public static function unverified_allowed() {
		return defined( 'PLANDOSE_ALLOW_UNVERIFIED_INVOICE_DIR' ) && true === PLANDOSE_ALLOW_UNVERIFIED_INVOICE_DIR;
	}

	/**
	 * Public URL for a path inside a web-mapped WordPress folder (uploads,
	 * wp-content, ABSPATH), or '' when it has none.
	 *
	 * @param string $path Absolute path.
	 * @return string URL without a trailing slash.
	 */
	public static function path_to_url( $path ) {
		$path = untrailingslashit( wp_normalize_path( (string) $path ) );

		if ( '' === $path ) {
			return '';
		}

		$uploads = wp_get_upload_dir();
		$roots   = array();

		if ( ! empty( $uploads['basedir'] ) && ! empty( $uploads['baseurl'] ) ) {
			$roots[ untrailingslashit( wp_normalize_path( $uploads['basedir'] ) ) ] = untrailingslashit( $uploads['baseurl'] );
		}

		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$roots[ untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) ] = untrailingslashit( content_url() );
		}

		$roots[ untrailingslashit( wp_normalize_path( ABSPATH ) ) ] = untrailingslashit( site_url() );

		foreach ( $roots as $root => $url ) {
			if ( '' === $root || '' === $url ) {
				continue;
			}

			if ( $path === $root ) {
				return $url;
			}

			if ( 0 === strpos( $path . '/', $root . '/' ) ) {
				// Each segment URL-encoded — a folder named
				// "a#1" or "a%41" must not turn into a different URL.
				$segments = explode( '/', ltrim( substr( $path, strlen( $root ) ), '/' ) );

				return $url . '/' . implode( '/', array_map( 'rawurlencode', $segments ) );
			}
		}

		return '';
	}

	/**
	 * Is the invoice folder reachable from the internet? Asks the live web
	 * server instead of trusting paths or .htaccess, so it covers Apache,
	 * Nginx, LiteSpeed and IIS alike:
	 *
	 * - a folder outside every web-mapped path (and outside DOCUMENT_ROOT)
	 *   has no URL → 'private';
	 * - otherwise a random canary file is written into the folder, its URL
	 *   is requested anonymously (no cookies, no redirects followed off the
	 *   host) and the canary deleted again. The canary's content coming back
	 *   → 'public'. A refusal (4xx) → 'private' ONLY when a control canary in
	 *   a public folder (the parent / uploads base), fetched the same way,
	 *   does come back — otherwise 'unverified' (fail closed);
	 * - when that cannot be done (loopback blocked, 5xx, a folder in the web
	 *   root without a derivable URL, …) → 'unverified'.
	 *
	 * @param string|WP_Error|null $dir     Folder to test (default: the configured one). Must exist.
	 * @param bool                 $persist Cache the verdict in PRIVACY_OPTION.
	 * @return array{status:string,reason:string,checked_at:int,dir:string,url:string,http_code:int,detail:string,docroot_known:bool,home:string}
	 */
	public static function probe_privacy( $dir = null, $persist = true ) {
		self::$probed_this_request = true;

		if ( null === $dir ) {
			$dir = self::configured_invoice_dir();
		}

		$result = array(
			'status'        => 'unverified',
			'reason'        => '',
			'checked_at'    => time(),
			'dir'           => is_string( $dir ) ? untrailingslashit( wp_normalize_path( $dir ) ) : '',
			'url'           => '',
			'http_code'     => 0,
			'detail'        => '',
			'docroot_known' => '' !== self::document_root(),
			'home'          => home_url( '/' ),
		);

		if ( is_wp_error( $dir ) ) {
			$result['reason'] = 'dir_invalid';
			$result['detail'] = $dir->get_error_message();
			return $result;
		}

		$url       = self::path_to_url( $result['dir'] );
		$in_public = self::path_is_in_public_tree( $result['dir'] );

		if ( '' === $url ) {
			if ( $in_public ) {
				$result['reason'] = 'in_web_root_no_url';
			} elseif ( ! $result['docroot_known'] ) {
				/*
				 * Without DOCUMENT_ROOT (WP-CLI, a cron run by
				 * php-cli) a folder such as public_html/private-inv looks
				 * «outside the web root» only because the web root is not
				 * known here. Not a verdict, and never cached: the next web
				 * request probes again with the real DOCUMENT_ROOT.
				 */
				$result['reason'] = 'docroot_unknown';
				return $result;
			} else {
				$result['status'] = 'private';
				$result['reason'] = 'outside_web_root';
			}

			return self::finish_probe( $result, $persist );
		}

		$result['url'] = $url;

		if ( ! is_dir( $result['dir'] ) ) {
			// Not persisted: an upload creates the folder and probes again.
			$result['reason'] = 'dir_missing';
			return $result;
		}

		$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );

		if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $home_host ) {
			$result['reason'] = 'foreign_host';
			return self::finish_probe( $result, $persist );
		}

		/*
		 * Fail closed: a refusal of the invoice-folder canary only counts as
		 * «private» when a CONTROL canary in a folder that is meant to be
		 * public (the folder's parent, or the uploads base) comes back with
		 * its content through the same kind of request. Otherwise a
		 * firewall, a rate limit or a blocked loopback — which refuse
		 * everything — would read as proof of privacy.
		 */
		$control_dir = self::control_dir( $result['dir'] );
		$control_url = '' !== $control_dir ? self::path_to_url( $control_dir ) : '';

		if ( '' === $control_url || strtolower( (string) wp_parse_url( $control_url, PHP_URL_HOST ) ) !== $home_host ) {
			$result['reason'] = 'control_unavailable';
			return self::finish_probe( $result, $persist );
		}

		// Canaries of earlier probes killed before their cleanup.
		self::delete_stale_canaries( $result['dir'] );
		self::delete_stale_canaries( $control_dir );

		/*
		 * One canary per allowed invoice extension: a per-extension rule
		 * (e.g. Nginx refusing only \.pdf$, or a CDN serving images
		 * itself) could keep PDFs private while image invoices are public.
		 * 'private' needs EVERY one refused. At most one request per
		 * extension plus the control; the first failure or served canary
		 * ends the probe.
		 */
		$token         = 'plandose-canary-' . wp_generate_password( 32, false, false );
		$canaries      = array();
		$control_name  = self::CANARY_PREFIX . 'control-' . wp_generate_password( 24, false, false ) . '.pdf';
		$control_path  = trailingslashit( $control_dir ) . $control_name;
		$control_token = 'plandose-control-' . wp_generate_password( 32, false, false );
		$written       = array();
		$responses     = array();
		$control       = null;

		foreach ( self::invoice_extensions() as $ext ) {
			$canaries[ $ext ] = self::CANARY_PREFIX . wp_generate_password( 24, false, false ) . '.' . $ext;
		}

		try {
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- A few bytes each, deleted in the finally below; WP_Filesystem may need FTP credentials here.
			foreach ( $canaries as $ext => $name ) {
				$path = trailingslashit( $result['dir'] ) . $name;

				if ( false === @file_put_contents( $path, self::canary_body( $ext, $token ) ) ) {
					$result['reason'] = 'canary_write_failed';
					return self::finish_probe( $result, $persist );
				}

				$written[] = $path;
			}

			if ( false === @file_put_contents( $control_path, $control_token . "\n" ) ) {
				$result['reason'] = 'control_write_failed';
				return self::finish_probe( $result, $persist );
			}
			// phpcs:enable

			$written[] = $control_path;

			foreach ( $canaries as $ext => $name ) {
				$response          = self::probe_request( $url . '/' . rawurlencode( $name ), $home_host );
				$responses[ $ext ] = $response;

				if ( is_wp_error( $response ) || self::canary_served( $response, $token ) ) {
					break;
				}
			}

			$last = end( $responses );

			if ( ! is_wp_error( $last ) && ! self::canary_served( $last, $token ) ) {
				$control = self::probe_request( $control_url . '/' . rawurlencode( $control_name ), $home_host );
			}
		} finally {
			foreach ( $written as $file ) {
				if ( file_exists( $file ) ) {
					self::fs_delete( $file, 'Deleting privacy probe canary' );
				}
			}
		}

		$result['control_url']  = $control_url . '/';
		$result['control_code'] = null === $control || is_wp_error( $control ) ? 0 : (int) wp_remote_retrieve_response_code( $control );

		// The verdict is decided by the least private answer: a served
		// canary, then a failed request, then any answer other than a 4xx.
		$worst     = null;
		$worst_ext = '';
		$rank      = -1;

		foreach ( $responses as $ext => $response ) {
			if ( is_wp_error( $response ) ) {
				$this_rank = 4;
			} elseif ( self::canary_served( $response, $token ) ) {
				$this_rank = 5;
			} else {
				$code      = (int) wp_remote_retrieve_response_code( $response );
				$this_rank = $code >= 400 && $code < 500 ? 0 : 1;
			}

			if ( $this_rank > $rank ) {
				$rank      = $this_rank;
				$worst     = $response;
				$worst_ext = $ext;
			}
		}

		$response = $worst;

		if ( count( $canaries ) > 1 && '' !== $worst_ext && $rank > 0 ) {
			$result['detail'] = '.' . $worst_ext;
		}

		if ( is_wp_error( $response ) ) {
			$result['reason'] = 'request_failed';
			$result['detail'] = trim( $result['detail'] . ' ' . $response->get_error_message() );
			return self::finish_probe( $result, $persist );
		}

		$code                = (int) wp_remote_retrieve_response_code( $response );
		$result['http_code'] = $code;

		// Served: public, whatever the control says.
		if ( self::canary_served( $response, $token ) ) {
			$result['status'] = 'public';
			$result['reason'] = 'http_served';
			return self::finish_probe( $result, $persist );
		}

		$control_ok = null !== $control
			&& ! is_wp_error( $control )
			&& 200 === $result['control_code']
			&& false !== strpos( (string) wp_remote_retrieve_body( $control ), $control_token );

		if ( ! $control_ok ) {
			$result['reason'] = 'control_failed';
			$result['detail'] = is_wp_error( $control ) ? $control->get_error_message() : 'HTTP ' . $result['control_code'];
		} elseif ( $code >= 300 && $code < 400 ) {
			$result['reason'] = 'redirect';
		} elseif ( $code >= 500 || $code < 100 ) {
			$result['reason'] = 'server_error';
		} elseif ( $code >= 400 ) {
			$result['status'] = 'private';
			$result['reason'] = 'http_refused';
		} else {
			/*
			 * A 2xx without the canary (a WAF / challenge page, a
			 * CDN that rewrote the body, 204) is not a refusal: fail closed.
			 */
			$result['reason'] = 'unexpected_response';
		}

		return self::finish_probe( $result, $persist );
	}

	/**
	 * Every file extension an invoice may be stored with.
	 *
	 * @return string[]
	 */
	public static function invoice_extensions() {
		$exts = array();

		foreach ( self::ALLOWED_INVOICE_EXTENSIONS_BY_MIME as $list ) {
			$exts = array_merge( $exts, $list );
		}

		return array_values( array_unique( $exts ) );
	}

	/**
	 * Content of a canary: the token, after a valid 1×1 image for an
	 * image extension (CANARY_IMAGES).
	 *
	 * @param string $ext   Extension.
	 * @param string $token Token.
	 * @return string
	 */
	private static function canary_body( $ext, $token ) {
		$key = 'jpeg' === $ext ? 'jpg' : $ext;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- constant 1×1 test images, not obfuscated code.
		$image = isset( self::CANARY_IMAGES[ $key ] ) ? (string) base64_decode( self::CANARY_IMAGES[ $key ] ) : '';

		return $image . $token . "\n";
	}

	/**
	 * Whether a probe response delivered the canary itself.
	 *
	 * @param array|WP_Error $response Response.
	 * @param string         $token    Canary token.
	 * @return bool
	 */
	private static function canary_served( $response, $token ) {
		return ! is_wp_error( $response )
			&& 200 === (int) wp_remote_retrieve_response_code( $response )
			&& false !== strpos( (string) wp_remote_retrieve_body( $response ), $token );
	}

	/**
	 * Whether a file name is one of probe_privacy()'s canaries.
	 *
	 * @param string $name Bare file name.
	 * @return bool
	 */
	public static function is_canary_name( $name ) {
		return 1 === preg_match( self::CANARY_PATTERN, (string) $name );
	}

	/**
	 * Delete canaries older than $max_age from a folder (never links or
	 * folders, never another file name). Returns how many are left.
	 *
	 * @param string $dir     Folder.
	 * @param int    $max_age Seconds; 0 deletes every canary.
	 * @return int Canaries that could not be deleted (or are still fresh).
	 */
	public static function delete_stale_canaries( $dir, $max_age = self::CANARY_STALE_AFTER ) {
		if ( '' === (string) $dir || ! is_dir( $dir ) ) {
			return 0;
		}

		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable folder simply has nothing to clean.

		if ( false === $entries ) {
			return 0;
		}

		$left = 0;

		foreach ( $entries as $entry ) {
			if ( ! self::is_canary_name( $entry ) ) {
				continue;
			}

			$path  = trailingslashit( $dir ) . $entry;
			$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the file may vanish meanwhile (a parallel probe).

			if ( ! is_file( $path ) || is_link( $path ) || false === $mtime ) {
				continue;
			}

			if ( ( time() - $mtime ) < $max_age || ! self::fs_delete( $path, 'Deleting stale privacy probe canary' ) ) {
				++$left;
			}
		}

		return $left;
	}

	/**
	 * Folder for the control canary: the invoice folder's parent when it has
	 * a URL (normally the uploads base for the default folder), otherwise
	 * the uploads base. '' when neither exists or has a URL.
	 *
	 * @param string $dir Normalized invoice folder.
	 * @return string
	 */
	private static function control_dir( $dir ) {
		$candidates = array( untrailingslashit( wp_normalize_path( dirname( $dir ) ) ) );
		$uploads    = wp_get_upload_dir();

		if ( ! empty( $uploads['basedir'] ) ) {
			$candidates[] = untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		}

		foreach ( $candidates as $candidate ) {
			if ( '' !== $candidate && $candidate !== $dir && is_dir( $candidate ) && '' !== self::path_to_url( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * One anonymous GET for the canary. Redirects are followed by hand, at
	 * most twice and only on this site's own host (e.g. http → https).
	 *
	 * @param string $url       Canary URL.
	 * @param string $home_host Lower-case host of home_url().
	 * @return array|WP_Error
	 */
	private static function probe_request( $url, $home_host ) {
		$args = array(
			'timeout'             => 10,
			'redirection'         => 0,
			'cookies'             => array(),
			'sslverify'           => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, the same one core uses for its own loopback requests.
			'user-agent'          => 'PlanDose-Privacy-Probe/' . ( defined( 'PLANDOSE_VERSION' ) ? PLANDOSE_VERSION : '1' ),
			'limit_response_size' => 65536,
		);

		$response = wp_remote_get( $url, $args );

		for ( $hops = 0; $hops < 2 && ! is_wp_error( $response ); $hops++ ) {
			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( $code < 300 || $code >= 400 ) {
				break;
			}

			$location = (string) wp_remote_retrieve_header( $response, 'location' );

			if ( '' === $location ) {
				break;
			}

			if ( 0 === strpos( $location, '/' ) && 0 !== strpos( $location, '//' ) ) {
				$parts    = wp_parse_url( $url );
				$location = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . $location;
			}

			if ( strtolower( (string) wp_parse_url( $location, PHP_URL_HOST ) ) !== $home_host ) {
				break;
			}

			$url      = $location;
			$response = wp_remote_get( $url, $args );
		}

		return $response;
	}

	/**
	 * Store a probe verdict (unless $persist is false) and return it.
	 *
	 * @param array $result  Verdict.
	 * @param bool  $persist Store it.
	 * @return array
	 */
	private static function finish_probe( array $result, $persist ) {
		if ( $persist ) {
			update_option( self::PRIVACY_OPTION, $result, false );
		}

		return $result;
	}

	/**
	 * The privacy verdict for the configured folder: the cached one while it
	 * is fresh, otherwise a new probe (when $probe_if_stale). A cached
	 * verdict for a different folder is ignored.
	 *
	 * @param bool $probe_if_stale Run a probe when nothing fresh is cached.
	 * @param bool $force          Always probe.
	 * @return array Same shape as probe_privacy(), plus 'stale' => bool.
	 *               'status' may also be 'unknown' (never probed, no probe run).
	 */
	public static function privacy_status( $probe_if_stale = true, $force = false ) {
		$dir = self::configured_invoice_dir();

		if ( is_wp_error( $dir ) ) {
			return self::probe_privacy( $dir, false ) + array( 'stale' => false );
		}

		$cached = get_option( self::PRIVACY_OPTION, array() );
		$valid  = is_array( $cached )
			&& isset( $cached['status'], $cached['dir'], $cached['checked_at'] )
			&& in_array( $cached['status'], array( 'private', 'public', 'unverified' ), true )
			&& untrailingslashit( wp_normalize_path( $dir ) ) === (string) $cached['dir']
			// Made on this site (not on the server a backup came from)…
			&& isset( $cached['home'] ) && home_url( '/' ) === (string) $cached['home']
			// …and never «outside the web root» without knowing the web root.
			&& ! ( isset( $cached['reason'] ) && 'outside_web_root' === $cached['reason'] && empty( $cached['docroot_known'] ) );

		if ( $valid ) {
			$ttl   = 'unverified' === $cached['status'] ? self::PRIVACY_UNVERIFIED_TTL : self::PRIVACY_TTL;
			$fresh = ( time() - (int) $cached['checked_at'] ) < $ttl;

			if ( $fresh && ! $force ) {
				return $cached + array( 'stale' => false );
			}

			if ( ! $probe_if_stale && ! $force ) {
				return $cached + array( 'stale' => true );
			}
		} elseif ( ! $probe_if_stale && ! $force ) {
			return array(
				'status'        => 'unknown',
				'reason'        => 'never_checked',
				'checked_at'    => 0,
				'dir'           => untrailingslashit( wp_normalize_path( $dir ) ),
				'url'           => '',
				'http_code'     => 0,
				'detail'        => '',
				'docroot_known' => '' !== self::document_root(),
				'stale'         => true,
			);
		}

		$probed = self::probe_privacy( $dir );

		/*
		 * A run without DOCUMENT_ROOT (WP-CLI, a php-cli cron) cannot
		 * tell where the web root is, so it learns nothing about a folder
		 * with no URL. Discarding the web server's verdict would block every
		 * CLI-cron batch of the invoice migration, hour after hour, once
		 * that «outside the web root» verdict is a day old, until someone
		 * opens wp-admin. The web-made verdict is kept (marked stale)
		 * instead; the next web request re-checks it — but
		 * only for CLI_KEEP_MAX: on such a site the daily re-check runs from
		 * the command line too, and a web root changed by the host must not
		 * stay unnoticed for ever. Past that, the run is blocked with the
		 * «--docroot / Επανέλεγχος» hint.
		 */
		if ( $valid && isset( $probed['reason'] ) && 'docroot_unknown' === $probed['reason']
			&& 'private' === $cached['status'] && isset( $cached['reason'] ) && 'outside_web_root' === $cached['reason']
			&& ! empty( $cached['docroot_known'] )
			&& ( time() - (int) $cached['checked_at'] ) < self::PRIVACY_CLI_KEEP_MAX
		) {
			return $cached + array( 'stale' => true, 'cli_kept' => true );
		}

		return $probed + array( 'stale' => false );
	}

	/**
	 * May an invoice be uploaded into the configured folder right now?
	 *
	 * In production only into storage verified private (see
	 * probe_privacy()), or 'unverified' storage the administrator accepted
	 * with PLANDOSE_ALLOW_UNVERIFIED_INVOICE_DIR. Other environments accept
	 * 'unverified' storage with a warning (see storage_warning()), but never
	 * a folder the probe found public. Existing invoices stay downloadable
	 * either way: downloads go through PHP.
	 *
	 * Call after invoice_dir(), so the folder exists for the canary.
	 *
	 * @return true|WP_Error
	 */
	public static function upload_allowed() {
		$state = self::privacy_status( true );

		// A cached refusal is re-checked on the spot: the admin may just
		// have fixed the server.
		if ( 'private' !== $state['status'] && ! self::$probed_this_request ) {
			$state = self::privacy_status( true, true );
		}

		if ( 'private' === $state['status'] ) {
			return true;
		}

		// A folder the probe found PUBLIC is refused everywhere —
		// staging copies often hold real invoices too.
		if ( 'public' === $state['status'] ) {
			return new WP_Error( 'plandose_invoice_storage_not_private', self::refusal_message( $state ) );
		}

		if ( ! self::is_production() ) {
			return true;
		}

		if ( 'unverified' === $state['status'] && self::unverified_allowed() ) {
			return true;
		}

		return new WP_Error( 'plandose_invoice_storage_not_private', self::refusal_message( $state ) );
	}

	/**
	 * Warning for an upload accepted into storage not verified private
	 * (non-production, or the unverified override), or ''.
	 *
	 * @return string
	 */
	public static function storage_warning() {
		$state = self::privacy_status( false );

		if ( 'private' === $state['status'] ) {
			return '';
		}

		return sprintf(
			/* translators: %s: reason the check could not be completed */
			__( 'Προσοχή: το τιμολόγιο αποθηκεύτηκε, αλλά δεν επιβεβαιώθηκε ότι ο φάκελος τιμολογίων είναι κλειστός για το internet (%s). Δείτε PlanDose → Διαγνωστικά.', 'plandose' ),
			self::privacy_reason_label( $state )
		);
	}

	/**
	 * The admin message for a refused upload: what is wrong, then what to
	 * do about it (refusal_problem() + refusal_fix()).
	 *
	 * @param array $state privacy_status() result.
	 * @return string
	 */
	public static function refusal_message( array $state ) {
		return self::refusal_problem( $state ) . ' ' . __( 'Λύση:', 'plandose' ) . ' ' . self::refusal_fix( $state ) . ' ' . __( 'Τα τιμολόγια που υπάρχουν ήδη ανοίγουν κανονικά.', 'plandose' );
	}

	/**
	 * One sentence: why the upload was refused.
	 *
	 * @param array $state privacy_status() result.
	 * @return string
	 */
	public static function refusal_problem( array $state ) {
		if ( 'public' === $state['status'] ) {
			return __( 'Το τιμολόγιο δεν αποθηκεύτηκε: ο φάκελος τιμολογίων είναι ανοιχτός στο internet — ένα δοκιμαστικό αρχείο του κατέβηκε χωρίς σύνδεση.', 'plandose' );
		}

		return sprintf(
			/* translators: %s: reason the check could not be completed */
			__( 'Το τιμολόγιο δεν αποθηκεύτηκε: δεν ήταν δυνατό να επιβεβαιωθεί ότι ο φάκελος τιμολογίων είναι κλειστός για το internet (%s).', 'plandose' ),
			self::privacy_reason_label( $state )
		);
	}

	/**
	 * One sentence: the concrete fix (for the site owner or the host).
	 *
	 * @param array $state privacy_status() result.
	 * @return string
	 */
	public static function refusal_fix( array $state ) {
		if ( 'public' === $state['status'] ) {
			return __( 'Ζητήστε από τον πάροχο φιλοξενίας να μεταφέρει τον φάκελο εκτός του δημόσιου χώρου του site (σταθερά PLANDOSE_INVOICE_DIR στο wp-config.php) ή να κλείσει την πρόσβαση σε αυτόν (οδηγίες στο αρχείο nginx-invoice-protection.txt του plugin), και μετά πατήστε «Επανέλεγχος» στο PlanDose → Διαγνωστικά.', 'plandose' );
		}

		return __( 'Ζητήστε από τον πάροχο φιλοξενίας να ορίσει τη σταθερά PLANDOSE_INVOICE_DIR σε φάκελο εκτός του δημόσιου χώρου του site, ή να επιτρέψει στο site να καλεί τον εαυτό του (loopback), και μετά πατήστε «Επανέλεγχος» στο PlanDose → Διαγνωστικά. Μόνο αν ο πάροχος επιβεβαιώσει ότι ο φάκελος είναι κλειστός: define( \'PLANDOSE_ALLOW_UNVERIFIED_INVOICE_DIR\', true ); στο wp-config.php.', 'plandose' );
	}

	/**
	 * Human-readable reason of a probe verdict.
	 *
	 * @param array $state privacy_status() result.
	 * @return string
	 */
	public static function privacy_reason_label( array $state ) {
		$reason = isset( $state['reason'] ) ? (string) $state['reason'] : '';
		$labels = array(
			'outside_web_root'    => __( 'ο φάκελος είναι εκτός κάθε δημόσιου φακέλου του web server', 'plandose' ),
			'http_refused'        => __( 'ο web server αρνήθηκε να παραδώσει δοκιμαστικό αρχείο του φακέλου, ενώ παρέδωσε κανονικά αρχείο σύγκρισης σε δημόσιο φάκελο', 'plandose' ),
			'http_served'         => __( 'ο web server παρέδωσε δοκιμαστικό αρχείο του φακέλου σε αίτημα χωρίς σύνδεση', 'plandose' ),
			'in_web_root_no_url'  => __( 'ο φάκελος είναι μέσα στο web root, αλλά δεν αντιστοιχεί σε γνωστή διεύθυνση του site', 'plandose' ),
			'dir_missing'         => __( 'ο φάκελος δεν έχει δημιουργηθεί ακόμη', 'plandose' ),
			'dir_invalid'         => __( 'ο φάκελος τιμολογίων δεν είναι έγκυρος', 'plandose' ),
			'canary_write_failed' => __( 'δεν ήταν δυνατή η εγγραφή δοκιμαστικού αρχείου στον φάκελο', 'plandose' ),
			'foreign_host'        => __( 'ο φάκελος uploads σερβίρεται από άλλο host (π.χ. CDN)', 'plandose' ),
			'request_failed'      => __( 'το site δεν μπόρεσε να καλέσει τον εαυτό του — loopback', 'plandose' ),
			'redirect'            => __( 'ο server απάντησε με ανακατεύθυνση', 'plandose' ),
			'server_error'        => __( 'ο server απάντησε με σφάλμα', 'plandose' ),
			'never_checked'       => __( 'δεν έχει γίνει ακόμη έλεγχος', 'plandose' ),
			'control_failed'      => __( 'ο server δεν παρέδωσε ούτε ένα δοκιμαστικό αρχείο σε δημόσιο φάκελο, οπότε η άρνηση δεν αποδεικνύει τίποτα (π.χ. firewall ή μπλοκαρισμένο loopback)', 'plandose' ),
			'control_unavailable' => __( 'δεν βρέθηκε δημόσιος φάκελος για δοκιμή σύγκρισης', 'plandose' ),
			'control_write_failed' => __( 'δεν ήταν δυνατή η εγγραφή δοκιμαστικού αρχείου σύγκρισης', 'plandose' ),
			'unexpected_response' => __( 'ο server απάντησε χωρίς να αρνηθεί και χωρίς να παραδώσει το δοκιμαστικό αρχείο (π.χ. σελίδα firewall ή CDN), οπότε η ιδιωτικότητα δεν αποδεικνύεται', 'plandose' ),
			'docroot_unknown'     => __( 'ο έλεγχος έτρεξε χωρίς γνωστό DOCUMENT_ROOT (π.χ. WP-CLI ή cron από γραμμή εντολών) — θα επαναληφθεί από τον web server', 'plandose' ),
		);

		$label = isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason;

		if ( ! empty( $state['http_code'] ) ) {
			$label .= ' (HTTP ' . (int) $state['http_code'] . ')';
		}

		// The raw transport error (cURL / WP_Error text: internal
		// addresses, ports, paths) only for site administrators and WP-CLI.
		// Delegated PlanDose managers see the reason and the HTTP code.
		if ( ! empty( $state['detail'] ) && self::may_see_technical_detail() ) {
			$label .= ': ' . $state['detail'];
		}

		return $label;
	}

	/**
	 * Whether the current context may see server-internal details
	 * (the same rule the Diagnostics page uses for its detail lines).
	 *
	 * @return bool
	 */
	public static function may_see_technical_detail() {
		// WP-CLI is run by whoever has shell access — unless it acts as a
		// user (--user=…), whose rights then decide: a message stored for
		// that user is shown to them later in wp-admin.
		if ( defined( 'WP_CLI' ) && WP_CLI && ( ! function_exists( 'get_current_user_id' ) || ! get_current_user_id() ) ) {
			return true;
		}

		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}
}
