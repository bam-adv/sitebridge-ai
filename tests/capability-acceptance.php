<?php
/**
 * Acceptance harness for the CAPABILITY REPORT route (v1.18.0).
 *
 * No WordPress, no PHPUnit, no network: stubs just enough WP (and ACF) to load
 * sitebridge-ai.php and drive sitebridge_capability_findings_rest() directly.
 *
 * function_exists()/defined() states can't be undone within one PHP process,
 * so each scenario runs as a subprocess selected by SB_CAP_SCENARIO; running
 * this file with no env var set is the runner that spawns them all.
 *
 * Run:  php tests/capability-acceptance.php     (exit 0 = all assertions passed)
 *
 * This covers the LOGIC — including the read-only assertion at the $wpdb/
 * options layer. The live acceptance pass (query log on a real site showing
 * zero writes, Dallas/San Diego pre-audit diffs) is still required.
 */

$scenario = getenv( 'SB_CAP_SCENARIO' );

// ------------------------------------------------------------------ runner --
if ( $scenario === false ) {
	$scenarios = array( 'culligan', 'no-acf', 'builder', 'fallback', 'section-fail' );
	$failed    = array();
	foreach ( $scenarios as $s ) {
		echo "\n\033[1mscenario: $s\033[0m\n";
		passthru(
			'SB_CAP_SCENARIO=' . escapeshellarg( $s ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ),
			$code
		);
		if ( $code !== 0 ) {
			$failed[] = $s;
		}
	}
	if ( $failed ) {
		echo "\n\033[31mFAILED scenarios: " . implode( ', ', $failed ) . "\033[0m\n";
		exit( 1 );
	}
	echo "\n\033[32mAll capability scenarios passed.\033[0m\n";
	exit( 0 );
}

// ---------------------------------------------------------------- WP stubs --
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', __DIR__ );
define( 'WP_PLUGIN_DIR', __DIR__ . '/plugins-not-real' );

function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function remove_filter( ...$a ) {}
function register_rest_route( ...$a ) {}
function register_activation_hook( ...$a ) {}
function plugin_basename( $f ) { return basename( $f ); }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return ''; }
function apply_filters( $tag, $value ) { return $value; }
function do_action( $tag, ...$a ) {
	if ( $tag === 'wp_head' && isset( $GLOBALS['wp_head_html'] ) ) {
		echo $GLOBALS['wp_head_html'];
	}
}
function current_user_can( $c ) { return true; }
function esc_url_raw( $u ) { return $u; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function __( $s, $d = null ) { return $s; }
function _e( $s, $d = null ) { echo $s; }
function is_admin() { return false; }
function home_url( $p = '' ) { return 'https://example.com' . $p; }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
function sanitize_text_field( $str ) { return trim( strip_tags( (string) $str ) ); }
function rest_ensure_response( $d ) { return $d; }
function get_bloginfo( $k ) { return $k === 'version' ? '6.7.1' : ''; }
function is_ssl() { return true; }
function is_multisite() { return false; }
function add_query_arg( $k, $v, $url ) { return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . $k . '=' . $v; }
function wp_remote_retrieve_response_code( $r ) { return isset( $r['response']['code'] ) ? $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return isset( $r['body'] ) ? $r['body'] : ''; }

$GLOBALS['options']      = array();
$GLOBALS['write_calls']  = array();
$GLOBALS['fetched_urls'] = array();
$GLOBALS['fetch_response'] = null;

function get_option( $k, $default = false ) {
	return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $default;
}
function update_option( $k, $v ) { $GLOBALS['write_calls'][] = "update_option:$k"; return true; }
function delete_option( $k ) { $GLOBALS['write_calls'][] = "delete_option:$k"; return true; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['write_calls'][] = "set_transient:$k"; return true; }
function get_transient( $k ) { return false; }

function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['fetched_urls'][] = $url;
	if ( $GLOBALS['fetch_response'] === null ) {
		return new WP_Error( 'http_error', 'no fixture' );
	}
	return $GLOBALS['fetch_response'];
}

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code = $code; $this->message = $message; $this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

