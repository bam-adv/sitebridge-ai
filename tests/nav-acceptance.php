<?php
/**
 * Acceptance harness for the NAVIGATION module (added with v1.14.0).
 *
 * No WordPress, no PHPUnit, no network: stubs just enough WP/ACF to load
 * sitebridge-ai.php and drive the nav REST callbacks directly against an
 * in-memory ACF option seeded with a Profile-A-shaped 8-item nav.
 *
 * Run:  php tests/nav-acceptance.php     (exit 0 = all assertions passed)
 *
 * This covers the LOGIC. The live acceptance pass — real ACF serialization, the
 * theme's render — still belongs on a staging site before touching a client.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', __DIR__ );
define( 'SITEBRIDGE_NAV_OPTION_ID', 'option' );

// ---------------------------------------------------------------- WP stubs --
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

/** Close enough to core: strip tags, collapse whitespace, trim. No entity encoding. */
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
	public function get_status() { return isset( $this->data['status'] ) ? $this->data['status'] : 0; }
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

// ------------------------------------------------------------- ACF stubs ----
$GLOBALS['acf_store'] = array();
function get_field( $field, $post_id = false ) {
	return isset( $GLOBALS['acf_store'][ $field ] ) ? $GLOBALS['acf_store'][ $field ] : null;
}
function update_field( $field, $value, $post_id = false ) {
	$GLOBALS['acf_store'][ $field ] = $value;
	$GLOBALS['acf_writes']          = ( isset( $GLOBALS['acf_writes'] ) ? $GLOBALS['acf_writes'] : 0 ) + 1;
	return true;
}
function get_fields( $post_id = false ) { return $GLOBALS['acf_store']; }

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
function section( $t ) { echo "\n\033[1m$t\033[0m\n"; }
function req( $params ) { return new WP_REST_Request( $params ); }

/** A Profile-A-shaped nav: 8 top-level items, one with a fat mega-menu. */
function fixture_nav() {
	$link = function ( $t, $u ) { return array( 'title' => $t, 'url' => $u, 'target' => '' ); };
	$sub  = function ( $t, $u ) use ( $link ) { return array( 'link' => $link( $t, $u ), 'link_style' => 'bold-caret' ); };
	$top  = function ( $t, $u, $subs = false ) use ( $link ) {
		return array(
			'nav_item_link'           => $link( $t, $u ),
			'nav_item_link_css_class' => '',
			'nav_item_sub_items'      => $subs,
		);
	};
	return array(
		'nav_items' => array(
			$top( 'Water Softeners', 'https://example-water.test/products/water-softener', array(
				array(
					'sub_item_title' => 'Services',
					'sub_item_links' => array(
						$sub( 'Softener Rental', 'https://example-water.test/rental' ),
						$sub( 'Salt Delivery', 'https://example-water.test/salt-delivery' ),
					),
				),
			) ),
			$top( 'Solution Center', '#', array(
				array( 'sub_item_title' => '', 'sub_item_links' => array( $sub( 'Hard Water', 'https://example-water.test/problems/hard-water' ) ) ),
				array( 'sub_item_title' => '', 'sub_item_links' => array( $sub( 'PFAS', 'https://example-water.test/problems/pfas' ) ) ),
				array( 'sub_item_title' => 'Counties', 'sub_item_links' => array( $sub( 'Duval', 'https://example-water.test/duval' ) ) ),
			) ),
			$top( 'Commercial/Industrial', 'https://example-water.test/commercial-industrial' ),
			$top( 'Water Delivery', '#', array(
				array( 'sub_item_title' => 'Bottled', 'sub_item_links' => array( $sub( '5 Gallon', 'https://example-water.test/5-gallon' ) ) ),
			) ),
			$top( 'About Us', 'https://example-water.test/about-us' ),
			$top( 'Locations', 'https://example-water.test/locations' ),
			$top( 'Specials', 'https://example-water.test/specials' ),
			$top( 'Contact Us', 'https://example-water.test/contact-us' ),
		),
	);
}
function reset_nav() {
	$GLOBALS['acf_store'] = array( SITEBRIDGE_NAV_FIELD => fixture_nav() );
	$GLOBALS['acf_writes'] = 0;
}
function nav() { return $GLOBALS['acf_store'][ SITEBRIDGE_NAV_FIELD ]; }
function writes() { return $GLOBALS['acf_writes']; }

