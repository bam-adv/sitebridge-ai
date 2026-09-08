<?php
/**
 * Acceptance harness for the SCHEDULED REPUBLISH module (v1.19.0).
 *
 * No WordPress, no PHPUnit, no network: stubs a posts/meta/options/cron store,
 * a real add_action/do_action dispatcher, and — critically — a mini-emulation
 * of Yoast Duplicate Post 4.7's scheduled-republish flow that reproduces the
 * re-dating trap faithfully (republish_scheduled_post on future_to_publish →
 * republish() clones the COPY's row, dates included, onto the original →
 * fires duplicate_post_after_republish → deletes the copy and clears
 * _dp_has_rewrite_republish_copy). The date-restore is therefore asserted
 * against the actual clobber, not a friendly fake.
 *
 * class_exists() states can't be undone within one PHP process, so the no-DP
 * scenario runs as a subprocess selected by SB_REPUBLISH_SCENARIO; running
 * this file with no env var set is the runner that spawns them all.
 *
 * Run:  php tests/republish-acceptance.php     (exit 0 = all assertions passed)
 *
 * This covers the LOGIC. The live acceptance pass (spec steps 3 + 5: original
 * stays online at schedule time; merged post keeps its permalink, ID, and
 * publish date on a real site) is still required before any batch depends on it.
 */

$scenario = getenv( 'SB_REPUBLISH_SCENARIO' );

// ------------------------------------------------------------------ runner --
if ( $scenario === false ) {
	$scenarios = array( 'main', 'no-dp' );
	$failed    = array();
	foreach ( $scenarios as $s ) {
		echo "\n\033[1mscenario: $s\033[0m\n";
		passthru(
			'SB_REPUBLISH_SCENARIO=' . escapeshellarg( $s ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ),
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
	echo "\n\033[32mAll republish scenarios passed.\033[0m\n";
	exit( 0 );
}

// Keep the module's deliberate error_log() lines out of the test output.
ini_set( 'error_log', sys_get_temp_dir() . '/sb-republish-test.log' );

// ---------------------------------------------------------------- WP stubs --
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', __DIR__ );
define( 'SITEBRIDGE_NAV_OPTION_ID', 'option' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

// Real (minimal) hook dispatcher — the module's duplicate_post_after_republish
// hook must actually fire when the DP emulation republishes.
$GLOBALS['sb_actions'] = array();
function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['sb_actions'][ $tag ][ $priority ][] = $cb;
}
function do_action( $tag, ...$args ) {
	if ( empty( $GLOBALS['sb_actions'][ $tag ] ) ) {
		return;
	}
	$by_priority = $GLOBALS['sb_actions'][ $tag ];
	ksort( $by_priority );
	foreach ( $by_priority as $cbs ) {
		foreach ( $cbs as $cb ) {
			call_user_func_array( $cb, $args );
		}
	}
}
function add_filter( ...$a ) {}
function apply_filters( $tag, $value ) { return $value; }
function register_rest_route( ...$a ) {}
function register_activation_hook( ...$a ) {}
function register_deactivation_hook( ...$a ) {}
function plugin_basename( $f ) { return basename( $f ); }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return ''; }
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
function wp_timezone() { return new DateTimeZone( 'America/Los_Angeles' ); }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function clean_post_cache( $id ) {}
function wp_using_ext_object_cache() { return false; }
function wp_cache_flush() {}

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

// ------------------------------------------------- posts / meta / options ---
class WP_Post {
	public $ID;
	public $post_type;
	public $post_status;
	public $post_title;
	public $post_content;
	public $post_name;
	public $post_date;
	public $post_date_gmt;
	public $post_modified;
	public $post_modified_gmt;
	public function __construct( array $fields ) {
		foreach ( $fields as $k => $v ) {
			$this->$k = $v;
		}
	}
}

$GLOBALS['sb_posts']   = array();
$GLOBALS['sb_meta']    = array();
$GLOBALS['sb_options'] = array();
$GLOBALS['sb_cron']    = array();

function sb_add_post( array $fields ) {
	$GLOBALS['sb_posts'][ $fields['ID'] ] = new WP_Post( $fields );
}
function get_post( $id ) {
	$id = is_object( $id ) ? $id->ID : (int) $id;
	return isset( $GLOBALS['sb_posts'][ $id ] ) ? clone $GLOBALS['sb_posts'][ $id ] : null;
}
function get_post_status( $id ) {
	$p = get_post( $id );
	return $p ? $p->post_status : false;
}
function get_permalink( $id ) {
	$p = get_post( is_object( $id ) ? $id->ID : $id );
	return $p ? 'https://example.com/blog/' . $p->post_name . '/' : false;
}
function get_post_meta( $post_id, $key, $single = false ) {
	return isset( $GLOBALS['sb_meta'][ $post_id ][ $key ] ) ? $GLOBALS['sb_meta'][ $post_id ][ $key ] : '';
}
function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['sb_meta'][ $post_id ][ $key ] = $value;
	return true;
}
function delete_post_meta( $post_id, $key ) {
	unset( $GLOBALS['sb_meta'][ $post_id ][ $key ] );
	return true;
}
// Terms store. The module always asks for fields=>ids; the DP emulation
// round-trips raw id arrays, matching how the real merge rewrites terms.
$GLOBALS['sb_terms'] = array();
function get_object_taxonomies( $post_type ) {
	return $post_type === 'post' ? array( 'category', 'post_tag' ) : array();
}
function wp_get_object_terms( $post_id, $tax, $args = array() ) {
	return isset( $GLOBALS['sb_terms'][ $post_id ][ $tax ] ) ? $GLOBALS['sb_terms'][ $post_id ][ $tax ] : array();
}
function wp_set_object_terms( $post_id, $terms, $tax ) {
	$GLOBALS['sb_terms'][ $post_id ][ $tax ] = array_values( (array) $terms );
}