class WP_REST_Request implements ArrayAccess {
	private $p;
	public function __construct( array $params = array() ) { $this->p = $params; }
	#[\ReturnTypeWillChange] public function offsetExists( $k ) { return isset( $this->p[ $k ] ); }
	#[\ReturnTypeWillChange] public function offsetGet( $k ) { return array_key_exists( $k, $this->p ) ? $this->p[ $k ] : null; }
	#[\ReturnTypeWillChange] public function offsetSet( $k, $v ) { $this->p[ $k ] = $v; }
	#[\ReturnTypeWillChange] public function offsetUnset( $k ) { unset( $this->p[ $k ] ); }
	public function get_param( $k ) { return $this->offsetGet( $k ); }
}

// ACF value stubs loaded by the nav module at include time (never used by the
// capability collector — that's part of what these scenarios prove).
function get_field( $field, $post_id = false ) { $GLOBALS['get_field_calls'][] = $field; return null; }
function update_field( $field, $value, $post_id = false ) { $GLOBALS['write_calls'][] = "update_field:$field"; return true; }
function get_fields( $post_id = false ) { return array(); }
$GLOBALS['get_field_calls'] = array();

// ---- theme -----------------------------------------------------------------
class SB_Test_Theme {
	public function get( $k ) {
		if ( $k === 'Name' )    { return 'Culligan v4'; }
		if ( $k === 'Version' ) { return '4.2.0'; }
		return '';
	}
	public function parent() { return null; }
	public function get_template() { return 'culligan-v4'; }
}
function wp_get_theme() { return new SB_Test_Theme(); }

// ---- content fixtures ------------------------------------------------------
$GLOBALS['post_types']      = array();
$GLOBALS['fixture_posts']   = array();
$GLOBALS['fixture_content'] = array();
$GLOBALS['fixture_meta']    = array();
$GLOBALS['nav_menus']       = array();
$GLOBALS['block_editor']    = true;

function get_post_types( $args = array(), $output = 'names' ) { return $GLOBALS['post_types']; }
function wp_count_posts( $type ) {
	$n = isset( $GLOBALS['fixture_posts'][ $type ] ) ? count( $GLOBALS['fixture_posts'][ $type ] ) : 0;
	return (object) array( 'publish' => $n );
}
function get_posts( $args ) {
	$t = $args['post_type'];
	return isset( $GLOBALS['fixture_posts'][ $t ] ) ? $GLOBALS['fixture_posts'][ $t ] : array();
}
function get_post( $id ) {
	return (object) array(
		'ID'           => $id,
		'post_content' => isset( $GLOBALS['fixture_content'][ $id ] ) ? $GLOBALS['fixture_content'][ $id ] : '',
	);
}
function get_post_meta( $id, $key = '', $single = false ) {
	return isset( $GLOBALS['fixture_meta'][ $id ] ) ? $GLOBALS['fixture_meta'][ $id ] : array();
}
function wp_get_nav_menus() { return $GLOBALS['nav_menus']; }
function use_block_editor_for_post_type( $t ) { return $GLOBALS['block_editor']; }

// ---- $wpdb -----------------------------------------------------------------
class SB_Test_WPDB {
	public $prefix  = 'wp_';
	public $posts   = 'wp_posts';
	public $options = 'wp_options';
	public $queries = array();
	public $var_map = array(); // substring => value
	public $col_map = array(); // substring => array
	public function prepare( $q, ...$args ) {
		foreach ( $args as $a ) {
			$q = preg_replace( '/%[sd]/', (string) $a, $q, 1 );
		}
		return $q;
	}
	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	public function get_var( $q ) {
		$this->queries[] = $q;
		foreach ( $this->var_map as $pat => $v ) {
			if ( strpos( $q, $pat ) !== false ) { return $v; }
		}
		return null;
	}
	public function get_col( $q ) {
		$this->queries[] = $q;
		foreach ( $this->col_map as $pat => $v ) {
			if ( strpos( $q, $pat ) !== false ) { return $v; }
		}
		return array();
	}
}
$GLOBALS['wpdb'] = new SB_Test_WPDB();

