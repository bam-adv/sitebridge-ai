<?php
/**
 * Plugin Name: SiteBridge AI
 * Plugin URI:  https://github.com/bam-adv/sitebridge-ai
 * Update URI:  https://github.com/bam-adv/sitebridge-ai
 * Description: Bridges AI tooling (the wp-mcp-hosted connector) to any WordPress site — JSON-LD schema, desktop ACF navigation, and managed redirects, all over REST. Self-updates from GitHub releases.
 * Version:     1.18.0
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

define( 'SITEBRIDGE_VERSION', '1.18.0' );

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
const SITEBRIDGE_UPDATE_PUBKEY = 'YN/8ey9X7JLyGQEp6binEwL3tQrJTLTYp+dxjjsuZOE=';

// --- Schema storage (kept as legacy keys for data compatibility) -------------
const SITEBRIDGE_SCHEMA_META_KEY        = '_bam_schema_jsonld';
const SITEBRIDGE_SCHEMA_TEMPLATE_PREFIX = 'bam_schema_template_';

// --- Navigation (Culligan v4 theme profile) ----------------------------------
if ( ! defined( 'SITEBRIDGE_NAV_OPTION_ID' ) )     define( 'SITEBRIDGE_NAV_OPTION_ID', 'option' );
if ( ! defined( 'SITEBRIDGE_NAV_FIELD' ) )         define( 'SITEBRIDGE_NAV_FIELD', 'main_nav_settings_version_2' );
if ( ! defined( 'SITEBRIDGE_NAV_VERSION_FIELD' ) ) define( 'SITEBRIDGE_NAV_VERSION_FIELD', 'main_nav_version' );

// --- Redirects (kept as legacy option key for data compatibility) ------------
if ( ! defined( 'SITEBRIDGE_REDIRECTS_OPTION' ) )  define( 'SITEBRIDGE_REDIRECTS_OPTION', 'bam_redirects' );

// --- Hero exclusivity (Culligan v4 theme profile) -----------------------------
// A page renders its hero EITHER from an acf/hero-banner block in post_content
// OR from the page-level meta-box group toggled by show_hero_banner. Both at
// once double-renders the hero, so /acf-fields refuses to set the toggle true
// on a page that already carries the block. Set either constant to '' in
// wp-config to disable the guard on themes without this pattern.
if ( ! defined( 'SITEBRIDGE_HERO_BLOCK' ) )  define( 'SITEBRIDGE_HERO_BLOCK', 'acf/hero-banner' );
if ( ! defined( 'SITEBRIDGE_HERO_TOGGLE' ) ) define( 'SITEBRIDGE_HERO_TOGGLE', 'show_hero_banner' );

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
			'old_url'      => array( 'required' => true,  'type' => 'string' ),
			'new_url'      => array( 'required' => false, 'type' => 'string' ),
			'new_title'    => array( 'required' => false, 'type' => 'string' ),
			'parent_title' => array( 'required' => false, 'type' => 'string' ),
			'column_title' => array( 'required' => false, 'type' => 'string' ),
			'column_index' => array( 'required' => false, 'type' => 'integer' ),
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
			'force_new_column' => array( 'required' => false, 'type' => 'boolean' ),
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
	register_rest_route( SITEBRIDGE_NS, '/nav/remove-item', array(
		'methods'             => 'POST',
		'callback'            => 'sitebridge_nav_rest_remove_item',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'args'                => array(
			'url'     => array( 'required' => false, 'type' => 'string' ),
			'title'   => array( 'required' => false, 'type' => 'string' ),
			'confirm' => array( 'required' => false, 'type' => 'boolean' ),
			'force'   => array( 'required' => false, 'type' => 'boolean' ),
		),
	) );
} );

function sitebridge_nav_rest_get() {
	if ( ! function_exists( 'get_field' ) ) {
		return new WP_Error( 'acf_missing', 'ACF is not active', array( 'status' => 500 ) );
	}
	$opt      = apply_filters( 'sitebridge_nav_option_id', SITEBRIDGE_NAV_OPTION_ID );
	$nav_data = get_field( SITEBRIDGE_NAV_FIELD, $opt );
	return array(
		'version'           => get_field( SITEBRIDGE_NAV_VERSION_FIELD, $opt ),
		'nav_field'         => SITEBRIDGE_NAV_FIELD,
		'nav'               => $nav_data,
		'_untrimmed_urls'   => sitebridge_nav_untrimmed_urls( $nav_data ),
		'_available_fields' => array_keys( (array) ( get_fields( $opt ) ?: array() ) ),
	);
}

/**
 * Feature 3 bonus: flag nav link URLs whose stored value carries surrounding
 * whitespace (stored !== trimmed). url_eq() now trims so these still match, but
 * surfacing them makes one-time cleanups auditable (e.g. " https://culligancares.org/").
 */
function sitebridge_nav_untrimmed_urls( $nav ) {
	$flagged = array();
	if ( ! is_array( $nav ) || empty( $nav['nav_items'] ) || ! is_array( $nav['nav_items'] ) ) {
		return $flagged;
	}
	$check = function ( $url, $where ) use ( &$flagged ) {
		$url = (string) $url;
		if ( $url !== '' && $url !== trim( $url ) ) {
			$flagged[] = array( 'where' => $where, 'stored' => $url, 'trimmed' => trim( $url ) );
		}
	};
	foreach ( $nav['nav_items'] as $ni => $item ) {
		$label = isset( $item['nav_item_link']['title'] ) ? (string) $item['nav_item_link']['title'] : ( '#' . $ni );
		if ( isset( $item['nav_item_link']['url'] ) ) {
			$check( $item['nav_item_link']['url'], $label );
		}
		if ( empty( $item['nav_item_sub_items'] ) || ! is_array( $item['nav_item_sub_items'] ) ) {
			continue;
		}
		foreach ( $item['nav_item_sub_items'] as $col ) {
			$coltitle = isset( $col['sub_item_title'] ) ? (string) $col['sub_item_title'] : '';
			if ( empty( $col['sub_item_links'] ) || ! is_array( $col['sub_item_links'] ) ) {
				continue;
			}
			foreach ( $col['sub_item_links'] as $l ) {
				if ( ! isset( $l['link']['url'] ) ) {
					continue;
				}
				$ltitle = isset( $l['link']['title'] ) ? (string) $l['link']['title'] : '';
				$check( $l['link']['url'], trim( $label . ' › ' . $coltitle . ' › ' . $ltitle, ' ›' ) );
			}
		}
	}
	return $flagged;
}

/**
 * Replace mega-menu link URLs (trailing-slash tolerant), optionally scoped.
 *
 * - No scoping (default)     => replace every matching URL anywhere in the nav
 *   tree, including top-level items — the original behaviour, unchanged.
 * - parent_title / column_*  => restrict to one top-level item and/or a single
 *   column's sub_item_links, with the same disambiguation as remove-link:
 *   column_index (0-based) wins over column_title; out-of-range => 400; an
 *   ambiguous column_title => 409.
 *
 * v1.14: new_url is optional — pass new_title alone to rename a link without
 * touching its URL (e.g. "Commercial/Industrial" => "Commercial").
 */
