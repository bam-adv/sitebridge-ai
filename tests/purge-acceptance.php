<?php
/**
 * Acceptance harness for the PURGE-CACHE route's NitroPack branch (v1.16.0).
 *
 * No WordPress, no PHPUnit, no network: stubs just enough WP (and NitroPack)
 * to load sitebridge-ai.php and drive sitebridge_purge_cache_rest() directly.
 *
 * function_exists()/defined() states can't be undone within one PHP process,
 * so each scenario runs as a subprocess selected by SB_PURGE_SCENARIO; running
 * this file with no env var set is the runner that spawns them all.
 *
 * Run:  php tests/purge-acceptance.php     (exit 0 = all assertions passed)
 *
 * This covers the LOGIC. The live acceptance pass — a real NitroPack-connected
 * a live production site going x-nitro-cache: MISS after a purge — is still required.
 */

$scenario = getenv( 'SB_PURGE_SCENARIO' );

// ------------------------------------------------------------------ runner --
if ( $scenario === false ) {
	$scenarios = array( 'baseline', 'np-site', 'np-url', 'np-disconnected', 'np-throws', 'np-legacy' );
	$failed    = array();
	foreach ( $scenarios as $s ) {
		echo "\n\033[1mscenario: $s\033[0m\n";
		passthru(
			'SB_PURGE_SCENARIO=' . escapeshellarg( $s ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ),
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
	echo "\n\033[32mAll purge scenarios passed.\033[0m\n";
	exit( 0 );
}

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

// ACF stubs (loaded by nav module at include time)
function get_field( $field, $post_id = false ) { return null; }
function update_field( $field, $value, $post_id = false ) { return true; }
function get_fields( $post_id = false ) { return array(); }

// Purge-route runtime stubs
function get_post( $id ) { return (object) array( 'ID' => $id ); }
function clean_post_cache( $id ) {}
function get_permalink( $id ) { return 'https://example.com/?p=' . $id; }
function wp_cache_flush() { $GLOBALS['object_cache_flushed'] = true; }
function wp_using_ext_object_cache() { return ! empty( $GLOBALS['ext_object_cache'] ); }

// --------------------------------------------------- per-scenario NitroPack --
$GLOBALS['np_calls'] = array();

switch ( $scenario ) {
	case 'np-site':
	case 'np-url':
		define( 'NITROPACK_VERSION', '1.19.9' );
		function nitropack_sdk_purge( $url = null, $tag = null, $reason = null ) {
			$GLOBALS['np_calls'][] = array( $url, $tag, $reason );
			return true;
		}
		break;
	case 'np-disconnected':
		define( 'NITROPACK_VERSION', '1.19.9' );
		function nitropack_sdk_purge( $url = null, $tag = null, $reason = null ) {
			$GLOBALS['np_calls'][] = array( $url, $tag, $reason );
			return false; // plugin installed, site not connected
		}
		break;
	case 'np-throws':
		define( 'NITROPACK_VERSION', '1.19.9' );
		function nitropack_sdk_purge( $url = null, $tag = null, $reason = null ) {
			$GLOBALS['np_calls'][] = array( $url, $tag, $reason );
			throw new RuntimeException( 'NitroPack API unreachable' );
		}
		class Breeze_PurgeCache {} // a layer BEFORE NitroPack, to prove note composition
		break;
	case 'np-legacy':
		define( 'NITROPACK_VERSION', '1.3.0' );
		function nitropack_purge( $url = null, $tag = null, $reason = null ) {
			$GLOBALS['np_calls'][] = array( $url, $tag, $reason );
		}
		break;
	case 'baseline':
	default:
		// no NitroPack at all
		break;
}

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

// ------------------------------------------------------------- scenarios ----
switch ( $scenario ) {

	case 'baseline':
		$r = sitebridge_purge_cache_rest( req() );
		ok( $r['scope'] === 'site', 'no url/post_id => site scope' );
		ok( $r['detected'] === array(), 'nothing detected on a bare install' );
		ok( $r['fired'] === array(), 'nothing fired' );
		ok( strpos( $r['note'], 'No purgeable cache layer detected' ) === 0, 'upstream-layer note intact (non-NitroPack hosts unchanged)' );
		break;

	case 'np-site':
		$r = sitebridge_purge_cache_rest( req() );
		ok( $r['detected'] === array( 'nitropack' ), 'detected: nitropack' );
		ok( $r['fired'] === array( 'nitropack' ), 'fired: nitropack' );
		ok( $GLOBALS['np_calls'] === array( array( null, null, 'SiteBridge purge_cache' ) ), 'site scope => sdk_purge(NULL, NULL, reason) — complete purge' );
		ok( $r['note'] === null, 'no note on a clean full purge' );
		break;

	case 'np-url':
		$r = sitebridge_purge_cache_rest( req( array( 'url' => 'about-us/' ) ) );
		ok( $r['scope'] === 'url', 'url param => url scope' );
		ok( $r['url'] === 'https://example.com/about-us/', 'bare path made absolute' );
		ok( $GLOBALS['np_calls'] === array( array( 'https://example.com/about-us/', null, 'SiteBridge purge_cache' ) ), 'url scope => sdk_purge(full URL, ...) — per-URL purge' );
		ok( $r['fired'] === array( 'nitropack' ), 'fired: nitropack' );
		ok( $r['note'] === null, 'per-URL purge is real => no partial note' );
		break;

	case 'np-disconnected':
		$r = sitebridge_purge_cache_rest( req() );
		ok( $r['detected'] === array( 'nitropack' ), 'still detected when not connected' );
		ok( $r['fired'] === array(), 'not fired when sdk_purge returns false' );
		ok( is_string( $r['note'] ) && strpos( $r['note'], 'NitroPack detected but its purge did not fire' ) !== false, 'detected-but-not-fired is surfaced in note' );
		break;

	case 'np-throws':
		// site scope, with an object cache AFTER the NitroPack branch: the throw
		// must not stop the rest of the chain (nor 500 the route).
		$GLOBALS['ext_object_cache'] = true;
		$r = sitebridge_purge_cache_rest( req() );
		ok( in_array( 'nitropack', $r['detected'], true ), 'detected despite the throw' );
		ok( ! in_array( 'nitropack', $r['fired'], true ), 'not fired on exception' );
		ok( in_array( 'object-cache', $r['fired'], true ), 'layers after NitroPack still fire' );
		ok( ! empty( $GLOBALS['object_cache_flushed'] ), 'object cache actually flushed' );
		ok( strpos( $r['note'], 'NitroPack detected but its purge did not fire' ) !== false, 'failure surfaced in note' );

		// url scope with Breeze present: the partial note and the NitroPack note compose.
		$GLOBALS['np_calls'] = array();
		$r = sitebridge_purge_cache_rest( req( array( 'url' => '/products/' ) ) );
		ok( in_array( 'breeze', $r['fired'], true ), 'breeze fired' );
		ok( strpos( $r['note'], 'No per-URL purge API for: breeze' ) !== false, 'partial note kept' );
		ok( strpos( $r['note'], 'NitroPack detected but its purge did not fire' ) !== false, 'NitroPack note appended after partial note' );
		break;

	case 'np-legacy':
		$r = sitebridge_purge_cache_rest( req() );
		ok( $r['detected'] === array( 'nitropack' ), 'legacy plugin detected via nitropack_purge()' );
		ok( $r['fired'] === array( 'nitropack' ), 'legacy queue-purge counts as fired' );
		ok( $GLOBALS['np_calls'] === array( array( null, null, 'SiteBridge purge_cache' ) ), 'legacy fallback got the same args' );
		break;
}

exit( $FAIL > 0 ? 1 : 0 );