// ------------------------------------------------------- scenario fixtures --

function sb_types( $names ) {
	$out = array();
	foreach ( $names as $name => $rest ) {
		$out[ $name ] = (object) array( 'name' => $name, 'rest_base' => $rest, 'label' => ucfirst( $name ), 'public' => true );
	}
	return $out;
}

switch ( $scenario ) {
	case 'culligan':
	case 'fallback':
	case 'section-fail':
		define( 'ACF_VERSION', '6.8.4' );
		define( 'ACF_PRO', true );
		define( 'WPE_APIKEY', 'not-a-real-key' );
		define( 'WPSEO_VERSION', '23.0' );
		if ( $scenario === 'section-fail' ) {
			function acf_get_field_groups() { throw new RuntimeException( 'ACF exploded' ); }
		} else {
			function acf_get_field_groups() { return $GLOBALS['acf_groups']; }
		}
		function acf_get_fields( $g ) {
			$k = is_array( $g ) ? $g['key'] : $g;
			return isset( $GLOBALS['acf_fields'][ $k ] ) ? $GLOBALS['acf_fields'][ $k ] : array();
		}
		function acf_get_setting( $k ) { return array(); }

		$GLOBALS['acf_groups'] = array(
			array(
				'key'      => 'group_nav',
				'title'    => 'Main Navigation',
				'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'main-nav' ) ) ),
			),
			array(
				'key'      => 'group_hero',
				'title'    => 'Page Hero',
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
			),
		);
		$GLOBALS['acf_fields'] = array(
			'group_nav'  => array(
				array( 'name' => 'main_nav_settings_version_2', 'type' => 'group', 'sub_fields' => array(
					array( 'name' => 'nav_items', 'type' => 'repeater', 'sub_fields' => array(
						array( 'name' => 'nav_item_link', 'type' => 'link' ),
					) ),
				) ),
			),
			'group_hero' => array(
				array( 'name' => 'hero_banner_slides', 'type' => 'repeater', 'sub_fields' => array(
					array( 'name' => 'slide_image', 'type' => 'image' ),
				) ),
				array( 'name' => 'page_intro', 'type' => 'wysiwyg' ),
			),
		);

		$GLOBALS['options']['active_plugins'] = array(
			'wordpress-seo/wp-seo.php',
			'advanced-custom-fields-pro/acf.php',
			'sitebridge-ai/sitebridge-ai.php',
			'quick-301-redirects/quick-301.php',
		);
		$GLOBALS['options']['bam_redirects'] = array(
			array( 'source' => '/a', 'target' => '/b' ),
			array( 'source' => '/c', 'target' => '/d' ),
			array( 'source' => '/e', 'target' => '/f' ),
		);
		$GLOBALS['options']['wpseo'] = array( 'enable_xml_sitemap' => true );

		$GLOBALS['post_types'] = sb_types( array( 'post' => 'posts', 'page' => 'pages', 'problems' => 'problems', 'attachment' => 'media' ) );
		$GLOBALS['fixture_posts'] = array(
			'post'     => array( 1, 2 ),
			'page'     => array( 10, 11 ),
			'problems' => array( 20 ),
		);
		$long = str_repeat( 'Real editorial content. ', 30 );
		$GLOBALS['fixture_content'] = array( 1 => $long, 2 => $long, 10 => '', 11 => '', 20 => $long );
		$GLOBALS['fixture_meta'] = array(
			10 => array( 'hero_banner_slides' => array( '2' ) ),
			11 => array( 'hero_banner_slides' => array( '1' ), 'page_intro' => array( '<p>intro</p>' ) ),
		);
		$GLOBALS['nav_menus'] = array( (object) array( 'term_id' => 5 ), (object) array( 'term_id' => 6 ) );

		$GLOBALS['wpdb']->var_map = array(
			'COUNT(*)'                                => 2,
			// prepared query carries esc_like()-escaped underscores
			'options_main\\_nav\\_settings\\_version' => 'options_main_nav_settings_version_2_nav_items',
		);
		$GLOBALS['wpdb']->col_map = array( 'SELECT ID FROM' => array( 1486, 999 ) );

		if ( $scenario === 'fallback' ) {
			$GLOBALS['wp_head_html']   = '<meta charset="utf-8">'; // no JSON-LD from the buffer
			$GLOBALS['fetch_response'] = array(
				'response' => array( 'code' => 200 ),
				'body'     => '<html><head><script type="application/ld+json">{"@type":"Organization"}</script></head><body>x</body></html>',
			);
		} else {
			$GLOBALS['wp_head_html'] = '<script class="sitebridge-schema" type="application/ld+json">'
				. '{"@context":"https://schema.org","@graph":[{"@type":"Organization"},{"@type":"LocalBusiness"}]}'
				. '</script>';
		}
		break;

	case 'no-acf':
		$GLOBALS['options']['active_plugins'] = array( 'sitebridge-ai/sitebridge-ai.php' );
		$GLOBALS['post_types']    = sb_types( array( 'post' => 'posts', 'page' => 'pages' ) );
		$GLOBALS['fixture_posts'] = array( 'post' => array( 1 ), 'page' => array( 10 ) );
		$GLOBALS['fixture_content'] = array( 1 => 'Plain post body text here.', 10 => 'Plain page body text here.' );
		$GLOBALS['nav_menus']    = array();
		$GLOBALS['wp_head_html'] = '';
		break;

	case 'builder':
		$GLOBALS['options']['active_plugins'] = array( 'elementor/elementor.php', 'sitebridge-ai/sitebridge-ai.php' );
		$GLOBALS['post_types']    = sb_types( array( 'page' => 'pages' ) );
		$GLOBALS['fixture_posts'] = array( 'page' => array( 10, 11 ) );
		$GLOBALS['fixture_content'] = array( 10 => '', 11 => '' );
		$GLOBALS['fixture_meta'] = array(
			10 => array( '_elementor_data' => array( '[{"id":"abc","elType":"section","elements":[]}]' ) ),
			11 => array( '_elementor_data' => array( '[{"id":"def","elType":"section","elements":[]}]' ) ),
		);
		$GLOBALS['nav_menus']    = array();
		$GLOBALS['wp_head_html'] = '';
		break;
}