function sitebridge_nav_rest_replace_link( WP_REST_Request $req ) {
	if ( ! function_exists( 'get_field' ) ) {
		return new WP_Error( 'acf_missing', 'ACF is not active', array( 'status' => 500 ) );
	}
	$opt       = apply_filters( 'sitebridge_nav_option_id', SITEBRIDGE_NAV_OPTION_ID );
	$old       = trim( (string) $req['old_url'] );
	$new       = ( $req['new_url'] !== null ) ? trim( (string) $req['new_url'] ) : '';
	$new_title = ( $req['new_title'] !== null ) ? sitebridge_nav_clean_title( $req['new_title'] ) : null;
	if ( $old === '' ) {
		return new WP_Error( 'bad_input', 'old_url is required', array( 'status' => 400 ) );
	}
	if ( $new === '' && ( $new_title === null || $new_title === '' ) ) {
		return new WP_Error( 'bad_input', 'new_url or new_title is required — pass new_title alone to rename a link without changing its URL', array( 'status' => 400 ) );
	}
	$parent_title = ( $req['parent_title'] !== null ) ? trim( (string) $req['parent_title'] ) : '';
	$column_title = ( $req['column_title'] !== null ) ? trim( (string) $req['column_title'] ) : null;
	$column_index = ( $req['column_index'] !== null ) ? (int) $req['column_index'] : null;
	$scoped       = ( $parent_title !== '' || $column_title !== null || $column_index !== null );

	$nav = get_field( SITEBRIDGE_NAV_FIELD, $opt );
	if ( ! is_array( $nav ) ) {
		return new WP_Error( 'no_nav', 'No data for field "' . SITEBRIDGE_NAV_FIELD . '" — check GET ' . SITEBRIDGE_NS . '/nav _available_fields', array( 'status' => 404 ) );
	}

	$count = 0;

	// Unscoped: preserve the original tree-wide replace (top-level items included).
	if ( ! $scoped ) {
		$walk = function ( &$node ) use ( &$walk, $old, $new, $new_title, &$count ) {
			if ( ! is_array( $node ) ) {
				return;
			}
			if ( isset( $node['url'] ) && is_string( $node['url'] ) ) {
				if ( sitebridge_nav_url_eq( $node['url'], $old ) ) {
					if ( $new !== '' ) {
						$node['url'] = $new;
					}
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
		return array(
			'replaced'   => $count,
			'old_url'    => $old,
			'new_url'    => ( $new !== '' ) ? $new : null,
			'new_title'  => $new_title,
			'title_only' => ( $new === '' ),
			'scoped'     => false,
		);
	}

	// Scoped: only touch sub_item_links inside the matched parent/column(s).
	if ( empty( $nav['nav_items'] ) || ! is_array( $nav['nav_items'] ) ) {
		return new WP_Error( 'no_nav', 'No data for field "' . SITEBRIDGE_NAV_FIELD . '" — check GET ' . SITEBRIDGE_NS . '/nav _available_fields', array( 'status' => 404 ) );
	}
	foreach ( $nav['nav_items'] as &$item ) {
		if ( $parent_title !== '' && ( ! isset( $item['nav_item_link']['title'] ) || ! sitebridge_nav_title_eq( $item['nav_item_link']['title'], $parent_title ) ) ) {
			continue;
		}
		if ( ! is_array( $item['nav_item_sub_items'] ) ) {
			continue;
		}

		// Resolve which column(s) to touch — same rules as remove-link.
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
				if ( isset( $col['sub_item_title'] ) && sitebridge_nav_title_eq( $col['sub_item_title'], $column_title ) ) {
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
			$only_cols = $matches; // 0 matches => nothing; 1 => that column
		}

		foreach ( $item['nav_item_sub_items'] as $ci => &$col ) {
			if ( $only_cols !== null && ! in_array( $ci, $only_cols, true ) ) {
				continue;
			}
			if ( empty( $col['sub_item_links'] ) || ! is_array( $col['sub_item_links'] ) ) {
				continue;
			}
			foreach ( $col['sub_item_links'] as &$l ) {
				if ( isset( $l['link']['url'] ) && sitebridge_nav_url_eq( $l['link']['url'], $old ) ) {
					if ( $new !== '' ) {
						$l['link']['url'] = $new;
					}
					if ( $new_title !== null && $new_title !== '' ) {
						$l['link']['title'] = $new_title;
					}
					$count++;
				}
			}
			unset( $l );
		}
		unset( $col );
	}
	unset( $item );

	if ( $count > 0 ) {
		update_field( SITEBRIDGE_NAV_FIELD, $nav, $opt );
	}
	return array(
		'replaced'   => $count,
		'old_url'    => $old,
		'new_url'    => ( $new !== '' ) ? $new : null,
		'new_title'  => $new_title,
		'title_only' => ( $new === '' ),
		'scoped'     => true,
	);
}

/**
 * Trailing-slash tolerant URL comparison (used by replace-link and remove-link).
 * Also trims surrounding whitespace first: some stored ACF nav URLs carry a stray
 * leading space (e.g. " https://culligancares.org/"), which otherwise makes every
 * URL variant fail to match. trim() both sides, then ignore trailing slashes.
 */
function sitebridge_nav_url_eq( $a, $b ) {
	return rtrim( trim( (string) $a ), '/' ) === rtrim( trim( (string) $b ), '/' );
}

/**
 * Normalize a title on the way IN to storage: decode HTML entities, then the
 * usual sanitize + trim. Without the decode, a caller passing
 * "St. Johns &amp; Nassau Counties" stored the literal "&amp;" and the nav
 * rendered it that way (the theme escapes on output).
 */
function sitebridge_nav_clean_title( $title ) {
	return trim( sanitize_text_field( html_entity_decode( (string) $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
}

/**
 * Case-insensitive title comparison for MATCHING (parent_title / column_title /
 * remove-item title). Entity-decodes both sides so a caller's "&" matches a
 * stored "&amp;" (and vice versa) — sites hand-built in wp-admin, and calls
 * made before clean_title() existed, both have literal entities on record.
 */
function sitebridge_nav_title_eq( $a, $b ) {
	$norm = function ( $s ) {
		return trim( html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	};
	return strcasecmp( $norm( $a ), $norm( $b ) ) === 0;
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
 * - force_new_column=true     => always APPEND a new column, even when a column
 *   with that title (including a blank one) already exists. Without it, a blank
 *   or whitespace-only column_title normalizes to "" and matches the existing ""
 *   column instead of creating a second one, so blank columns can't be added.
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

	$title = sitebridge_nav_clean_title( $req['title'] );
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
			if ( isset( $item['nav_item_link']['title'] ) && sitebridge_nav_title_eq( $item['nav_item_link']['title'], $title ) ) {
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
		if ( ! isset( $item['nav_item_link']['title'] ) || ! sitebridge_nav_title_eq( $item['nav_item_link']['title'], $parent_title ) ) {
			continue;
		}
		if ( ! is_array( $item['nav_item_sub_items'] ) ) {
			$item['nav_item_sub_items'] = array();
		}

		$column_title = ( $req['column_title'] !== null ) ? trim( (string) $req['column_title'] ) : null;
		$column_index = ( $req['column_index'] !== null ) ? (int) $req['column_index'] : null;
		$force_new    = ! empty( $req['force_new_column'] );
		$col_index    = null;
		if ( $force_new && $column_index !== null ) {
			return new WP_Error(
				'bad_input',
				'force_new_column appends a new column, so it cannot be combined with column_index (which targets an existing one).',
				array( 'status' => 400 )
			);
		}
		if ( $force_new ) {
			$col_index = null; // Skip resolution entirely — fall through to the create branch.
		} elseif ( $column_index !== null ) {
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
				if ( isset( $col['sub_item_title'] ) && sitebridge_nav_title_eq( $col['sub_item_title'], $column_title ) ) {
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
			if ( empty( $req['create_column'] ) && ! $force_new ) {
				$names = array();
				foreach ( $item['nav_item_sub_items'] as $col ) {
					$names[] = isset( $col['sub_item_title'] ) ? (string) $col['sub_item_title'] : '';
				}
				return new WP_Error(
					'column_not_found',
					'Column not found or ambiguous. Pass column_title matching one of: [' . implode( ' | ', $names ) . '] — or create_column=true to add it (force_new_column=true to append one even when the title already exists).',
					array( 'status' => 400 )
				);
			}
			$item['nav_item_sub_items'][] = array(
				'sub_item_title' => ( $column_title !== null ) ? sitebridge_nav_clean_title( $column_title ) : '',
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
			'added'        => 'sub_link',
			'parent'       => trim( (string) $item['nav_item_link']['title'] ),
			'column'       => (string) $item['nav_item_sub_items'][ $col_index ]['sub_item_title'],
			'column_index' => $col_index,
			'title'        => $title,
			'url'          => $link['url'],
			'position'     => $pos,
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
		if ( $parent_title !== '' && ( ! isset( $item['nav_item_link']['title'] ) || ! sitebridge_nav_title_eq( $item['nav_item_link']['title'], $parent_title ) ) ) {
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
				if ( isset( $col['sub_item_title'] ) && sitebridge_nav_title_eq( $col['sub_item_title'], $column_title ) ) {
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

/** Column + link counts for a top-level item's dropdown (sub_items may be false). */
function sitebridge_nav_item_dropdown_size( $item ) {
	$cols = 0;
	$links = 0;
	if ( isset( $item['nav_item_sub_items'] ) && is_array( $item['nav_item_sub_items'] ) ) {
		foreach ( $item['nav_item_sub_items'] as $col ) {
			$cols++;
			if ( ! empty( $col['sub_item_links'] ) && is_array( $col['sub_item_links'] ) ) {
				$links += count( $col['sub_item_links'] );
			}
		}
	}
	return array( 'columns' => $cols, 'links' => $links );
}

/** Compact `index="Title" (url)` summary of one top-level item, for error/preview text. */
function sitebridge_nav_item_summary( $index, $item ) {
	$size = sitebridge_nav_item_dropdown_size( $item );
	return array(
		'index'   => $index,
		'title'   => isset( $item['nav_item_link']['title'] ) ? trim( (string) $item['nav_item_link']['title'] ) : '',
		'url'     => isset( $item['nav_item_link']['url'] ) ? trim( (string) $item['nav_item_link']['url'] ) : '',
		'columns' => $size['columns'],
		'links'   => $size['links'],
	);
}

/** Human-readable listing of item summaries for disambiguation errors. */
function sitebridge_nav_items_listing( $summaries ) {
	$parts = array();
	foreach ( $summaries as $s ) {
		$parts[] = $s['index'] . '="' . $s['title'] . '" (' . $s['url'] . ')';
	}
	return implode( ' | ', $parts );
}

/**
 * Remove ONE top-level nav item from nav_items — the thing remove-link
 * deliberately refuses to do. Deleting a top-level item can take a whole
 * mega-menu with it, so the destructive path is gated rather than open:
 *
 * - Matcher: url (trailing-slash + whitespace tolerant, same as replace-link)
 *   and/or title (case-insensitive, entity-tolerant). Both => the item must
 *   match both. Dropdown-only parents share url "#", so title is the usable
 *   matcher there.
 * - 0 matches => 404 listing the top-level items; >1 => 409 listing the matches.
 *   Never removes more than one item in a call.
 * - confirm !== true => PREVIEW only: returns the full item JSON (dropdown
 *   included) and changes nothing.
 * - A populated dropdown additionally requires force=true; the refusal reports
 *   how many columns/links would be destroyed.
 * - On success returns the removed item verbatim so the caller can rebuild it
 *   via add-link, plus the new top-level count.
 */
function sitebridge_nav_rest_remove_item( WP_REST_Request $req ) {
	if ( ! function_exists( 'get_field' ) ) {
		return new WP_Error( 'acf_missing', 'ACF is not active', array( 'status' => 500 ) );
	}
	$opt = apply_filters( 'sitebridge_nav_option_id', SITEBRIDGE_NAV_OPTION_ID );
	$nav = get_field( SITEBRIDGE_NAV_FIELD, $opt );
	if ( ! is_array( $nav ) || empty( $nav['nav_items'] ) || ! is_array( $nav['nav_items'] ) ) {
		return new WP_Error( 'no_nav', 'No data for field "' . SITEBRIDGE_NAV_FIELD . '" — check GET ' . SITEBRIDGE_NS . '/nav _available_fields', array( 'status' => 404 ) );
	}

	$url   = ( $req['url'] !== null ) ? trim( (string) $req['url'] ) : '';
	$title = ( $req['title'] !== null ) ? trim( (string) $req['title'] ) : '';
	if ( $url === '' && $title === '' ) {
		return new WP_Error( 'bad_input', 'Pass url and/or title to identify the top-level item to remove', array( 'status' => 400 ) );
	}
	$confirm = ! empty( $req['confirm'] );
	$force   = ! empty( $req['force'] );

	$items    = array_values( $nav['nav_items'] );
	$matches  = array();
	$all      = array();
	foreach ( $items as $i => $item ) {
		$all[] = sitebridge_nav_item_summary( $i, $item );
		if ( $url !== '' && ! ( isset( $item['nav_item_link']['url'] ) && sitebridge_nav_url_eq( $item['nav_item_link']['url'], $url ) ) ) {
			continue;
		}
		if ( $title !== '' && ! ( isset( $item['nav_item_link']['title'] ) && sitebridge_nav_title_eq( $item['nav_item_link']['title'], $title ) ) ) {
			continue;
		}
		$matches[] = $i;
	}

	$matcher = trim( ( $url !== '' ? 'url "' . $url . '"' : '' ) . ( $url !== '' && $title !== '' ? ' + ' : '' ) . ( $title !== '' ? 'title "' . $title . '"' : '' ) );

	if ( count( $matches ) === 0 ) {
		return new WP_Error(
			'item_not_found',
			'No top-level nav item matched ' . $matcher . '. Top-level items: [' . sitebridge_nav_items_listing( $all ) . ']',
			array( 'status' => 404, 'nav_items' => $all )
		);
	}
	if ( count( $matches ) > 1 ) {
		$matched_summaries = array();
		foreach ( $matches as $i ) {
			$matched_summaries[] = $all[ $i ];
		}
		return new WP_Error(
			'item_ambiguous',
			$matcher . ' matches ' . count( $matches ) . ' top-level items — narrow it (add title, or a more specific url) so exactly one matches. Matches: [' . sitebridge_nav_items_listing( $matched_summaries ) . ']',
			array( 'status' => 409, 'matches' => $matched_summaries )
		);
	}

	$index   = $matches[0];
	$item    = $items[ $index ];
	$size    = sitebridge_nav_item_dropdown_size( $item );
	$summary = $all[ $index ];

	// Preview: no confirm => report exactly what WOULD go, touch nothing.
	if ( ! $confirm ) {
		return array(
			'removed'          => 0,
			'confirm_required' => true,
			'force_required'   => ( $size['links'] > 0 || $size['columns'] > 0 ),
			'matched'          => $summary,
			'would_remove'     => $item,
			'nav_items_total'  => count( $items ),
			'message'          => sprintf(
				'Preview only — nothing was changed. Removing "%s" would delete %d column(s) and %d dropdown link(s). Re-send with confirm=true%s to apply.',
				$summary['title'],
				$size['columns'],
				$size['links'],
				( $size['links'] > 0 || $size['columns'] > 0 ) ? ' and force=true' : ''
			),
		);
	}

	// Dropdown protection: confirm alone never destroys a populated mega-menu.
	if ( ! $force && ( $size['links'] > 0 || $size['columns'] > 0 ) ) {
		return new WP_Error(
			'dropdown_protected',
			sprintf(
				'"%s" has a dropdown (%d column(s), %d link(s)) that would be destroyed with it — re-send with force=true to remove the item and its whole mega-menu.',
				$summary['title'],
				$size['columns'],
				$size['links']
			),
			array( 'status' => 409, 'matched' => $summary, 'would_remove' => $item )
		);
	}

	array_splice( $items, $index, 1 );
	$nav['nav_items'] = $items;
	update_field( SITEBRIDGE_NAV_FIELD, $nav, $opt );

	return array(
		'removed'              => 1,
		'index'                => $index,
		'title'                => $summary['title'],
		'url'                  => $summary['url'],
		'had_dropdown'         => ( $size['columns'] > 0 ),
		'columns_removed'      => $size['columns'],
		'links_removed'        => $size['links'],
		'removed_item'         => $item,
		'nav_items_remaining'  => count( $items ),
	);
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

/* ============================================================================
 * CONTENT: byte-exact search/replace + Yoast canonical/robots meta
 * ----------------------------------------------------------------------------
 * Two write paths the connector can't safely do through core REST:
 *   POST /bam/v1/search-replace  — surgical str_replace() on raw post_content.
 *   POST /bam/v1/yoast-meta      — canonical / robots-noindex (v1.13) plus
 *                                  seo_title / meta_description / focus_keyword
 *                                  (v1.15, page-safe) via *_post_meta.
 * Both are gated behind manage_options, same as the redirect endpoints.
 * ========================================================================== */

add_action( 'rest_api_init', function () {
	$perm = function () { return current_user_can( 'manage_options' ); };

	register_rest_route( SITEBRIDGE_NS, '/search-replace', array(
		'methods'             => 'POST',
		'callback'            => 'sitebridge_search_replace_rest',
		'permission_callback' => $perm,
		'args'                => array(
			'post_id'      => array( 'required' => true,  'type' => 'integer' ),
			'replacements' => array( 'required' => true ),
			'dry_run'      => array( 'required' => false, 'type' => 'boolean' ),
		),
	) );

	register_rest_route( SITEBRIDGE_NS, '/yoast-meta', array(
		'methods'             => 'POST',
		'callback'            => 'sitebridge_yoast_meta_rest',
		'permission_callback' => $perm,
		'args'                => array(
			'post_id'          => array( 'required' => true,  'type' => 'integer' ),
			'post_type'        => array( 'required' => false, 'type' => 'string' ),
			'canonical'        => array( 'required' => false ),
			'robots_noindex'   => array( 'required' => false, 'type' => 'string' ),
			'seo_title'        => array( 'required' => false ),
			'meta_description' => array( 'required' => false ),
			'focus_keyword'    => array( 'required' => false ),
		),
	) );
} );

/**
 * Byte-exact search/replace on raw post_content.
 *
 * The whole reason this exists: editing an href inside ACF block-comment JSON
 * via update_post / wp_update_post() round-trips post_content through
 * wp_slash()/kses, which re-normalizes the unicode escapes ACF stores ( ",
 * <, \r\n, … ). That silently strips block attributes and blanks live
 * sections (the "v1.1 blank-section trap"). So here we:
 *   1. read post_content straight from the DB (no the_content, no client copy),
 *   2. str_replace() each { old, new } pair sequentially on the raw bytes,
 *   3. write back with a raw $wpdb->update() — deliberately NOT wp_update_post().
 *
 * Guards: dry_run defaults true; every `old` must be >= 8 bytes and != `new`;
 * an `expect` count that doesn't match the actual match count aborts the WHOLE
 * request (409, nothing written); max 20 pairs. No transcoding anywhere, so a
 * literal multibyte needle (e.g. U+202F narrow no-break space) matches the exact
 * bytes stored in the row. Trade-off: no revision entry — the response returns
 * md5/byte counts before & after so the change stays reconstructable.
 */
function sitebridge_search_replace_rest( WP_REST_Request $req ) {
	global $wpdb;

	$post_id = (int) $req['post_id'];
	if ( $post_id <= 0 ) {
		return new WP_Error( 'bad_input', 'post_id is required', array( 'status' => 400 ) );
	}

	$replacements = $req['replacements'];
	if ( ! is_array( $replacements ) || empty( $replacements ) ) {
		return new WP_Error( 'bad_input', 'replacements must be a non-empty array', array( 'status' => 400 ) );
	}
	if ( count( $replacements ) > 20 ) {
		return new WP_Error( 'too_many_pairs', 'Maximum 20 replacement pairs per call', array( 'status' => 400 ) );
	}

	$dry_run = ( $req['dry_run'] === null ) ? true : (bool) $req['dry_run'];

	// Validate every pair up front so a bad pair aborts before any mutation.
	$pairs = array();
	foreach ( $replacements as $idx => $r ) {
		if ( ! is_array( $r ) || ! array_key_exists( 'old', $r ) || ! array_key_exists( 'new', $r ) ) {
			return new WP_Error( 'bad_pair', sprintf( 'replacements[%d] must have "old" and "new"', $idx ), array( 'status' => 400 ) );
		}
		$old = (string) $r['old'];
		$new = (string) $r['new'];
		if ( strlen( $old ) < 8 ) { // strlen() = bytes, which is what we want.
			return new WP_Error( 'old_too_short', sprintf( 'replacements[%d].old must be at least 8 bytes (got %d)', $idx, strlen( $old ) ), array( 'status' => 400 ) );
		}
		if ( $old === $new ) {
			return new WP_Error( 'noop_pair', sprintf( 'replacements[%d].old === replacements[%d].new (no-op)', $idx, $idx ), array( 'status' => 400 ) );
		}
		$expect = ( isset( $r['expect'] ) && $r['expect'] !== null && $r['expect'] !== '' ) ? (int) $r['expect'] : null;
		$pairs[] = array( 'old' => $old, 'new' => $new, 'expect' => $expect );
	}

	// Read raw content straight from the DB — no filters, no client-supplied copy.
	$content = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
	if ( null === $content ) {
		return new WP_Error( 'not_found', sprintf( 'No post with ID %d', $post_id ), array( 'status' => 404 ) );
	}

	$md5_before   = md5( $content );
	$bytes_before = strlen( $content );

	// Single pass: count each pair against the working buffer (so a pair that
	// only appears AFTER an earlier pair applied still validates), advancing the
	// buffer as we go. Enforce every `expect` before deciding to write anything.
	$working  = $content;
	$report   = array();
	$mismatch = false;
	foreach ( $pairs as $i => $p ) {
		$found = substr_count( $working, $p['old'] );
		$report[] = array(
			'old_preview' => sitebridge_sr_preview( $p['old'] ),
			'found'       => $found,
			'expect'      => $p['expect'],
			'replaced'    => 0,
		);
		if ( $p['expect'] !== null && $found !== $p['expect'] ) {
			$mismatch = true;
		}
		if ( $found > 0 ) {
			$working             = str_replace( $p['old'], $p['new'], $working );
			$report[ $i ]['replaced'] = $found;
		}
	}

	// Any expect mismatch → abort the whole request, write nothing. Returned as
	// HTTP 200 (not 4xx) on purpose: the connector surfaces the body verbatim, and
	// the per-pair `found` counts are exactly what the caller needs to diagnose the
	// mismatch. Callers must branch on `applied` / `aborted`, not on status code.
	if ( $mismatch ) {
		foreach ( $report as &$row ) {
			$row['replaced'] = 0; // nothing was applied
		}
		unset( $row );
		return array(
			'post_id'      => $post_id,
			'dry_run'      => $dry_run,
			'applied'      => false,
			'aborted'      => true,
			'reason'       => 'expect_mismatch',
			'pairs'        => $report,
			'md5_before'   => $md5_before,
			'md5_after'    => $md5_before,
			'bytes_before' => $bytes_before,
			'bytes_after'  => $bytes_before,
		);
	}

	$new_content = $working;
	$md5_after   = md5( $new_content );
	$bytes_after = strlen( $new_content );

	$applied = false;
	if ( ! $dry_run && $md5_after !== $md5_before ) {
		// Raw write — $wpdb->update() escapes for SQL only; it does NOT slash or
		// kses the value, so the stored bytes are exactly $new_content.
		$updated = $wpdb->update(
			$wpdb->posts,
			array( 'post_content' => $new_content ),
			array( 'ID' => $post_id )
		);
		if ( false === $updated ) {
			return new WP_Error( 'db_error', 'Database update failed', array( 'status' => 500 ) );
		}
		clean_post_cache( $post_id );
		$applied = true;
	}

	return array(
		'post_id'      => $post_id,
		'dry_run'      => $dry_run,
		'applied'      => $applied,
		'pairs'        => $report,
		'md5_before'   => $md5_before,
		'md5_after'    => $md5_after,
		'bytes_before' => $bytes_before,
		'bytes_after'  => $bytes_after,
	);
}

/** Short, safe preview of an `old` needle for the response (first ~60 bytes). */
function sitebridge_sr_preview( $s ) {
	$s = (string) $s;
	return ( strlen( $s ) <= 60 ) ? $s : substr( $s, 0, 60 ) . '…';
}

/**
 * Write Yoast meta server-side.
 *
 * canonical and robots-noindex have owned this path since v1.13 — they aren't
 * dependably core-REST-writable at all. v1.15 pulls seo_title /
 * meta_description / focus_keyword in as well: the core-REST route Yoast
 * exposes for them silently drops the write on `page` post types (HTTP 200,
 * meta never persists), so the connector needs a post-type-agnostic path with
 * a read-back it can trust. All five go through update_post_meta()/
 * delete_post_meta(); an empty string clears. Partial update: only params
 * actually passed are touched. canonical is normalized (single trailing slash
 * stripped unless the path is "/"). Response echoes the resulting effective
 * values READ BACK from the DB so the caller can verify the write landed.
 */
function sitebridge_yoast_meta_rest( WP_REST_Request $req ) {
	$post_id = (int) $req['post_id'];
	if ( $post_id <= 0 || ! get_post( $post_id ) ) {
		return new WP_Error( 'not_found', sprintf( 'No post with ID %d', $post_id ), array( 'status' => 404 ) );
	}

	$changed = array();

	if ( $req['canonical'] !== null ) {
		$canonical = (string) $req['canonical'];
		if ( trim( $canonical ) === '' ) {
			delete_post_meta( $post_id, '_yoast_wpseo_canonical' );
			$changed[] = 'canonical';
		} else {
			$canonical = sitebridge_normalize_canonical( $canonical );
			update_post_meta( $post_id, '_yoast_wpseo_canonical', $canonical );
			$changed[] = 'canonical';
		}
	}

	if ( $req['robots_noindex'] !== null ) {
		$mode = strtolower( trim( (string) $req['robots_noindex'] ) );
		$map  = array( 'noindex' => '1', 'index' => '2' ); // Yoast convention.
		if ( $mode === 'default' ) {
			delete_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex' );
			$changed[] = 'robots_noindex';
		} elseif ( isset( $map[ $mode ] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', $map[ $mode ] );
			$changed[] = 'robots_noindex';
		} else {
			return new WP_Error( 'bad_input', 'robots_noindex must be one of: index, noindex, default', array( 'status' => 400 ) );
		}
	}

	// v1.15: title / metadesc / focuskw — post-type-agnostic, empty string clears.
	$text_fields = array(
		'seo_title'        => '_yoast_wpseo_title',
		'meta_description' => '_yoast_wpseo_metadesc',
		'focus_keyword'    => '_yoast_wpseo_focuskw',
	);
	foreach ( $text_fields as $param => $meta_key ) {
		if ( $req[ $param ] === null ) {
			continue;
		}
		$value = (string) $req[ $param ];
		if ( trim( $value ) === '' ) {
			delete_post_meta( $post_id, $meta_key );
		} else {
			update_post_meta( $post_id, $meta_key, sanitize_text_field( $value ) );
		}
		$changed[] = $param;
	}

	if ( empty( $changed ) ) {
		return new WP_Error( 'no_op', 'Pass at least one of: canonical, robots_noindex, seo_title, meta_description, focus_keyword', array( 'status' => 400 ) );
	}

	// Read back the stored values so the caller sees ground truth.
	$raw_robots       = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
	$robots_effective = ( $raw_robots === '1' ) ? 'noindex' : ( ( $raw_robots === '2' ) ? 'index' : 'default' );

	return array(
		'post_id'   => $post_id,
		'changed'   => $changed,
		'effective' => array(
			'canonical'        => (string) get_post_meta( $post_id, '_yoast_wpseo_canonical', true ),
			'robots_noindex'   => $robots_effective,
			'seo_title'        => (string) get_post_meta( $post_id, '_yoast_wpseo_title', true ),
			'meta_description' => (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
			'focus_keyword'    => (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ),
		),
	);
}

/**
 * Canonical normalization guard (Feature 4). Strip a SINGLE trailing slash
 * unless the URL path is exactly "/" (root). Scheme/host/port/query/fragment are
 * preserved. Root-only URLs (https://example.com/) are left untouched.
 */
function sitebridge_normalize_canonical( $url ) {
	$url = trim( (string) $url );
	if ( $url === '' ) {
		return '';
	}
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || ! isset( $parts['path'] ) ) {
		return $url;
	}
	$path = $parts['path'];
	if ( $path !== '/' && substr( $path, -1 ) === '/' ) {
		$parts['path'] = substr( $path, 0, -1 ); // one slash only
		return sitebridge_build_url( $parts );
	}
	return $url;
}

/** Reassemble a URL from wp_parse_url() parts (enough for canonical use). */
function sitebridge_build_url( $p ) {
	$url = '';
	if ( ! empty( $p['scheme'] ) ) {
		$url .= $p['scheme'] . '://';
	}
	if ( ! empty( $p['host'] ) ) {
		$url .= $p['host'];
		if ( ! empty( $p['port'] ) ) {
			$url .= ':' . $p['port'];
		}
	}
	if ( isset( $p['path'] ) ) {
		$url .= $p['path'];
	}
	if ( ! empty( $p['query'] ) ) {
		$url .= '?' . $p['query'];
	}
	if ( ! empty( $p['fragment'] ) ) {
		$url .= '#' . $p['fragment'];
	}
	return $url;
}

/* ============================================================================
 * ACF FIELDS: safe page-level (meta-box) field writes (v1.17)
 * ----------------------------------------------------------------------------
 * POST /bam/v1/acf-fields — body: post_id, fields { name-or-key: value },
 * optional clear_stale_rows (default true).
 *
 * Why core REST can't do this: ACF's REST layer resolves the sub-fields of a
 * seamless clone with COMPOSITE keys ("{cloneKey}_{subKey}"), and
 * acf_update_value() writes whatever key it was handed into the "_"-prefixed
 * reference row in postmeta. The front end resolves values THROUGH that
 * reference (get_field → "_hero_banner_badges" → acf_get_field(key)); a
 * composite key doesn't resolve outside the REST/clone loading context, so the
 * template renders nothing — while REST read-back, which re-derives fields from
 * the group schema and never consults the reference rows, keeps reporting the
 * written values as if all were well. Verified against ACF PRO 6.8.4
 * (rest-api/class-acf-rest-api.php::update_fields() resolves via
 * acf_search_fields() over the clone-flattened composite-key fields;
 * acf-value-functions.php::acf_update_value() stores $field['key'] as the
 * reference). Same root cause as the July 2026 hero-rollout pointer
 * corruption, where connector writes prepended the clone key as a prefix.
 *
 * So here we:
 *   1. resolve every incoming selector to its REAL field object up front
 *      (acf_get_field by key or by name; composite keys are refused) — any
 *      unresolvable selector aborts the whole request before a single write,
 *   2. write through update_field() with the field KEY, so ACF stores value
 *      AND reference rows exactly as an admin save does,
 *   3. repair any composite-corrupted reference row already on the post
 *      (damage left by past core-REST writes — every write self-heals the page),
 *   4. delete the stale higher-index rows a shrinking repeater leaves behind,
 *   5. read the result back THROUGH the reference rows — the same resolution
 *      path the front-end template uses — so `reference_ok` in the response is
 *      a render-truthful verification, not an input echo,
 *   6. return post_content md5 before/after to prove content was untouched.
 *
 * Partial update is guaranteed by construction: the loop only ever calls
 * update_field() for selectors present in `fields`.
 *
 * NULL VALUES (v1.17.1): JSON `null` is not something ACF can store. Passing it
 * for an empty image sub-field leaves a value/reference row pair the front end
 * resolves to nothing — the broken row observed during the Aug 2026 hero
 * rollout. The storable "no value" is `""`, so nulls anywhere in `fields` are
 * normalized to `""` before any write (a null repeater/group/flexible value
 * becomes `[]`), and the count is reported per field as `nulls_normalized`.
 * ========================================================================== */

add_action( 'rest_api_init', function () {
	register_rest_route( SITEBRIDGE_NS, '/acf-fields', array(
		'methods'             => 'POST',
		'callback'            => 'sitebridge_acf_fields_rest',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'args'                => array(
			'post_id'          => array( 'required' => true,  'type' => 'integer' ),
			'fields'           => array( 'required' => true ),
			'clear_stale_rows' => array( 'required' => false, 'type' => 'boolean' ),
		),
	) );
} );

function sitebridge_acf_fields_rest( WP_REST_Request $req ) {
	global $wpdb;

	if ( ! function_exists( 'update_field' ) || ! function_exists( 'acf_get_field' ) ) {
		return new WP_Error( 'acf_missing', 'ACF is not active', array( 'status' => 500 ) );
	}

	$post_id = (int) $req['post_id'];
	$post    = $post_id > 0 ? get_post( $post_id ) : null;
	if ( ! $post ) {
		return new WP_Error( 'not_found', sprintf( 'No post with ID %d', $post_id ), array( 'status' => 404 ) );
	}

	$fields = $req['fields'];
	if ( ! is_array( $fields ) || empty( $fields ) ) {
		return new WP_Error( 'bad_input', 'fields must be a non-empty object of { field-name-or-key: value }', array( 'status' => 400 ) );
	}
	if ( count( $fields ) > 20 ) {
		return new WP_Error( 'too_many_fields', 'Maximum 20 fields per call', array( 'status' => 400 ) );
	}

	$clear_stale = ( $req['clear_stale_rows'] === null ) ? true : (bool) $req['clear_stale_rows'];

	$md5_before = md5( (string) $post->post_content );

	// Resolve every selector up front — one bad selector aborts before any write.
	$resolved = array();
	foreach ( $fields as $selector => $value ) {
		$field = acf_get_field( $selector );
		if ( ! $field || empty( $field['key'] ) || empty( $field['name'] ) ) {
			return new WP_Error(
				'unknown_field',
				sprintf( '"%s" does not resolve to a registered ACF field on this site. Pass the field name (e.g. "hero_banner_badges") or its real field key.', $selector ),
				array( 'status' => 400 )
			);
		}
		// A composite clone key ("field_X_field_Y") is exactly the corruption
		// this route exists to prevent — refuse to write through one.
		if ( substr_count( $field['key'], 'field_' ) > 1 ) {
			return new WP_Error(
				'composite_key',
				sprintf( '"%s" resolved to composite clone key "%s". Refusing: writing through it corrupts the field reference rows. Use the field\'s own name or key.', $selector, $field['key'] ),
				array( 'status' => 409 )
			);
		}
		// JSON null is not storable — normalize it to ACF's empty value before it
		// can write a reference row the front end resolves to nothing.
		$nulls = 0;
		if ( $value === null && in_array( $field['type'], array( 'repeater', 'flexible_content', 'group' ), true ) ) {
			$value = array();
			$nulls = 1;
		} else {
			$value = sitebridge_acf_normalize_nulls( $value, $nulls );
		}

		$resolved[] = array( 'field' => $field, 'value' => $value, 'nulls' => $nulls );
	}

	// Hero exclusivity guard (theme profile, see CONFIG): a page that renders the
	// hero from a block must not also enable the meta-box hero — double render.
	if ( SITEBRIDGE_HERO_BLOCK !== '' && SITEBRIDGE_HERO_TOGGLE !== '' ) {
		foreach ( $resolved as $r ) {
			if ( $r['field']['name'] === SITEBRIDGE_HERO_TOGGLE
				&& ! empty( $r['value'] )
				&& strpos( (string) $post->post_content, '<!-- wp:' . SITEBRIDGE_HERO_BLOCK ) !== false ) {
				return new WP_Error(
					'hero_conflict',
					sprintf( 'Post %d contains a %s block; enabling %s as well would render the hero twice. Leave the toggle false on block pages (the block is the hero).', $post_id, SITEBRIDGE_HERO_BLOCK, SITEBRIDGE_HERO_TOGGLE ),
					array( 'status' => 409 )
				);
			}
		}
	}

	$results = array();
	foreach ( $resolved as $r ) {
		$field = $r['field'];
		$name  = $field['name'];

		$is_repeater = ( $field['type'] === 'repeater' && is_array( $r['value'] ) );
		$after_count = $is_repeater ? count( $r['value'] ) : 0;

		// Rows above the incoming row count, measured BEFORE the write: what this
		// shrink actually strands, whoever ends up clearing it.
		$stale_found = $is_repeater
			? count( sitebridge_acf_repeater_rows_from( $post_id, $name, $after_count ) )
			: 0;

		// The write. Return value is NOT the verification (update_metadata()
		// returns false on an unchanged value) — the read-back below is.
		update_field( $field['key'], $r['value'], $post_id );

		// A shrinking repeater leaves its old higher-index rows behind. ACF's own
		// repeater update_value() already deletes the rows of sub-fields still in
		// the group schema, so this sweep normally finds nothing to do — which is
		// why `stale_rows_deleted` is legitimately 0 on a clean shrink and
		// `stale_rows_found` is the number a caller is actually asking for. What
		// the sweep does catch is what ACF cannot know about: rows of sub-fields
		// since removed from the group, and rows orphaned above a stale count row.
		$stale_delete = ( $clear_stale && $is_repeater )
			? sitebridge_acf_repeater_rows_from( $post_id, $name, $after_count )
			: array();

		$results[ $name ] = array(
			'field_key'          => $field['key'],
			'type'               => $field['type'],
			'stale_rows_found'   => $stale_found,
			'stale_rows_deleted' => $stale_delete ? sitebridge_acf_delete_meta_keys( $post_id, $stale_delete ) : 0,
			'nulls_normalized'   => (int) $r['nulls'],
		);
	}

	// Heal composite-corrupted reference rows anywhere on this post (including
	// fields this call didn't touch) left behind by past core-REST writes.
	$repaired = sitebridge_acf_repair_references( $post_id );

	clean_post_cache( $post_id );

	// Read back through the reference rows — the front end's resolution path.
	foreach ( $resolved as $r ) {
		$results[ $r['field']['name'] ]['state'] = sitebridge_acf_field_state( $post_id, $r['field'] );
	}

	// Fresh from the DB: prove post_content was untouched by the whole call.
	$content_after = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
	$md5_after     = md5( $content_after );

	return array(
		'post_id'             => $post_id,
		'fields'              => $results,
		'repaired_references' => $repaired,
		'content_md5_before'  => $md5_before,
		'content_md5_after'   => $md5_after,
		'content_untouched'   => ( $md5_before === $md5_after ),
	);
}

/**
 * Recursively replace JSON `null` with ACF's storable empty value (`""`),
 * counting the substitutions. `null` reaches update_field() as "no value" but
 * ACF still writes the field's reference row, leaving a pair the front end
 * resolves to nothing; `""` is what an admin save stores for an emptied field.
 */
function sitebridge_acf_normalize_nulls( $value, &$count ) {
	if ( $value === null ) {
		$count++;
		return '';
	}
	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value[ $k ] = sitebridge_acf_normalize_nulls( $v, $count );
		}
	}
	return $value;
}

/**
 * Every postmeta key belonging to repeater {$name} at a row index >= $min_index,
 * value rows and their "_" references alike.
 *
 * Two guards keep a neighbouring field's rows out of the result: matching is
 * anchored on the digits that must follow the repeater name (so "{$name}_extra_…"
 * never matches at all), and an index whose "{$name}_{i}" prefix is itself a
 * registered field is skipped entirely — those rows are that field's, not row i
 * of this one. Anything else at or above the index is fair game, including rows
 * of sub-fields no longer in the group.
 */
function sitebridge_acf_repeater_rows_from( $post_id, $name, $min_index ) {
	global $wpdb;

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta}
		 WHERE post_id = %d AND ( meta_key LIKE %s OR meta_key LIKE %s )",
		$post_id,
		$wpdb->esc_like( $name . '_' ) . '%',
		$wpdb->esc_like( '_' . $name . '_' ) . '%'
	) );

	$keys       = array();
	$regex      = '/^_?' . preg_quote( $name, '/' ) . '_(\d+)_/';
	$is_sibling = array();
	foreach ( (array) $rows as $row ) {
		if ( ! preg_match( $regex, $row->meta_key, $m ) || (int) $m[1] < $min_index ) {
			continue;
		}
		$i = (int) $m[1];
		if ( ! isset( $is_sibling[ $i ] ) ) {
			$is_sibling[ $i ] = (bool) acf_get_field( $name . '_' . $i );
		}
		if ( ! $is_sibling[ $i ] ) {
			$keys[] = $row->meta_key;
		}
	}
	return $keys;
}

/** Delete the named postmeta keys off one post; returns the row count deleted. */
function sitebridge_acf_delete_meta_keys( $post_id, array $keys ) {
	global $wpdb;

	if ( empty( $keys ) ) {
		return 0;
	}
	$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
	return (int) $wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ( $placeholders )",
		array_merge( array( $post_id ), array_values( $keys ) )
	) );
}

/**
 * Repair "_"-prefixed field reference rows corrupted with composite clone keys
 * ("field_X_field_Y..."). Legitimate references on this theme are single field
 * keys; a composite value is always damage from an ACF REST clone write. The
 * repair strips to the FINAL "field_..." segment, and only applies when that
 * candidate resolves to a registered field whose name matches the row it would
 * govern (the row key must end in "_{field name}", which holds for both
 * top-level rows like "_show_hero_banner" and repeater sub-rows like
 * "_hero_banner_badges_0_badge_image"). Anything that doesn't match both
 * checks is left alone and reported as skipped.
 */
function sitebridge_acf_repair_references( $post_id ) {
	global $wpdb;

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta}
		 WHERE post_id = %d AND meta_key LIKE %s AND meta_value LIKE %s",
		$post_id,
		$wpdb->esc_like( '_' ) . '%',
		$wpdb->esc_like( 'field_' ) . '%' . $wpdb->esc_like( '_field_' ) . '%'
	) );

	$repaired = array();
	foreach ( $rows as $row ) {
		$pos       = strrpos( $row->meta_value, 'field_' );
		$candidate = substr( $row->meta_value, $pos );
		$field     = ( $candidate !== $row->meta_value ) ? acf_get_field( $candidate ) : false;

		$entry = array(
			'meta_key' => $row->meta_key,
			'from'     => $row->meta_value,
			'to'       => $candidate,
		);

		if ( ! $field || $field['key'] !== $candidate
			|| substr( $row->meta_key, -strlen( '_' . $field['name'] ) ) !== '_' . $field['name'] ) {
			$entry['repaired'] = false;
			$repaired[]        = $entry;
			continue;
		}

		update_metadata_by_mid( 'post', $row->meta_id, $candidate );
		$entry['repaired'] = true;
		$repaired[]        = $entry;
	}
	return $repaired;
}

/**
 * Render-truthful state of one field on one post: the raw value row, the
 * reference row, and whether that reference resolves back to the field — i.e.
 * whether get_field() on the front end will find it. For repeaters, the same
 * per-row for every sub-field of every row the count says exists.
 */
function sitebridge_acf_field_state( $post_id, $field ) {
	$name      = $field['name'];
	$value     = get_post_meta( $post_id, $name, true );
	$reference = (string) get_post_meta( $post_id, '_' . $name, true );

	$state = array(
		'value'        => $value,
		'reference'    => $reference,
		'reference_ok' => ( $reference === $field['key'] ),
	);

	if ( $field['type'] === 'repeater' && ! empty( $field['sub_fields'] ) ) {
		$count         = (int) $value;
		$state['rows'] = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$row = array();
			foreach ( $field['sub_fields'] as $sub ) {
				$sub_name = $name . '_' . $i . '_' . $sub['name'];
				$sub_ref  = (string) get_post_meta( $post_id, '_' . $sub_name, true );
				$row[ $sub['name'] ] = array(
					'value'        => get_post_meta( $post_id, $sub_name, true ),
					'reference'    => $sub_ref,
					'reference_ok' => ( $sub_ref === $sub['key'] ),
				);
			}
			$state['rows'][] = $row;
		}
	}
	return $state;
}

/* ============================================================================
 * CACHE: purge page caches after a content / redirect / nav change (v1.15)
 * ----------------------------------------------------------------------------
 * POST /bam/v1/purge-cache — body: optional `url` (path or absolute; per-URL
 * purge where the engine supports it) and/or `post_id` (resolves the permalink
 * and cleans that post's WP cache); neither = full-site purge.
 *
 * Why: rebuilt pages sit invisible behind cached stale renders, and some hosts
 * ignore query-string cache-busters entirely. Detects whichever cache layers
 * are present in THIS WordPress and fires their purge APIs. Anything upstream
 * of PHP (CDN, external proxy) is invisible from here — an empty `detected`
 * array is the caller's signal that the stale layer is upstream and needs a
 * host-level purge instead.
 * ========================================================================== */

add_action( 'rest_api_init', function () {
	register_rest_route( SITEBRIDGE_NS, '/purge-cache', array(
		'methods'             => 'POST',
		'callback'            => 'sitebridge_purge_cache_rest',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'args'                => array(
			'url'     => array( 'required' => false, 'type' => 'string' ),
			'post_id' => array( 'required' => false, 'type' => 'integer' ),
		),
	) );
} );

function sitebridge_purge_cache_rest( WP_REST_Request $req ) {
	$url     = ( $req['url'] !== null ) ? trim( (string) $req['url'] ) : '';
	$post_id = ( $req['post_id'] !== null ) ? (int) $req['post_id'] : 0;

	if ( $post_id > 0 ) {
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'not_found', sprintf( 'No post with ID %d', $post_id ), array( 'status' => 404 ) );
		}
		clean_post_cache( $post_id );
		if ( $url === '' ) {
			$url = get_permalink( $post_id );
		}
	}

	// A bare path becomes absolute against this site — per-URL engine APIs want
	// the full URL.
	if ( $url !== '' && strpos( $url, 'http://' ) !== 0 && strpos( $url, 'https://' ) !== 0 ) {
		$url = home_url( '/' . ltrim( $url, '/' ) );
	}

	$scope    = ( $url !== '' ) ? 'url' : 'site';
	$detected = array();
	$fired    = array();
	$partial  = array(); // engines detected where only a FULL purge could be fired for a url-scoped request

	// --- WP Engine (mu-plugin). Varnish purge is per-post when given an ID. ----
	if ( class_exists( 'WpeCommon' ) ) {
		$detected[] = 'wpengine';
		if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
			WpeCommon::purge_memcached();
		}
		if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
			if ( $scope === 'url' && $post_id > 0 ) {
				WpeCommon::purge_varnish_cache( $post_id );
			} else {
				WpeCommon::purge_varnish_cache();
				if ( $scope === 'url' ) {
					$partial[] = 'wpengine';
				}
			}
			$fired[] = 'wpengine';
		}
	}

	// --- Kinsta (mu-plugin). No public per-URL API — always full. -------------
	if ( class_exists( '\Kinsta\Cache' ) ) {
		$detected[] = 'kinsta';
		global $kinsta_cache;
		if ( is_object( $kinsta_cache ) && isset( $kinsta_cache->kinsta_cache_purge )
			&& method_exists( $kinsta_cache->kinsta_cache_purge, 'purge_complete_caches' ) ) {
			$kinsta_cache->kinsta_cache_purge->purge_complete_caches();
			$fired[] = 'kinsta';
			if ( $scope === 'url' ) {
				$partial[] = 'kinsta';
			}
		}
	}

	// --- W3 Total Cache --------------------------------------------------------
	if ( function_exists( 'w3tc_flush_all' ) ) {
		$detected[] = 'w3-total-cache';
		if ( $scope === 'url' && function_exists( 'w3tc_flush_url' ) ) {
			w3tc_flush_url( $url );
		} else {
			w3tc_flush_all();
			if ( $scope === 'url' ) {
				$partial[] = 'w3-total-cache';
			}
		}
		$fired[] = 'w3-total-cache';
	}

	// --- WP Rocket -------------------------------------------------------------
	if ( function_exists( 'rocket_clean_domain' ) ) {
		$detected[] = 'wp-rocket';
		if ( $scope === 'url' && function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( array( $url ) );
		} else {
			rocket_clean_domain();
			if ( $scope === 'url' ) {
				$partial[] = 'wp-rocket';
			}
		}
		$fired[] = 'wp-rocket';
	}

	// --- WP Super Cache --------------------------------------------------------
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		$detected[] = 'wp-super-cache';
		if ( $scope === 'url' && function_exists( 'wpsc_delete_url_cache' ) ) {
			wpsc_delete_url_cache( $url );
		} else {
			wp_cache_clear_cache();
			if ( $scope === 'url' ) {
				$partial[] = 'wp-super-cache';
			}
		}
		$fired[] = 'wp-super-cache';
	}

	// --- LiteSpeed Cache (action API — safe no-op if the listener is gone) -----
	if ( defined( 'LSCWP_V' ) || class_exists( '\LiteSpeed\Purge' ) ) {
		$detected[] = 'litespeed';
		if ( $scope === 'url' ) {
			do_action( 'litespeed_purge_url', $url );
		} else {
			do_action( 'litespeed_purge_all' );
		}
		$fired[] = 'litespeed';
	}

	// --- SiteGround Optimizer (empty arg = purge everything) -------------------
	if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
		$detected[] = 'sg-optimizer';
		sg_cachepress_purge_cache( ( $scope === 'url' ) ? $url : '' );
		$fired[] = 'sg-optimizer';
	}

	// --- Breeze (Cloudways). Action API — full purge only. ---------------------
	if ( class_exists( 'Breeze_PurgeCache' ) ) {
		$detected[] = 'breeze';
		do_action( 'breeze_clear_all_cache' );
		$fired[] = 'breeze';
		if ( $scope === 'url' ) {
			$partial[] = 'breeze';
		}
	}

	// --- NitroPack. Its advanced-cache.php drop-in serves stored pages before
	// WordPress loads, and its own auto-invalidation only hooks post saves, so
	// options-level changes (nav, schema, redirects, Yoast meta) never trigger
	// it. Purge explicitly — for BOTH scopes. nitropack_sdk_purge() purges the
	// local drop-in cache AND calls NitroPack's remote API; the API call can
	// throw, and an unreachable NitroPack must not 500 the rest of the chain.
	// Returns false when the plugin is installed but not connected. -------------
	if ( defined( 'NITROPACK_VERSION' )
		&& ( function_exists( 'nitropack_sdk_purge' ) || function_exists( 'nitropack_purge' ) ) ) {
		$detected[] = 'nitropack';
		$np_ok = false;
		try {
			if ( function_exists( 'nitropack_sdk_purge' ) ) {
				// Per-URL purge is real here (local + remote), so no $partial entry.
				$np_ok = (bool) nitropack_sdk_purge( ( $scope === 'url' ) ? $url : null, null, 'SiteBridge purge_cache' );
			} else {
				// Pre-SDK NitroPack: queues a purge the plugin flushes at shutdown.
				nitropack_purge( ( $scope === 'url' ) ? $url : null, null, 'SiteBridge purge_cache' );
				$np_ok = true;
			}
		} catch ( \Throwable $e ) {
			$np_ok = false;
		}
		if ( $np_ok ) {
			$fired[] = 'nitropack';
		}
	}

	// --- External object cache (Redis/Memcached drop-in). Full purge only:
	// per-URL requests already ran clean_post_cache() above when post_id given. --
	if ( $scope === 'site' && wp_using_ext_object_cache() ) {
		$detected[] = 'object-cache';
		wp_cache_flush();
		$fired[] = 'object-cache';
	}

	if ( empty( $detected ) ) {
		$note = 'No purgeable cache layer detected in WordPress — if the page is still stale, the layer is upstream (CDN/proxy) and needs a host-level purge.';
	} elseif ( ! empty( $partial ) ) {
		$note = sprintf( 'No per-URL purge API for: %s — fired a full-site purge there instead.', implode( ', ', array_unique( $partial ) ) );
	} else {
		$note = null;
	}

	// Detected-but-not-fired must be visible: a NitroPack miss looks exactly
	// like a successful purge to the caller otherwise.
	if ( in_array( 'nitropack', $detected, true ) && ! in_array( 'nitropack', $fired, true ) ) {
		$np_note = 'NitroPack detected but its purge did not fire (plugin not connected, or its API unreachable) — pages may stay stale until NitroPack is purged from its own dashboard.';
		$note    = ( $note === null ) ? $np_note : $note . ' ' . $np_note;
	}

	return array(
		'scope'    => $scope,
		'url'      => ( $url !== '' ) ? $url : null,
		'detected' => $detected,
		'fired'    => $fired,
		'note'     => $note,
	);
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