function get_option( $k, $default = false ) {
	return array_key_exists( $k, $GLOBALS['sb_options'] ) ? $GLOBALS['sb_options'][ $k ] : $default;
}
function update_option( $k, $v, $autoload = null ) {
	$GLOBALS['sb_options'][ $k ] = $v;
	return true;
}
function delete_option( $k ) {
	unset( $GLOBALS['sb_options'][ $k ] );
	return true;
}

// ------------------------------------------------------------------- cron ---
function wp_next_scheduled( $hook, $args = array() ) {
	foreach ( $GLOBALS['sb_cron'] as $ev ) {
		if ( $ev['hook'] === $hook && $ev['args'] === $args ) {
			return $ev['ts'];
		}
	}
	return false;
}
function wp_schedule_single_event( $ts, $hook, $args = array() ) {
	$GLOBALS['sb_cron'][] = array( 'ts' => $ts, 'hook' => $hook, 'args' => $args );
}
function wp_schedule_event( $ts, $recurrence, $hook, $args = array() ) {
	$GLOBALS['sb_cron'][] = array( 'ts' => $ts, 'hook' => $hook, 'args' => $args );
}
function wp_clear_scheduled_hook( $hook, $args = array() ) {
	foreach ( $GLOBALS['sb_cron'] as $i => $ev ) {
		if ( $ev['hook'] === $hook && $ev['args'] === $args ) {
			unset( $GLOBALS['sb_cron'][ $i ] );
		}
	}
	$GLOBALS['sb_cron'] = array_values( $GLOBALS['sb_cron'] );
}

