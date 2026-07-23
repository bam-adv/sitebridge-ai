<?php
/**
 * Plugin Name: SiteBridge AI
 * Plugin URI:  https://github.com/bam-adv/sitebridge-ai
 * Update URI:  https://github.com/bam-adv/sitebridge-ai
 * Description: Bridges AI tooling (the wp-mcp-hosted connector) to any WordPress site — JSON-LD schema, desktop ACF navigation, and managed redirects, all over REST. Self-updates from GitHub releases.
 * Version:     1.11.0
 * Author:      Devon Moore
 * Text Domain: sitebridge-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/* ============================================================================
 * CONFIG / PROFILE  (the per-client / per-theme tailoring layer)
 * ----------------------------------------------------------------------------
 * Everything site- or theme-specific lives here so the rest of the plugin stays
 * generic. Override any of these via wp-config constants or the filters noted.
 *
 * NOTE: the *string values* below (the `bam/...` REST namespaces and the
 * `_bam_*` / `bam_*` storage keys) are intentionally preserved so this version
 * is a drop-in replacement — the deployed connector and the schema/redirect data
 * already on live sites keep working untouched. They can be renamed later in a
 * coordinated connector update + data migration. The constant *names* are
 * SiteBridge-branded; only their values stay legacy.
 * ========================================================================== */

define( 'SITEBRIDGE_VERSION', '1.11.0' );

// --- Self-update source: set this to your GitHub "owner/repo" ----------------
if ( ! defined( 'SITEBRIDGE_GH_REPO' ) ) {
	define( 'SITEBRIDGE_GH_REPO', 'bam-adv/sitebridge-ai' );
}

// --- Release signature verification (Ed25519 / libsodium) --------------------
// PUBLIC key only. Each release .zip is signed with the matching PRIVATE key,
// which is held offline by the maintainer and never lives in this repo. The
// updater refuses to install any release whose .zip does not verify against
// this key (fail-closed). Key rotation = ship a manual plugin update carrying
// the new public key here, then sign all later releases with the new key.
const SITEBRIDGE_UPDATE_PUBKEY = '2TopyeMZDoA7MD/rA07m8L3Tjp40/ktFPRPi7asEzUk=';

// --- Schema storage (kept as legacy keys for data compatibility) -------------
const SITEBRIDGE_SCHEMA_META_KEY        = '_bam_schema_jsonld';
const SITEBRIDGE_SCHEMA_TEMPLATE_PREFIX = 'bam_schema_template_';

// --- Navigation (Culligan v4 theme profile) ----------------------------------
if ( ! defined( 'SITEBRIDGE_NAV_OPTION_ID' ) )     define( 'SITEBRIDGE_NAV_OPTION_ID', 'option' );
if ( ! defined( 'SITEBRIDGE_NAV_FIELD' ) )         define( 'SITEBRIDGE_NAV_FIELD', 'main_nav_settings_version_2' );
if ( ! defined( 'SITEBRIDGE_NAV_VERSION_FIELD' ) ) define( 'SITEBRIDGE_NAV_VERSION_FIELD', 'main_nav_version' );

// --- Redirects (kept as legacy option key for data compatibility) ------------
if ( ! defined( 'SITEBRIDGE_REDIRECTS_OPTION' ) )  define( 'SITEBRIDGE_REDIRECTS_OPTION', 'bam_redirects' );

// --- REST namespaces (kept legacy so the deployed connector keeps working) ---
const SITEBRIDGE_NS        = 'bam/v1';
const SITEBRIDGE_SCHEMA_NS = 'bam-schema/v1';

/* ============================================================================
 * SELF-UPDATER  — checks GitHub Releases and feeds WordPress' update system,
 * so each site can one-click (or auto) update. Publish a GitHub Release with a
 * tag like v1.7.0 (and ideally attach the built zip as a release asset).
 * ========================================================================== */

add_filter( 'pre_set_site_transient_update_plugins', 'sitebridge_check_for_update' );
function sitebridge_check_for_update( $transient ) {
	if ( empty( $transient->checked ) ) {
		return $transient;
	}
	$release = sitebridge_get_latest_release();
	if ( ! $release || empty( $release->tag_name ) ) {
		return $transient;
	}
	$latest = ltrim( $release->tag_name, 'vV' );
	if ( version_compare( $latest, SITEBRIDGE_VERSION, '>' ) ) {
		// Use the signed release .zip asset — verified before install (see
		// sitebridge_verify_before_download). Fall back to the zipball only so an
		// update is still offered; it has no .sig and will be refused.
		$package = sitebridge_release_asset( $release, '.zip' );
		if ( $package === '' && ! empty( $release->zipball_url ) ) {
			$package = $release->zipball_url;
		}
		$file = plugin_basename( __FILE__ );
		$transient->response[ $file ] = (object) array(
			'slug'        => dirname( $file ),
			'plugin'      => $file,
			'new_version' => $latest,
			'package'     => $package,
			'url'         => 'https://github.com/' . SITEBRIDGE_GH_REPO,
		);
	}
	return $transient;
}

function sitebridge_get_latest_release() {
	$cached = get_transient( 'sitebridge_latest_release' );
	if ( $cached !== false ) {
		return $cached;
	}
	$resp = wp_remote_get(
		'https://api.github.com/repos/' . SITEBRIDGE_GH_REPO . '/releases/latest',
		array(
			'timeout' => 15,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'SiteBridge-AI',
			),
		)
	);
	if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
		return false;
	}
	$data = json_decode( wp_remote_retrieve_body( $resp ) );
	set_transient( 'sitebridge_latest_release', $data, 6 * HOUR_IN_SECONDS );
	return $data;
}

// GitHub source zipballs unpack to "repo-tag/"; rename to the plugin's real slug.
add_filter( 'upgrader_source_selection', 'sitebridge_fix_update_folder', 10, 4 );
function sitebridge_fix_update_folder( $source, $remote_source, $upgrader, $hook_extra ) {
	if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== plugin_basename( __FILE__ ) ) {
		return $source;
	}
	global $wp_filesystem;
	$desired = trailingslashit( $remote_source ) . dirname( plugin_basename( __FILE__ ) ) . '/';
	if ( $source === $desired || ! $wp_filesystem ) {
		return $source;
	}
	return $wp_filesystem->move( $source, $desired ) ? $desired : $source;
}

// Opt this plugin into automatic background updates on every site — no per-site
// toggle needed. Install once; thereafter each site auto-applies new GitHub
// releases on its own update cron. Remove this filter if you ever want manual control.
add_filter( 'auto_update_plugin', function ( $update, $item ) {
	if ( isset( $item->plugin ) && $item->plugin === plugin_basename( __FILE__ ) ) {
		return true;
	}
	return $update;
}, 10, 2 );

