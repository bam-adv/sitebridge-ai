<?php
/**
 * Plugin Name: SiteBridge AI
 * Plugin URI:  https://github.com/bam-adv/sitebridge-ai
 * Update URI:  https://github.com/bam-adv/sitebridge-ai
 * Description: Bridges AI tooling (the wp-mcp-hosted connector) to any WordPress site — JSON-LD schema, desktop ACF navigation, and managed redirects, all over REST. Self-updates from GitHub releases.
 * Version:     1.7.2
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

define( 'SITEBRIDGE_VERSION', '1.7.2' );

// --- Self-update source: set this to your GitHub "owner/repo" ----------------
if ( ! defined( 'SITEBRIDGE_GH_REPO' ) ) {
	define( 'SITEBRIDGE_GH_REPO', 'bam-adv/sitebridge-ai' ); // <-- REPLACE with your repo
}

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
		// Prefer an attached release asset (.zip); fall back to the source zipball.
		$package = '';
		if ( ! empty( $release->assets ) && ! empty( $release->assets[0]->browser_download_url ) ) {
			$package = $release->assets[0]->browser_download_url;
		} elseif ( ! empty( $release->zipball_url ) ) {
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

function sitebridge_redirects_all() {
	$r = get_option( SITEBRIDGE_REDIRECTS_OPTION, array() );
	return is_array( $r ) ? $r : array();
}

/** Shared add/update logic (used by REST and the admin page). Returns the entry. */
function sitebridge_redirects_save( $source, $target, $type = 301 ) {
	$type = in_array( (int) $type, array( 301, 302, 307, 308 ), true ) ? (int) $type : 301;
	$redirects = sitebridge_redirects_all();
	$key   = sitebridge_redirects_norm_path( $source );
	$entry = array( 'source' => $source, 'target' => $target, 'type' => $type, 'created' => current_time( 'mysql' ) );
	$replaced = false;
	foreach ( $redirects as $i => $r ) {
		if ( sitebridge_redirects_norm_path( isset( $r['source'] ) ? $r['source'] : '' ) === $key ) {
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
	$key    = sitebridge_redirects_norm_path( $source );
	$before = count( $redirects );
	$redirects = array_values( array_filter( $redirects, function ( $r ) use ( $key ) {
		return sitebridge_redirects_norm_path( isset( $r['source'] ) ? $r['source'] : '' ) !== $key;
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
	$req = sitebridge_redirects_norm_path( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '' );
	foreach ( $redirects as $r ) {
		if ( empty( $r['source'] ) || empty( $r['target'] ) ) {
			continue;
		}
		if ( sitebridge_redirects_norm_path( $r['source'] ) === $req ) {
			$type = in_array( (int) ( isset( $r['type'] ) ? $r['type'] : 301 ), array( 301, 302, 307, 308 ), true ) ? (int) $r['type'] : 301;
			wp_redirect( (string) $r['target'], $type );
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
		$index[ sitebridge_redirects_norm_path( isset( $r['source'] ) ? $r['source'] : '' ) ] = $i;
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
		$key   = sitebridge_redirects_norm_path( $source );
		$entry = array( 'source' => $source, 'target' => $target, 'type' => $type, 'created' => current_time( 'mysql' ) );
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
					<td><input name="source" id="sb_source" type="text" class="regular-text" placeholder="/old-page/" required /></td>
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
