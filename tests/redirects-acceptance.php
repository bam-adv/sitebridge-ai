<?php
/**
 * Acceptance harness for the REDIRECTS module (v1.23 chain-safe writes).
 *
 * No WordPress, no network: an in-memory options table stands in for
 * bam_redirects. Covers the five acceptance tests from the 2026-11-20 spec
 * (flatten on insert, resolve to final target, loop refusal, normalized
 * matching, list_redirect_chains) plus absolute-target storage, dry_run,
 * the delete warning and regressions on the pre-1.23 behavior.
 *
 * Run:  php tests/redirects-acceptance.php     (exit 0 = all assertions passed)
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
$GLOBALS['OPT'] = array(); $GLOBALS['OPT_WRITES'] = 0;
function get_option( $k, $default = false ) { return array_key_exists( $k, $GLOBALS['OPT'] ) ? $GLOBALS['OPT'][ $k ] : $default; }
function update_option( $k, $v ) { $GLOBALS['OPT'][ $k ] = $v; $GLOBALS['OPT_WRITES']++; return true; }
function user_trailingslashit( $s ) { return trailingslashit( $s ); }
function current_time( $t ) { return '2026-11-20 12:00:00'; }
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
function section( $t ) { echo "\n\033[1m$t\033[0m\n"; }
function seed( array $rules ) {
	$list = array();
	foreach ( $rules as $r ) { $list[] = array( 'source' => $r[0], 'target' => $r[1], 'type' => 301, 'created' => 'x' ); }
	$GLOBALS['OPT'] = array( SITEBRIDGE_REDIRECTS_OPTION => $list );
	$GLOBALS['OPT_WRITES'] = 0;
}
function stored() { return $GLOBALS['OPT'][ SITEBRIDGE_REDIRECTS_OPTION ]; }
function target_of( $source ) {
	foreach ( stored() as $r ) { if ( sitebridge_redirects_key( $r['source'] ) === sitebridge_redirects_key( $source ) ) return $r['target']; }
	return null;
}
function add( array $p ) { return sitebridge_redirects_rest_add( new WP_REST_Request( $p ) ); }
function import( array $p ) { return sitebridge_redirects_rest_import( new WP_REST_Request( $p ) ); }
function del( array $p ) { return sitebridge_redirects_rest_delete( new WP_REST_Request( $p ) ); }
$U_FFFC = "\xEF\xBF\xBC";
$ZWSP   = "\xE2\x80\x8B";

section( 'acceptance 1: existing X → A, add A → B flattens X' );
seed( array( array( '/x/', '/a/' ) ) );
$r = add( array( 'source' => '/a/', 'target' => '/b/' ) );
ok( target_of( '/a/' ) === 'https://example.com/b/', 'A → B stored (absolute)' );
ok( target_of( '/x/' ) === 'https://example.com/b/', 'X → B (flattened)' );
ok( count( $r['flattened'] ) === 1 && $r['flattened'][0]['source'] === '/x/' && $r['flattened'][0]['old_target'] === '/a/', 'X listed in flattened[] with its old target' );
ok( $r['saved'] === true && $r['dry_run'] === false && $r['resolved'] === null, 'saved, not resolved' );
$x = stored()[0];
ok( isset( $x['via'] ) && $x['via'] === array( '/a' ), 'flattened rule records via [/a]' );

section( 'acceptance 2: add A → B where B → C exists stores A → C' );
seed( array( array( '/b', 'https://example.com/c/' ) ) );
$r = add( array( 'source' => '/a', 'target' => '/b/' ) );
ok( target_of( '/a' ) === 'https://example.com/c/', 'A → C stored' );
ok( $r['resolved']['via'] === array( '/b' ) && $r['resolved']['stored_target'] === 'https://example.com/c/', 'resolved{} reports the hop through B' );
seed( array( array( '/b', '/c' ), array( '/c', '/d' ), array( '/d', 'https://other.example.org/e' ) ) );
add( array( 'source' => '/a', 'target' => 'http://www.example.com/b' ) );
ok( target_of( '/a' ) === 'https://other.example.org/e', 'multi-hop chain followed to an external final URL' );

section( 'acceptance 3: loops and self-redirects refused' );
seed( array( array( '/b/', '/a/' ) ) );
$r = add( array( 'source' => '/a/', 'target' => '/b/' ) );
ok( is_wp_error( $r ) && $r->code === 'redirect_loop' && $r->data['status'] === 409, 'A → B with B → A → 409 redirect_loop' );
ok( $GLOBALS['OPT_WRITES'] === 0 && count( stored() ) === 1, 'nothing written' );
seed( array() );
$r = add( array( 'source' => '/a/', 'target' => 'https://www.example.com/A' ) );
ok( is_wp_error( $r ) && $r->code === 'self_redirect', 'self-redirect (www + case + slash variant) refused' );
seed( array( array( '/b', '/c' ), array( '/c', '/a' ) ) );
$r = add( array( 'source' => '/a', 'target' => '/b' ) );
ok( is_wp_error( $r ) && $r->code === 'redirect_loop' && count( $r->data['chain'] ) >= 3, 'loop across a 3-rule chain refused, chain reported' );
seed( array() );
$r = import( array( 'redirects' => array( array( 'source' => '/ok', 'target' => '/fine' ), array( 'source' => '/p', 'target' => '/q' ), array( 'source' => '/q', 'target' => '/p' ) ) ) );
ok( is_wp_error( $r ) && $r->code === 'redirect_loop' && count( $r->data['errors'] ) === 1, 'import with an in-batch loop → 409, errors[] names it' );
ok( $GLOBALS['OPT_WRITES'] === 0, 'import is all-or-nothing (the good row was not written)' );

section( 'acceptance 4: normalized matching' );
seed( array( array( '/x', 'https://example.com/old-post/' ) ) );
add( array( 'source' => 'https://www.example.com/old-post', 'target' => '/new-post/' ) );
ok( target_of( '/x' ) === 'https://example.com/new-post/', 'source differing by trailing slash + www + absolute form matches an existing target' );
ok( count( stored() ) === 2, 'no duplicate rule created' );
seed( array( array( '/x', '/old-post' . $U_FFFC . '/' ) ) );
add( array( 'source' => '/old-post/', 'target' => '/new/' ) );
ok( target_of( '/x' ) === 'https://example.com/new/', 'target with raw U+FFFC matches the clean source' );
seed( array( array( '/x', '/old-post%EF%BF%BC/' ) ) );
add( array( 'source' => '/Old-Post', 'target' => '/new/' ) );
ok( target_of( '/x' ) === 'https://example.com/new/', 'percent-encoded U+FFFC + case difference matches' );
seed( array( array( '/x', '/caf%C3%A9/' ) ) );
add( array( 'source' => "/caf\xC3\xA9" . $ZWSP, 'target' => '/new/' ) );
ok( target_of( '/x' ) === 'https://example.com/new/', 'percent-encoded é matches raw é (zero-width space stripped)' );
seed( array( array( '/x', 'http://example.com/old' ) ) );
add( array( 'source' => '/old', 'target' => '/new/' ) );
ok( target_of( '/x' ) === 'https://example.com/new/', 'http vs https target matches' );
seed( array( array( '/x', 'https://another-site.com/old' ) ) );
add( array( 'source' => '/old', 'target' => '/new/' ) );
ok( target_of( '/x' ) === 'https://another-site.com/old', 'same path on a DIFFERENT host is not flattened' );
ok( sitebridge_redirects_norm_path( '/a%2520b/' ) === sitebridge_redirects_norm_path( '/a b' ), 'double-encoded path decodes' );

section( 'absolute targets' );
seed( array() );
add( array( 'source' => '/s1', 'target' => 'new-page' ) );
ok( target_of( '/s1' ) === 'https://example.com/new-page/', 'bare relative → absolute with trailing slash' );
add( array( 'source' => '/s2', 'target' => 'http://www.example.com/blog/post?x=1#h' ) );
ok( target_of( '/s2' ) === 'https://example.com/blog/post/?x=1#h', 'http/www variant rebuilt on home_url, query + fragment kept' );
add( array( 'source' => '/s3', 'target' => '/files/guide.pdf' ) );
ok( target_of( '/s3' ) === 'https://example.com/files/guide.pdf', 'file path gets no trailing slash' );
add( array( 'source' => '/s4', 'target' => '//cdn.other.com/x' ) );
ok( target_of( '/s4' ) === 'https://cdn.other.com/x', 'protocol-relative external → https' );
add( array( 'source' => '/s5' . $U_FFFC, 'target' => '/t%EF%BF%BC/' ) );
ok( stored()[4]['source'] === '/s5' && target_of( '/s5' ) === 'https://example.com/t/', 'invisible chars scrubbed from stored source and target' );
add( array( 'source' => '/s6', 'target' => '/a%20b/' ) );
ok( target_of( '/s6' ) === 'https://example.com/a%20b/', 'legitimate %20 preserved' );
add( array( 'source' => 'regex:^/old/(.+)$', 'target' => '/new/$1' ) );
ok( target_of( 'regex:^/old/(.+)$' ) === 'https://example.com/new/$1', 'regex capture target made absolute, no slash appended' );

section( 'dry_run' );
seed( array( array( '/x', '/a' ) ) );
$r = add( array( 'source' => '/a', 'target' => '/b', 'dry_run' => true ) );
ok( $r['dry_run'] === true && $r['saved'] === false && count( $r['flattened'] ) === 1, 'add dry_run reports flattened' );
ok( $GLOBALS['OPT_WRITES'] === 0 && target_of( '/x' ) === '/a', 'add dry_run writes nothing' );
$r = import( array( 'dry_run' => true, 'redirects' => array( array( 'source' => '/a', 'target' => '/b' ), array( 'source' => '/y', 'target' => '/x' ) ) ) );
ok( $r['dry_run'] === true && $r['imported'] === false && $r['added'] === 2 && count( $r['rules'] ) === 2, 'import dry_run lists rules[]' );
ok( count( $r['flattened'] ) === 1 && count( $r['resolved'] ) === 1 && $r['rules'][1]['target'] === 'https://example.com/b/', 'import dry_run: X flattened, /y resolved through /x to /b' );
ok( $GLOBALS['OPT_WRITES'] === 0, 'import dry_run writes nothing' );

section( 'import: retire-batch shape (new rule + repointed inbound rules in one call)' );
seed( array( array( '/x1', '/retired' ), array( '/x2/', 'https://www.example.com/retired/' ), array( '/keep', '/other' ) ) );
$r = import( array( 'redirects' => array(
	array( 'source' => '/retired/', 'target' => '/live/' ),
	array( 'source' => '/x1', 'target' => '/live/' ),
	array( 'source' => '/x2/', 'target' => '/live/' ),
) ) );
ok( $r['imported'] === true && $r['added'] === 1 && $r['updated'] === 2, '1 added, 2 updated' );
ok( target_of( '/x1' ) === 'https://example.com/live/' && target_of( '/x2' ) === 'https://example.com/live/' && target_of( '/keep' ) === '/other', 'inbound repointed, unrelated rule untouched' );
ok( sitebridge_redirects_chains()['clean'] === true, 'no chains afterwards' );
seed( array( array( '/a', '/b' ) ) );
import( array( 'redirects' => array( array( 'source' => '/b', 'target' => '/c' ) ) ) );
ok( target_of( '/a' ) === 'https://example.com/c/', 'import flattens too' );
seed( array( array( '/old', '/x' ) ) );
$r = import( array( 'replace_all' => true, 'redirects' => array( array( 'source' => '/a', 'target' => '/b' ) ) ) );
ok( count( stored() ) === 1 && $r['total'] === 1, 'replace_all still clears first' );
$r = import( array( 'redirects' => array( array( 'source' => '', 'target' => '/b' ), array( 'source' => 'regex:(', 'target' => '/b' ) ) ) );
ok( $r['skipped'] === 2 && $r['skipped_details'][1]['reason'] === 'invalid_regex', 'skips still reported, now with reasons' );

section( 'acceptance 5: list_redirect_chains' );
seed( array( array( '/a', '/b' ), array( '/c', 'https://example.com/d/' ) ) );
$c = sitebridge_redirects_chains();
ok( $c['clean'] === true && $c['chains'] === array() && $c['loops'] === array(), 'site with no chains → empty' );
ok( count( $c['non_absolute_targets'] ) === 1, 'relative legacy target reported as format debt (not a chain)' );
seed( array( array( '/x', '/a/' ), array( '/a', '/b' ), array( '/b', '/c' ), array( '/p', '/q' ), array( '/q', '/p/' ), array( '/s', '/S/' ), array( '/dup/', '/z' ), array( '/dup' . $U_FFFC, '/z' ) ) );
$c = sitebridge_redirects_chains();
ok( $c['clean'] === false, 'chained site not clean' );
$x = null; foreach ( $c['chains'] as $ch ) { if ( $ch['source'] === '/x' ) $x = $ch; }
ok( $x && $x['hop_count'] === 3 && $x['final'] === 'https://example.com/c/', 'X → A → B → C reported with 3 hops and final' );
ok( count( $c['chains'] ) === 2, 'A → B → C also reported (2 chains total)' );
ok( count( $c['loops'] ) === 1 && count( $c['loops'][0]['cycle'] ) === 2, 'P ↔ Q loop reported once' );
ok( count( $c['self_redirects'] ) === 1 && $c['self_redirects'][0]['source'] === '/s', 'case-variant self-redirect reported' );
ok( count( $c['duplicate_sources'] ) === 1, 'U+FFFC duplicate source reported' );
// Fixing: re-import each chain with its final target → clean.
$fix = array();
foreach ( $c['chains'] as $ch ) { $fix[] = array( 'source' => $ch['source'], 'target' => $ch['final'] ); }
import( array( 'redirects' => $fix ) );
$c2 = sitebridge_redirects_chains();
ok( $c2['chains'] === array(), 'chains gone after re-importing them with final targets' );
$GLOBALS['OPT_WRITES'] = 0;
sitebridge_redirects_rest_chains();
ok( $GLOBALS['OPT_WRITES'] === 0, 'chains endpoint is read-only' );

section( 'delete_redirect warns about rules that lead to the source' );
seed( array( array( '/x', '/a' ), array( '/y', '/z' ) ) );
add( array( 'source' => '/a', 'target' => '/b' ) );
add( array( 'source' => '/w', 'target' => '/a/' ) ); // resolved through /a → /b, via [/a]
add( array( 'source' => '/v', 'target' => 'https://example.com/a' ) ); // also via /a
$r = del( array( 'source' => '/a/' ) );
ok( $r['deleted'] === 1, 'rule deleted (trailing-slash variant)' );
ok( count( $r['flattened_through'] ) === 3 && $r['inbound'] === array(), 'X, W, V listed in flattened_through[]' );
ok( is_string( $r['warning'] ) && strpos( $r['warning'], '3 other rule' ) !== false, 'warning names the count' );
seed( array( array( '/x', '/a' ) ) );
$r = del( array( 'source' => '/a' ) );
ok( $r['deleted'] === 0 && count( $r['inbound'] ) === 1, 'rule still targeting the URL listed in inbound[]' );
seed( array( array( '/y', '/z' ) ) );
$r = del( array( 'source' => '/y' ) );
ok( $r['warning'] === null && $r['inbound'] === array(), 'no warning when nothing leads there' );

section( 'regressions' );
seed( array() );
$r = add( array( 'source' => 'regex:(', 'target' => '/b' ) );
ok( is_wp_error( $r ) && $r->code === 'invalid_regex', 'invalid regex still 400s' );
seed( array( array( '/a', '/old' ) ) );
$r = add( array( 'source' => '/a/', 'target' => '/new' ) );
ok( $r['updated'] === true && count( stored() ) === 1, 'adding an existing source updates in place' );
seed( array( array( '/x', '/a' ) ) );
add( array( 'source' => 'regex:^/a/?$', 'target' => '/b' ) );
ok( target_of( '/x' ) === '/a', 'a regex source never flattens exact rules' );
ok( sitebridge_redirects_norm_path( '/Foo/?q=1' ) === '/foo' && sitebridge_redirects_norm_path( '' ) === '/', 'norm_path: query ignored, empty → /' );

echo "\n$PASS passed, $FAIL failed\n";
exit( $FAIL === 0 ? 0 : 1 );