$SNAPSHOT = fixture_nav();

/* ==========================================================================
 * 1. remove_nav_item — preview / confirm / force / ambiguity
 * ======================================================================== */
section( 'remove-item: matcher validation' );
reset_nav();
$r = sitebridge_nav_rest_remove_item( req( array() ) );
ok( is_wp_error( $r ) && $r->get_error_code() === 'bad_input' && $r->get_status() === 400, 'no matcher => 400 bad_input' );
ok( writes() === 0, 'no matcher => nothing written' );

section( 'remove-item: preview (no confirm)' );
reset_nav();
$r = sitebridge_nav_rest_remove_item( req( array( 'url' => 'https://example-water.test/contact-us' ) ) );
ok( ! is_wp_error( $r ), 'preview returns a result, not an error' );
ok( $r['removed'] === 0, 'preview: removed = 0' );
ok( $r['confirm_required'] === true, 'preview: confirm_required = true' );
ok( $r['force_required'] === false, 'preview: no dropdown => force_required false' );
ok( $r['matched']['title'] === 'Contact Us' && $r['matched']['index'] === 7, 'preview: matched index/title' );
ok( isset( $r['would_remove']['nav_item_link']['url'] ), 'preview: returns full item JSON' );
ok( $r['nav_items_total'] === 8, 'preview: nav_items_total = 8' );
ok( writes() === 0, 'preview: nothing written' );
ok( nav() == $SNAPSHOT, 'preview: nav byte-identical to snapshot' );

section( 'remove-item: trailing-slash / scheme tolerance' );
reset_nav();
$r = sitebridge_nav_rest_remove_item( req( array( 'url' => 'https://example-water.test/contact-us/' ) ) );
ok( ! is_wp_error( $r ) && $r['matched']['title'] === 'Contact Us', 'trailing slash still matches' );
$r = sitebridge_nav_rest_remove_item( req( array( 'url' => '  https://example-water.test/contact-us  ' ) ) );
ok( ! is_wp_error( $r ) && $r['matched']['title'] === 'Contact Us', 'surrounding whitespace still matches' );

section( 'remove-item: confirm on a no-dropdown item' );
reset_nav();
$r = sitebridge_nav_rest_remove_item( req( array( 'url' => 'https://example-water.test/contact-us', 'confirm' => true ) ) );
ok( ! is_wp_error( $r ), 'confirm: succeeds' );
ok( $r['removed'] === 1, 'confirm: removed = 1' );
ok( $r['nav_items_remaining'] === 7, 'confirm: 7 items remain' );
ok( $r['had_dropdown'] === false && $r['columns_removed'] === 0 && $r['links_removed'] === 0, 'confirm: reports no dropdown destroyed' );
ok( $r['removed_item']['nav_item_link']['title'] === 'Contact Us', 'confirm: returns the removed item JSON' );
ok( writes() === 1, 'confirm: exactly one ACF write' );
$after = nav();
ok( count( $after['nav_items'] ) === 7, 'confirm: nav has 7 top-level items' );
$titles = array_map( function ( $i ) { return $i['nav_item_link']['title']; }, $after['nav_items'] );
ok( ! in_array( 'Contact Us', $titles, true ), 'confirm: Contact Us is gone' );
ok( array_keys( $after['nav_items'] ) === range( 0, 6 ), 'confirm: nav_items re-indexed 0..6 (no holes)' );
// Byte-for-byte preservation of every untouched sibling.
$expected_rest = array_values( array_filter( $SNAPSHOT['nav_items'], function ( $i ) { return $i['nav_item_link']['title'] !== 'Contact Us'; } ) );
ok( $after['nav_items'] === $expected_rest, 'confirm: all other items byte-identical to snapshot' );