/* ============================================================================
 * CAPABILITY REPORT MODULE  (v1.18.0) — read-only site discovery
 * ----------------------------------------------------------------------------
 * One authenticated route, GET sitebridge/v1/capability-findings, that
 * fingerprints the site and returns raw findings JSON. The plugin COLLECTS,
 * NEVER JUDGES: all verdict logic lives in the wp-mcp-hosted connector, so it
 * can evolve without a fleet release. The new `sitebridge/v1` namespace is for
 * new surface only — the legacy `bam/*` namespaces stay untouched.
 *
 * Read-only guarantee: this module performs no update_*, insert, or transient calls
 * and keeps no state between requests. A $wpdb 'query' monitor counts any
 * write-verb SQL issued while collecting (third-party hooks firing during the
 * wp_head buffer can write; ours never do) and the count ships in the payload
 * as read_only_attestation. Rate limiting is deliberately OMITTED: a transient
 * throttle would itself violate the zero-write guarantee, and the route is
 * already admin-auth-gated.
 *
 * Every collector section runs in its own try/catch under a shared deadline;
 * a failed section lands in collection_status.failed_sections and the run
 * still returns — the connector renders those modules UNKNOWN, never guessed.
 * ========================================================================== */

const SITEBRIDGE_CAP_NS             = 'sitebridge/v1';
const SITEBRIDGE_CAP_SCHEMA_VERSION = '1.0';

