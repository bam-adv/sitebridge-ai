<?php
/**
 * Acceptance harness for the self-updater's signature verification (v1.22.3).
 *
 * No WordPress, no network: stubs just enough WP to load sitebridge-ai.php and
 * drive sitebridge_verify_before_download() directly, with an in-memory
 * transient store, a fake download_url()/wp_remote_get() keyed by URL, and a
 * throwaway Ed25519 keypair whose PUBLIC half is patched into the plugin
 * source before it is loaded (the constant is otherwise the real fleet key).
 *
 * Reproduces the 2026-10-27 incident: WordPress's update check fixed the
 * package URL on release A while the plugin's 6-hour "latest release" cache
 * later rolled over to release B, so A's zip was checked against B's .sig and
 * a valid update was refused on several sites.
 *
 * Run:  php tests/updater-acceptance.php     (exit 0 = all assertions passed)
 */

// ---------------------------------------------------------------- WP stubs --
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', __DIR__ );
define( 'SITEBRIDGE_NAV_OPTION_ID', 'option' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function register_rest_route( ...$a ) {}
function register_activation_hook( ...$a ) {}
function register_deactivation_hook( ...$a ) {}
function plugin_basename( $f ) { return 'sitebridge-ai/sitebridge-ai.php'; }
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
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function get_field( $f, $p = false ) { return null; }
function update_field( $f, $v, $p = false ) { return true; }
function get_fields( $p = false ) { return array(); }
function get_post( $id ) { return (object) array( 'ID' => $id ); }
function clean_post_cache( $id ) {}
function get_permalink( $id ) { return 'https://example.com/?p=' . $id; }
function wp_cache_flush() {}
function wp_using_ext_object_cache() { return false; }

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
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

// Transients (plain + site) in memory.
$GLOBALS['transients'] = array();
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['transients'] ) ? $GLOBALS['transients'][ $k ] : false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function get_site_transient( $k ) { return get_transient( 'site:' . $k ); }
function set_site_transient( $k, $v, $ttl = 0 ) { return set_transient( 'site:' . $k, $v ); }

// Network, keyed by URL: $GLOBALS['http'][url] = body (string) or WP_Error.
$GLOBALS['http'] = array();
$GLOBALS['http_log'] = array();
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['http_log'][] = $url;
	if ( ! isset( $GLOBALS['http'][ $url ] ) ) { return new WP_Error( 'http', "no stub for $url" ); }
	$b = $GLOBALS['http'][ $url ];
	if ( $b instanceof WP_Error ) { return $b; }
	return array( 'response' => array( 'code' => 200 ), 'body' => $b );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }
function download_url( $url ) {
	$GLOBALS['http_log'][] = $url;
	if ( ! isset( $GLOBALS['http'][ $url ] ) ) { return new WP_Error( 'http', "no stub for $url" ); }
	$f = tempnam( sys_get_temp_dir(), 'sbzip' );
	file_put_contents( $f, $GLOBALS['http'][ $url ] );
	return $f;
}

// ------------------------------------------- load the plugin with a test key --
$kp     = sodium_crypto_sign_keypair();
$pk_b64 = base64_encode( sodium_crypto_sign_publickey( $kp ) );
$sk     = sodium_crypto_sign_secretkey( $kp );
$src    = file_get_contents( dirname( __DIR__ ) . '/sitebridge-ai.php' );
$src    = preg_replace( "/(SITEBRIDGE_UPDATE_PUBKEY\s*=\s*')[^']+(')/", '${1}' . $pk_b64 . '${2}', $src, 1, $n );
if ( $n !== 1 ) { fwrite( STDERR, "could not patch SITEBRIDGE_UPDATE_PUBKEY\n" ); exit( 1 ); }
$tmp = tempnam( sys_get_temp_dir(), 'sbplugin' ) . '.php';
file_put_contents( $tmp, $src );
require_once $tmp;
unlink( $tmp );

// ------------------------------------------------------------- test rig -----
$PASS = 0; $FAIL = 0;
function ok( $cond, $label ) {
	global $PASS, $FAIL;
	if ( $cond ) { $PASS++; echo "  \033[32mok\033[0m   $label\n"; }
	else         { $FAIL++; echo "  \033[31mFAIL\033[0m $label\n"; }
}
$DL = 'https://github.com/' . SITEBRIDGE_GH_REPO . '/releases/download/';
function release( $tag ) {
	global $DL;
	return (object) array(
		'tag_name' => $tag,
		'zipball_url' => "https://api.github.com/repos/bam-adv/sitebridge-ai/zipball/$tag",
		'assets' => array(
			(object) array( 'name' => 'sitebridge-ai.zip',     'browser_download_url' => "{$DL}{$tag}/sitebridge-ai.zip" ),
			(object) array( 'name' => 'sitebridge-ai.zip.sig', 'browser_download_url' => "{$DL}{$tag}/sitebridge-ai.zip.sig" ),
		),
	);
}
function sign( $bytes ) { global $sk; return base64_encode( sodium_crypto_sign_detached( $bytes, $sk ) ) . "\n"; }
function reset_world() {
	$GLOBALS['transients'] = array();
	$GLOBALS['http'] = array();
	$GLOBALS['http_log'] = array();
}
function verify( $package ) {
	return sitebridge_verify_before_download( false, $package, null, array( 'plugin' => plugin_basename( __FILE__ ) ) );
}
$zipA = "PK-release-A-bytes-" . str_repeat( 'a', 64 );
$zipB = "PK-release-B-bytes-" . str_repeat( 'b', 64 );
$hook = array( 'plugin' => plugin_basename( __FILE__ ) );