section( 'remove-item: dropdown protection' );
reset_nav();
$r = sitebridge_nav_rest_remove_item( req( array( 'title' => 'Solution Center', 'confirm' => true ) ) );
ok( is_wp_error( $r ) && $r->get_error_code() === 'dropdown_protected' && $r->get_status() === 409, 'confirm w/o force on a dropdown => 409 dropdown_protected' );
ok( strpos( $r->get_error_message(), '3 column(s)' ) !== false && strpos( $r->get_error_message(), '3 link(s)' ) !== false, 'refusal reports column + link counts' );
ok( writes() === 0, 'refusal: nothing written' );
ok( nav() == $SNAPSHOT, 'refusal: nav unchanged' );

reset_nav();
$r = sitebridge_nav_rest_remove_item( req( array( 'title' => 'Solution Center' ) ) );
ok( ! is_wp_error( $r ) && $r['force_required'] === true, 'preview on a dropdown item flags force_required' );
ok( strpos( $r['message'], 'force=true' ) !== false, 'preview message names force=true' );

reset_nav();
$r = sitebridge_nav_rest_remove_item( req( array( 'title' => 'Solution Center', 'confirm' => true, 'force' => true ) ) );
ok( ! is_wp_error( $r ) && $r['removed'] === 1, 'confirm+force removes the dropdown item' );
ok( $r['had_dropdown'] === true && $r['columns_removed'] === 3 && $r['links_removed'] === 3, 'confirm+force reports what was destroyed' );
ok( count( $r['removed_item']['nav_item_sub_items'] ) === 3, 'confirm+force returns the whole dropdown in removed_item' );
ok( count( nav()['nav_items'] ) === 7, 'confirm+force: 7 items remain' );

section( 'remove-item: force alone is not enough' );
reset_nav();
$r = sitebridge_nav_rest_remove_item( req( array( 'title' => 'Solution Center', 'force' => true ) ) );
ok( ! is_wp_error( $r ) && $r['removed'] === 0 && $r['confirm_required'] === true, 'force without confirm still only previews' );
ok( writes() === 0, 'force without confirm writes nothing' );

section( 'remove-item: ambiguity abort' );
reset_nav();
$r = sitebridge_nav_rest_remove_item( req( array( 'url' => '#', 'confirm' => true, 'force' => true ) ) );
ok( is_wp_error( $r ) && $r->get_error_code() === 'item_ambiguous' && $r->get_status() === 409, 'url "#" matches 2 items => 409 item_ambiguous' );
ok( strpos( $r->get_error_message(), 'Solution Center' ) !== false && strpos( $r->get_error_message(), 'Water Delivery' ) !== false, 'ambiguity error lists both matches' );
ok( count( $r->data['matches'] ) === 2, 'ambiguity error carries the match list in data' );
ok( writes() === 0, 'ambiguity: nothing written' );
ok( nav() == $SNAPSHOT, 'ambiguity: nav unchanged' );

// url + title together disambiguates the "#" parents.
reset_nav();
$r = sitebridge_nav_rest_remove_item( req( array( 'url' => '#', 'title' => 'Water Delivery', 'confirm' => true, 'force' => true ) ) );
ok( ! is_wp_error( $r ) && $r['removed'] === 1 && $r['title'] === 'Water Delivery', 'url + title narrows to one item' );

section( 'remove-item: not found' );
reset_nav();
$r = sitebridge_nav_rest_remove_item( req( array( 'title' => 'Careers', 'confirm' => true ) ) );
ok( is_wp_error( $r ) && $r->get_error_code() === 'item_not_found' && $r->get_status() === 404, 'no match => 404 item_not_found' );
ok( strpos( $r->get_error_message(), 'Water Softeners' ) !== false, 'not-found error lists the actual top-level items' );
ok( count( $r->data['nav_items'] ) === 8, 'not-found error carries all 8 items in data' );
ok( writes() === 0, 'not found: nothing written' );

