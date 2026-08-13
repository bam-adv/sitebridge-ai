<?php
/**
 * Acceptance harness for the ACF-FIELDS route (v1.17.0).
 *
 * No WordPress, no ACF, no PHPUnit, no network: stubs enough WP to load
 * sitebridge-ai.php, plus an ACF storage emulation faithful to how ACF PRO
 * actually persists fields — value rows by name, "_"-prefixed reference rows
 * holding the field KEY, repeaters as a count row + "{name}_{i}_{sub}" rows.
 * update_field() here writes exactly what a WP-admin save writes, which is the
 * behavior the route depends on; the composite-key corruption scenarios seed
 * the damage the ACF REST clone path leaves behind.
 *
 * Run:  php tests/acf-fields-acceptance.php     (exit 0 = all assertions passed)
 *
 * This covers the LOGIC. The live acceptance pass — a meta-box hero page whose
 * badges render on the front end after a route write, no WP-admin re-save — is
 * still required.
 */

// ---------------------------------------------------------------- WP stubs --
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', __DIR__ );
define( 'SITEBRIDGE_NAV_OPTION_ID', 'option' );

function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function register_rest_route( ...$a ) {}
function register_activation_hook( ...$a ) {}
function register_deactivation_hook( ...$a ) {}
function plugin_basename( $f ) { return basename( $f ); }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return ''; }
function apply_filters( $tag, $value ) { return $value; }
function do_action( ...$a ) {}
function current_user_can( $c ) { return true; }
function esc_url_raw( $u ) { return $u; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function __( $s, $d = null ) { return $s; }
function _e( $s, $d = null ) { echo $s; }
function is_admin() { return false; }
function get_option( $k, $default = false ) { return $default; }
function update_option( $k, $v ) { return true; }
function delete_option( $k ) { return true; }
function home_url( $p = '' ) { return 'https://example.com' . $p; }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
function sanitize_text_field( $str ) {
	$str = (string) $str;
	$str = strip_tags( $str );
	$str = preg_replace( '/[\r\n\t ]+/u', ' ', $str );
	$str = preg_replace( '/%[a-f0-9]{2}/i', '', $str );
	return trim( $str );
}
function clean_post_cache( $id ) {}
function get_permalink( $id ) { return 'https://example.com/?p=' . $id; }
function wp_cache_flush() {}
function wp_using_ext_object_cache() { return false; }

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
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

// --------------------------------------------------- emulated postmeta store --
// $GLOBALS['meta_rows'] = array of (object) { meta_id, post_id, meta_key, meta_value }
$GLOBALS['meta_rows']    = array();
$GLOBALS['next_meta_id'] = 1;
$GLOBALS['post_content'] = '';

function meta_reset( array $seed = array(), $content = '' ) {
	$GLOBALS['meta_rows']    = array();
	$GLOBALS['next_meta_id'] = 1;
	$GLOBALS['post_content'] = $content;
	foreach ( $seed as $k => $v ) {
		meta_set( $k, $v );
	}
}
function meta_set( $key, $value ) {
	foreach ( $GLOBALS['meta_rows'] as $row ) {
		if ( $row->meta_key === $key ) {
			$row->meta_value = (string) $value;
			return;
		}
	}
	$GLOBALS['meta_rows'][] = (object) array(
		'meta_id'    => $GLOBALS['next_meta_id']++,
		'post_id'    => 42,
		'meta_key'   => $key,
		'meta_value' => (string) $value,
	);
}
function meta_get( $key ) {
	foreach ( $GLOBALS['meta_rows'] as $row ) {
		if ( $row->meta_key === $key ) {
			return $row->meta_value;
		}
	}
	return null; // row absent (distinct from '')
}
function meta_snapshot() {
	$s = array();
	foreach ( $GLOBALS['meta_rows'] as $row ) {
		$s[ $row->meta_key ] = $row->meta_value;
	}
	ksort( $s );
	return $s;
}

function get_post( $id ) {
	if ( (int) $id !== 42 ) {
		return null;
	}
	return (object) array( 'ID' => 42, 'post_content' => $GLOBALS['post_content'] );
}
function get_post_meta( $post_id, $key, $single = false ) {
	$v = meta_get( $key );
	return ( $v === null ) ? '' : $v;
}
function update_metadata_by_mid( $type, $mid, $value ) {
	foreach ( $GLOBALS['meta_rows'] as $row ) {
		if ( $row->meta_id === $mid ) {
			$row->meta_value = (string) $value;
			return true;
		}
	}
	return false;
}

// ------------------------------------------------------------- $wpdb stub ---
// Supports exactly the three query shapes the route issues: the corrupted-
// reference SELECT, the stale-row DELETE, and the post_content get_var.
function sql_like_to_regex( $pattern ) {
	// Unescape SQL-LIKE escapes, then translate wildcards.
	$regex = '';
	$len   = strlen( $pattern );
	for ( $i = 0; $i < $len; $i++ ) {
		$c = $pattern[ $i ];
		if ( $c === '\\' && $i + 1 < $len ) {
			$regex .= preg_quote( $pattern[ ++$i ], '/' );
		} elseif ( $c === '%' ) {
			$regex .= '.*';
		} elseif ( $c === '_' ) {
			$regex .= '.';
		} else {
			$regex .= preg_quote( $c, '/' );
		}
	}
	return '/^' . $regex . '$/s';
}

class FakeWpdb {
	public $postmeta = 'wp_postmeta';
	public $posts    = 'wp_posts';
	public $last_args;
	public function prepare( $sql, ...$args ) {
		// Keep sql + args separate; our executors parse args positionally.
		$this->last_args = $args;
		return array( 'sql' => $sql, 'args' => $args );
	}
	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	public function get_results( $q ) {
		// SELECT meta_id, meta_key, meta_value ... WHERE post_id=%d AND meta_key LIKE %s AND meta_value LIKE %s
		list( , $key_like, $value_like ) = array( $q['args'][0], $q['args'][1], $q['args'][2] );
		$key_re   = sql_like_to_regex( $key_like );
		$value_re = sql_like_to_regex( $value_like );
		$out      = array();
		foreach ( $GLOBALS['meta_rows'] as $row ) {
			if ( preg_match( $key_re, $row->meta_key ) && preg_match( $value_re, $row->meta_value ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}
	public function query( $q ) {
		// DELETE ... WHERE post_id=%d AND meta_key LIKE %s
		$like = $q['args'][1];
		$re   = sql_like_to_regex( $like );
		$n    = 0;
		foreach ( $GLOBALS['meta_rows'] as $i => $row ) {
			if ( preg_match( $re, $row->meta_key ) ) {
				unset( $GLOBALS['meta_rows'][ $i ] );
				$n++;
			}
		}
		$GLOBALS['meta_rows'] = array_values( $GLOBALS['meta_rows'] );
		return $n;
	}
	public function get_var( $q ) {
		// SELECT post_content FROM wp_posts WHERE ID=%d
		return $GLOBALS['post_content'];
	}
	public function update( ...$a ) { return false; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

// -------------------------------------------------------- ACF emulation -----
// Field registry mirrors the real Culligan v4 meta-box group (real keys).
$GLOBALS['acf_fields'] = array();
function acf_register_test_field( $field ) {
	$GLOBALS['acf_fields'][ $field['key'] ] = $field;
}
acf_register_test_field( array(
	'key'  => 'field_61531e0536f8f',
	'name' => 'show_hero_banner',
	'type' => 'true_false',
) );
acf_register_test_field( array(
	'key'        => 'field_61bce16ca13a6',
	'name'       => 'hero_banner_badges',
	'type'       => 'repeater',
	'sub_fields' => array(
		array( 'key' => 'field_61bce179a13a7', 'name' => 'badge_image', 'type' => 'image' ),
	),
) );
acf_register_test_field( array(
	'key'        => 'field_61379f1853de0',
	'name'       => 'hero_banner_slides',
	'type'       => 'repeater',
	'sub_fields' => array(
		array( 'key' => 'field_61379f3453de1', 'name' => 'headline', 'type' => 'text' ),
	),
) );
// A clone-flattened composite-key field, as ACF's REST layer would resolve it —
// what the route must REFUSE to write through.
acf_register_test_field( array(
	'key'  => 'field_617af16e582f3_field_61531e0536f8f',
	'name' => 'show_hero_banner_composite_alias',
	'type' => 'true_false',
) );

function acf_get_field( $selector ) {
	// Real ACF registers every field — including repeater sub-fields — as an
	// individual entry in its local store, resolvable by key or name.
	$all = array();
	$walk = function ( $fields ) use ( &$walk, &$all ) {
		foreach ( $fields as $f ) {
			$all[] = $f;
			if ( ! empty( $f['sub_fields'] ) ) {
				$walk( $f['sub_fields'] );
			}
		}
	};
	$walk( $GLOBALS['acf_fields'] );
	foreach ( array( 'key', 'name' ) as $prop ) {
		foreach ( $all as $f ) {
			if ( isset( $f[ $prop ] ) && $f[ $prop ] === $selector ) {
				return $f;
			}
		}
	}
	return false;
}

// Faithful to ACF PRO's storage semantics for the types used here: value row
// under the field NAME, reference row "_{name}" = field KEY; repeaters store
// the row count and one row per sub-field per row. A shrinking repeater does
// NOT remove old higher-index rows (that is the route's job).
function update_field( $key, $value, $post_id = false ) {
	$field = acf_get_field( $key );
	if ( ! $field ) {
		return false;
	}
	$name = $field['name'];
	if ( $field['type'] === 'repeater' && is_array( $value ) ) {
		$rows = array_values( $value );
		meta_set( $name, count( $rows ) );
		meta_set( '_' . $name, $field['key'] );
		foreach ( $rows as $i => $row ) {
			foreach ( $field['sub_fields'] as $sub ) {
				$v = null;
				if ( is_array( $row ) && array_key_exists( $sub['name'], $row ) ) {
					$v = $row[ $sub['name'] ];
				} elseif ( is_array( $row ) && array_key_exists( $sub['key'], $row ) ) {
					$v = $row[ $sub['key'] ];
				}
				if ( $v === null ) {
					continue;
				}
				meta_set( $name . '_' . $i . '_' . $sub['name'], $v );
				meta_set( '_' . $name . '_' . $i . '_' . $sub['name'], $sub['key'] );
			}
		}
		return true;
	}
	if ( $field['type'] === 'true_false' ) {
		$value = $value ? 1 : 0;
	}
	meta_set( $name, $value );
	meta_set( '_' . $name, $field['key'] );
	return true;
}
function get_field( $field, $post_id = false ) { return null; }
function get_fields( $post_id = false ) { return array(); }

require_once dirname( __DIR__ ) . '/sitebridge-ai.php';

// ------------------------------------------------------------- test rig -----
$PASS = 0;
$FAIL = 0;
function ok( $cond, $label ) {
	global $PASS, $FAIL;
	if ( $cond ) {
		$PASS++;
		echo "  \033[32mok\033[0m   $label\n";
	} else {
		$FAIL++;
		echo "  \033[31mFAIL\033[0m $label\n";
	}
}
function req( $params = array() ) { return new WP_REST_Request( $params ); }

// The healthy admin-saved state of a 2-badge meta-box hero page.
function healthy_seed() {
	return array(
		'show_hero_banner'                  => '1',
		'_show_hero_banner'                 => 'field_61531e0536f8f',
		'hero_banner_badges'                => '2',
		'_hero_banner_badges'               => 'field_61bce16ca13a6',
		'hero_banner_badges_0_badge_image'  => '4001',
		'_hero_banner_badges_0_badge_image' => 'field_61bce179a13a7',
		'hero_banner_badges_1_badge_image'  => '4225',
		'_hero_banner_badges_1_badge_image' => 'field_61bce179a13a7',
		'hero_banner_slides'                => '1',
		'_hero_banner_slides'               => 'field_61379f1853de0',
		'hero_banner_slides_0_headline'     => 'Salt-Free Water Conditioners',
		'_hero_banner_slides_0_headline'    => 'field_61379f3453de1',
	);
}
$CONTENT = '<!-- wp:paragraph --><p>Body copy, no hero block.</p><!-- /wp:paragraph -->';

// ---------------------------------------------------------------- scenarios --

echo "\n\033[1mscenario: single-badge write (acceptance #1) + verified read-back\033[0m\n";
meta_reset( healthy_seed(), $CONTENT );
$r = sitebridge_acf_fields_rest( req( array(
	'post_id' => 42,
	'fields'  => array( 'hero_banner_badges' => array( array( 'badge_image' => 4419 ) ) ),
) ) );
ok( ! is_wp_error( $r ), 'write succeeds' );
ok( $r['fields']['hero_banner_badges']['field_key'] === 'field_61bce16ca13a6', 'resolved to the REAL repeater key, not the composite' );
ok( meta_get( 'hero_banner_badges' ) === '2' ? false : meta_get( 'hero_banner_badges' ) === '1', 'repeater count row is 1' );
ok( meta_get( '_hero_banner_badges' ) === 'field_61bce16ca13a6', 'repeater reference row holds the real key' );
ok( meta_get( 'hero_banner_badges_0_badge_image' ) === '4419', 'row 0 has the new badge' );
ok( meta_get( '_hero_banner_badges_0_badge_image' ) === 'field_61bce179a13a7', 'row 0 reference holds the real sub key' );
ok( meta_get( 'hero_banner_badges_1_badge_image' ) === null, 'stale row 1 value deleted (2→1 shrink cleanup)' );
ok( meta_get( '_hero_banner_badges_1_badge_image' ) === null, 'stale row 1 reference deleted' );
ok( $r['fields']['hero_banner_badges']['stale_rows_deleted'] === 2, 'response reports 2 stale rows deleted' );
$st = $r['fields']['hero_banner_badges']['state'];
ok( $st['reference_ok'] === true, 'state: reference_ok true' );
ok( count( $st['rows'] ) === 1 && $st['rows'][0]['badge_image']['reference_ok'] === true, 'state: 1 row, sub reference_ok true' );
ok( $r['content_untouched'] === true && $r['content_md5_before'] === md5( $CONTENT ), 'post_content untouched (acceptance #4)' );

echo "\n\033[1mscenario: partial update guarantee (acceptance #3)\033[0m\n";
meta_reset( healthy_seed(), $CONTENT );
$before = meta_snapshot();
$r = sitebridge_acf_fields_rest( req( array(
	'post_id' => 42,
	'fields'  => array( 'hero_banner_badges' => array( array( 'badge_image' => 4419 ) ) ),
) ) );
$after = meta_snapshot();
ok( $after['show_hero_banner'] === '1' && $after['_show_hero_banner'] === 'field_61531e0536f8f', 'show_hero_banner value + reference untouched' );
ok( $after['hero_banner_slides'] === $before['hero_banner_slides']
	&& $after['hero_banner_slides_0_headline'] === $before['hero_banner_slides_0_headline']
	&& $after['_hero_banner_slides_0_headline'] === $before['_hero_banner_slides_0_headline'], 'slides repeater untouched' );

echo "\n\033[1mscenario: idempotent re-run (acceptance #5)\033[0m\n";
$snap1 = meta_snapshot();
$r2 = sitebridge_acf_fields_rest( req( array(
	'post_id' => 42,
	'fields'  => array( 'hero_banner_badges' => array( array( 'badge_image' => 4419 ) ) ),
) ) );
ok( ! is_wp_error( $r2 ) && meta_snapshot() === $snap1, 'identical write is a byte-identical no-op' );
ok( $r2['fields']['hero_banner_badges']['state']['reference_ok'] === true, 'second run still verifies' );
ok( $r2['content_untouched'] === true, 'content still untouched' );

echo "\n\033[1mscenario: composite-corrupted references are healed on any write\033[0m\n";
$seed = healthy_seed();
// The exact damage ACF's REST clone path writes ("Pages"-group clone key prefix).
$seed['_show_hero_banner']                 = 'field_617af16e582f3_field_61531e0536f8f';
$seed['_hero_banner_badges']               = 'field_617af16e582f3_field_61bce16ca13a6';
$seed['_hero_banner_badges_0_badge_image'] = 'field_617af16e582f3_field_61bce179a13a7';
// Composite whose tail resolves to NO registered field — must be left alone.
$seed['_hero_banner_slides']               = 'field_617af16e582f3_field_0000000000000';
meta_reset( $seed, $CONTENT );
$r = sitebridge_acf_fields_rest( req( array(
	'post_id' => 42,
	'fields'  => array( 'show_hero_banner' => true ),
) ) );
ok( ! is_wp_error( $r ), 'write succeeds' );
ok( meta_get( '_show_hero_banner' ) === 'field_61531e0536f8f', 'toggle reference healed (also rewritten by update_field)' );
ok( meta_get( '_hero_banner_badges' ) === 'field_61bce16ca13a6', 'badges reference healed WITHOUT badges being in the payload' );
ok( meta_get( '_hero_banner_badges_0_badge_image' ) === 'field_61bce179a13a7', 'badge sub-row reference healed' );
ok( meta_get( '_hero_banner_slides' ) === 'field_617af16e582f3_field_0000000000000', 'unresolvable composite left alone' );
$fixed = array_filter( $r['repaired_references'], function ( $e ) { return $e['repaired']; } );
$skipped = array_filter( $r['repaired_references'], function ( $e ) { return ! $e['repaired']; } );
ok( count( $fixed ) >= 2 && count( $skipped ) === 1, 'response reports repaired + skipped entries' );

echo "\n\033[1mscenario: hero double-render guard\033[0m\n";
$BLOCK_CONTENT = '<!-- wp:acf/hero-banner {"name":"acf/hero-banner"} /--><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->';
meta_reset( healthy_seed(), $BLOCK_CONTENT );
$r = sitebridge_acf_fields_rest( req( array(
	'post_id' => 42,
	'fields'  => array( 'show_hero_banner' => true ),
) ) );
ok( is_wp_error( $r ) && $r->get_error_code() === 'hero_conflict', 'toggle=true on a block page → hero_conflict' );
$r = sitebridge_acf_fields_rest( req( array(
	'post_id' => 42,
	'fields'  => array( 'show_hero_banner' => false ),
) ) );
ok( ! is_wp_error( $r ) && meta_get( 'show_hero_banner' ) === '0', 'toggle=false on a block page is allowed (the sanctioned state)' );
meta_reset( healthy_seed(), $CONTENT );
$r = sitebridge_acf_fields_rest( req( array(
	'post_id' => 42,
	'fields'  => array( 'show_hero_banner' => true ),
) ) );
ok( ! is_wp_error( $r ), 'toggle=true on a blockless page is allowed' );

echo "\n\033[1mscenario: input validation, all-or-nothing\033[0m\n";
meta_reset( healthy_seed(), $CONTENT );
$snap = meta_snapshot();
$r = sitebridge_acf_fields_rest( req( array(
	'post_id' => 42,
	'fields'  => array( 'show_hero_banner' => true, 'no_such_field' => 'x' ),
) ) );
ok( is_wp_error( $r ) && $r->get_error_code() === 'unknown_field', 'unknown field → unknown_field error' );
ok( meta_snapshot() === $snap, 'NOTHING was written (valid field in same payload untouched)' );
$r = sitebridge_acf_fields_rest( req( array(
	'post_id' => 42,
	'fields'  => array( 'field_617af16e582f3_field_61531e0536f8f' => true ),
) ) );
ok( is_wp_error( $r ) && $r->get_error_code() === 'composite_key', 'composite clone key selector → refused' );
$r = sitebridge_acf_fields_rest( req( array( 'post_id' => 999, 'fields' => array( 'show_hero_banner' => true ) ) ) );
ok( is_wp_error( $r ) && $r->get_error_code() === 'not_found', 'missing post → not_found' );
$r = sitebridge_acf_fields_rest( req( array( 'post_id' => 42, 'fields' => array() ) ) );
ok( is_wp_error( $r ) && $r->get_error_code() === 'bad_input', 'empty fields → bad_input' );
$too_many = array();
for ( $i = 0; $i < 21; $i++ ) { $too_many[ "f$i" ] = 1; }
$r = sitebridge_acf_fields_rest( req( array( 'post_id' => 42, 'fields' => $too_many ) ) );
ok( is_wp_error( $r ) && $r->get_error_code() === 'too_many_fields', '>20 fields → too_many_fields' );

echo "\n\033[1mscenario: clear_stale_rows=false leaves shrink leftovers\033[0m\n";
meta_reset( healthy_seed(), $CONTENT );
$r = sitebridge_acf_fields_rest( req( array(
	'post_id'          => 42,
	'fields'           => array( 'hero_banner_badges' => array( array( 'badge_image' => 4419 ) ) ),
	'clear_stale_rows' => false,
) ) );
ok( ! is_wp_error( $r ) && $r['fields']['hero_banner_badges']['stale_rows_deleted'] === 0, 'nothing deleted' );
ok( meta_get( 'hero_banner_badges' ) === '1', 'count row still governs (1)' );
ok( meta_get( 'hero_banner_badges_1_badge_image' ) === '4225', 'old row 1 left behind, as ACF itself would' );

// ---------------------------------------------------------------- summary ---
echo "\n$PASS passed, $FAIL failed\n";
exit( $FAIL === 0 ? 0 : 1 );