/* ----------------------------------------------------------------------------
 * Release signature verification — refuse to install any release whose .zip
 * does not verify against SITEBRIDGE_UPDATE_PUBKEY. Runs for BOTH manual and
 * background auto-updates (upgrader_pre_download fires for both). Fail-closed:
 * a missing/bad/unverifiable signature aborts the install and logs a notice.
 * -------------------------------------------------------------------------- */

// Find a release asset URL whose name ends with $suffix (e.g. '.zip', '.sig').
function sitebridge_release_asset( $release, $suffix ) {
	if ( empty( $release->assets ) || ! is_array( $release->assets ) ) {
		return '';
	}
	$suffix = strtolower( $suffix );
	foreach ( $release->assets as $asset ) {
		if ( ! empty( $asset->name ) && ! empty( $asset->browser_download_url )
			&& substr( strtolower( $asset->name ), -strlen( $suffix ) ) === $suffix ) {
			return $asset->browser_download_url;
		}
	}
	return '';
}

add_filter( 'upgrader_pre_download', 'sitebridge_verify_before_download', 10, 4 );
function sitebridge_verify_before_download( $reply, $package, $upgrader, $hook_extra = array() ) {
	// Only gate OUR plugin's update; leave everything else to WordPress.
	if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== plugin_basename( __FILE__ ) ) {
		return $reply;
	}

	$refuse = function ( $why ) {
		error_log( 'SiteBridge AI: update refused — ' . $why );
		set_transient( 'sitebridge_update_sig_error', $why, DAY_IN_SECONDS );
		return new WP_Error( 'sitebridge_bad_signature', 'SiteBridge AI update refused: ' . $why );
	};

	if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
		return $refuse( 'PHP libsodium is unavailable, so the release signature cannot be verified.' );
	}
	$pubkey = base64_decode( SITEBRIDGE_UPDATE_PUBKEY, true );
	if ( $pubkey === false || strlen( $pubkey ) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) {
		return $refuse( 'the embedded public key is invalid.' );
	}

	$release = sitebridge_get_latest_release();
	$sig_url = $release ? sitebridge_release_asset( $release, '.zip.sig' ) : '';
	if ( $sig_url === '' && $release ) {
		$sig_url = sitebridge_release_asset( $release, '.sig' );
	}
	if ( $sig_url === '' ) {
		return $refuse( 'no ".sig" signature asset was found on the release.' );
	}

	if ( ! function_exists( 'download_url' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	$zip = download_url( $package );
	if ( is_wp_error( $zip ) ) {
		return $refuse( 'could not download the release package: ' . $zip->get_error_message() );
	}

	$resp = wp_remote_get( $sig_url, array(
		'timeout' => 15,
		'headers' => array( 'User-Agent' => 'SiteBridge-AI' ),
	) );
	if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
		@unlink( $zip );
		return $refuse( 'could not download the signature asset.' );
	}
	$sig = base64_decode( trim( wp_remote_retrieve_body( $resp ) ), true );
	if ( $sig === false || strlen( $sig ) !== SODIUM_CRYPTO_SIGN_BYTES ) {
		@unlink( $zip );
		return $refuse( 'the signature asset is malformed.' );
	}

	$bytes = file_get_contents( $zip );
	if ( $bytes === false || ! sodium_crypto_sign_verify_detached( $sig, $bytes, $pubkey ) ) {
		@unlink( $zip );
		return $refuse( 'the release .zip did NOT verify against the embedded public key (tampered or unsigned).' );
	}

	// Verified — hand WordPress the local file; it skips its own download.
	delete_transient( 'sitebridge_update_sig_error' );
	return $zip;
}

// Surface a refused update to admins (the background updater is otherwise silent).
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'update_plugins' ) ) {
		return;
	}
	$why = get_transient( 'sitebridge_update_sig_error' );
	if ( $why ) {
		echo '<div class="notice notice-error"><p><strong>SiteBridge AI:</strong> an automatic update was refused — '
			. esc_html( $why )
			. ' The plugin was <strong>not</strong> updated. Confirm the release .zip is signed, then retry.</p></div>';
	}
} );

/* ============================================================================
 * SCHEMA MODULE  (JSON-LD per-post meta + per-post-type templates)
 * ========================================================================== */

add_action( 'init', function () {
	$post_types = get_post_types( array( 'show_in_rest' => true ), 'names' );
	foreach ( $post_types as $post_type ) {
		add_post_type_support( $post_type, 'custom-fields' );
		register_post_meta( $post_type, SITEBRIDGE_SCHEMA_META_KEY, array(
			'type'          => 'string',
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => function () { return current_user_can( 'edit_posts' ); },
		) );
	}
}, 999 );

function sitebridge_schema_clean_empty( $data ) {
	if ( is_array( $data ) ) {
		$is_assoc = array_keys( $data ) !== range( 0, count( $data ) - 1 );
		if ( $is_assoc ) {
			foreach ( $data as $k => $v ) {
				$cleaned = sitebridge_schema_clean_empty( $v );
				if ( $cleaned === '' || $cleaned === null || ( is_array( $cleaned ) && empty( $cleaned ) ) ) {
					unset( $data[ $k ] );
				} else {
					$data[ $k ] = $cleaned;
				}
			}
		} else {
			$out = array();
			foreach ( $data as $v ) {
				$cleaned = sitebridge_schema_clean_empty( $v );
				if ( $cleaned !== '' && $cleaned !== null && ! ( is_array( $cleaned ) && empty( $cleaned ) ) ) {
					$out[] = $cleaned;
				}
			}
			$data = $out;
		}
	}
	return $data;
}