add_action( 'rest_api_init', function () {
	register_rest_route( SITEBRIDGE_CAP_NS, '/capability-findings', array(
		'methods'             => 'GET',
		'callback'            => 'sitebridge_capability_findings_rest',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
	) );
} );

function sitebridge_capability_findings_rest( $req ) {
	$deadline = microtime( true ) + 50; // < 60s budget, headroom for transport.

	// -- write monitor (attestation, not enforcement) --------------------------
	$writes  = array();
	$monitor = function ( $query ) use ( &$writes ) {
		if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i', (string) $query ) ) {
			$writes[] = substr( trim( (string) $query ), 0, 120 );
		}
		return $query;
	};
	add_filter( 'query', $monitor );

	$status   = array( 'complete' => true, 'failed_sections' => array(), 'notes' => array() );
	$findings = array(
		'schema_version' => SITEBRIDGE_CAP_SCHEMA_VERSION,
		'collected_at'   => gmdate( 'c' ),
		'site'           => array( 'url' => home_url( '/' ) ),
	);

	$sections = array(
		'environment'   => 'sitebridge_cap_environment',
		'editor'        => 'sitebridge_cap_editor',
		'plugins'       => 'sitebridge_cap_plugins',
		'hosting'       => 'sitebridge_cap_hosting',
		'nav'           => 'sitebridge_cap_nav',
		'sitemap'       => 'sitebridge_cap_sitemap',
		'content_model' => 'sitebridge_cap_content_model',
		'schema_output' => 'sitebridge_cap_schema_output', // last: may self-fetch (network)
	);
	foreach ( $sections as $key => $fn ) {
		if ( microtime( true ) > $deadline ) {
			$status['complete']          = false;
			$status['failed_sections'][] = $key;
			$status['notes'][]           = $key . ': skipped — time budget exhausted';
			continue;
		}
		try {
			$findings[ $key ] = call_user_func( $fn, $deadline );
		} catch ( Throwable $e ) {
			$status['complete']          = false;
			$status['failed_sections'][] = $key;
			$status['notes'][]           = $key . ': ' . substr( $e->getMessage(), 0, 160 );
		}
	}

	remove_filter( 'query', $monitor );
	$findings['read_only_attestation'] = array(
		'writes_performed' => count( $writes ),
		'method'           => 'no update_*/insert/transient calls; $wpdb query monitor during collection',
	);
	if ( $writes ) {
		// Not ours — the collector issues none — but worth surfacing: something
		// else on this site writes during passive collection (heartbeat loggers,
		// stat counters hooked to wp_head, …).
		$status['notes'][] = 'write queries observed during collection (third-party hooks): '
			. implode( ' | ', array_slice( $writes, 0, 3 ) );
	}
	$findings['collection_status'] = $status;

	return rest_ensure_response( $findings );
}