section( 'remove-item: title matching is case/entity tolerant' );
reset_nav();
$r = sitebridge_nav_rest_remove_item( req( array( 'title' => 'contact us' ) ) );
ok( ! is_wp_error( $r ) && $r['matched']['title'] === 'Contact Us', 'title match is case-insensitive' );
$GLOBALS['acf_store'][ SITEBRIDGE_NAV_FIELD ]['nav_items'][7]['nav_item_link']['title'] = 'Sales &amp; Service';
$r = sitebridge_nav_rest_remove_item( req( array( 'title' => 'Sales & Service' ) ) );
ok( ! is_wp_error( $r ), 'plain "&" matcher finds a stored "&amp;" title' );
$r = sitebridge_nav_rest_remove_item( req( array( 'title' => 'Sales &amp; Service' ) ) );
ok( ! is_wp_error( $r ), 'entity matcher finds the same title' );

section( 'remove-item: round-trip via add_nav_link' );
reset_nav();
$r = sitebridge_nav_rest_remove_item( req( array( 'url' => 'https://example-water.test/contact-us', 'confirm' => true ) ) );
$removed = $r['removed_item'];
$a = sitebridge_nav_rest_add_link( req( array(
	'title'    => $removed['nav_item_link']['title'],
	'url'      => $removed['nav_item_link']['url'],
	'target'   => $removed['nav_item_link']['target'],
	'position' => 7,
) ) );
ok( ! is_wp_error( $a ) && $a['added'] === 'top_level', 're-add from removed_item succeeds' );
ok( nav() == $SNAPSHOT, 'nav round-trips byte-identical to the snapshot' );

/* ==========================================================================
 * 2. Quirk 2 — title-only rename via replace_nav_link
 * ======================================================================== */
section( 'replace-link: title-only rename (new_url optional)' );
reset_nav();
$r = sitebridge_nav_rest_replace_link( req( array(
	'old_url'   => 'https://example-water.test/commercial-industrial',
	'new_title' => 'Commercial',
) ) );
ok( ! is_wp_error( $r ) && $r['replaced'] === 1, 'rename without new_url replaces 1' );
ok( $r['title_only'] === true && $r['new_url'] === null, 'response flags title_only' );
$item = nav()['nav_items'][2];
ok( $item['nav_item_link']['title'] === 'Commercial', 'title updated' );
ok( $item['nav_item_link']['url'] === 'https://example-water.test/commercial-industrial', 'url untouched' );

reset_nav();
$r = sitebridge_nav_rest_replace_link( req( array( 'old_url' => 'https://example-water.test/about-us' ) ) );
ok( is_wp_error( $r ) && $r->get_error_code() === 'bad_input', 'neither new_url nor new_title => 400' );
ok( writes() === 0, 'bad input: nothing written' );

reset_nav();
$r = sitebridge_nav_rest_replace_link( req( array( 'old_url' => '', 'new_url' => 'https://x.test/y' ) ) );
ok( is_wp_error( $r ) && $r->get_error_code() === 'bad_input', 'empty old_url => 400' );

// Scoped title-only rename (sub-link inside a column).
reset_nav();
$r = sitebridge_nav_rest_replace_link( req( array(
	'old_url'      => 'https://example-water.test/salt-delivery',
	'new_title'    => 'Salt & Delivery',
	'parent_title' => 'Water Softeners',
) ) );
ok( ! is_wp_error( $r ) && $r['replaced'] === 1 && $r['scoped'] === true, 'scoped title-only rename works' );
$l = nav()['nav_items'][0]['nav_item_sub_items'][0]['sub_item_links'][1];
ok( $l['link']['title'] === 'Salt & Delivery', 'scoped rename applied' );
ok( $l['link']['url'] === 'https://example-water.test/salt-delivery', 'scoped rename left url alone' );

// URL change still works exactly as before.
reset_nav();
$r = sitebridge_nav_rest_replace_link( req( array(
	'old_url' => 'https://example-water.test/specials',
	'new_url' => 'https://example-water.test/offers',
) ) );
ok( ! is_wp_error( $r ) && $r['replaced'] === 1 && $r['title_only'] === false, 'plain URL replace unchanged' );
ok( nav()['nav_items'][6]['nav_item_link']['url'] === 'https://example-water.test/offers', 'url replaced' );
ok( nav()['nav_items'][6]['nav_item_link']['title'] === 'Specials', 'title preserved when no new_title' );