// ---------------------------------------------------------- post updating ---
// Mirrors the slices of wp_update_post()/core the module relies on: field
// merge, post_modified refresh, and _transition_post_status scheduling the
// publish_future_post single event when a post becomes `future`.
function wp_update_post( $arr, $wp_error = false ) {
	$id = (int) $arr['ID'];
	if ( ! isset( $GLOBALS['sb_posts'][ $id ] ) ) {
		return $wp_error ? new WP_Error( 'invalid_post', 'Invalid post ID.' ) : 0;
	}
	$p = $GLOBALS['sb_posts'][ $id ];
	foreach ( array( 'post_status', 'post_title', 'post_content', 'post_name', 'post_date', 'post_date_gmt' ) as $f ) {
		if ( array_key_exists( $f, $arr ) ) {
			$p->$f = $arr[ $f ];
		}
	}
	$p->post_modified     = gmdate( 'Y-m-d H:i:s', time() + wp_timezone()->getOffset( new DateTime( 'now' ) ) );
	$p->post_modified_gmt = gmdate( 'Y-m-d H:i:s' );
	if ( isset( $arr['post_status'] ) && $arr['post_status'] === 'future' ) {
		wp_clear_scheduled_hook( 'publish_future_post', array( $id ) );
		wp_schedule_single_event( strtotime( $p->post_date_gmt . ' UTC' ), 'publish_future_post', array( $id ) );
	}
	return $id;
}
function sb_delete_post( $id ) {
	unset( $GLOBALS['sb_posts'][ $id ], $GLOBALS['sb_meta'][ $id ] );
}
// Core's publish_future_post cron handler (also called by the module's sweep).
function check_and_publish_future_post( $id ) {
	$p = get_post( $id );
	if ( ! $p || $p->post_status !== 'future' ) {
		return;
	}
	if ( strtotime( $p->post_date_gmt . ' UTC' ) > time() ) {
		return; // core would reschedule
	}
	$GLOBALS['sb_posts'][ $id ]->post_status = 'publish'; // wp_publish_post: direct flip
	do_action( 'future_to_publish', get_post( $id ) );
}
// Run every due publish_future_post event (what WP-Cron would do).
function sb_run_due_cron() {
	foreach ( $GLOBALS['sb_cron'] as $i => $ev ) {
		if ( $ev['hook'] === 'publish_future_post' && $ev['ts'] <= time() ) {
			unset( $GLOBALS['sb_cron'][ $i ] );
			check_and_publish_future_post( $ev['args'][0] );
		}
	}
	$GLOBALS['sb_cron'] = array_values( $GLOBALS['sb_cron'] );
}

function get_posts( $args ) {
	$statuses = (array) ( isset( $args['post_status'] ) ? $args['post_status'] : array( 'publish' ) );
	$out      = array();
	foreach ( $GLOBALS['sb_posts'] as $p ) {
		if ( ! in_array( $p->post_status, $statuses, true ) ) {
			continue;
		}
		if ( isset( $args['meta_key'] ) ) {
			$v = get_post_meta( $p->ID, $args['meta_key'], true );
			if ( isset( $args['meta_value'] ) && $v != $args['meta_value'] ) {
				continue;
			}
			if ( ! isset( $args['meta_value'] ) && $v === '' ) {
				continue;
			}
		}
		$out[] = clone $p;
	}
	return $out;
}

class SB_Test_WPDB {
	public $posts = 'wp_posts';
	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		if ( $table !== $this->posts || ! isset( $where['ID'], $GLOBALS['sb_posts'][ $where['ID'] ] ) ) {
			return false;
		}
		foreach ( $data as $col => $val ) {
			$GLOBALS['sb_posts'][ $where['ID'] ]->$col = $val;
		}
		return 1;
	}
}
$GLOBALS['wpdb'] = new SB_Test_WPDB();

// ----------------------------------------- Duplicate Post 4.7 emulation -----
if ( $scenario === 'main' ) {
	// The presence check the module runs.
	eval( 'namespace Yoast\WP\Duplicate_Post; class Post_Republisher {}' );

	// Faithful to Post_Republisher::republish_scheduled_post + republish() in
	// the 4.7 source: meta merge (delete-then-add, DP internals excluded, NO
	// admin-settings consultation), then the element clone that carries the
	// copy's dates onto the original (the trap), then the after action, then
	// copy deletion + pointer cleanup.
	function dp_republish_scheduled_post( $copy ) {
		if ( (int) get_post_meta( $copy->ID, '_dp_is_rewrite_republish_copy', true ) !== 1 ) {
			return;
		}
		$original = get_post( (int) get_post_meta( $copy->ID, '_dp_original', true ) );
		if ( ! $original ) {
			return;
		}

		// Taxonomies: DP clears category then sets the ORIGINAL's terms to the
		// COPY's terms for every taxonomy (copy_post_taxonomies) — the wipe the
		// module's schedule-time mirroring defends against.
		wp_set_object_terms( $original->ID, array(), 'category' );
		foreach ( get_object_taxonomies( $original->post_type ) as $tax ) {
			wp_set_object_terms( $original->ID, wp_get_object_terms( $copy->ID, $tax ), $tax );
		}

		$exclude = array( '_edit_lock', '_edit_last', '_dp_original', '_dp_is_rewrite_republish_copy', '_dp_has_rewrite_republish_copy', '_dp_has_been_republished', '_dp_creation_date_gmt' );
		foreach ( ( isset( $GLOBALS['sb_meta'][ $copy->ID ] ) ? $GLOBALS['sb_meta'][ $copy->ID ] : array() ) as $k => $v ) {
			if ( ! in_array( $k, $exclude, true ) ) {
				delete_post_meta( $original->ID, $k );
				update_post_meta( $original->ID, $k, $v );
			}
		}

		wp_update_post( array(
			'ID'            => $original->ID,
			'post_title'    => $copy->post_title,
			'post_content'  => $copy->post_content,
			'post_name'     => $original->post_name,
			'post_status'   => 'publish',
			'post_date'     => $copy->post_date,     // ← the re-dating trap
			'post_date_gmt' => $copy->post_date_gmt,
		) );

		update_post_meta( $copy->ID, '_dp_has_been_republished', '1' );
		do_action( 'duplicate_post_after_republish', get_post( $copy->ID ), $original );
		sb_delete_post( $copy->ID );
		delete_post_meta( $original->ID, '_dp_has_rewrite_republish_copy' );
	}
	add_action( 'future_to_publish', 'dp_republish_scheduled_post', 10 );
}

