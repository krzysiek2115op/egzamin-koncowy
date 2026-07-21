<?php
/**
 * Shim WordPressa dla harnessu procesu MP Lead Intake.
 *
 * Definiuje minimum stałych/funkcji WP + fałszywy $wpdb, aby uruchomić CAŁY
 * pipeline (11 działów) poza WordPressem i zweryfikować PROCES w runtime.
 *
 * Zasady stubów:
 *  - permisywne, ale wierne kontraktowi (sanitizery działają, transienty w pamięci);
 *  - wp_remote_get zwraca WP_Error -> wymusza łagodny fallback działu 3 (bez sieci);
 *  - wp_verify_nonce/check_ajax_referer: poprawny tylko token 'valid';
 *  - fałszywy $wpdb egzekwuje UNIQUE(nip) w mp_leads, by testować duplikaty.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

// --- Stałe ---
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fake-wp/' );
}
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'DB_NAME', 'wp_test_db' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );

// --- Magazyny stanu (in-memory) ---
$GLOBALS['__mp_transients'] = array();
$GLOBALS['__mp_options']    = array( 'admin_email' => 'admin@example.test' );
$GLOBALS['__mp_mails']      = array();
$GLOBALS['__mp_posts']      = array();
$GLOBALS['__mp_actions']    = array(); // do_action zliczone
// Sterowanie testami: co zwraca get_leads_by_nip; czy insert leada ma udać.
$GLOBALS['__mp_cfg'] = array(
	'leads_by_nip'         => array(), // wiersze zwracane przez get_leads_by_nip (AKTYWNE)
	'archived_lead'        => null,    // zarchiwizowany lead zwracany przez get_archived_lead_by_nip
	'valid_nonce'          => 'valid',
	'fail_activity_insert' => false,   // symulacja awarii zapisu do activity_log (test transakcji)
);

// --- WP_Error ---
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $msg;
		public function __construct( $msg = '' ) {
			$this->msg = $msg; }
		public function get_error_message() {
			return $this->msg; }
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

// --- Escapowanie / sanityzacja ---
function sanitize_text_field( $s ) {
	$s = is_scalar( $s ) ? (string) $s : '';
	$s = preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags_local( $s ) );
	return trim( $s );
}
function sanitize_textarea_field( $s ) {
	return trim( wp_strip_all_tags_local( (string) $s ) );
}
function wp_strip_all_tags_local( $s ) {
	return trim( preg_replace( '/<[^>]*>/', '', (string) $s ) );
}
function sanitize_email( $s ) {
	$s = trim( (string) $s );
	return filter_var( $s, FILTER_VALIDATE_EMAIL ) ? $s : '';
}
function sanitize_key( $s ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) );
}
function sanitize_title( $s ) {
	return preg_replace( '/[^a-z0-9\-]/', '-', strtolower( trim( (string) $s ) ) );
}
function wp_unslash( $v ) {
	return is_string( $v ) ? stripslashes( $v ) : $v;
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $s ) {
	return (string) $s;
}
function esc_html_e( $s, $d = null ) {
	echo esc_html( $s ); }
function esc_html__( $s, $d = null ) {
	return esc_html( $s ); }
function esc_attr_e( $s, $d = null ) {
	echo esc_attr( $s ); }
function __( $s, $d = null ) {
	return $s; }
function _e( $s, $d = null ) {
	echo $s; }
function _x( $s, $c = '', $d = null ) {
	return $s; }
function is_email( $s ) {
	return (bool) filter_var( (string) $s, FILTER_VALIDATE_EMAIL );
}
function absint( $n ) {
	return abs( (int) $n );
}
function wp_json_encode( $data, $opts = 0, $depth = 512 ) {
	return json_encode( $data, $opts, $depth );
}

// --- Opcje ---
function get_option( $k, $default = false ) {
	return array_key_exists( $k, $GLOBALS['__mp_options'] ) ? $GLOBALS['__mp_options'][ $k ] : $default;
}
function update_option( $k, $v, $a = null ) {
	$GLOBALS['__mp_options'][ $k ] = $v;
	return true;
}
function delete_option( $k ) {
	unset( $GLOBALS['__mp_options'][ $k ] );
	return true;
}

// --- Transienty (in-memory, z TTL logicznym) ---
function get_transient( $k ) {
	if ( ! isset( $GLOBALS['__mp_transients'][ $k ] ) ) {
		return false;
	}
	$t = $GLOBALS['__mp_transients'][ $k ];
	if ( $t['exp'] > 0 && $t['exp'] < time() ) {
		unset( $GLOBALS['__mp_transients'][ $k ] );
		return false;
	}
	return $t['val'];
}
function set_transient( $k, $v, $ttl = 0 ) {
	$GLOBALS['__mp_transients'][ $k ] = array(
		'val' => $v,
		'exp' => $ttl > 0 ? time() + $ttl : 0,
	);
	return true;
}
function delete_transient( $k ) {
	unset( $GLOBALS['__mp_transients'][ $k ] );
	return true;
}

// --- HTTP (offline: zawsze WP_Error -> łagodny fallback w dziale 3) ---
function wp_remote_get( $url, $args = array() ) {
	return new WP_Error( 'offline_harness: ' . $url );
}
function wp_remote_post( $url, $args = array() ) {
	return new WP_Error( 'offline_harness' );
}
function wp_remote_retrieve_response_code( $r ) {
	return is_array( $r ) && isset( $r['response']['code'] ) ? $r['response']['code'] : 0;
}
function wp_remote_retrieve_body( $r ) {
	return is_array( $r ) && isset( $r['body'] ) ? $r['body'] : '';
}

// --- Mail ---
function wp_mail( $to, $subject, $body, $headers = '', $att = array() ) {
	$GLOBALS['__mp_mails'][] = compact( 'to', 'subject', 'body' );
	return true;
}

// --- Użytkownik / uprawnienia ---
function get_current_user_id() {
	return 0; }
function current_user_can( $cap ) {
	return true; }
function wp_get_current_user() {
	return (object) array(
		'ID'         => 0,
		'user_email' => '',
	); }

// --- Nonce ---
function wp_create_nonce( $action = -1 ) {
	return 'valid'; }
function wp_verify_nonce( $nonce, $action = -1 ) {
	return ( (string) $nonce === (string) $GLOBALS['__mp_cfg']['valid_nonce'] ) ? 1 : false;
}
function check_ajax_referer( $action = -1, $q = false, $die = true ) {
	$nonce = isset( $_REQUEST[ $q ] ) ? $_REQUEST[ $q ] : '';
	return wp_verify_nonce( $nonce, $action );
}

// --- Hooki (no-op z liczeniem) ---
function add_action( ...$a ) {
	return true; }
function add_filter( ...$a ) {
	return true; }
function do_action( $tag, ...$a ) {
	$GLOBALS['__mp_actions'][ $tag ] = ( $GLOBALS['__mp_actions'][ $tag ] ?? 0 ) + 1;
}
function apply_filters( $tag, $value, ...$a ) {
	return $value; }
function add_shortcode( ...$a ) {
	return true; }

// --- URL / posty ---
function home_url( $p = '' ) {
	return 'https://example.test' . $p; }
function admin_url( $p = '' ) {
	return 'https://example.test/wp-admin/' . $p; }
function site_url( $p = '' ) {
	return 'https://example.test' . $p; }
function plugins_url( $p = '', $f = '' ) {
	return 'https://example.test/wp-content/plugins' . $p; }
function wp_insert_post( $arr, $err = false ) {
	$id                          = count( $GLOBALS['__mp_posts'] ) + 1000;
	$GLOBALS['__mp_posts'][ $id ] = $arr;
	return $id;
}
function wp_update_post( $arr, $err = false ) {
	return isset( $arr['ID'] ) ? $arr['ID'] : 0; }
function wp_delete_post( $id, $force = false ) {
	unset( $GLOBALS['__mp_posts'][ $id ] );
	return true; }
function get_post( $id ) {
	return isset( $GLOBALS['__mp_posts'][ $id ] ) ? (object) $GLOBALS['__mp_posts'][ $id ] : null; }
function wp_list_pluck( $list, $field ) {
	$out = array();
	foreach ( (array) $list as $row ) {
		if ( is_array( $row ) && array_key_exists( $field, $row ) ) {
			$out[] = $row[ $field ];
		} elseif ( is_object( $row ) && isset( $row->$field ) ) {
			$out[] = $row->$field;
		}
	}
	return $out;
}
function current_time( $type, $gmt = 0 ) {
	return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time();
}
function wp_generate_password( $len = 12, $special = true ) {
	return substr( str_shuffle( str_repeat( 'abcdef0123456789', 4 ) ), 0, $len );
}
// Domyślnie brak handlowców w bazie testowej — dział 7 musi to znieść.
function get_users( $args = array() ) {
	return isset( $GLOBALS['__mp_cfg']['users'] ) ? $GLOBALS['__mp_cfg']['users'] : array();
}
function get_permalink( $id = 0 ) {
	return 'https://example.test/?p=' . (int) $id;
}
function get_role( $r ) {
	return null; }
function current_time_gmt() {
	return gmdate( 'Y-m-d H:i:s' ); }

// --- Fałszywy $wpdb ---
class MP_Fake_WPDB {
	public $prefix      = 'wp_';
	public $insert_id   = 0;
	public $last_error  = '';
	public $rows_leads  = array(); // egzekwowanie UNIQUE(nip)
	public $tx_snapshot = null;    // snapshot rows_leads na czas transakcji
	public $last_tx     = '';      // ostatnia operacja transakcyjna (START/COMMIT/ROLLBACK)

	public function get_charset_collate() {
		return 'DEFAULT CHARSET=utf8mb4';
	}
	public function prepare( $query, ...$args ) {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		// Zgrubna interpolacja tylko do celów dopasowania w get_* .
		$i = 0;
		return preg_replace_callback(
			'/%[sdf]/',
			function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i ] ?? '';
				++$i;
				return is_numeric( $v ) ? $v : "'" . addslashes( (string) $v ) . "'";
			},
			$query
		);
	}
	public function insert( $table, $data, $format = null ) {
		if ( strpos( $table, 'mp_activity_log' ) !== false && ! empty( $GLOBALS['__mp_cfg']['fail_activity_insert'] ) ) {
			$this->last_error = 'symulowana awaria zapisu activity_log';
			return false;
		}
		if ( strpos( $table, 'mp_leads' ) !== false ) {
			$nip = isset( $data['nip'] ) ? (string) $data['nip'] : '';
			foreach ( $this->rows_leads as $r ) {
				if ( (string) $r['nip'] === $nip && $nip !== '' ) {
					$this->last_error = 'Duplicate entry for key uq_nip';
					return false; // UNIQUE(nip) naruszony
				}
			}
			++$this->insert_id;
			$data['id']         = $this->insert_id;
			$this->rows_leads[] = $data;
			return 1;
		}
		++$this->insert_id;
		return 1;
	}
	public function update( $table, $data, $where, $f = null, $wf = null ) {
		return 1;
	}
	public function get_results( $query, $output = OBJECT ) {
		if ( strpos( $query, 'mp_leads' ) !== false ) {
			return $GLOBALS['__mp_cfg']['leads_by_nip'];
		}
		return array();
	}
	public function get_row( $query, $output = OBJECT, $y = 0 ) {
		// get_archived_lead_by_nip: WHERE ... deleted_at IS NOT NULL
		if ( strpos( $query, 'mp_leads' ) !== false && strpos( $query, 'IS NOT NULL' ) !== false ) {
			return $GLOBALS['__mp_cfg']['archived_lead'];
		}
		return null;
	}
	public function get_var( $query = null, $x = 0, $y = 0 ) {
		return 0;
	}
	public function query( $query ) {
		$q = strtoupper( trim( (string) $query ) );
		if ( 'START TRANSACTION' === $q ) {
			$this->tx_snapshot = $this->rows_leads;
			$this->last_tx     = 'START';
		} elseif ( 'ROLLBACK' === $q ) {
			if ( null !== $this->tx_snapshot ) {
				$this->rows_leads = $this->tx_snapshot;
			}
			$this->tx_snapshot = null;
			$this->last_tx     = 'ROLLBACK';
		} elseif ( 'COMMIT' === $q ) {
			$this->tx_snapshot = null;
			$this->last_tx     = 'COMMIT';
		}
		return true;
	}
}
$GLOBALS['wpdb'] = new MP_Fake_WPDB();