/* ------------------------------------------------------------- environment -- */

function sitebridge_cap_environment( $deadline ) {
	$theme  = wp_get_theme();
	$parent = $theme->parent();
	return array(
		'wp_version'   => get_bloginfo( 'version' ),
		'php_version'  => phpversion(),
		'https'        => ( strpos( home_url( '/' ), 'https://' ) === 0 ) || is_ssl(),
		'multisite'    => is_multisite(),
		'memory_limit' => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : ini_get( 'memory_limit' ),
		'theme'        => array(
			'name'    => $theme->get( 'Name' ),
			'version' => $theme->get( 'Version' ),
			'parent'  => $parent ? $parent->get( 'Name' ) : null,
		),
	);
}

/* ------------------------------------------------------------------ editor -- */

function sitebridge_cap_editor( $deadline ) {
	$classic = sitebridge_cap_plugin_active( 'classic-editor' );
	$block   = function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( 'post' );
	$default = 'block';
	if ( $classic ) {
		$default = ( get_option( 'classic-editor-replace', 'classic' ) === 'block' ) ? 'block' : 'classic';
	} elseif ( ! $block ) {
		$default = 'classic';
	}
	return array(
		'gutenberg_available'   => $block,
		'classic_editor_plugin' => $classic,
		'default_editor'        => $default,
	);
}