// ------------------------------------------------------------ load & drive --
require dirname( __DIR__ ) . '/sitebridge-ai.php';

$pass = 0;
$fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  \033[32mok\033[0m   $label\n"; }
	else         { $fail++; echo "  \033[31mFAIL\033[0m $label\n"; }
}
function section( $t ) { echo "\n\033[1m$t\033[0m\n"; }

$f = sitebridge_capability_findings_rest( new WP_REST_Request() );

// Universal assertions — every scenario is read-only and returns a payload.
section( 'read-only + envelope' );
ok( is_array( $f ), 'route returns findings array' );
ok( $f['schema_version'] === '1.0', 'schema_version 1.0' );
ok( $f['site']['url'] === 'https://example.com/', 'site url' );
ok( $GLOBALS['write_calls'] === array(), 'no update_option/set_transient/update_field calls' );
$writes = array_filter( $GLOBALS['wpdb']->queries, function ( $q ) {
	return preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i', $q );
} );
ok( $writes === array(), 'no write-verb SQL issued' );
ok( $GLOBALS['get_field_calls'] === array(), 'collector never calls get_field()' );
ok( $f['read_only_attestation']['writes_performed'] === 0, 'attestation reports zero writes' );

switch ( $scenario ) {
	case 'culligan':
		section( 'collection status' );
		ok( $f['collection_status']['complete'] === true, 'complete' );
		ok( $f['collection_status']['failed_sections'] === array(), 'no failed sections' );

		section( 'environment / editor' );
		ok( $f['environment']['wp_version'] === '6.7.1', 'wp version' );
		ok( $f['environment']['https'] === true, 'https' );
		ok( $f['environment']['theme']['name'] === 'Culligan v4', 'theme name' );
		ok( $f['editor']['gutenberg_available'] === true, 'gutenberg available' );
		ok( $f['editor']['classic_editor_plugin'] === false, 'no classic editor plugin' );
		ok( $f['editor']['default_editor'] === 'block', 'default editor block' );

		section( 'plugins' );
		ok( $f['plugins']['seo']['detected'] === 'yoast', 'seo yoast' );
		ok( $f['plugins']['sitebridge']['version'] === SITEBRIDGE_VERSION, 'sitebridge version' );
		ok( $f['plugins']['acf']['present'] === true && $f['plugins']['acf']['pro'] === true, 'acf pro present' );
		ok( $f['plugins']['acf']['field_group_count'] === 2, 'field group count' );
		ok( $f['plugins']['builders'] === array( 'none' ), 'no builders' );
		ok( $f['plugins']['redirects']['handler'] === 'sitebridge', 'redirect handler sitebridge' );
		ok( $f['plugins']['redirects']['rule_count'] === 3, 'redirect rule count' );
		ok( count( $f['plugins']['redirects']['suspect_plugins'] ) === 1
			&& $f['plugins']['redirects']['suspect_plugins'][0]['slug'] === 'quick-301-redirects',
			'hidden redirect plugin surfaces as suspect' );
		ok( $f['plugins']['redirects']['host_level_rules_detectable'] === false, 'host-level rules flagged undetectable' );

		section( 'hosting / nav / sitemap' );
		ok( $f['hosting']['provider_detected'] === 'wpengine', 'hosting wpengine' );
		ok( in_array( 'wp_menus', $f['nav']['mechanisms'], true )
			&& in_array( 'acf_options_nav', $f['nav']['mechanisms'], true ),
			'nav mechanisms: wp_menus + acf_options_nav' );
		ok( $f['nav']['menu_count'] === 2, 'menu count' );
		ok( $f['sitemap'] === array( 'present' => true, 'source' => 'yoast' ), 'sitemap yoast' );

		section( 'content model' );
		$by = array();
		foreach ( $f['content_model']['post_types'] as $t ) { $by[ $t['name'] ] = $t; }
		ok( ! isset( $by['attachment'] ), 'attachment excluded' );
		ok( $by['post']['body_storage'] === 'post_content' && $by['post']['storage_confidence'] === 1.0, 'posts: post_content @ 1.0' );
		ok( $by['post']['rest_base'] === 'posts' && $by['post']['name'] === 'post', 'both naming conventions emitted' );
		ok( $by['page']['body_storage'] === 'acf_complex', 'pages: acf_complex' );
		ok( in_array( 'repeater', $by['page']['acf_complex_types_seen'], true ), 'repeater seen on pages' );
		ok( $by['problems']['body_storage'] === 'post_content', 'problems: post_content' );
		ok( $f['content_model']['empty_published_pages'] === array( 'count' => 2, 'sample_ids' => array( 1486, 999 ) ),
			'empty published pages counted with samples' );
		ok( $f['content_model']['acf_block_json_present'] === false, 'no acf block json' );
		$g0 = $f['content_model']['acf_field_groups'][0];
		ok( $g0['location_rules_summary'] === 'options_page == main-nav', 'location summary rendered' );
		ok( ! array_key_exists( '_location_raw', $g0 ), 'raw location rules not leaked' );

		section( 'schema output' );
		ok( $f['schema_output']['fetch_method'] === 'output_buffer', 'buffer method used' );
		ok( $f['schema_output']['emitters_detected'] === array( 'sitebridge' ), 'sitebridge sole emitter' );
		ok( $f['schema_output']['sitebridge_signature_found'] === true, 'sitebridge signature' );
		ok( $f['schema_output']['head_jsonld_block_count'] === 1, 'one JSON-LD graph' );
		ok( in_array( 'LocalBusiness', $f['schema_output']['types_seen'], true ), 'LocalBusiness type seen' );
		ok( $GLOBALS['fetched_urls'] === array(), 'no network fetch when buffer succeeds' );
		break;

	case 'no-acf':
		section( 'no ACF installed' );
		ok( $f['collection_status']['complete'] === true, 'complete' );
		ok( $f['plugins']['acf']['present'] === false, 'acf absent' );
		ok( $f['plugins']['seo']['detected'] === 'none', 'no seo plugin' );
		$by = array();
		foreach ( $f['content_model']['post_types'] as $t ) { $by[ $t['name'] ] = $t; }
		ok( $by['page']['body_storage'] === 'post_content', 'pages classify post_content without acf' );
		ok( $f['content_model']['acf_field_groups'] === array(), 'no field groups' );
		ok( $f['nav']['mechanisms'] === array(), 'no nav mechanisms detected' );
		ok( $f['sitemap']['source'] === 'other' && $f['sitemap']['present'] === false, 'no sitemap detected' );
		break;

	case 'builder':
		section( 'builder site' );
		ok( $f['plugins']['builders'] === array( 'elementor' ), 'elementor detected' );
		$by = array();
		foreach ( $f['content_model']['post_types'] as $t ) { $by[ $t['name'] ] = $t; }
		ok( $by['page']['body_storage'] === 'builder', 'pages classify builder via _elementor_data' );
		ok( $by['page']['storage_confidence'] === 1.0, 'builder confidence 1.0' );
		ok( $f['nav']['mechanisms'] === array( 'builder' ), 'nav builder-managed' );
		break;

	case 'fallback':
		section( 'schema fetch fallback' );
		ok( $f['schema_output']['fetch_method'] === 'self_fetch_cachebusted', 'fell back to self-fetch' );
		ok( count( $GLOBALS['fetched_urls'] ) === 1 && strpos( $GLOBALS['fetched_urls'][0], 'sb_cap_nocache=' ) !== false,
			'self-fetch carries cache-buster' );
		ok( $f['schema_output']['head_jsonld_block_count'] === 1, 'block found in fetched head' );
		ok( in_array( 'Organization', $f['schema_output']['types_seen'], true ), 'type parsed from fetched page' );
		ok( $f['schema_output']['emitters_detected'] === array( 'theme_inline' ), 'unattributed block = theme_inline' );
		break;

	case 'section-fail':
		section( 'partial failure isolation' );
		ok( $f['collection_status']['complete'] === false, 'run marked incomplete' );
		ok( in_array( 'plugins', $f['collection_status']['failed_sections'], true ), 'plugins section failed' );
		ok( in_array( 'nav', $f['collection_status']['failed_sections'], true ), 'nav section failed' );
		ok( isset( $f['environment']['wp_version'] ), 'environment still collected' );
		ok( isset( $f['content_model']['post_types'] ), 'content_model still collected (cached empty maps)' );
		ok( isset( $f['schema_output']['emitters_detected'] ), 'schema_output still collected' );
		ok( $f['read_only_attestation']['writes_performed'] === 0, 'attestation intact on partial failure' );
		$notes = implode( ' ', $f['collection_status']['notes'] );
		ok( strpos( $notes, 'ACF exploded' ) !== false, 'failure reason surfaced in notes' );
		break;
}

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