/* ==========================================================================
 * 3. Quirk 1 — force_new_column
 * ======================================================================== */
section( 'add-link: force_new_column' );
reset_nav();
$before = count( nav()['nav_items'][1]['nav_item_sub_items'] );
// Quirk 1, shape A: parent already has TWO ""-titled columns, so a whitespace
// column_title normalizes to "" and hits both => 409, no way to add a third.
$r = sitebridge_nav_rest_add_link( req( array(
	'title'        => 'Iron',
	'url'          => 'https://example-water.test/problems/iron',
	'parent_title' => 'Solution Center',
	'column_title' => ' ',
	'create_column' => true,
) ) );
ok( is_wp_error( $r ) && $r->get_error_code() === 'column_ambiguous', 'old behaviour: blank column_title collides with 2 "" columns => 409' );
ok( count( nav()['nav_items'][1]['nav_item_sub_items'] ) === $before, 'old behaviour: no column created' );

// Quirk 1, shape B: exactly one column with that title — create_column silently
// MATCHES it instead of creating a second one.
reset_nav();
$r = sitebridge_nav_rest_add_link( req( array(
	'title'         => 'Duval',
	'url'           => 'https://example-water.test/counties/duval',
	'parent_title'  => 'Solution Center',
	'column_title'  => 'Counties',
	'create_column' => true,
) ) );
ok( ! is_wp_error( $r ) && $r['column_index'] === 2, 'old behaviour: create_column matched the existing "Counties" column' );
ok( count( nav()['nav_items'][1]['nav_item_sub_items'] ) === $before, 'old behaviour: still 3 columns' );

reset_nav();
$r = sitebridge_nav_rest_add_link( req( array(
	'title'            => 'Iron',
	'url'              => 'https://example-water.test/problems/iron',
	'parent_title'     => 'Solution Center',
	'column_title'     => ' ',
	'force_new_column' => true,
) ) );
ok( ! is_wp_error( $r ), 'force_new_column succeeds without create_column' );
ok( count( nav()['nav_items'][1]['nav_item_sub_items'] ) === $before + 1, 'force_new_column appended a 4th column' );
ok( $r['column_index'] === 3 && $r['column'] === '', 'new column is blank-titled at index 3' );
ok( count( nav()['nav_items'][1]['nav_item_sub_items'][3]['sub_item_links'] ) === 1, 'link landed in the new column' );
ok( nav()['nav_items'][1]['nav_item_sub_items'][0]['sub_item_links'][0]['link']['title'] === 'Hard Water', 'existing columns untouched' );

reset_nav();
$r = sitebridge_nav_rest_add_link( req( array(
	'title'            => 'Iron',
	'url'              => 'https://example-water.test/problems/iron',
	'parent_title'     => 'Solution Center',
	'force_new_column' => true,
	'column_index'     => 1,
) ) );
ok( is_wp_error( $r ) && $r->get_error_code() === 'bad_input', 'force_new_column + column_index => 400' );

// Single-column parent: force_new_column beats the "only one column" shortcut.
reset_nav();
$r = sitebridge_nav_rest_add_link( req( array(
	'title'            => 'Coolers',
	'url'              => 'https://example-water.test/coolers',
	'parent_title'     => 'Water Delivery',
	'column_title'     => 'Coolers',
	'force_new_column' => true,
) ) );
ok( ! is_wp_error( $r ) && $r['column_index'] === 1, 'force_new_column adds a 2nd column to a single-column parent' );
ok( nav()['nav_items'][3]['nav_item_sub_items'][1]['sub_item_title'] === 'Coolers', 'new column carries the given title' );

/* ==========================================================================
 * 4. Quirk 3 — HTML entities in titles
 * ======================================================================== */