function sitebridge_schema_render_template( $template, $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return '';
	}
	$featured_image_id  = get_post_thumbnail_id( $post_id );
	$featured_image_url = $featured_image_id ? wp_get_attachment_image_url( $featured_image_id, 'full' ) : '';
	$yoast_desc  = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
	$raw_excerpt = $post->post_excerpt ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );
	$description = $yoast_desc ? $yoast_desc : $raw_excerpt;
	$author      = get_user_by( 'id', $post->post_author );
	$author_name = $author ? $author->display_name : '';

	$values = array(
		'title'          => html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ),
		'url'            => get_permalink( $post_id ),
		'date_published' => mysql2date( 'c', $post->post_date_gmt, false ),
		'date_modified'  => mysql2date( 'c', $post->post_modified_gmt, false ),
		'excerpt'        => wp_strip_all_tags( $raw_excerpt ),
		'description'    => wp_strip_all_tags( $description ),
		'featured_image' => $featured_image_url ? $featured_image_url : '',
		'author_name'    => $author_name,
		'post_id'        => (string) $post_id,
	);
	foreach ( $values as $key => $val ) {
		$encoded  = json_encode( (string) $val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$escaped  = substr( $encoded, 1, -1 );
		$template = str_replace( '{{' . $key . '}}', $escaped, $template );
	}
	$decoded = json_decode( $template, true );
	if ( json_last_error() === JSON_ERROR_NONE ) {
		$template = json_encode( sitebridge_schema_clean_empty( $decoded ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
	return $template;
}

add_action( 'wp_head', function () {
	if ( ! is_singular() ) {
		return;
	}
	$post_id = get_queried_object_id();
	$jsonld  = get_post_meta( $post_id, SITEBRIDGE_SCHEMA_META_KEY, true );
	if ( empty( $jsonld ) ) {
		$template = get_option( SITEBRIDGE_SCHEMA_TEMPLATE_PREFIX . get_post_type( $post_id ), '' );
		if ( ! empty( $template ) ) {
			$jsonld = sitebridge_schema_render_template( $template, $post_id );
		}
	}
	if ( empty( $jsonld ) ) {
		return;
	}
	$safe = str_replace( '</', '<\/', $jsonld );
	echo "\n<script type=\"application/ld+json\" class=\"sitebridge-schema\">\n" . $safe . "\n</script>\n";
}, 20 );

add_action( 'rest_api_init', function () {
	register_rest_route( SITEBRIDGE_SCHEMA_NS, '/template/(?P<post_type>[a-zA-Z0-9_-]+)', array(
		array(
			'methods'             => 'GET',
			'callback'            => 'sitebridge_schema_rest_template_get',
			'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
		),
		array(
			'methods'             => 'POST',
			'callback'            => 'sitebridge_schema_rest_template_set',
			'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
			'args'                => array( 'template' => array( 'required' => true, 'type' => 'string' ) ),
		),
		array(
			'methods'             => 'DELETE',
			'callback'            => 'sitebridge_schema_rest_template_clear',
			'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
		),
	) );
} );

function sitebridge_schema_rest_template_get( $req ) {
	$post_type = $req['post_type'];
	$template  = get_option( SITEBRIDGE_SCHEMA_TEMPLATE_PREFIX . $post_type, '' );
	return array( 'post_type' => $post_type, 'hasTemplate' => ! empty( $template ), 'template' => $template );
}

function sitebridge_schema_rest_template_set( $req ) {
	$post_type = $req['post_type'];
	$template  = (string) $req->get_param( 'template' );
	json_decode( $template );
	if ( json_last_error() !== JSON_ERROR_NONE ) {
		return new WP_Error( 'invalid_json', 'Template is not valid JSON: ' . json_last_error_msg(), array( 'status' => 400 ) );
	}
	update_option( SITEBRIDGE_SCHEMA_TEMPLATE_PREFIX . $post_type, $template );
	return array( 'post_type' => $post_type, 'saved' => true, 'template' => $template );
}

function sitebridge_schema_rest_template_clear( $req ) {
	$post_type = $req['post_type'];
	delete_option( SITEBRIDGE_SCHEMA_TEMPLATE_PREFIX . $post_type );
	return array( 'post_type' => $post_type, 'cleared' => true );
}

/* ============================================================================
 * NAVIGATION MODULE  (read / update the ACF Options desktop mega-menu)
 * ========================================================================== */

add_action( 'rest_api_init', function () {
	register_rest_route( SITEBRIDGE_NS, '/nav', array(
		'methods'             => 'GET',
		'callback'            => 'sitebridge_nav_rest_get',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
	) );
	register_rest_route( SITEBRIDGE_NS, '/nav/replace-link', array(
		'methods'             => 'POST',
		'callback'            => 'sitebridge_nav_rest_replace_link',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'args'                => array(
			'old_url'   => array( 'required' => true,  'type' => 'string' ),
			'new_url'   => array( 'required' => true,  'type' => 'string' ),
			'new_title' => array( 'required' => false, 'type' => 'string' ),
		),
	) );
	register_rest_route( SITEBRIDGE_NS, '/nav/add-link', array(
		'methods'             => 'POST',
		'callback'            => 'sitebridge_nav_rest_add_link',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'args'                => array(
			'title'         => array( 'required' => true,  'type' => 'string' ),
			'url'           => array( 'required' => true,  'type' => 'string' ),
			'target'        => array( 'required' => false, 'type' => 'string' ),
			'link_style'    => array( 'required' => false, 'type' => 'string' ),
			'parent_title'  => array( 'required' => false, 'type' => 'string' ),
			'column_title'  => array( 'required' => false, 'type' => 'string' ),
			'column_index'  => array( 'required' => false, 'type' => 'integer' ),
			'create_column' => array( 'required' => false, 'type' => 'boolean' ),
			'position'      => array( 'required' => false, 'type' => 'integer' ),
		),
	) );
	register_rest_route( SITEBRIDGE_NS, '/nav/remove-link', array(
		'methods'             => 'POST',
		'callback'            => 'sitebridge_nav_rest_remove_link',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'args'                => array(
			'url'          => array( 'required' => true,  'type' => 'string' ),
			'parent_title' => array( 'required' => false, 'type' => 'string' ),
			'column_title' => array( 'required' => false, 'type' => 'string' ),
			'column_index' => array( 'required' => false, 'type' => 'integer' ),
		),
	) );
} );

function sitebridge_nav_rest_get() {
	if ( ! function_exists( 'get_field' ) ) {
		return new WP_Error( 'acf_missing', 'ACF is not active', array( 'status' => 500 ) );
	}
	$opt = apply_filters( 'sitebridge_nav_option_id', SITEBRIDGE_NAV_OPTION_ID );
	return array(
		'version'           => get_field( SITEBRIDGE_NAV_VERSION_FIELD, $opt ),
		'nav_field'         => SITEBRIDGE_NAV_FIELD,
		'nav'               => get_field( SITEBRIDGE_NAV_FIELD, $opt ),
		'_available_fields' => array_keys( (array) ( get_fields( $opt ) ?: array() ) ),
	);
}

function sitebridge_nav_rest_replace_link( WP_REST_Request $req ) {
	if ( ! function_exists( 'get_field' ) ) {
		return new WP_Error( 'acf_missing', 'ACF is not active', array( 'status' => 500 ) );
	}
	$opt       = apply_filters( 'sitebridge_nav_option_id', SITEBRIDGE_NAV_OPTION_ID );
	$old       = trim( (string) $req['old_url'] );
	$new       = trim( (string) $req['new_url'] );
	$new_title = ( $req['new_title'] !== null ) ? (string) $req['new_title'] : null;
	if ( $old === '' || $new === '' ) {
		return new WP_Error( 'bad_input', 'old_url and new_url are required', array( 'status' => 400 ) );
	}
	$nav = get_field( SITEBRIDGE_NAV_FIELD, $opt );
	if ( ! is_array( $nav ) ) {
		return new WP_Error( 'no_nav', 'No data for field "' . SITEBRIDGE_NAV_FIELD . '" — check GET ' . SITEBRIDGE_NS . '/nav _available_fields', array( 'status' => 404 ) );
	}
	$count = 0;
	$norm  = function ( $u ) { return rtrim( (string) $u, '/' ); };
	$walk  = function ( &$node ) use ( &$walk, $old, $new, $new_title, &$count, $norm ) {
		if ( ! is_array( $node ) ) {
			return;
		}
		if ( isset( $node['url'] ) && is_string( $node['url'] ) ) {
			if ( $norm( $node['url'] ) === $norm( $old ) ) {
				$node['url'] = $new;
				if ( $new_title !== null && $new_title !== '' ) {
					$node['title'] = $new_title;
				}
				$count++;
			}
			return;
		}
		foreach ( $node as &$child ) {
			if ( is_array( $child ) ) {
				$walk( $child );
			}
		}
		unset( $child );
	};
	$walk( $nav );
	if ( $count > 0 ) {
		update_field( SITEBRIDGE_NAV_FIELD, $nav, $opt );
	}
	return array( 'replaced' => $count, 'old_url' => $old, 'new_url' => $new );
}

/** Trailing-slash tolerant URL comparison (same tolerance as replace-link). */
function sitebridge_nav_url_eq( $a, $b ) {
	return rtrim( (string) $a, '/' ) === rtrim( (string) $b, '/' );
}

/** Human-readable `index="title"` column listing for disambiguation errors. */
function sitebridge_nav_columns_listing( $sub_items ) {
	$listing = array();
	if ( is_array( $sub_items ) ) {
		foreach ( $sub_items as $i => $col ) {
			$title = isset( $col['sub_item_title'] ) ? (string) $col['sub_item_title'] : '';
			$listing[] = $i . '="' . $title . '"';
		}
	}
	return implode( ' | ', $listing );
}

/**
 * Add a link to the desktop mega-menu (Culligan v4 theme shape:
 * nav_items[] -> nav_item_link / nav_item_sub_items[] (columns) -> sub_item_links[]).
 *
 * - No parent_title           => append/insert a new TOP-LEVEL nav item.
 * - parent_title + column     => insert into that column's sub_item_links.
 * - parent_title, one column  => column_title optional (unambiguous).
 * - column_index (0-based)    => targets a column directly; wins over column_title.
 * - column_title (ambiguous)  => 409 listing the columns instead of taking the first.
 * - create_column=true        => add the named column to the parent first.
 */
function sitebridge_nav_rest_add_link( WP_REST_Request $req ) {
	if ( ! function_exists( 'get_field' ) ) {
		return new WP_Error( 'acf_missing', 'ACF is not active', array( 'status' => 500 ) );
	}
	$opt = apply_filters( 'sitebridge_nav_option_id', SITEBRIDGE_NAV_OPTION_ID );
	$nav = get_field( SITEBRIDGE_NAV_FIELD, $opt );
	if ( ! is_array( $nav ) || empty( $nav['nav_items'] ) || ! is_array( $nav['nav_items'] ) ) {
		return new WP_Error( 'no_nav', 'No data for field "' . SITEBRIDGE_NAV_FIELD . '" — check GET ' . SITEBRIDGE_NS . '/nav _available_fields', array( 'status' => 404 ) );
	}

	$title = trim( sanitize_text_field( (string) $req['title'] ) );
	$url   = trim( (string) $req['url'] );
	if ( $title === '' || $url === '' ) {
		return new WP_Error( 'bad_input', 'title and url are required', array( 'status' => 400 ) );
	}
	$link = array(
		'title'  => $title,
		'url'    => ( $url === '#' ) ? '#' : esc_url_raw( $url ),
		'target' => sanitize_text_field( (string) ( $req['target'] !== null ? $req['target'] : '' ) ),
	);
	$link_style   = ( $req['link_style'] !== null && $req['link_style'] !== '' ) ? sanitize_text_field( (string) $req['link_style'] ) : 'bold-caret';
	$parent_title = ( $req['parent_title'] !== null ) ? trim( (string) $req['parent_title'] ) : '';

	// ---- Case A: new top-level nav item -------------------------------------
	if ( $parent_title === '' ) {
		foreach ( $nav['nav_items'] as $item ) {
			if ( isset( $item['nav_item_link']['title'] ) && strcasecmp( trim( (string) $item['nav_item_link']['title'] ), $title ) === 0 ) {
				return new WP_Error( 'duplicate', 'A top-level nav item with that title already exists', array( 'status' => 409 ) );
			}
		}
		$new_item = array(
			'nav_item_link'           => $link,
			'nav_item_link_css_class' => '',
			'nav_item_sub_items'      => false,
		);
		$pos = ( $req['position'] !== null )
			? max( 0, min( (int) $req['position'], count( $nav['nav_items'] ) ) )
			: count( $nav['nav_items'] );
		array_splice( $nav['nav_items'], $pos, 0, array( $new_item ) );
		update_field( SITEBRIDGE_NAV_FIELD, $nav, $opt );
		return array( 'added' => 'top_level', 'title' => $title, 'url' => $link['url'], 'position' => $pos );
	}

	// ---- Case B: link inside a parent item's mega-menu column ---------------
	foreach ( $nav['nav_items'] as &$item ) {
		if ( ! isset( $item['nav_item_link']['title'] ) || strcasecmp( trim( (string) $item['nav_item_link']['title'] ), $parent_title ) !== 0 ) {
			continue;
		}
		if ( ! is_array( $item['nav_item_sub_items'] ) ) {
			$item['nav_item_sub_items'] = array();
		}

		$column_title = ( $req['column_title'] !== null ) ? trim( (string) $req['column_title'] ) : null;
		$column_index = ( $req['column_index'] !== null ) ? (int) $req['column_index'] : null;
		$col_index    = null;
		if ( $column_index !== null ) {
			// Direct 0-based targeting — wins over column_title; errors if out of range.
			$col_count = count( $item['nav_item_sub_items'] );
			if ( $column_index < 0 || $column_index >= $col_count ) {
				return new WP_Error(
					'column_index_out_of_range',
					sprintf(
						'column_index %d is out of range for "%s" — it has %d column(s)%s. Columns: [%s]',
						$column_index,
						trim( (string) $item['nav_item_link']['title'] ),
						$col_count,
						$col_count > 0 ? ' (valid 0–' . ( $col_count - 1 ) . ')' : '',
						sitebridge_nav_columns_listing( $item['nav_item_sub_items'] )
					),
					array( 'status' => 400 )
				);
			}
			$col_index = $column_index;
		} elseif ( $column_title !== null ) {
			$matches = array();
			foreach ( $item['nav_item_sub_items'] as $i => $col ) {
				if ( isset( $col['sub_item_title'] ) && strcasecmp( trim( (string) $col['sub_item_title'] ), $column_title ) === 0 ) {
					$matches[] = $i;
				}
			}
			if ( count( $matches ) > 1 ) {
				return new WP_Error(
					'column_ambiguous',
					'column_title "' . $column_title . '" matches ' . count( $matches ) . ' columns — pass column_index to target one directly. Columns: [' . sitebridge_nav_columns_listing( $item['nav_item_sub_items'] ) . ']',
					array( 'status' => 409 )
				);
			}
			if ( count( $matches ) === 1 ) {
				$col_index = $matches[0];
			}
		} elseif ( count( $item['nav_item_sub_items'] ) === 1 ) {
			$col_index = 0; // only one column — unambiguous
		}

		if ( $col_index === null ) {
			if ( empty( $req['create_column'] ) ) {
				$names = array();
				foreach ( $item['nav_item_sub_items'] as $col ) {
					$names[] = isset( $col['sub_item_title'] ) ? (string) $col['sub_item_title'] : '';
				}
				return new WP_Error(
					'column_not_found',
					'Column not found or ambiguous. Pass column_title matching one of: [' . implode( ' | ', $names ) . '] — or create_column=true to add it.',
					array( 'status' => 400 )
				);
			}
			$item['nav_item_sub_items'][] = array(
				'sub_item_title' => sanitize_text_field( (string) ( $column_title !== null ? $column_title : '' ) ),
				'sub_item_links' => array(),
			);
			$col_index = count( $item['nav_item_sub_items'] ) - 1;
		}

		if ( ! isset( $item['nav_item_sub_items'][ $col_index ]['sub_item_links'] ) || ! is_array( $item['nav_item_sub_items'][ $col_index ]['sub_item_links'] ) ) {
			$item['nav_item_sub_items'][ $col_index ]['sub_item_links'] = array();
		}
		$links = &$item['nav_item_sub_items'][ $col_index ]['sub_item_links'];

		foreach ( $links as $existing ) {
			if ( isset( $existing['link']['url'] ) && sitebridge_nav_url_eq( $existing['link']['url'], $link['url'] ) ) {
				return new WP_Error( 'duplicate', 'That URL already exists in this column', array( 'status' => 409 ) );
			}
		}

		$pos = ( $req['position'] !== null )
			? max( 0, min( (int) $req['position'], count( $links ) ) )
			: count( $links );
		array_splice( $links, $pos, 0, array( array( 'link' => $link, 'link_style' => $link_style ) ) );
		unset( $links );

		update_field( SITEBRIDGE_NAV_FIELD, $nav, $opt );
		return array(
			'added'    => 'sub_link',
			'parent'   => trim( (string) $item['nav_item_link']['title'] ),
			'column'   => (string) $item['nav_item_sub_items'][ $col_index ]['sub_item_title'],
			'title'    => $title,
			'url'      => $link['url'],
			'position' => $pos,
		);
	}
	unset( $item );

	return new WP_Error( 'parent_not_found', 'No top-level nav item matched parent_title "' . $parent_title . '"', array( 'status' => 404 ) );
}

/**
 * Remove mega-menu links whose URL matches (trailing-slash tolerant), optionally
 * scoped to one top-level item via parent_title and/or to a single column via
 * column_index (0-based, wins over column_title) or column_title (409 on an
 * ambiguous match). With no column scoping, every column is searched. Only removes
 * links inside columns (sub_item_links) — never removes top-level nav items.
 * Columns left empty are pruned; a parent left with zero columns gets sub_items=false.
 */
function sitebridge_nav_rest_remove_link( WP_REST_Request $req ) {
	if ( ! function_exists( 'get_field' ) ) {
		return new WP_Error( 'acf_missing', 'ACF is not active', array( 'status' => 500 ) );
	}
	$opt = apply_filters( 'sitebridge_nav_option_id', SITEBRIDGE_NAV_OPTION_ID );
	$nav = get_field( SITEBRIDGE_NAV_FIELD, $opt );
	if ( ! is_array( $nav ) || empty( $nav['nav_items'] ) || ! is_array( $nav['nav_items'] ) ) {
		return new WP_Error( 'no_nav', 'No data for field "' . SITEBRIDGE_NAV_FIELD . '" — check GET ' . SITEBRIDGE_NS . '/nav _available_fields', array( 'status' => 404 ) );
	}

	$url = trim( (string) $req['url'] );
	if ( $url === '' ) {
		return new WP_Error( 'bad_input', 'url is required', array( 'status' => 400 ) );
	}
	$parent_title = ( $req['parent_title'] !== null ) ? trim( (string) $req['parent_title'] ) : '';
	$column_title = ( $req['column_title'] !== null ) ? trim( (string) $req['column_title'] ) : null;
	$column_index = ( $req['column_index'] !== null ) ? (int) $req['column_index'] : null;

	$removed = 0;
	foreach ( $nav['nav_items'] as &$item ) {
		if ( $parent_title !== '' && ( ! isset( $item['nav_item_link']['title'] ) || strcasecmp( trim( (string) $item['nav_item_link']['title'] ), $parent_title ) !== 0 ) ) {
			continue;
		}
		if ( ! is_array( $item['nav_item_sub_items'] ) ) {
			continue;
		}

		// Optionally scope the removal to one column. column_index (0-based) targets a
		// column directly and wins over column_title; column_title errors on an ambiguous
		// (multi-column) match rather than silently taking the first. Neither set => all
		// columns, preserving the original behaviour.
		$only_cols = null;
		if ( $column_index !== null ) {
			$col_count = count( $item['nav_item_sub_items'] );
			if ( $column_index < 0 || $column_index >= $col_count ) {
				return new WP_Error(
					'column_index_out_of_range',
					sprintf(
						'column_index %d is out of range for "%s" — it has %d column(s)%s. Columns: [%s]',
						$column_index,
						isset( $item['nav_item_link']['title'] ) ? trim( (string) $item['nav_item_link']['title'] ) : '',
						$col_count,
						$col_count > 0 ? ' (valid 0–' . ( $col_count - 1 ) . ')' : '',
						sitebridge_nav_columns_listing( $item['nav_item_sub_items'] )
					),
					array( 'status' => 400 )
				);
			}
			$only_cols = array( $column_index );
		} elseif ( $column_title !== null ) {
			$matches = array();
			foreach ( $item['nav_item_sub_items'] as $i => $col ) {
				if ( isset( $col['sub_item_title'] ) && strcasecmp( trim( (string) $col['sub_item_title'] ), $column_title ) === 0 ) {
					$matches[] = $i;
				}
			}
			if ( count( $matches ) > 1 ) {
				return new WP_Error(
					'column_ambiguous',
					'column_title "' . $column_title . '" matches ' . count( $matches ) . ' columns — pass column_index to target one. Columns: [' . sitebridge_nav_columns_listing( $item['nav_item_sub_items'] ) . ']',
					array( 'status' => 409 )
				);
			}
			$only_cols = $matches; // 0 matches => remove nothing; 1 => that column
		}

		foreach ( $item['nav_item_sub_items'] as $ci => &$col ) {
			if ( $only_cols !== null && ! in_array( $ci, $only_cols, true ) ) {
				continue;
			}
			if ( empty( $col['sub_item_links'] ) || ! is_array( $col['sub_item_links'] ) ) {
				continue;
			}
			$before = count( $col['sub_item_links'] );
			$col['sub_item_links'] = array_values( array_filter( $col['sub_item_links'], function ( $l ) use ( $url ) {
				return ! ( isset( $l['link']['url'] ) && sitebridge_nav_url_eq( $l['link']['url'], $url ) );
			} ) );
			$removed += $before - count( $col['sub_item_links'] );
		}
		unset( $col );
		// Prune columns emptied by the removal; false when no columns remain.
		$item['nav_item_sub_items'] = array_values( array_filter( $item['nav_item_sub_items'], function ( $c ) {
			return ! empty( $c['sub_item_links'] );
		} ) );
		if ( empty( $item['nav_item_sub_items'] ) ) {
			$item['nav_item_sub_items'] = false;
		}
	}
	unset( $item );

	if ( $removed > 0 ) {
		update_field( SITEBRIDGE_NAV_FIELD, $nav, $opt );
	}
	return array( 'removed' => $removed, 'url' => $url );
}

/* ============================================================================
 * REDIRECTS MODULE  (301/302 store + template_redirect hook + REST + admin UI)
 * ========================================================================== */

function sitebridge_redirects_norm_path( $url ) {
	$path = parse_url( (string) $url, PHP_URL_PATH );
	if ( $path === null || $path === false ) {
		$path = (string) $url;
	}
	$path = rtrim( '/' . ltrim( $path, '/' ), '/' );
	return strtolower( $path === '' ? '/' : $path );
}

/** If a source uses the "regex:" prefix, return the bare pattern; else null. */
function sitebridge_redirects_regex_pattern( $source ) {
	$source = (string) $source;
	if ( stripos( $source, 'regex:' ) === 0 ) {
		return substr( $source, 6 );
	}
	return null;
}

/** True when a regex pattern compiles. */
function sitebridge_redirects_regex_valid( $pattern ) {
	$d = chr( 1 );
	return @preg_match( $d . str_replace( $d, '', (string) $pattern ) . $d, '' ) !== false;
}

/** Dedupe key for an entry: normalized path for exact rules, verbatim pattern for regex rules. */
function sitebridge_redirects_key( $source ) {
	$pattern = sitebridge_redirects_regex_pattern( $source );
	if ( $pattern !== null ) {
		return 'regex:' . $pattern;
	}
	return sitebridge_redirects_norm_path( $source );
}

function sitebridge_redirects_all() {
	$r = get_option( SITEBRIDGE_REDIRECTS_OPTION, array() );
	return is_array( $r ) ? $r : array();
}

/** Shared add/update logic (used by REST and the admin page). Returns the entry. */
function sitebridge_redirects_save( $source, $target, $type = 301 ) {
	$type = in_array( (int) $type, array( 301, 302, 307, 308 ), true ) ? (int) $type : 301;
	$redirects = sitebridge_redirects_all();
	$pattern = sitebridge_redirects_regex_pattern( $source );
	if ( $pattern !== null && ! sitebridge_redirects_regex_valid( $pattern ) ) {
		return array( 'error' => 'invalid_regex', 'entry' => null, 'updated' => false, 'count' => count( $redirects ) );
	}
	$key   = sitebridge_redirects_key( $source );
	$entry = array( 'source' => $source, 'target' => $target, 'type' => $type, 'created' => current_time( 'mysql' ) );
	if ( $pattern !== null ) {
		$entry['regex'] = true;
	}
	$replaced = false;
	foreach ( $redirects as $i => $r ) {
		if ( sitebridge_redirects_key( isset( $r['source'] ) ? $r['source'] : '' ) === $key ) {
			$redirects[ $i ] = $entry;
			$replaced = true;
			break;
		}
	}
	if ( ! $replaced ) {
		$redirects[] = $entry;
	}
	update_option( SITEBRIDGE_REDIRECTS_OPTION, array_values( $redirects ) );
	return array( 'entry' => $entry, 'updated' => $replaced, 'count' => count( $redirects ) );
}

/** Shared delete logic. Returns number removed. */
function sitebridge_redirects_remove( $source ) {
	$redirects = sitebridge_redirects_all();
	$key    = sitebridge_redirects_key( $source );
	$before = count( $redirects );
	$redirects = array_values( array_filter( $redirects, function ( $r ) use ( $key ) {
		return sitebridge_redirects_key( isset( $r['source'] ) ? $r['source'] : '' ) !== $key;
	} ) );
	update_option( SITEBRIDGE_REDIRECTS_OPTION, $redirects );
	return $before - count( $redirects );
}

// Fire matching redirects on the front end, early.
add_action( 'template_redirect', function () {
	if ( is_admin() ) {
		return;
	}
	$redirects = sitebridge_redirects_all();
	if ( empty( $redirects ) ) {
		return;
	}
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	$req      = sitebridge_redirects_norm_path( $request_uri );
	$raw_path = parse_url( $request_uri, PHP_URL_PATH );
	if ( $raw_path === null || $raw_path === false || $raw_path === '' ) {
		$raw_path = '/';
	}

	// Pass 1: exact-path rules (fast, trailing-slash/case tolerant).
	foreach ( $redirects as $r ) {
		if ( empty( $r['source'] ) || empty( $r['target'] ) ) {
			continue;
		}
		if ( sitebridge_redirects_regex_pattern( $r['source'] ) !== null ) {
			continue; // regex rules run in pass 2
		}
		if ( sitebridge_redirects_norm_path( $r['source'] ) === $req ) {
			$type = in_array( (int) ( isset( $r['type'] ) ? $r['type'] : 301 ), array( 301, 302, 307, 308 ), true ) ? (int) $r['type'] : 301;
			wp_redirect( (string) $r['target'], $type );
			exit;
		}
	}

	// Pass 2: regex rules ("regex:" prefixed sources), matched against the raw
	// request path in stored order. $1–$9 / ${1}–${9} in the target are replaced
	// with capture groups.
	$delim = chr( 1 );
	foreach ( $redirects as $r ) {
		if ( empty( $r['source'] ) || empty( $r['target'] ) ) {
			continue;
		}
		$pattern = sitebridge_redirects_regex_pattern( $r['source'] );
		if ( $pattern === null ) {
			continue;
		}
		$m = array();
		if ( @preg_match( $delim . str_replace( $delim, '', $pattern ) . $delim, $raw_path, $m ) ) {
			$target = (string) $r['target'];
			for ( $i = min( count( $m ) - 1, 9 ); $i >= 1; $i-- ) {
				$target = str_replace( array( '${' . $i . '}', '$' . $i ), $m[ $i ], $target );
			}
			$type = in_array( (int) ( isset( $r['type'] ) ? $r['type'] : 301 ), array( 301, 302, 307, 308 ), true ) ? (int) $r['type'] : 301;
			wp_redirect( $target, $type );
			exit;
		}
	}
}, 1 );

add_action( 'rest_api_init', function () {
	$perm = function () { return current_user_can( 'manage_options' ); };
	register_rest_route( SITEBRIDGE_NS, '/redirects', array(
		array( 'methods' => 'GET',    'callback' => 'sitebridge_redirects_rest_list',   'permission_callback' => $perm ),
		array(
			'methods'             => 'POST',
			'callback'            => 'sitebridge_redirects_rest_add',
			'permission_callback' => $perm,
			'args'                => array(
				'source' => array( 'required' => true,  'type' => 'string' ),
				'target' => array( 'required' => true,  'type' => 'string' ),
				'type'   => array( 'required' => false, 'type' => 'integer' ),
			),
		),
		array(
			'methods'             => 'DELETE',
			'callback'            => 'sitebridge_redirects_rest_delete',
			'permission_callback' => $perm,
			'args'                => array( 'source' => array( 'required' => true, 'type' => 'string' ) ),
		),
	) );
	register_rest_route( SITEBRIDGE_NS, '/redirects/import', array(
		'methods'             => 'POST',
		'callback'            => 'sitebridge_redirects_rest_import',
		'permission_callback' => $perm,
		'args'                => array(
			'redirects'   => array( 'required' => false ),
			'csv'         => array( 'required' => false, 'type' => 'string' ),
			'replace_all' => array( 'required' => false, 'type' => 'boolean' ),
		),
	) );
} );

function sitebridge_redirects_rest_list() {
	$redirects = sitebridge_redirects_all();
	return array( 'count' => count( $redirects ), 'redirects' => array_values( $redirects ) );
}

function sitebridge_redirects_rest_add( WP_REST_Request $req ) {
	$source = trim( (string) $req['source'] );
	$target = trim( (string) $req['target'] );
	if ( $source === '' || $target === '' ) {
		return new WP_Error( 'bad_input', 'source and target are required', array( 'status' => 400 ) );
	}
	$res = sitebridge_redirects_save( $source, $target, $req['type'] !== null ? $req['type'] : 301 );
	if ( ! empty( $res['error'] ) ) {
		return new WP_Error( 'invalid_regex', 'The "regex:" source pattern does not compile', array( 'status' => 400 ) );
	}
	return array( 'saved' => true, 'updated' => $res['updated'], 'redirect' => $res['entry'], 'count' => $res['count'] );
}

function sitebridge_redirects_rest_delete( WP_REST_Request $req ) {
	$source = trim( (string) $req['source'] );
	if ( $source === '' ) {
		return new WP_Error( 'bad_input', 'source is required', array( 'status' => 400 ) );
	}
	$deleted = sitebridge_redirects_remove( $source );
	return array( 'deleted' => $deleted, 'source' => $source, 'count' => count( sitebridge_redirects_all() ) );
}

/** Bulk import: one read + one write. Merges by source path; returns counts. */
function sitebridge_redirects_import( $entries ) {
	$existing = sitebridge_redirects_all();
	$index = array();
	foreach ( $existing as $i => $r ) {
		$index[ sitebridge_redirects_key( isset( $r['source'] ) ? $r['source'] : '' ) ] = $i;
	}
	$added = 0; $updated = 0; $skipped = 0;
	foreach ( $entries as $e ) {
		$source = isset( $e['source'] ) ? trim( (string) $e['source'] ) : '';
		$target = isset( $e['target'] ) ? trim( (string) $e['target'] ) : '';
		$type   = isset( $e['type'] ) ? (int) $e['type'] : 301;
		if ( ! in_array( $type, array( 301, 302, 307, 308 ), true ) ) {
			$type = 301;
		}
		if ( $source === '' || $target === '' ) {
			$skipped++;
			continue;
		}
		$pattern = sitebridge_redirects_regex_pattern( $source );
		if ( $pattern !== null && ! sitebridge_redirects_regex_valid( $pattern ) ) {
			$skipped++;
			continue;
		}
		$key   = sitebridge_redirects_key( $source );
		$entry = array( 'source' => $source, 'target' => $target, 'type' => $type, 'created' => current_time( 'mysql' ) );
		if ( $pattern !== null ) {
			$entry['regex'] = true;
		}
		if ( isset( $index[ $key ] ) ) {
			$existing[ $index[ $key ] ] = $entry;
			$updated++;
		} else {
			$existing[] = $entry;
			$index[ $key ] = count( $existing ) - 1;
			$added++;
		}
	}
	update_option( SITEBRIDGE_REDIRECTS_OPTION, array_values( $existing ) );
	return array( 'added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'total' => count( $existing ) );
}

/** Parse CSV text (source,target,type per line; optional header row) into entries. */
function sitebridge_redirects_parse_csv( $csv ) {
	$entries = array();
	$lines   = preg_split( '/\r\n|\r|\n/', (string) $csv );
	foreach ( $lines as $idx => $line ) {
		$line = trim( $line );
		if ( $line === '' ) {
			continue;
		}
		$cols = str_getcsv( $line );
		if ( $idx === 0 && isset( $cols[0] ) && strtolower( trim( $cols[0] ) ) === 'source' ) {
			continue; // header row
		}
		$source = isset( $cols[0] ) ? trim( $cols[0] ) : '';
		$target = isset( $cols[1] ) ? trim( $cols[1] ) : '';
		$type   = isset( $cols[2] ) ? (int) trim( $cols[2] ) : 301;
		if ( $source === '' || $target === '' ) {
			continue;
		}
		$entries[] = array( 'source' => $source, 'target' => $target, 'type' => $type );
	}
	return $entries;
}

function sitebridge_redirects_rest_import( WP_REST_Request $req ) {
	$entries = $req['redirects'];
	if ( ! is_array( $entries ) ) {
		$csv     = $req['csv'];
		$entries = ( is_string( $csv ) && $csv !== '' ) ? sitebridge_redirects_parse_csv( $csv ) : array();
	}
	if ( empty( $entries ) ) {
		return new WP_Error( 'bad_input', 'Provide a non-empty "redirects" array or a "csv" string', array( 'status' => 400 ) );
	}
	if ( ! empty( $req['replace_all'] ) ) {
		update_option( SITEBRIDGE_REDIRECTS_OPTION, array() );
	}
	$res = sitebridge_redirects_import( $entries );
	return array_merge( array( 'imported' => true ), $res );
}

/* ---- Admin page: Redirects dashboard (so humans can manage them too) ------- */

add_action( 'admin_menu', function () {
	add_menu_page(
		'SiteBridge AI',
		'SiteBridge AI',
		'manage_options',
		'sitebridge-ai',
		'sitebridge_admin_redirects_page',
		'dashicons-randomize',
		80
	);
} );

function sitebridge_admin_redirects_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$notice = '';

	if ( isset( $_POST['sitebridge_action'] ) && check_admin_referer( 'sitebridge_redirects' ) ) {
		if ( $_POST['sitebridge_action'] === 'add' ) {
			$source = isset( $_POST['source'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['source'] ) ) ) : '';
			$target = isset( $_POST['target'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['target'] ) ) ) : '';
			$type   = isset( $_POST['type'] ) ? (int) $_POST['type'] : 301;
			if ( $source !== '' && $target !== '' ) {
				$res    = sitebridge_redirects_save( $source, $target, $type );
				$notice = $res['updated'] ? 'Redirect updated.' : 'Redirect added.';
			} else {
				$notice = 'Source and target are both required.';
			}
		} elseif ( $_POST['sitebridge_action'] === 'delete' && isset( $_POST['source'] ) ) {
			$src = trim( sanitize_text_field( wp_unslash( $_POST['source'] ) ) );
			sitebridge_redirects_remove( $src );
			$notice = 'Redirect deleted.';
		} elseif ( $_POST['sitebridge_action'] === 'import' ) {
			$csv = '';
			if ( ! empty( $_FILES['csv_file']['tmp_name'] ) && is_uploaded_file( $_FILES['csv_file']['tmp_name'] ) ) {
				$csv = file_get_contents( $_FILES['csv_file']['tmp_name'] );
			} elseif ( ! empty( $_POST['csv_text'] ) ) {
				$csv = wp_unslash( $_POST['csv_text'] );
			}
			$entries = ( $csv !== '' ) ? sitebridge_redirects_parse_csv( $csv ) : array();
			if ( ! empty( $entries ) ) {
				if ( ! empty( $_POST['replace_all'] ) ) {
					update_option( SITEBRIDGE_REDIRECTS_OPTION, array() );
				}
				$res    = sitebridge_redirects_import( $entries );
				$notice = sprintf( 'Imported: %d added, %d updated, %d skipped (%d total).', $res['added'], $res['updated'], $res['skipped'], $res['total'] );
			} else {
				$notice = 'No valid rows found — upload a CSV file or paste rows (source,target,type).';
			}
		}
	}

	$redirects = sitebridge_redirects_all();
	?>
	<div class="wrap">
		<h1>SiteBridge AI — Redirects</h1>
		<?php if ( $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<h2>Add / update a redirect</h2>
		<form method="post">
			<?php wp_nonce_field( 'sitebridge_redirects' ); ?>
			<input type="hidden" name="sitebridge_action" value="add" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sb_source">From (path)</label></th>
					<td><input name="source" id="sb_source" type="text" class="regular-text" placeholder="/old-page/" required />
					<p class="description">Exact path, or a regex rule: prefix with <code>regex:</code> (e.g. <code>regex:^/blog/[0-9]{4}/[0-9]{2}/[0-9]{2}/(.+)$</code>). Use <code>$1</code>&hellip;<code>$9</code> in the target for capture groups.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="sb_target">To (URL or path)</label></th>
					<td><input name="target" id="sb_target" type="text" class="regular-text" placeholder="/new-page/" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="sb_type">Type</label></th>
					<td>
						<select name="type" id="sb_type">
							<option value="301">301 (permanent)</option>
							<option value="302">302 (temporary)</option>
							<option value="307">307</option>
							<option value="308">308</option>
						</select>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save redirect' ); ?>
		</form>

		<h2>Bulk import (CSV)</h2>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'sitebridge_redirects' ); ?>
			<input type="hidden" name="sitebridge_action" value="import" />
			<p class="description">Upload a <code>.csv</code> file or paste rows below — <code>source,target,type</code> per line (type optional, defaults to 301; a header row is fine).</p>
			<p><input type="file" name="csv_file" accept=".csv,text/csv" /></p>
			<p><textarea name="csv_text" rows="6" class="large-text code" placeholder="/old-page/,/new-page/,301&#10;/legacy-url/,https://example.com/new/,301"></textarea></p>
			<p><label><input type="checkbox" name="replace_all" value="1" /> Replace all existing redirects first (wipe before import)</label></p>
			<?php submit_button( 'Import CSV', 'secondary' ); ?>
		</form>

		<h2>Current redirects (<?php echo count( $redirects ); ?>)</h2>
		<table class="widefat striped">
			<thead><tr><th>From</th><th>To</th><th>Type</th><th>Added</th><th></th></tr></thead>
			<tbody>
			<?php if ( empty( $redirects ) ) : ?>
				<tr><td colspan="5"><em>No redirects yet.</em></td></tr>
			<?php else : ?>
				<?php foreach ( $redirects as $r ) : ?>
					<tr>
						<td><code><?php echo esc_html( $r['source'] ); ?></code></td>
						<td><code><?php echo esc_html( $r['target'] ); ?></code></td>
						<td><?php echo esc_html( isset( $r['type'] ) ? $r['type'] : 301 ); ?></td>
						<td><?php echo esc_html( isset( $r['created'] ) ? $r['created'] : '' ); ?></td>
						<td>
							<form method="post" onsubmit="return confirm('Delete this redirect?');">
								<?php wp_nonce_field( 'sitebridge_redirects' ); ?>
								<input type="hidden" name="sitebridge_action" value="delete" />
								<input type="hidden" name="source" value="<?php echo esc_attr( $r['source'] ); ?>" />
								<button type="submit" class="button button-small button-link-delete">Delete</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<p class="description">These are also managed automatically by the AI connector (e.g. after a slug rename). Both edit the same list.</p>
	</div>
	<?php
}