// ---------------------------------------------------------------- scenarios --

echo "\n\033[1mscenario: the 2026-10-27 incident — package fixed on A, 'latest' cache rolled to B\033[0m\n";
reset_world();
$GLOBALS['http'][ "{$DL}v1.22.1/sitebridge-ai.zip" ]     = $zipA;
$GLOBALS['http'][ "{$DL}v1.22.1/sitebridge-ai.zip.sig" ] = sign( $zipA );
$GLOBALS['http'][ "{$DL}v1.22.2/sitebridge-ai.zip" ]     = $zipB;
$GLOBALS['http'][ "{$DL}v1.22.2/sitebridge-ai.zip.sig" ] = sign( $zipB );
set_transient( 'sitebridge_latest_release', release( 'v1.22.2' ) ); // cache now says B…
$r = verify( "{$DL}v1.22.1/sitebridge-ai.zip" );                     // …but WP is installing A
ok( ! is_wp_error( $r ) && is_string( $r ) && file_exists( $r ), 'A verifies (was refused before 1.22.3)' );
ok( in_array( "{$DL}v1.22.1/sitebridge-ai.zip.sig", $GLOBALS['http_log'], true ), 'the .sig fetched is the SIBLING of the package (same release)' );
ok( ! in_array( "{$DL}v1.22.2/sitebridge-ai.zip.sig", $GLOBALS['http_log'], true ), "…never the newer release's .sig" );
ok( ! in_array( 'https://api.github.com/repos/' . SITEBRIDGE_GH_REPO . '/releases/latest', $GLOBALS['http_log'], true ), 'the release listing is not consulted at all for an asset package' );
ok( get_transient( 'sitebridge_update_sig_error' ) === false, 'no error notice' );
if ( is_string( $r ) && file_exists( $r ) ) { unlink( $r ); }

echo "\n\033[1mscenario: a genuinely bad signature is still refused, and the stale package is dropped\033[0m\n";
reset_world();
$GLOBALS['http'][ "{$DL}v1.22.2/sitebridge-ai.zip" ]     = $zipB;
$GLOBALS['http'][ "{$DL}v1.22.2/sitebridge-ai.zip.sig" ] = sign( $zipA ); // signature of the wrong bytes
set_transient( 'sitebridge_latest_release', release( 'v1.22.2' ) );
set_site_transient( 'update_plugins', (object) array( 'response' => array(
	plugin_basename( __FILE__ ) => (object) array( 'package' => "{$DL}v1.22.2/sitebridge-ai.zip", 'new_version' => '1.22.2' ),
	'other/other.php'           => (object) array( 'package' => 'x', 'new_version' => '9' ),
) ) );
$r = verify( "{$DL}v1.22.2/sitebridge-ai.zip" );
ok( is_wp_error( $r ) && $r->get_error_code() === 'sitebridge_bad_signature', 'refused with sitebridge_bad_signature' );
ok( strpos( (string) get_transient( 'sitebridge_update_sig_error' ), 'did NOT verify' ) !== false, 'admin notice set' );
ok( get_transient( 'sitebridge_latest_release' ) === false, 'release cache busted (v1.19.2 behavior kept)' );
$up = get_site_transient( 'update_plugins' );
ok( ! isset( $up->response[ plugin_basename( __FILE__ ) ] ), "WordPress's cached package for THIS plugin dropped — next check re-derives it" );
ok( isset( $up->response['other/other.php'] ), "…other plugins' entries untouched" );

echo "\n\033[1mscenario: non-asset package (source zipball) falls back to the release listing\033[0m\n";
reset_world();
$zipball = 'https://api.github.com/repos/bam-adv/sitebridge-ai/zipball/v1.22.2';
$GLOBALS['http'][ $zipball ] = $zipB;
$GLOBALS['http'][ "{$DL}v1.22.2/sitebridge-ai.zip.sig" ] = sign( $zipB );
set_transient( 'sitebridge_latest_release', release( 'v1.22.2' ) );
$r = verify( $zipball );
ok( in_array( "{$DL}v1.22.2/sitebridge-ai.zip.sig", $GLOBALS['http_log'], true ), 'sig located via the release listing' );
ok( ! is_wp_error( $r ), 'verifies when the listed .sig matches the bytes' );
if ( is_string( $r ) && file_exists( $r ) ) { unlink( $r ); }

echo "\n\033[1mscenario: sig URL derivation\033[0m\n";
ok( sitebridge_sig_url_for_package( "{$DL}v1.22.1/sitebridge-ai.zip" ) === "{$DL}v1.22.1/sitebridge-ai.zip.sig", 'release asset → sibling .sig' );
ok( sitebridge_sig_url_for_package( $zipball ) === '', 'zipball → none' );
ok( sitebridge_sig_url_for_package( 'https://evil.example/releases/download/v1/sitebridge-ai.zip' ) === '', 'foreign host → none' );
ok( sitebridge_sig_url_for_package( "{$DL}v1.22.1/notes.txt" ) === '', 'non-zip asset → none' );

echo "\n" . ( $FAIL === 0 ? "\033[32m" : "\033[31m" ) . "$PASS passed, $FAIL failed\033[0m\n";
exit( $FAIL === 0 ? 0 : 1 );