section( 'titles: entities are decoded on write' );
reset_nav();
$r = sitebridge_nav_rest_add_link( req( array(
	'title'         => 'Duval &amp; Nassau',
	'url'           => 'https://example-water.test/duval-nassau',
	'parent_title'  => 'Solution Center',
	'column_title'  => 'St. Johns &amp; Nassau Counties',
	'create_column' => true,
) ) );
ok( ! is_wp_error( $r ), 'add with entity-laden titles succeeds' );
ok( $r['column'] === 'St. Johns & Nassau Counties', 'column title stored decoded' );
ok( $r['title'] === 'Duval & Nassau', 'link title stored decoded' );
$cols = nav()['nav_items'][1]['nav_item_sub_items'];
ok( $cols[3]['sub_item_title'] === 'St. Johns & Nassau Counties', 'stored column title has a literal &' );
ok( $cols[3]['sub_item_links'][0]['link']['title'] === 'Duval & Nassau', 'stored link title has a literal &' );

section( 'titles: matching is entity-tolerant both ways' );
reset_nav();
$GLOBALS['acf_store'][ SITEBRIDGE_NAV_FIELD ]['nav_items'][1]['nav_item_sub_items'][2]['sub_item_title'] = 'St. Johns &amp; Nassau';
$r = sitebridge_nav_rest_add_link( req( array(
	'title'        => 'Clay',
	'url'          => 'https://example-water.test/clay',
	'parent_title' => 'Solution Center',
	'column_title' => 'St. Johns & Nassau',
) ) );
ok( ! is_wp_error( $r ) && $r['column_index'] === 2, 'plain "&" column_title matches a stored "&amp;" column' );

reset_nav();
$GLOBALS['acf_store'][ SITEBRIDGE_NAV_FIELD ]['nav_items'][1]['nav_item_link']['title'] = 'Solution &amp; Center';
$r = sitebridge_nav_rest_remove_link( req( array(
	'url'          => 'https://example-water.test/problems/pfas',
	'parent_title' => 'Solution & Center',
) ) );
ok( ! is_wp_error( $r ) && $r['removed'] === 1, 'plain "&" parent_title matches a stored "&amp;" parent (remove-link)' );

section( 'add-link: duplicate top-level title still rejected' );
reset_nav();
$r = sitebridge_nav_rest_add_link( req( array( 'title' => 'about us', 'url' => 'https://x.test/a' ) ) );
ok( is_wp_error( $r ) && $r->get_error_code() === 'duplicate', 'case-insensitive duplicate top-level title => 409' );

/* ==========================================================================
 * 5. Regression — remove_nav_link still refuses top-level items
 * ======================================================================== */
section( 'regression: remove-link is unchanged' );
reset_nav();
$r = sitebridge_nav_rest_remove_link( req( array( 'url' => 'https://example-water.test/contact-us' ) ) );
ok( ! is_wp_error( $r ) && $r['removed'] === 0, 'remove-link still returns removed:0 for a top-level item' );
ok( count( nav()['nav_items'] ) === 8, 'remove-link left all 8 items in place' );

reset_nav();
$r = sitebridge_nav_rest_remove_link( req( array( 'url' => 'https://example-water.test/salt-delivery' ) ) );
ok( ! is_wp_error( $r ) && $r['removed'] === 1, 'remove-link still removes sub-links' );
ok( count( nav()['nav_items'][0]['nav_item_sub_items'][0]['sub_item_links'] ) === 1, 'sub-link removed from the column' );

reset_nav();
$r = sitebridge_nav_rest_remove_link( req( array( 'url' => 'https://example-water.test/5-gallon' ) ) );
ok( ! is_wp_error( $r ) && $r['removed'] === 1, 'remove-link empties a single-link column' );
ok( nav()['nav_items'][3]['nav_item_sub_items'] === false, 'emptied parent gets sub_items = false (pruning unchanged)' );

// ------------------------------------------------------------------ done ---
echo "\n";
echo $FAIL === 0
	? "\033[32mAll $PASS assertions passed.\033[0m\n"
	: "\033[31m$FAIL failed\033[0m, $PASS passed.\n";
exit( $FAIL === 0 ? 0 : 1 );
