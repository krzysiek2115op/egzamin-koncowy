<?php
/**
 * Shim WordPressa dla harnessu procesu MP Offer Builder.
 *
 * Definiuje minimum stałych/funkcji WP + fałszywy $wpdb, aby uruchomić CAŁY
 * pipeline (11 działów) poza WordPressem i zweryfikować rusztowanie (krok 2)
 * w runtime — działy są na tym etapie zaślepkami (MP_OB_Stub_Agent/Critic),
 * więc harness sprawdza MECHANIKĘ (kolejność, bramki, transakcyjność,
 * jednokierunkowość danych), nie logikę biznesową (ta trafi w kroku 3).
 *
 * Zasady stubów: permisywne, ale wierne kontraktowi (sanitizery działają,
 * transienty w pamięci, fałszywy $wpdb egzekwuje START TRANSACTION/COMMIT/
 * ROLLBACK i zapisuje wstawiane wiersze, by można było zweryfikować log).
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

// --- Stałe ---
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fake-wp/' );
}
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );

// --- Magazyny stanu (in-memory) ---
$GLOBALS['__mp_ob_transients'] = array();
$GLOBALS['__mp_ob_options']    = array( 'admin_email' => 'admin@example.test' );
$GLOBALS['__mp_ob_mails']      = array();
$GLOBALS['__mp_ob_cfg']        = array( 'valid_nonce' => 'valid' );
$GLOBALS['__mp_ob_hooks']      = array();
$GLOBALS['__mp_ob_actions']    = array();

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
	$s = preg_replace( '/[\r\n\t ]+/', ' ', trim( preg_replace( '/<[^>]*>/', '', $s ) ) );
	return trim( $s );
}
function sanitize_email( $s ) {
	$s = trim( (string) $s );
	return filter_var( $s, FILTER_VALIDATE_EMAIL ) ? $s : '';
}
function wp_unslash( $v ) {
	return is_string( $v ) ? stripslashes( $v ) : $v;
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_html__( $s, $d = null ) {
	return esc_html( $s ); }
function __( $s, $d = null ) {
	return $s; }
function wp_json_encode( $data, $opts = 0, $depth = 512 ) {
	return json_encode( $data, $opts, $depth );
}
function wp_generate_uuid4() {
	$data    = random_bytes( 16 );
	$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
	$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );
	return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
}
function trailingslashit( $s ) {
	return rtrim( (string) $s, '/\\' ) . '/';
}
// Prawdziwy, tymczasowy katalog systemowy — Dział 9 (Storage) realnie zapisuje
// pliki PDF na dysk (świadomy wyjątek od "działy 3-9 to czyste funkcje": render
// PDF Z NATURY jest efektem ubocznym, patrz docblock class-mp-ob-department-09.php).
function wp_upload_dir() {
	$base = sys_get_temp_dir() . '/mp-ob-harness-uploads';
	if ( ! is_dir( $base ) ) {
		mkdir( $base, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
	}
	return array(
		'basedir' => $base,
		'baseurl' => 'http://harness.test/wp-content/uploads',
	);
}
function wp_mkdir_p( $dir ) {
	return is_dir( $dir ) || mkdir( $dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
}

// --- Opcje ---
function get_option( $k, $default = false ) {
	return array_key_exists( $k, $GLOBALS['__mp_ob_options'] ) ? $GLOBALS['__mp_ob_options'][ $k ] : $default;
}
function update_option( $k, $v, $a = null ) {
	$GLOBALS['__mp_ob_options'][ $k ] = $v;
	return true;
}
function delete_option( $k ) {
	unset( $GLOBALS['__mp_ob_options'][ $k ] );
	return true;
}

// --- Transienty (in-memory, z TTL logicznym) ---
function get_transient( $k ) {
	if ( ! isset( $GLOBALS['__mp_ob_transients'][ $k ] ) ) {
		return false;
	}
	$t = $GLOBALS['__mp_ob_transients'][ $k ];
	if ( $t['exp'] > 0 && $t['exp'] < time() ) {
		unset( $GLOBALS['__mp_ob_transients'][ $k ] );
		return false;
	}
	return $t['val'];
}
function set_transient( $k, $v, $ttl = 0 ) {
	$GLOBALS['__mp_ob_transients'][ $k ] = array(
		'val' => $v,
		'exp' => $ttl > 0 ? time() + $ttl : 0,
	);
	return true;
}
function delete_transient( $k ) {
	unset( $GLOBALS['__mp_ob_transients'][ $k ] );
	return true;
}

// --- Mail ---
function wp_mail( $to, $subject, $body, $headers = '', $att = array() ) {
	$GLOBALS['__mp_ob_mails'][] = compact( 'to', 'subject', 'body' );
	return true;
}

// --- Użytkownik / uprawnienia ---
function get_current_user_id() {
	return 0; }
// Sterowalne przez test: $GLOBALS['__mp_ob_cfg']['denied_caps'] = array('cap' => true).
function current_user_can( $cap ) {
	return empty( $GLOBALS['__mp_ob_cfg']['denied_caps'][ $cap ] );
}
function absint( $n ) {
	return abs( (int) $n );
}

// --- Role/capabilities (minimalny magazyn w pamięci — używany przez
//     mp_offer_builder_activate()/uninstall.php, nie przez harness bezpośrednio) ---
class MP_OB_Fake_Role {
	public $caps = array();
	public function has_cap( $cap ) {
		return ! empty( $this->caps[ $cap ] ); }
	public function add_cap( $cap ) {
		$this->caps[ $cap ] = true; }
	public function remove_cap( $cap ) {
		unset( $this->caps[ $cap ] ); }
}
function get_role( $role ) {
	if ( ! isset( $GLOBALS['__mp_ob_roles'][ $role ] ) ) {
		$GLOBALS['__mp_ob_roles'][ $role ] = new MP_OB_Fake_Role();
	}
	return $GLOBALS['__mp_ob_roles'][ $role ];
}

// --- Nonce ---
function wp_create_nonce( $action = -1 ) {
	return 'valid'; }
function wp_verify_nonce( $nonce, $action = -1 ) {
	return ( (string) $nonce === (string) $GLOBALS['__mp_ob_cfg']['valid_nonce'] ) ? 1 : false;
}
function check_ajax_referer( $action = -1, $q = false, $die = true ) {
	$nonce = isset( $_REQUEST[ $q ] ) ? $_REQUEST[ $q ] : '';
	return wp_verify_nonce( $nonce, $action );
}

// --- Hooki (minimalny pub/sub — pipeline nie odpala żadnych własnych do_action
//     na tym etapie, ale add_action jest wywoływane przez MP_Offer_Builder_Ajax::register()) ---
function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['__mp_ob_hooks'][ $tag ][] = array(
		'cb'       => $callback,
		'priority' => $priority,
		'args'     => $accepted_args,
	);
	return true;
}
function do_action( $tag, ...$a ) {
	$GLOBALS['__mp_ob_actions'][ $tag ] = ( $GLOBALS['__mp_ob_actions'][ $tag ] ?? 0 ) + 1;
	if ( empty( $GLOBALS['__mp_ob_hooks'][ $tag ] ) ) {
		return;
	}
	$hooks = $GLOBALS['__mp_ob_hooks'][ $tag ];
	usort(
		$hooks,
		function ( $x, $y ) {
			return $x['priority'] <=> $y['priority'];
		}
	);
	foreach ( $hooks as $h ) {
		call_user_func_array( $h['cb'], array_slice( $a, 0, max( 1, (int) $h['args'] ) ) );
	}
}
function apply_filters( $tag, $value, ...$a ) {
	return $value; }

// --- WooCommerce (fałszywe minimum: tylko to, czego dotyka Dział 2) ---
$GLOBALS['__mp_ob_wc_products']  = array(); // id => array(status,name,tax_class,purchasable,regular_price,sale_price)
$GLOBALS['__mp_ob_wc_tax_rates'] = array(); // tax_class => array(array('rate'=>23.0,'label'=>'VAT'))
$GLOBALS['__mp_ob_wc_currency']  = 'PLN';

class WC_Product {
	protected $data;
	public function __construct( array $data ) {
		$this->data = $data;
	}
	public function get_status() {
		return isset( $this->data['status'] ) ? $this->data['status'] : 'publish'; }
	public function is_purchasable() {
		return isset( $this->data['purchasable'] ) ? (bool) $this->data['purchasable'] : true; }
	public function get_name() {
		return isset( $this->data['name'] ) ? $this->data['name'] : ''; }
	public function get_tax_class() {
		return isset( $this->data['tax_class'] ) ? $this->data['tax_class'] : ''; }
	public function get_regular_price() {
		return isset( $this->data['regular_price'] ) ? $this->data['regular_price'] : ''; }
	public function get_sale_price() {
		return isset( $this->data['sale_price'] ) ? $this->data['sale_price'] : ''; }
}
function wc_get_product( $id ) {
	if ( ! isset( $GLOBALS['__mp_ob_wc_products'][ $id ] ) ) {
		return false;
	}
	return new WC_Product( $GLOBALS['__mp_ob_wc_products'][ $id ] );
}
class WC_Tax {
	public static function get_rates( $tax_class = '', $customer = null ) {
		return isset( $GLOBALS['__mp_ob_wc_tax_rates'][ $tax_class ] ) ? $GLOBALS['__mp_ob_wc_tax_rates'][ $tax_class ] : array();
	}
}
function get_woocommerce_currency() {
	return $GLOBALS['__mp_ob_wc_currency'];
}

// --- Fałszywy $wpdb ---
class MP_OB_Fake_WPDB {
	public $prefix        = 'wp_';
	public $insert_id     = 0;
	public $last_error    = '';
	public $activity_log  = array(); // wiersze wstawione do wp_mp_ob_offer_activity_log
	public $offers        = array(); // wiersze wstawione do wp_mp_ob_offers (id => dane)
	public $items         = array(); // wiersze wstawione do wp_mp_ob_offer_items
	public $versions      = array(); // wiersze wstawione do wp_mp_ob_offer_versions
	public $templates     = array(); // wiersze wp_mp_ob_offer_templates (id => dane)
	public $tx_log        = array(); // kolejność START/COMMIT/ROLLBACK (weryfikacja transakcyjności)
	public $in_transaction = false;
	// Sterowanie testem Działu 10 (Agent 10.2, retry lokalny przy kolizji UNIQUE):
	// gdy true, NASTĘPNY insert()/update() na wp_mp_ob_offers "koliduje" (jak
	// realny błąd UNIQUE(offer_number, version)) i flaga sama się kasuje —
	// symuluje DOKŁADNIE JEDNĄ kolizję, którą retry musi rozwiązać.
	public $force_unique_collision_once = false;
	// Jak wyżej, ale NIE kasuje się samo — symuluje TRWAŁĄ kolizję (retry
	// wyczerpuje maks. 2 podejścia i musi się poddać z jawnym FAIL).
	public $force_unique_collision_always = false;

	public function get_charset_collate() {
		return 'DEFAULT CHARSET=utf8mb4';
	}
	public function prepare( $query, ...$args ) {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) {
			$args = $args[0];
		}
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
		if ( strpos( $table, 'mp_ob_offer_activity_log' ) !== false ) {
			$this->activity_log[] = $data;
			++$this->insert_id;
			return 1;
		}
		if ( strpos( $table, 'mp_ob_offer_items' ) !== false ) {
			++$this->insert_id;
			$data['id']     = $this->insert_id;
			$this->items[] = $data;
			return 1;
		}
		if ( strpos( $table, 'mp_ob_offer_versions' ) !== false ) {
			++$this->insert_id;
			$data['id']        = $this->insert_id;
			$this->versions[] = $data;
			return 1;
		}
		if ( strpos( $table, 'mp_ob_offers' ) !== false ) {
			if ( $this->force_unique_collision_always ) {
				$this->last_error = "Duplicate entry for key 'uq_offer_number_version'";
				return false;
			}
			if ( $this->force_unique_collision_once ) {
				$this->force_unique_collision_once = false;
				$this->last_error                   = "Duplicate entry for key 'uq_offer_number_version'";
				return false;
			}
			++$this->insert_id;
			$data['id']                       = $this->insert_id;
			$this->offers[ $this->insert_id ] = $data;
			return 1;
		}
		return false;
	}
	public function update( $table, $data, $where ) {
		if ( strpos( $table, 'mp_ob_offers' ) !== false && isset( $where['id'] ) ) {
			$id = (int) $where['id'];
			if ( ! isset( $this->offers[ $id ] ) ) {
				return false;
			}
			if ( $this->force_unique_collision_always ) {
				$this->last_error = "Duplicate entry for key 'uq_offer_number_version'";
				return false;
			}
			if ( $this->force_unique_collision_once ) {
				$this->force_unique_collision_once = false;
				$this->last_error                   = "Duplicate entry for key 'uq_offer_number_version'";
				return false;
			}
			$this->offers[ $id ] = array_merge( $this->offers[ $id ], $data );
			return 1;
		}
		return false;
	}
	public function get_var( $query = null, $x = 0, $y = 0 ) {
		$q = (string) $query;
		// get_var na wp_mp_ob_offers: SELECT id ... WHERE lead_id = N AND status = 'draft'
		// (idempotencja MP_Offer_Builder_Lead_Listener::on_lead_created).
		if ( strpos( $q, 'mp_ob_offers' ) !== false && preg_match( '/lead_id\s*=\s*(\d+)/', $q, $m ) ) {
			$lead_id = (int) $m[1];
			foreach ( $this->offers as $row ) {
				if ( (int) ( $row['lead_id'] ?? 0 ) === $lead_id && 'draft' === ( $row['status'] ?? '' ) ) {
					return $row['id'];
				}
			}
			return null;
		}
		// MP_Offer_Builder_DB::get_last_offer_number_for_year(): SELECT offer_number ...
		// WHERE offer_number LIKE 'OF/2026/%' ORDER BY offer_number DESC LIMIT 1
		if ( strpos( $q, 'mp_ob_offers' ) !== false && preg_match( "/offer_number LIKE '([^']*)'/", $q, $m ) ) {
			$prefix   = rtrim( $m[1], '%' );
			$matching = array();
			foreach ( $this->offers as $row ) {
				if ( 0 === strpos( (string) ( $row['offer_number'] ?? '' ), $prefix ) ) {
					$matching[] = $row['offer_number'];
				}
			}
			if ( ! $matching ) {
				return null;
			}
			rsort( $matching );
			return $matching[0];
		}
		// MP_Offer_Builder_DB::get_max_version_for_offer_number(): SELECT MAX(version) ...
		// WHERE offer_number = '...'
		if ( strpos( $q, 'mp_ob_offers' ) !== false && strpos( $q, 'MAX(version)' ) !== false && preg_match( "/offer_number\s*=\s*'([^']*)'/", $q, $m ) ) {
			$max = null;
			foreach ( $this->offers as $row ) {
				if ( ( $row['offer_number'] ?? null ) === $m[1] ) {
					$max = null === $max ? (int) $row['version'] : max( $max, (int) $row['version'] );
				}
			}
			return $max;
		}
		return null;
	}
	public function get_row( $query = null, $output = ARRAY_A, $y = 0 ) {
		$q = (string) $query;
		// MP_OB_D2_Agent_Templates: SELECT * FROM wp_mp_ob_offer_templates WHERE lang = '..' AND status = 'active' ORDER BY version DESC LIMIT 1
		if ( strpos( $q, 'mp_ob_offer_templates' ) !== false && preg_match( "/lang\s*=\s*'([^']*)'/", $q, $m ) ) {
			$lang    = $m[1];
			$matches = array_filter(
				$this->templates,
				function ( $row ) use ( $lang ) {
					return ( $row['lang'] ?? '' ) === $lang && 'active' === ( $row['status'] ?? '' );
				}
			);
			if ( ! $matches ) {
				return null;
			}
			usort(
				$matches,
				function ( $a, $b ) {
					return $b['version'] <=> $a['version'];
				}
			);
			return reset( $matches );
		}
		// MP_Offer_Builder_DB::get_offer(): SELECT * FROM wp_mp_ob_offers WHERE id = N
		if ( strpos( $q, 'mp_ob_offers' ) !== false && preg_match( '/id\s*=\s*(\d+)/', $q, $m ) ) {
			$id = (int) $m[1];
			return isset( $this->offers[ $id ] ) ? $this->offers[ $id ] : null;
		}
		// MP_Offer_Builder_DB::get_offer_by_request_id(): WHERE request_id = '...'
		if ( strpos( $q, 'mp_ob_offers' ) !== false && preg_match( "/request_id\s*=\s*'([^']*)'/", $q, $m ) ) {
			foreach ( $this->offers as $row ) {
				if ( ( $row['request_id'] ?? null ) === $m[1] ) {
					return $row;
				}
			}
			return null;
		}
		return null;
	}
	public function query( $query ) {
		$q = strtoupper( trim( (string) $query ) );
		if ( 'START TRANSACTION' === $q ) {
			$this->tx_log[]      = 'START';
			$this->in_transaction = true;
		} elseif ( 'ROLLBACK' === $q ) {
			$this->tx_log[]      = 'ROLLBACK';
			$this->in_transaction = false;
		} elseif ( 'COMMIT' === $q ) {
			$this->tx_log[]      = 'COMMIT';
			$this->in_transaction = false;
		}
		return true;
	}
}
$GLOBALS['wpdb'] = new MP_OB_Fake_WPDB();
