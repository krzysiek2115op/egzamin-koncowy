<?php
/**
 * Ochrona metadanych `mp_*` uzytkownika.
 *
 * Zakres widoczny w LP.3 liczy sie z `mp_team`. Dopoki to pole moze zmienic sam
 * jego wlasciciel, caly Dzial 3 jest dekoracja: handlowiec przepisuje sie do
 * zespolu konkurencji i legalnie, przez wszystkie kontrole, oglada cudze
 * negocjacje. Uprawnienie nie chroni danych, jesli chroniony jest tylko odczyt,
 * a nie klucz, na ktorym opiera sie decyzja o odczycie.
 *
 * WordPress daje dwie osobne furtki i obie trzeba zamknac:
 *  - profil uzytkownika i `update_user_meta()` — przez `auth_callback` w
 *    `register_meta()` oraz przez `is_protected_meta` (pole z prefiksem
 *    chronionym nie pojawia sie w formularzu pol wlasnych),
 *  - REST `/wp/v2/users` — przez ten sam `auth_callback`, bo REST pyta o niego
 *    przy kazdym zapisie meta.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Straznik metadanych wtyczki.
 */
class MP_SW_Meta_Guard {

	/** Prefiks kluczy nalezacych do systemu MP. */
	const PREFIX = 'mp_';

	/**
	 * Klucze, ktorych pilnujemy jawnie.
	 *
	 * Prefiks obejmuje je wszystkie, ale lista sluzy rejestracji `register_meta()`
	 * i jest zarazem dokumentacja: to sa pola, ktore steruja dostepem.
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array( 'mp_country', 'mp_team', 'mp_lang', 'mp_scope', 'mp_absent' );
	}

	/**
	 * Wpina ochrone.
	 *
	 * @return void
	 */
	public static function register() {
		foreach ( self::keys() as $key ) {
			register_meta(
				'user',
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => array( __CLASS__, 'can_edit' ),
				)
			);
		}

		add_filter( 'is_protected_meta', array( __CLASS__, 'mark_protected' ), 10, 3 );
		add_filter( 'update_user_metadata', array( __CLASS__, 'block_update' ), 10, 4 );
		add_filter( 'add_user_metadata', array( __CLASS__, 'block_update' ), 10, 4 );
	}

	/**
	 * Czy klucz nalezy do systemu MP.
	 *
	 * @param string $key Klucz meta.
	 * @return bool
	 */
	public static function is_ours( $key ) {
		return 0 === strpos( (string) $key, self::PREFIX );
	}

	/**
	 * Kto moze zmieniac te pola: wylacznie administrator.
	 *
	 * Nie `edit_user` — to uprawnienie ma kazdy wobec SIEBIE, wiec sprawdzenie
	 * „czy wolno ci edytowac tego uzytkownika" przepusciloby wlasnie ten
	 * przypadek, ktory zamykamy.
	 *
	 * @param bool   $allowed  Decyzja domyslna.
	 * @param string $meta_key Klucz meta.
	 * @return bool
	 */
	public static function can_edit( $allowed = false, $meta_key = '' ) {
		if ( '' !== (string) $meta_key && ! self::is_ours( $meta_key ) ) {
			return (bool) $allowed;
		}

		return current_user_can( 'promote_users' );
	}

	/**
	 * Oznacza klucze `mp_*` jako chronione.
	 *
	 * Nazwa parametru NIE brzmi `$protected` (jak w dokumentacji haka), bo
	 * „protected" jest slowem kluczowym jezyka — nazwa zastrzezona w miejscu
	 * parametru czyta sie mylnie i zglasza ja analiza statyczna. WordPress
	 * przekazuje argumenty pozycyjnie, wiec nazwa nie ma znaczenia dla kontraktu.
	 *
	 * @param bool   $domyslna  Decyzja domyslna.
	 * @param string $meta_key  Klucz meta.
	 * @param string $meta_type Rodzaj obiektu.
	 * @return bool
	 */
	public static function mark_protected( $domyslna, $meta_key, $meta_type ) {
		if ( 'user' === $meta_type && self::is_ours( $meta_key ) ) {
			return true;
		}

		return (bool) $domyslna;
	}

	/**
	 * Ostatnia linia obrony: zapis meta wprost z zadania.
	 *
	 * `register_meta()` chroni REST i profil, ale nie kazda sciezke — wtyczka
	 * do importu uzytkownikow albo wlasny formularz woluja `update_user_meta()`
	 * bezpośrednio. Ten filtr zatrzymuje zapis niezaleznie od tego, kto go
	 * wywolal, o ile dzieje sie to w zadaniu HTTP bez uprawnienia.
	 *
	 * Kod wewnetrzny (aktywacja, cron, WP-CLI, migracja) NIE jest blokowany:
	 * tam nie ma zalogowanego uzytkownika, ktory moglby sobie cos nadac, a
	 * zablokowanie tej sciezki uniemozliwiloby administracje zespolami skryptem.
	 *
	 * @param mixed  $check      Null, gdy nikt nie przejal zapisu.
	 * @param int    $object_id  Identyfikator uzytkownika.
	 * @param string $meta_key   Klucz meta.
	 * @param mixed  $meta_value Wartosc.
	 * @return mixed Null przepuszcza zapis, false go zatrzymuje.
	 */
	public static function block_update( $check, $object_id, $meta_key, $meta_value ) {
		// Wartosc jest czescia kontraktu haka `update_user_metadata`, ale decyzja
		// zapada wylacznie po KLUCZU: chronimy przestrzen `mp_*` niezaleznie od tego,
		// co ktos probuje w nia wpisac. To samo rozwiazanie co w MP_Flag_Critic.
		unset( $meta_value );

		if ( ! self::is_ours( $meta_key ) ) {
			return $check;
		}

		$user_id = get_current_user_id();

		if ( $user_id < 1 ) {
			return $check;
		}

		if ( current_user_can( 'promote_users' ) ) {
			return $check;
		}

		MP_SW_Log::security(
			MP_SW_Errors::E_CAPABILITY,
			array(
				'code'    => MP_SW_Errors::E_CAPABILITY,
				'reason'  => 'protected_meta',
				'user_id' => $user_id,
				'ip_hash' => MP_SW_Log::ip_hash(),
			)
		);

		return false;
	}
}