/* ----------------------------------------------------------------- plugins -- */

// Active plugins as [ [slug, file, name, version], … ] — slug is the directory
// (or basename for single-file plugins), headers read straight from the file.
function sitebridge_cap_active_plugins() {
	static $list = null;
	if ( $list !== null ) {
		return $list;
	}
	$list   = array();
	$active = (array) get_option( 'active_plugins', array() );
	foreach ( $active as $file ) {
		$slug = ( strpos( $file, '/' ) !== false ) ? dirname( $file ) : basename( $file, '.php' );
		$row  = array( 'slug' => $slug, 'file' => $file, 'name' => $slug, 'version' => '' );
		$path = WP_PLUGIN_DIR . '/' . $file;
		if ( function_exists( 'get_file_data' ) && is_readable( $path ) ) {
			$head = get_file_data( $path, array( 'Name' => 'Plugin Name', 'Version' => 'Version' ) );
			if ( ! empty( $head['Name'] ) )    { $row['name'] = $head['Name']; }
			if ( ! empty( $head['Version'] ) ) { $row['version'] = $head['Version']; }
		}
		$list[] = $row;
	}
	return $list;
}

function sitebridge_cap_plugin_active( $slug ) {
	foreach ( sitebridge_cap_active_plugins() as $p ) {
		if ( $p['slug'] === $slug ) {
			return $p;
		}
	}
	return false;
}