// ------------------------------------------------------------ load plugin ---
require dirname( __DIR__ ) . '/sitebridge-ai.php';

// -------------------------------------------------------------- assertions --
$pass = 0;
$fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  \033[32mok\033[0m   $label\n";
	} else {
		$fail++;
		echo "  \033[31mFAIL\033[0m $label\n";
	}
}
function section( $t ) { echo "\n\033[1m$t\033[0m\n"; }
function req( array $p ) { return new WP_REST_Request( $p ); }
function err_code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : null; }

// ============================================================== no-dp =======
if ( $scenario === 'no-dp' ) {
	section( 'Duplicate Post inactive' );
	$r = sitebridge_schedule_republish_rest( req( array( 'draft_id' => 2, 'target_id' => 1, 'publish_at' => '2030-01-01T09:00:00' ) ) );
	ok( err_code( $r ) === 'duplicate_post_missing', 'schedule refuses with duplicate_post_missing' );
	ok( is_wp_error( $r ) && $r->data['status'] === 501, 'and a 501' );
	sitebridge_republish_sweep_run(); // must be a silent no-op
	ok( true, 'sweep is a no-op without Duplicate Post' );
	echo "\n$pass passed, $fail failed\n";
	exit( $fail ? 1 : 0 );
}

// =============================================================== main =======
// Seed: the live post (LA site, so 14:22:09 local = 21:22:09 GMT in March/PDT)
// and its staging draft carrying new content + Yoast meta.
sb_add_post( array(
	'ID' => 3571, 'post_type' => 'post', 'post_status' => 'publish',
	'post_title' => 'Old Title', 'post_content' => '<p>old body</p>',
	'post_name' => 'what-is-the-life-expectancy-of-a-water-softeners',
	'post_date' => '2025-03-11 14:22:09', 'post_date_gmt' => '2025-03-11 21:22:09',
	'post_modified' => '2025-03-11 14:22:09', 'post_modified_gmt' => '2025-03-11 21:22:09',
) );
sb_add_post( array(
	'ID' => 4489, 'post_type' => 'post', 'post_status' => 'draft',
	'post_title' => 'New Title', 'post_content' => '<p>new body</p>',
	'post_name' => 'staging-draft-4489',
	'post_date' => gmdate( 'Y-m-d H:i:s' ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s' ),
	'post_modified' => gmdate( 'Y-m-d H:i:s' ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s' ),
) );
update_post_meta( 4489, '_yoast_wpseo_title', 'New SEO Title' );
update_post_meta( 4489, '_yoast_wpseo_metadesc', 'New meta description.' );
// Live post has real terms; the fresh staging draft carries only the
// auto-assigned default category (what wp_insert_post gives a new post).
$GLOBALS['sb_options']['default_category'] = 1;
wp_set_object_terms( 3571, array( 5, 7 ), 'category' );
wp_set_object_terms( 3571, array( 9 ), 'post_tag' );
wp_set_object_terms( 4489, array( 1 ), 'category' );

$future_local = new DateTimeImmutable( '+7 days 09:00', wp_timezone() );
$publish_at   = $future_local->format( 'Y-m-d\TH:i:s' );

section( 'preflight validation (nothing may be written on failure)' );
$r = sitebridge_schedule_republish_rest( req( array( 'draft_id' => 4489, 'target_id' => 3571, 'publish_at' => $publish_at, 'delete_copy_after' => false ) ) );
ok( err_code( $r ) === 'unsupported_option', 'delete_copy_after:false is refused (DP always deletes the copy)' );
$r = sitebridge_schedule_republish_rest( req( array( 'draft_id' => 3571, 'target_id' => 3571, 'publish_at' => $publish_at ) ) );
ok( err_code( $r ) === 'same_post', 'draft_id == target_id refused' );
$r = sitebridge_schedule_republish_rest( req( array( 'draft_id' => 9999, 'target_id' => 3571, 'publish_at' => $publish_at ) ) );
ok( err_code( $r ) === 'draft_not_found', 'missing draft → draft_not_found' );
$r = sitebridge_schedule_republish_rest( req( array( 'draft_id' => 4489, 'target_id' => 9999, 'publish_at' => $publish_at ) ) );
ok( err_code( $r ) === 'target_not_found', 'missing target → target_not_found' );
$r = sitebridge_schedule_republish_rest( req( array( 'draft_id' => 3571, 'target_id' => 4489, 'publish_at' => $publish_at ) ) );
ok( err_code( $r ) === 'target_not_published', 'draft as target → target_not_published' );
sb_add_post( array( 'ID' => 77, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'x', 'post_content' => '', 'post_name' => 'x', 'post_date' => gmdate( 'Y-m-d H:i:s' ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s' ), 'post_modified' => '', 'post_modified_gmt' => '' ) );
$r = sitebridge_schedule_republish_rest( req( array( 'draft_id' => 77, 'target_id' => 3571, 'publish_at' => $publish_at ) ) );
ok( err_code( $r ) === 'post_type_mismatch', 'page draft vs post target → post_type_mismatch' );
$r = sitebridge_schedule_republish_rest( req( array( 'draft_id' => 4489, 'target_id' => 3571, 'publish_at' => $publish_at, 'timezone' => 'Mars/Olympus' ) ) );
ok( err_code( $r ) === 'bad_timezone', 'bad IANA name → bad_timezone' );
$r = sitebridge_schedule_republish_rest( req( array( 'draft_id' => 4489, 'target_id' => 3571, 'publish_at' => 'not a datetime' ) ) );
ok( err_code( $r ) === 'bad_datetime', 'unparseable publish_at → bad_datetime' );
$r = sitebridge_schedule_republish_rest( req( array( 'draft_id' => 4489, 'target_id' => 3571, 'publish_at' => '2020-01-01T00:00:00' ) ) );
ok( err_code( $r ) === 'publish_at_past', 'past publish_at → publish_at_past' );
ok( get_post( 4489 )->post_status === 'draft', 'draft untouched by all failed preflights' );
ok( get_post_meta( 4489, '_dp_original', true ) === '', 'no DP meta staged by failed preflights' );

section( 'happy path: schedule' );
$r = sitebridge_schedule_republish_rest( req( array( 'draft_id' => 4489, 'target_id' => 3571, 'publish_at' => $publish_at ) ) );
ok( ! is_wp_error( $r ) && $r['scheduled'] === true, 'schedules' );
ok( $r['copy_status'] === 'future', 'copy is status future' );
ok( $r['dp_original_set'] === true, '_dp_original verified in the response' );
ok( $r['preserve_publish_date'] === true, 'preserve_publish_date defaults true' );
ok( $r['original_post_date'] === '2025-03-11T14:22:09', 'original publish date captured in response' );
ok( $r['target_url'] === 'https://example.com/blog/what-is-the-life-expectancy-of-a-water-softeners/', 'target_url is the live permalink' );
$expected_utc = $future_local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s' ) . 'Z';
ok( $r['publish_at_utc'] === $expected_utc, "site-time publish_at converted to UTC ($expected_utc)" );
ok( $r['cron_event_utc'] === $expected_utc, 'publish_future_post cron event confirmed at that time' );
ok( (int) get_post_meta( 4489, '_dp_is_rewrite_republish_copy', true ) === 1, 'copy marked _dp_is_rewrite_republish_copy' );
ok( (int) get_post_meta( 4489, '_dp_original', true ) === 3571, 'copy linked via _dp_original' );
ok( get_post_meta( 4489, '_dp_creation_date_gmt', true ) !== '', 'copy carries _dp_creation_date_gmt' );
ok( (int) get_post_meta( 3571, '_dp_has_rewrite_republish_copy', true ) === 4489, 'original points at the copy (_dp_has_rewrite_republish_copy)' );
ok( get_post_meta( 4489, '_sitebridge_restore_post_date_gmt', true ) === '2025-03-11 21:22:09', 'restore stash holds the original GMT date' );
ok( $r['taxonomies_mirrored'] === array( 'category', 'post_tag' ), 'target terms mirrored onto the term-less draft (DP\'s merge would wipe them otherwise)' );
ok( wp_get_object_terms( 4489, 'category' ) === array( 5, 7 ) && wp_get_object_terms( 4489, 'post_tag' ) === array( 9 ), 'draft now carries the live post\'s categories + tags' );
$t = get_post( 3571 );
ok( $t->post_status === 'publish' && $t->post_content === '<p>old body</p>' && $t->post_date === '2025-03-11 14:22:09', 'original is untouched at schedule time (stays live with OLD content)' );

section( 'conflicts while scheduled' );
sb_add_post( array( 'ID' => 5000, 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'other', 'post_content' => '', 'post_name' => 'other', 'post_date' => gmdate( 'Y-m-d H:i:s' ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s' ), 'post_modified' => '', 'post_modified_gmt' => '' ) );
$r = sitebridge_schedule_republish_rest( req( array( 'draft_id' => 5000, 'target_id' => 3571, 'publish_at' => $publish_at ) ) );
ok( err_code( $r ) === 'target_has_pending_copy', 'second draft against same target → target_has_pending_copy' );
sb_add_post( array( 'ID' => 6000, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'other live', 'post_content' => '', 'post_name' => 'other-live', 'post_date' => '2024-01-01 00:00:00', 'post_date_gmt' => '2024-01-01 08:00:00', 'post_modified' => '', 'post_modified_gmt' => '' ) );
$r = sitebridge_schedule_republish_rest( req( array( 'draft_id' => 4489, 'target_id' => 6000, 'publish_at' => $publish_at ) ) );
ok( err_code( $r ) === 'draft_linked_elsewhere', 'scheduled copy against a different target → draft_linked_elsewhere' );

section( 'reschedule (same copy, same target, new time)' );
$later    = $future_local->modify( '+1 day' );
$r        = sitebridge_schedule_republish_rest( req( array( 'draft_id' => 4489, 'target_id' => 3571, 'publish_at' => $later->format( 'Y-m-d\TH:i:s' ) ) ) );
ok( ! is_wp_error( $r ) && $r['rescheduled'] === true, 'rescheduling an already-scheduled sitebridge copy works in one call' );
$new_utc = $later->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s' ) . 'Z';
ok( $r['publish_at_utc'] === $new_utc && $r['cron_event_utc'] === $new_utc, 'cron event moved to the new time (single event)' );
$events = 0;
foreach ( $GLOBALS['sb_cron'] as $ev ) { if ( $ev['hook'] === 'publish_future_post' && $ev['args'] === array( 4489 ) ) { $events++; } }
ok( $events === 1, 'exactly one publish_future_post event for the copy' );

section( 'list while queued' );
$l = sitebridge_list_republishes_rest( req( array() ) );
ok( $l['count'] === 1 && $l['scheduled'][0]['draft_id'] === 4489, 'list shows the queued copy' );
ok( $l['scheduled'][0]['target_id'] === 3571 && $l['scheduled'][0]['target_url'] === 'https://example.com/blog/what-is-the-life-expectancy-of-a-water-softeners/', 'with target id + URL' );
ok( $l['scheduled'][0]['copy_status'] === 'future' && $l['scheduled'][0]['past_due'] === false, 'status future, not past due' );
ok( $l['scheduled'][0]['staged_by_sitebridge'] === true && $l['scheduled'][0]['preserve_publish_date'] === true, 'flags carried' );

section( 'the schedule fires: merge + date restore (the whole point)' );
// Simulate the scheduled time arriving: shift the copy's dates and the cron
// event into the past, then run due cron exactly as WP-Cron would.
$GLOBALS['sb_posts'][4489]->post_date     = gmdate( 'Y-m-d H:i:s', time() - 700 );
$GLOBALS['sb_posts'][4489]->post_date_gmt = gmdate( 'Y-m-d H:i:s', time() - 700 );
$scheduled_date_gmt = $GLOBALS['sb_posts'][4489]->post_date_gmt;
foreach ( $GLOBALS['sb_cron'] as &$ev ) { if ( $ev['hook'] === 'publish_future_post' ) { $ev['ts'] = time() - 700; } }
unset( $ev );
sb_run_due_cron();

$t = get_post( 3571 );
ok( $t !== null && get_post( 4489 ) === null, 'copy deleted, original still exists (same post ID)' );
ok( $t->post_title === 'New Title' && $t->post_content === '<p>new body</p>', 'original carries the NEW content' );
ok( $t->post_name === 'what-is-the-life-expectancy-of-a-water-softeners', 'slug/permalink unchanged' );
ok( $t->post_status === 'publish', 'original is published' );
ok( $t->post_date === '2025-03-11 14:22:09' && $t->post_date_gmt === '2025-03-11 21:22:09', 'publish date RESTORED (datePublished did not move)' );
ok( $t->post_date_gmt !== $scheduled_date_gmt, 'i.e. the copy\'s scheduled date did not stick' );
ok( $t->post_modified_gmt >= gmdate( 'Y-m-d H:i:s', time() - 60 ), 'modified date is merge time (dateModified moved)' );
ok( get_post_meta( 3571, '_yoast_wpseo_title', true ) === 'New SEO Title' && get_post_meta( 3571, '_yoast_wpseo_metadesc', true ) === 'New meta description.', 'Yoast meta landed on the original' );
ok( wp_get_object_terms( 3571, 'category' ) === array( 5, 7 ) && wp_get_object_terms( 3571, 'post_tag' ) === array( 9 ), 'categories + tags survived the merge (round-tripped through the copy)' );
ok( get_post_meta( 3571, '_dp_has_rewrite_republish_copy', true ) === '', 'copy pointer cleared on the original' );
$log = get_option( 'sitebridge_republish_log' );
$merged = array_values( array_filter( (array) $log, function ( $e ) { return $e['event'] === 'merged'; } ) );
ok( count( $merged ) === 1 && $merged[0]['draft_id'] === 4489 && $merged[0]['target_id'] === 3571, 'one merged log entry' );
ok( $merged[0]['publish_date_preserved'] === true, 'log confirms the date was preserved' );
ok( $merged[0]['url'] === 'https://example.com/blog/what-is-the-life-expectancy-of-a-water-softeners/', 'log carries the live URL' );
ok( array_key_exists( 'cache_purge_fired', $merged[0] ), 'log records the cache purge attempt' );

section( 'opt-in re-dating: preserve_publish_date false (+ copy_yoast_meta false)' );
sb_add_post( array( 'ID' => 5001, 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Redate New', 'post_content' => '<p>redate</p>', 'post_name' => 'redate-draft', 'post_date' => gmdate( 'Y-m-d H:i:s' ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s' ), 'post_modified' => '', 'post_modified_gmt' => '' ) );
wp_set_object_terms( 5001, array( 12 ), 'category' ); // deliberately chosen — must NOT be overwritten
wp_set_object_terms( 6000, array( 5 ), 'category' );
$r = sitebridge_schedule_republish_rest( req( array( 'draft_id' => 5001, 'target_id' => 6000, 'publish_at' => $publish_at, 'preserve_publish_date' => false, 'copy_yoast_meta' => false ) ) );
ok( ! is_wp_error( $r ) && $r['preserve_publish_date'] === false, 'schedules with preservation off' );
ok( ! in_array( 'category', $r['taxonomies_mirrored'], true ) && wp_get_object_terms( 5001, 'category' ) === array( 12 ), 'a draft with deliberately chosen terms is left alone' );
ok( get_post_meta( 5001, '_sitebridge_restore_post_date_gmt', true ) === '', 'no restore stash written' );
$GLOBALS['sb_posts'][5001]->post_date     = gmdate( 'Y-m-d H:i:s', time() - 700 );
$GLOBALS['sb_posts'][5001]->post_date_gmt = gmdate( 'Y-m-d H:i:s', time() - 700 );
$redate_gmt = $GLOBALS['sb_posts'][5001]->post_date_gmt;
foreach ( $GLOBALS['sb_cron'] as &$ev ) { if ( $ev['hook'] === 'publish_future_post' && $ev['args'] === array( 5001 ) ) { $ev['ts'] = time() - 700; } }
unset( $ev );
sb_run_due_cron();
$t2 = get_post( 6000 );
ok( $t2->post_date_gmt === $redate_gmt, 'original re-dated to the scheduled date (opt-in path)' );
$log = get_option( 'sitebridge_republish_log' );
$last = end( $log );
ok( $last['event'] === 'merged' && $last['publish_date_preserved'] === 'off', 'log records preservation off' );
ok( $last['yoast_meta_copied'] === array(), 'no explicit Yoast copy when copy_yoast_meta false' );

section( 'cancel' );
sb_add_post( array( 'ID' => 5002, 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Cancel Me', 'post_content' => '<p>x</p>', 'post_name' => 'cancel-draft', 'post_date' => gmdate( 'Y-m-d H:i:s' ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s' ), 'post_modified' => '', 'post_modified_gmt' => '' ) );
sb_add_post( array( 'ID' => 6001, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Cancel Target', 'post_content' => '<p>y</p>', 'post_name' => 'cancel-target', 'post_date' => '2024-06-01 10:00:00', 'post_date_gmt' => '2024-06-01 17:00:00', 'post_modified' => '', 'post_modified_gmt' => '' ) );
$r = sitebridge_schedule_republish_rest( req( array( 'draft_id' => 5002, 'target_id' => 6001, 'publish_at' => $publish_at ) ) );
ok( ! is_wp_error( $r ), 'scheduled the cancel candidate' );
$r = sitebridge_cancel_republish_rest( req( array( 'draft_id' => 5002 ) ) );
ok( ! is_wp_error( $r ) && $r['canceled'] === true && $r['copy_status'] === 'draft', 'cancel sets the copy back to draft' );
ok( (int) get_post_meta( 5002, '_dp_original', true ) === 6001, '_dp_original link left intact for rescheduling' );
ok( wp_next_scheduled( 'publish_future_post', array( 5002 ) ) === false, 'cron event cleared' );
$r = sitebridge_cancel_republish_rest( req( array( 'draft_id' => 5002 ) ) );
ok( err_code( $r ) === 'not_scheduled', 'canceling a non-scheduled copy → not_scheduled' );
$r = sitebridge_cancel_republish_rest( req( array( 'draft_id' => 6001 ) ) );
ok( err_code( $r ) === 'not_a_rewrite_copy', 'canceling a non-copy → not_a_rewrite_copy' );
$r = sitebridge_schedule_republish_rest( req( array( 'draft_id' => 5002, 'target_id' => 6001, 'publish_at' => $publish_at ) ) );
ok( ! is_wp_error( $r ) && $r['scheduled'] === true, 'canceled copy reschedules cleanly' );
$l = sitebridge_list_republishes_rest( req( array() ) );
ok( $l['count'] === 1 && $l['scheduled'][0]['draft_id'] === 5002, 'list shows it queued again' );

section( 'daily sweep: a copy stuck past its schedule gets merged' );
// Craft the stuck state directly (a missed cron: status future, time long past).
$GLOBALS['sb_posts'][5002]->post_date     = gmdate( 'Y-m-d H:i:s', time() - 3600 );
$GLOBALS['sb_posts'][5002]->post_date_gmt = gmdate( 'Y-m-d H:i:s', time() - 3600 );
wp_clear_scheduled_hook( 'publish_future_post', array( 5002 ) ); // the "missed" event
$l = sitebridge_list_republishes_rest( req( array() ) );
ok( $l['scheduled'][0]['past_due'] === true, 'list flags it past_due' );
sitebridge_republish_sweep_run();
ok( get_post( 5002 ) === null && get_post( 6001 )->post_title === 'Cancel Me', 'sweep merged the stuck copy' );
ok( get_post( 6001 )->post_date_gmt === '2024-06-01 17:00:00', 'sweep merge also preserved the publish date' );
$log    = get_option( 'sitebridge_republish_log' );
$sweeps = array_values( array_filter( (array) $log, function ( $e ) { return $e['event'] === 'sweep_republish'; } ) );
ok( count( $sweeps ) === 1 && $sweeps[0]['draft_id'] === 5002, 'sweep logged' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
