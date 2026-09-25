<?php
/**
 * Acceptance harness for the SEARCH-REPLACE route (v1.20 occurrence targeting).
 *
 * No WordPress, no network: stubs just enough WP to load sitebridge-ai.php and
 * a one-row $wpdb that serves / stores raw post_content, byte for byte.
 * Fixture is ACF block-comment JSON as the block serializer stores it —
 * `<`/`>` escapes and literal `\r\n` — with three identical
 * "e 10\r\n" windows: two in a card whose value is wrong, one in a card whose
 * value is right (the 2026-09-25 handoff's defect 2 shape).
 *
 * Run:  php tests/search-replace-acceptance.php     (exit 0 = all assertions passed)
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


function get_post( $id ) { return null; }
function get_post_meta( $post_id, $key = '', $single = false ) { return $single ? '' : array(); }

class FakeWpdb {
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $content = '';
	public $writes = 0;
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_var( $q ) { return $this->content; }
	public function update( $table, $data, $where ) {
		$this->writes++;
		$this->content = $data['post_content'];
		return 1;
	}
}
$GLOBALS['wpdb'] = new FakeWpdb();

require_once dirname( __DIR__ ) . '/sitebridge-ai.php';

$PASS = 0;
$FAIL = 0;
function ok( $cond, $label ) {
	global $PASS, $FAIL;
	if ( $cond ) { $PASS++; echo "  \033[32mok\033[0m   $label\n"; }
	else { $FAIL++; echo "  \033[31mFAIL\033[0m $label\n"; }
}
function sr( array $params ) { return sitebridge_search_replace_rest( new WP_REST_Request( $params ) ); }

// Single quotes: every backslash below is a literal byte in post_content.
// blockesc() applies the block serializer's < > escapes (backslash-u003c /
// backslash-u003e); built with chr(92) so no tool in the authoring chain can
// decode the escape sequences out of this file's source.
function blockesc( $s ) { return str_replace( array( '<', '>' ), array( chr( 92 ) . 'u003c', chr( 92 ) . 'u003e' ), $s ); }
$FLUORIDE = blockesc( '<strong>EPA Max. Contaminant Level:</strong> 10\r\n<strong>Public Health Goal:</strong> 10\r\n' );
$ARSENIC  = blockesc( '<strong>EPA Max. Contaminant Level:</strong> 10\r\n<strong>Public Health Goal:</strong> 0\r\n' );
$CONTENT  = '<!-- wp:acf/contaminant-cards {"name":"acf/contaminant-cards","data":{"cards_0_description":"' . $FLUORIDE
	. '","_cards_0_description":"field_661e8d0723e63","cards_1_description":"' . $ARSENIC
	. '","_cards_1_description":"field_661e8d0723e63"},"mode":"auto"} /-->';
function seed() { global $CONTENT; $GLOBALS['wpdb']->content = $CONTENT; $GLOBALS['wpdb']->writes = 0; }

echo "\n\033[1mscenario: byte-exact matching of \\uXXXX escapes (defect 1, plugin side)\033[0m\n";
seed();
$r = sr( array( 'post_id' => 7, 'replacements' => array(
	array( 'old' => blockesc( '<strong>Public Health Goal:</strong> 10' ), 'new' => 'X' ),
	array( 'old' => '<strong>Public Health Goal:</strong> 10', 'new' => 'Y' ),
) ) );
ok( $r['pairs'][0]['found'] === 1, 'literal \\u003c needle matches the stored escape exactly once' );
ok( $r['pairs'][1]['found'] === 0, 'decoded <strong> needle does not (no normalization either way)' );
ok( $r['pairs'][0]['old_preview'] === blockesc( '<strong>Public Health Goal:</strong> 10' ), 'old_preview echoes the needle verbatim, escapes intact' );
seed();
$r = sr( array( 'post_id' => 7, 'replacements' => array( array( 'old' => 'e 10\r\n', 'new' => 'e 99\r\n' ) ) ) );
ok( $r['pairs'][0]['found'] === 3, '"e 10\\r\\n" still found 3x (\\r\\n literal, no regression)' );

// Round trip: every 40-byte window containing < matches exactly as many
// times as it occurs.
seed();
$bad = 0; $checked = 0;
for ( $off = 0; $off + 40 <= strlen( $CONTENT ); $off += 7 ) {
	$needle = substr( $CONTENT, $off, 40 );
	if ( strpos( $needle, blockesc( '<' ) ) === false ) { continue; }
	$checked++;
	$r = sr( array( 'post_id' => 7, 'replacements' => array( array( 'old' => $needle, 'new' => 'Z' ) ) ) );
	if ( $r['pairs'][0]['found'] !== substr_count( $CONTENT, $needle ) || $r['pairs'][0]['found'] < 1 ) { $bad++; }
}
ok( $checked > 10 && $bad === 0, "round trip: $checked raw 40-byte windows containing \\u003c each match per occurrence" );

echo "\n\033[1mscenario: occurrence targets one match (defect 2)\033[0m\n";
seed();
$r = sr( array( 'post_id' => 7, 'dry_run' => false, 'replacements' => array(
	array( 'old' => 'e 10\r\n', 'new' => 'e 4.0\r\n', 'expect' => 3, 'occurrence' => 2 ),
) ) );
$expected = str_replace( blockesc( 'Goal:</strong> 10\r\n' ), blockesc( 'Goal:</strong> 4.0\r\n' ), $CONTENT );
ok( $r['applied'] === true && $r['pairs'][0]['replaced'] === 1, 'occurrence:2 of 3 → exactly one replacement' );
ok( $GLOBALS['wpdb']->content === $expected, 'and it is the middle one (fluoride PHG), byte-exact' );
ok( $r['md5_after'] === md5( $expected ) && $r['md5_before'] === md5( $CONTENT ), 'md5 before/after agree with the re-read content' );
ok( substr_count( $GLOBALS['wpdb']->content, 'e 10\r\n' ) === 2, 'the other two matches (incl. arsenic, correct) untouched' );
ok( $r['pairs'][0]['occurrence'] === 2, 'row echoes occurrence' );

seed();
$r = sr( array( 'post_id' => 7, 'dry_run' => false, 'replacements' => array(
	array( 'old' => 'e 10\r\n', 'new' => 'e 4.0\r\n', 'occurrence' => 1 ),
	array( 'old' => 'e 10\r\n', 'new' => 'e 4.0\r\n', 'occurrence' => 1 ),
) ) );
ok( $r['pairs'][1]['found'] === 2, 'occurrence indexes the working buffer at that pair\'s turn (2 left after pair 1)' );
ok( substr_count( $GLOBALS['wpdb']->content, 'e 4.0\r\n' ) === 2
	&& strpos( $GLOBALS['wpdb']->content, $ARSENIC ) !== false, 'two sequential occurrence:1 pairs fix the fluoride card only' );

echo "\n\033[1mscenario: out-of-range occurrence aborts, writes nothing\033[0m\n";
seed();
$r = sr( array( 'post_id' => 7, 'dry_run' => false, 'replacements' => array(
	array( 'old' => 'Public Health Goal:', 'new' => 'PHG:', 'occurrence' => 1 ),
	array( 'old' => 'e 10\r\n', 'new' => 'e 4.0\r\n', 'occurrence' => 4 ),
) ) );
ok( $r['aborted'] === true && $r['applied'] === false, 'occurrence:4 of 3 → aborted, not applied' );
ok( $r['reason'] === 'occurrence_out_of_range' && ! empty( $r['message'] ), 'clear reason + message' );
ok( ! empty( $r['pairs'][1]['occurrence_out_of_range'] ) && $r['pairs'][1]['found'] === 3, 'offending pair flagged with its found count' );
ok( $r['pairs'][0]['replaced'] === 0 && $r['md5_after'] === $r['md5_before'], 'earlier valid pair reported as not applied' );
ok( $GLOBALS['wpdb']->writes === 0 && $GLOBALS['wpdb']->content === $CONTENT, 'no DB write' );

seed();
$r = sr( array( 'post_id' => 7, 'replacements' => array( array( 'old' => 'e 10\r\n', 'new' => 'x', 'expect' => 2, 'occurrence' => 1 ) ) ) );
ok( $r['aborted'] === true && $r['reason'] === 'expect_mismatch', 'expect still asserts the total count alongside occurrence' );

foreach ( array( 0, -1, 'two', 1.5 ) as $badocc ) {
	seed();
	$r = sr( array( 'post_id' => 7, 'replacements' => array( array( 'old' => 'e 10\r\n', 'new' => 'x', 'occurrence' => $badocc ) ) ) );
	ok( is_wp_error( $r ) && $r->get_error_code() === 'bad_occurrence', 'occurrence ' . var_export( $badocc, true ) . ' → 400 bad_occurrence' );
}

echo "\n\033[1mscenario: omitting occurrence keeps replace-all exactly\033[0m\n";
seed();
$r = sr( array( 'post_id' => 7, 'dry_run' => false, 'replacements' => array( array( 'old' => 'e 10\r\n', 'new' => 'e 11\r\n', 'expect' => 3 ) ) ) );
ok( $r['pairs'][0]['replaced'] === 3 && $GLOBALS['wpdb']->content === str_replace( 'e 10\r\n', 'e 11\r\n', $CONTENT ), 'all 3 replaced, identical to str_replace' );
ok( array_key_exists( 'occurrence', $r['pairs'][0] ) && $r['pairs'][0]['occurrence'] === null, 'row echoes occurrence:null (connector version probe)' );
seed();
$r = sr( array( 'post_id' => 7, 'replacements' => array( array( 'old' => 'e 10\r\n', 'new' => 'x', 'expect' => 2 ) ) ) );
ok( $r['reason'] === 'expect_mismatch' && $GLOBALS['wpdb']->writes === 0, 'plain expect mismatch unchanged' );

echo "\n$PASS passed, $FAIL failed\n";
exit( $FAIL === 0 ? 0 : 1 );