function sitebridge_cap_plugins( $deadline ) {
	global $wpdb;
	$active = sitebridge_cap_active_plugins();

	$first_match = function ( $map ) use ( $active ) {
		foreach ( $active as $p ) {
			if ( isset( $map[ $p['slug'] ] ) ) {
				return array( $map[ $p['slug'] ], $p );
			}
		}
		return array( null, null );
	};
	$all_matches = function ( $map ) use ( $active ) {
		$out = array();
		foreach ( $active as $p ) {
			if ( isset( $map[ $p['slug'] ] ) && ! in_array( $map[ $p['slug'] ], $out, true ) ) {
				$out[] = $map[ $p['slug'] ];
			}
		}
		return $out ? $out : array( 'none' );
	};

	list( $seo, $seo_p ) = $first_match( array(
		'wordpress-seo' => 'yoast', 'wordpress-seo-premium' => 'yoast',
		'seo-by-rank-math' => 'rankmath', 'all-in-one-seo-pack' => 'aioseo',
		'aioseo-pro' => 'aioseo', 'wp-seopress' => 'seopress', 'wp-seopress-pro' => 'seopress',
	) );

	$builder_map = array(
		'elementor' => 'elementor', 'elementor-pro' => 'elementor',
		'divi-builder' => 'divi', 'js_composer' => 'wpbakery',
		'beaver-builder-lite-version' => 'beaver', 'bb-plugin' => 'beaver',
	);
	$builders = $all_matches( $builder_map );
	$theme    = wp_get_theme();
	if ( in_array( strtolower( (string) $theme->get_template() ), array( 'divi', 'extra' ), true )
		&& ! in_array( 'divi', $builders, true ) ) {
		$builders   = array_diff( $builders, array( 'none' ) );
		$builders[] = 'divi';
		$builders   = array_values( $builders );
	}

	// --- redirects: SiteBridge first, then known plugins, then suspects -------
	$sb_rules  = get_option( SITEBRIDGE_REDIRECTS_OPTION, array() );
	$handler   = 'none_detected';
	$rules     = 0;
	$handled_slug = null;
	if ( is_array( $sb_rules ) && count( $sb_rules ) > 0 ) {
		$handler = 'sitebridge';
		$rules   = count( $sb_rules );
	} elseif ( sitebridge_cap_plugin_active( 'redirection' ) ) {
		$handler      = 'redirection';
		$handled_slug = 'redirection';
		$table        = $wpdb->prefix . 'redirection_items';
		$exists       = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$rules        = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ) : 0;
	} elseif ( sitebridge_cap_plugin_active( 'wordpress-seo-premium' ) ) {
		$handler      = 'yoast_premium';
		$handled_slug = 'wordpress-seo-premium';
		$yr           = get_option( 'wpseo-premium-redirects-base', array() );
		$rules        = is_array( $yr ) ? count( $yr ) : 0;
	} elseif ( sitebridge_cap_plugin_active( 'safe-redirect-manager' ) ) {
		$handler      = 'safe_redirect';
		$handled_slug = 'safe-redirect-manager';
		$counts       = wp_count_posts( 'redirect_rule' );
		$rules        = isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	// Suspect scan: redirect-ish slugs/names not already classified. A hidden
	// redirect plugin is a proven fleet failure mode — an unknown handler must
	// surface here, never collapse into none_detected.
	$suspects = array();
	foreach ( $active as $p ) {
		if ( $p['slug'] === $handled_slug || $p['slug'] === 'sitebridge-ai' ) {
			continue;
		}
		$why = '';
		if ( preg_match( '/redirect|301|url.?rewrit/i', $p['slug'] ) ) {
			$why = 'slug matches redirect pattern';
		} elseif ( preg_match( '/redirect|301|url.?rewrit/i', $p['name'] ) ) {
			$why = 'name matches redirect pattern';
		}
		if ( $why ) {
			$suspects[] = array( 'slug' => $p['slug'], 'name' => $p['name'], 'match_reason' => $why );
		}
	}

	// --- ACF ------------------------------------------------------------------
	$acf = array( 'present' => false, 'version' => '', 'pro' => false, 'field_group_count' => 0, 'local_json' => false );
	if ( function_exists( 'acf_get_field_groups' ) ) {
		$acf['present'] = true;
		$acf['version'] = defined( 'ACF_VERSION' ) ? ACF_VERSION : '';
		$acf['pro']     = class_exists( 'acf_pro' ) || defined( 'ACF_PRO' );
		$groups         = (array) acf_get_field_groups();
		$acf['field_group_count'] = count( $groups );
		if ( function_exists( 'acf_get_setting' ) ) {
			$json = acf_get_setting( 'load_json' );
			$acf['local_json'] = is_array( $json ) && count( array_filter( (array) $json, function ( $d ) {
				return is_string( $d ) && is_dir( $d ) && glob( trailingslashit( $d ) . '*.json' );
			} ) ) > 0;
		}
	}

	return array(
		'active'     => array_map( function ( $p ) {
			return array( 'slug' => $p['slug'], 'name' => $p['name'], 'version' => $p['version'] );
		}, $active ),
		'sitebridge' => array( 'present' => true, 'version' => SITEBRIDGE_VERSION ),
		'seo'        => array( 'detected' => $seo ? $seo : 'none', 'version' => $seo_p ? $seo_p['version'] : '' ),
		'acf'        => $acf,
		'builders'   => $builders,
		'redirects'  => array(
			'handler'                     => $handler,
			'rule_count'                  => $rules,
			'suspect_plugins'             => $suspects,
			'host_level_rules_detectable' => false, // WPE/Kinsta portal rules are edge-evaluated — invisible from WP.
		),
		'cache'        => $all_matches( array(
			'nitropack' => 'nitropack', 'wp-rocket' => 'wprocket', 'w3-total-cache' => 'w3tc',
			'cloudflare' => 'cloudflare_plugin', 'wp-super-cache' => 'wpsupercache',
			'litespeed-cache' => 'litespeed', 'sg-cachepress' => 'sg_optimizer', 'breeze' => 'breeze',
		) ),
		'security'     => $all_matches( array(
			'wordfence' => 'wordfence', 'better-wp-security' => 'ithemes', 'sucuri-scanner' => 'sucuri',
		) ),
		'multilingual' => $all_matches( array(
			'sitepress-multilingual-cms' => 'wpml', 'polylang' => 'polylang', 'polylang-pro' => 'polylang',
		) ),
	);
}

/* ----------------------------------------------------------------- hosting -- */

function sitebridge_cap_hosting( $deadline ) {
	$signals = array();
	$mu      = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : ( WP_CONTENT_DIR . '/mu-plugins' );
	if ( defined( 'WPE_APIKEY' ) || function_exists( 'wpe_param' ) )      { $signals['wpengine'][] = 'WPE constant/function'; }
	if ( is_dir( $mu . '/wpengine-common' ) )                             { $signals['wpengine'][] = 'mu-plugin: wpengine-common'; }
	if ( defined( 'KINSTAMU_VERSION' ) )                                  { $signals['kinsta'][] = 'KINSTAMU_VERSION'; }
	if ( is_dir( $mu . '/kinsta-mu-plugins' ) )                           { $signals['kinsta'][] = 'mu-plugin: kinsta-mu-plugins'; }
	if ( defined( 'FLYWHEEL_CONFIG_DIR' ) || defined( 'FLYWHEEL_PLUGIN_DIR' ) ) { $signals['flywheel'][] = 'FLYWHEEL constant'; }
	if ( is_dir( WP_CONTENT_DIR . '/.fw-config' ) )                       { $signals['flywheel'][] = 'dropin: .fw-config'; }

	$provider = 'unknown';
	$flat     = array();
	foreach ( $signals as $host => $sigs ) {
		if ( $provider === 'unknown' ) {
			$provider = $host;
		}
		foreach ( $sigs as $s ) {
			$flat[] = $host . ': ' . $s;
		}
	}
	return array( 'provider_detected' => $provider, 'signals' => $flat );
}

/* ---------------------------------------------------- ACF field-type maps  -- */

// Walk registered field groups once and map field NAME → type, split into
// simple content types (wysiwyg/textarea) and complex structures
// (repeater/flexible_content/group). Local-JSON groups register on init, so
// this sees them. NEVER uses get_field() — value retrieval runs theme filters
// that can fatal (culligan-v4's decrypt filter is the proven case).
function sitebridge_cap_acf_field_maps() {
	static $maps = null;
	if ( $maps !== null ) {
		return $maps;
	}
	$maps = array( 'simple' => array(), 'complex' => array(), 'groups' => array() );
	if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
		return $maps;
	}
	$walk = function ( $fields ) use ( &$walk, &$maps ) {
		foreach ( (array) $fields as $f ) {
			if ( empty( $f['name'] ) || empty( $f['type'] ) ) {
				continue;
			}
			if ( in_array( $f['type'], array( 'wysiwyg', 'textarea' ), true ) ) {
				$maps['simple'][ $f['name'] ] = $f['type'];
			} elseif ( in_array( $f['type'], array( 'repeater', 'flexible_content', 'group' ), true ) ) {
				$maps['complex'][ $f['name'] ] = $f['type'];
			}
			if ( ! empty( $f['sub_fields'] ) ) {
				$walk( $f['sub_fields'] );
			}
			if ( ! empty( $f['layouts'] ) ) {
				foreach ( (array) $f['layouts'] as $layout ) {
					if ( ! empty( $layout['sub_fields'] ) ) {
						$walk( $layout['sub_fields'] );
					}
				}
			}
		}
	};
	foreach ( (array) acf_get_field_groups() as $g ) {
		$fields = (array) acf_get_fields( $g );
		$walk( $fields );
		$types = array();
		$scan  = function ( $fs ) use ( &$scan, &$types ) {
			foreach ( (array) $fs as $f ) {
				if ( ! empty( $f['type'] ) && in_array( $f['type'], array( 'wysiwyg', 'textarea', 'flexible_content', 'repeater' ), true )
					&& ! in_array( $f['type'], $types, true ) ) {
					$types[] = $f['type'];
				}
				if ( ! empty( $f['sub_fields'] ) ) { $scan( $f['sub_fields'] ); }
			}
		};
		$scan( $fields );
		$loc = array();
		foreach ( (array) ( isset( $g['location'] ) ? $g['location'] : array() ) as $rule_group ) {
			$parts = array();
			foreach ( (array) $rule_group as $rule ) {
				if ( isset( $rule['param'], $rule['operator'], $rule['value'] ) ) {
					$parts[] = $rule['param'] . ' ' . $rule['operator'] . ' ' . $rule['value'];
				}
			}
			if ( $parts ) { $loc[] = implode( ' AND ', $parts ); }
		}
		$maps['groups'][] = array(
			'key'                    => isset( $g['key'] ) ? $g['key'] : '',
			'title'                  => isset( $g['title'] ) ? $g['title'] : '',
			'location_rules_summary' => implode( ' OR ', $loc ),
			'content_field_types'    => $types,
			'_location_raw'          => isset( $g['location'] ) ? $g['location'] : array(),
		);
	}
	return $maps;
}

/* ----------------------------------------------------------- content model -- */

function sitebridge_cap_content_model( $deadline ) {
	global $wpdb;
	$maps  = sitebridge_cap_acf_field_maps();
	$types = get_post_types( array( 'public' => true ), 'objects' );
	unset( $types['attachment'] );

	$out_types = array();
	foreach ( $types as $t ) {
		if ( microtime( true ) > $deadline ) {
			break;
		}
		$counts  = wp_count_posts( $t->name );
		$count   = isset( $counts->publish ) ? (int) $counts->publish : 0;
		$ids     = get_posts( array(
			'post_type'      => $t->name,
			'post_status'    => 'publish',
			'numberposts'    => 25,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
		$votes   = array( 'post_content' => 0, 'acf_simple' => 0, 'acf_complex' => 0, 'mixed' => 0, 'builder' => 0, 'empty' => 0 );
		$complex_seen = array();
		foreach ( $ids as $id ) {
			$post    = get_post( $id );
			$content = $post ? trim( (string) $post->post_content ) : '';
			$meta    = (array) get_post_meta( $id );

			$has_builder = (bool) preg_match( '/\[et_pb_|\[vc_row|<!-- wp:elementor|<!-- wp:divi/', $content )
				|| ( isset( $meta['_elementor_data'][0] ) && strlen( (string) $meta['_elementor_data'][0] ) > 10 );

			$has_simple  = false;
			foreach ( $maps['simple'] as $name => $type ) {
				if ( ! empty( $meta[ $name ][0] ) ) { $has_simple = true; break; }
			}
			$has_complex = false;
			foreach ( $maps['complex'] as $name => $type ) {
				// Repeater/flexible storage: the value row is the row COUNT (or
				// layout list); non-empty / >0 means real rows exist.
				if ( isset( $meta[ $name ][0] ) && $meta[ $name ][0] !== '' && $meta[ $name ][0] !== '0' ) {
					$has_complex = true;
					if ( ! in_array( $maps['complex'][ $name ], $complex_seen, true ) ) {
						$complex_seen[] = $maps['complex'][ $name ];
					}
				}
			}

			$len = strlen( $content );
			if ( $has_builder ) {
				$votes['builder']++;
			} elseif ( $len > 200 && ( $has_simple || $has_complex ) ) {
				$votes['mixed']++;
			} elseif ( $has_complex ) {
				$votes['acf_complex']++;
			} elseif ( $has_simple ) {
				$votes['acf_simple']++;
			} elseif ( $len > 0 ) {
				$votes['post_content']++;
			} else {
				$votes['empty']++;
			}
		}
		$sampled = count( $ids );
		arsort( $votes );
		reset( $votes );
		$label      = $sampled ? key( $votes ) : 'empty';
		$confidence = $sampled ? round( $votes[ $label ] / $sampled, 2 ) : 0.0;

		$out_types[] = array(
			'name'                   => $t->name,
			'rest_base'              => ! empty( $t->rest_base ) ? $t->rest_base : $t->name,
			'label'                  => $t->label,
			'public'                 => true,
			'count'                  => $count,
			'body_storage'           => $label,
			'acf_complex_types_seen' => $complex_seen,
			'storage_confidence'     => $confidence,
			'sample_size'            => $sampled,
		);
	}

	// Published pages with zero-length content — proven to masquerade as a
	// homepage redirect (reads as a mystery 301 in crawls).
	$empty_count = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND TRIM(post_content) = ''"
	);
	$empty_ids = array_map( 'intval', (array) $wpdb->get_col(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND TRIM(post_content) = '' ORDER BY ID ASC LIMIT 10"
	) );

	// Any ACF block JSON in sampled content? (search_replace escaped-quote trap)
	$acf_block = (bool) $wpdb->get_var(
		"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE '%<!-- wp:acf/%' LIMIT 1"
	);

	$groups = array();
	foreach ( $maps['groups'] as $g ) {
		unset( $g['_location_raw'] );
		$groups[] = $g;
	}

	return array(
		'post_type_naming'      => array( 'slug_convention' => 'post_type_name', 'also_emitted' => 'rest_base' ),
		'post_types'            => $out_types,
		'empty_published_pages' => array( 'count' => $empty_count, 'sample_ids' => $empty_ids ),
		'acf_block_json_present' => $acf_block,
		'acf_field_groups'      => $groups,
	);
}

/* ----------------------------------------------------------- schema output -- */

function sitebridge_cap_parse_jsonld( $html ) {
	$blocks = array();
	if ( preg_match_all( '#<script\b[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', (string) $html, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $hit ) {
			$blocks[] = array( 'tag' => $hit[0], 'json' => trim( $hit[1] ) );
		}
	}
	return $blocks;
}

function sitebridge_cap_schema_output( $deadline ) {
	// Primary: output-buffer wp_head in a controlled context — immune to
	// page-cache rewrites (cached HTML can move/rewrite inline JSON-LD).
	$html   = '';
	$method = 'output_buffer';
	$level  = ob_get_level();
	try {
		ob_start();
		do_action( 'wp_head' );
		$html = (string) ob_get_clean();
	} catch ( Throwable $e ) {
		$html = '';
	}
	// Drop only buffers we opened; pre-existing ones stay.
	while ( ob_get_level() > $level ) {
		ob_end_clean();
	}
	$blocks = sitebridge_cap_parse_jsonld( $html );

	// Zero blocks from the buffer is ambiguous — either the site truly emits
	// none, or emitters bailed outside a real front-end query. The cache-busted
	// self-fetch settles it.
	if ( ! $blocks ) {
		$url  = add_query_arg( 'sb_cap_nocache', (string) time(), home_url( '/' ) );
		$resp = wp_remote_get( $url, array(
			'timeout'   => 10,
			'headers'   => array( 'Cache-Control' => 'no-cache', 'Pragma' => 'no-cache', 'User-Agent' => 'SiteBridge-Capability/1.0' ),
			'sslverify' => true,
		) );
		if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
			$body   = wp_remote_retrieve_body( $resp );
			$head   = ( preg_match( '#^(.*?)</head>#is', $body, $hm ) ) ? $hm[1] : $body;
			$blocks = sitebridge_cap_parse_jsonld( $head );
			$html   = $head;
			$method = 'self_fetch_cachebusted';
		}
	}

	$emitters = array();
	$sb_found = ( strpos( $html, 'sitebridge-schema' ) !== false ) || ( strpos( $html, 'bam-schema' ) !== false );
	if ( $sb_found ) { $emitters[] = 'sitebridge'; }
	if ( strpos( $html, 'yoast-schema-graph' ) !== false )        { $emitters[] = 'yoast'; }
	if ( stripos( $html, 'saswp' ) !== false )                    { $emitters[] = 'saswp'; }
	if ( preg_match( '/schema[-_ ]?pro/i', $html ) )              { $emitters[] = 'schema_pro'; }
	// Blocks not attributable to a known emitter = theme/inline.
	$attributed = 0;
	foreach ( $blocks as $b ) {
		if ( strpos( $b['tag'], 'sitebridge-schema' ) !== false || strpos( $b['tag'], 'bam-schema' ) !== false
			|| strpos( $b['tag'], 'yoast-schema-graph' ) !== false || stripos( $b['tag'], 'saswp' ) !== false ) {
			$attributed++;
		}
	}
	if ( count( $blocks ) > $attributed ) { $emitters[] = 'theme_inline'; }
	if ( ! $emitters ) { $emitters[] = 'none'; }

	$types = array();
	foreach ( $blocks as $b ) {
		$data = json_decode( $b['json'], true );
		if ( ! is_array( $data ) ) { continue; }
		$nodes = isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : array( $data );
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) { continue; }
			foreach ( (array) $node['@type'] as $tn ) {
				if ( is_string( $tn ) && ! in_array( $tn, $types, true ) && count( $types ) < 20 ) {
					$types[] = $tn;
				}
			}
		}
	}

	return array(
		'emitters_detected'         => $emitters,
		'sitebridge_signature_found' => $sb_found,
		'head_jsonld_block_count'   => count( $blocks ),
		'types_seen'                => $types,
		'fetch_method'              => $method,
	);
}

/* --------------------------------------------------------------------- nav -- */

function sitebridge_cap_nav( $deadline ) {
	global $wpdb;
	$mechanisms = array();
	$menus      = function_exists( 'wp_get_nav_menus' ) ? (array) wp_get_nav_menus() : array();
	if ( count( $menus ) > 0 ) {
		$mechanisms[] = 'wp_menus';
	}

	// Generic ACF options-page nav: a field group located on an options page
	// with a repeater/group/flexible field named like nav/menu — confirmed by
	// raw options rows (existence only; never get_field()). The Culligan
	// mega-menu (main_nav_settings_version_2) is one instance of this.
	$acf_nav = false;
	$maps    = sitebridge_cap_acf_field_maps();
	foreach ( $maps['groups'] as $g ) {
		$on_options = false;
		foreach ( (array) $g['_location_raw'] as $rule_group ) {
			foreach ( (array) $rule_group as $rule ) {
				if ( isset( $rule['param'] ) && $rule['param'] === 'options_page' ) {
					$on_options = true;
				}
			}
		}
		if ( ! $on_options ) { continue; }
		foreach ( $maps['complex'] as $name => $type ) {
			if ( preg_match( '/nav|menu/i', $name ) ) {
				$row = $wpdb->get_var( $wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1",
					'options_' . $wpdb->esc_like( $name ) . '%'
				) );
				if ( $row ) { $acf_nav = true; break 2; }
			}
		}
	}
	// Data probe even when groups aren't discoverable in this context.
	if ( ! $acf_nav ) {
		$row = $wpdb->get_var(
			"SELECT option_name FROM {$wpdb->options}
			 WHERE ( option_name LIKE 'options\\_%nav%' OR option_name LIKE 'options\\_%menu%' )
			 LIMIT 1"
		);
		if ( $row ) { $acf_nav = true; }
	}
	if ( $acf_nav ) {
		$mechanisms[] = 'acf_options_nav';
	}

	// Builder-managed nav: a builder is active and neither classic menus nor
	// ACF nav data exist.
	if ( ! $mechanisms ) {
		foreach ( sitebridge_cap_active_plugins() as $p ) {
			if ( preg_match( '/elementor|divi|js_composer|beaver|bb-plugin/', $p['slug'] ) ) {
				$mechanisms[] = 'builder';
				break;
			}
		}
	}

	return array( 'mechanisms' => $mechanisms, 'menu_count' => count( $menus ) );
}

/* ----------------------------------------------------------------- sitemap -- */

function sitebridge_cap_sitemap( $deadline ) {
	if ( defined( 'WPSEO_VERSION' ) ) {
		$opt     = get_option( 'wpseo', array() );
		$enabled = ! is_array( $opt ) || ! isset( $opt['enable_xml_sitemap'] ) || $opt['enable_xml_sitemap'];
		return array( 'present' => (bool) $enabled, 'source' => 'yoast' );
	}
	if ( function_exists( 'wp_sitemaps_get_server' ) ) {
		return array( 'present' => (bool) apply_filters( 'wp_sitemaps_enabled', true ), 'source' => 'core' );
	}
	return array( 'present' => false, 'source' => 'other' );
}
