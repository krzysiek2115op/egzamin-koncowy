<?php
/**
 * Ustawienia rabatów — próg wolumenu bez edycji kodu.
 *
 * Reguły rabatowe były zaszyte w `const RULES` w Dziale 5. Zmiana progu — czyli
 * decyzja handlowa, nie techniczna — wymagała edycji pliku wtyczki, wydania nowej
 * wersji i wgrania jej na produkcję. Wszystko po to, żeby partner dostawał 12%
 * zamiast 10%.
 *
 * ODTWARZALNOŚĆ RABATU JEST TU RZECZĄ NAJWAŻNIEJSZĄ. Każda oferta zapisuje przy
 * sobie `rules_version` — po to, żeby dało się później odpowiedzieć na pytanie
 * „dlaczego ta oferta ma taki rabat". Gdyby wersja została ta sama po zmianie
 * progów, dwie oferty z identycznym znacznikiem miałyby różne rabaty i znacznik
 * przestałby cokolwiek znaczyć. Dlatego KAŻDY zapis ustawień nadaje nową wersję,
 * a stare oferty zachowują swoją.
 *
 * Domyślka nie znika: pusta konfiguracja znaczy „reguły wbudowane" (`RULES`),
 * a nie „brak rabatów". Przycisk przywracania kasuje opcję zamiast zapisywać
 * kopię domyślnych wartości — inaczej domyślne progi zamrożiłyby się w bazie
 * i przestały nadążać za aktualizacjami wtyczki.
 *
 * Źródła (oficjalne) — Golden Rule #2:
 *  - add_submenu_page()    https://developer.wordpress.org/reference/functions/add_submenu_page/
 *  - check_admin_referer() https://developer.wordpress.org/reference/functions/check_admin_referer/
 *
 * @package MP_Offer_Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Podstrona ustawień rabatów.
 */
class MP_OB_Settings {

	/** Slug podstrony. */
	const PAGE = 'mp-offer-builder-rabaty';

	/** Opcja z regułami (pusta = reguły wbudowane). */
	const OPTION = 'mp_ob_discount_rules';

	/** Akcja formularza (nonce). */
	const ACTION = 'mp_ob_zapisz_rabaty';

	/**
	 * Wpina podstronę.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
	}

	/**
	 * Dodaje podstronę pod „MP Offer Builder".
	 *
	 * @return void
	 */
	public static function add_page() {
		add_submenu_page(
			MP_Offer_Builder_Admin::PAGE_SLUG,
			__( 'Reguły rabatowe', 'mp-offer-builder' ),
			__( 'Reguły rabatowe', 'mp-offer-builder' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Obowiązujące reguły: z ustawień albo wbudowane.
	 *
	 * @return array
	 */
	public static function rules() {
		$zapisane = get_option( self::OPTION, array() );

		if ( empty( $zapisane['rules'] ) || ! is_array( $zapisane['rules'] ) ) {
			return MP_OB_D5_Agent_Discount_Rules::RULES;
		}

		return $zapisane['rules'];
	}

	/**
	 * Wersja obowiązującego słownika reguł.
	 *
	 * @return string
	 */
	public static function rules_version() {
		$zapisane = get_option( self::OPTION, array() );

		if ( empty( $zapisane['version'] ) ) {
			return MP_OB_D5_Agent_Discount_Rules::RULES_VERSION;
		}

		return (string) $zapisane['version'];
	}

	/**
	 * Sprawdza i normalizuje reguły podane w formularzu.
	 *
	 * Reguła R-00 (catch-all, 0%) jest DOKŁADANA zawsze i nie da się jej usunąć:
	 * dobór w Dziale 5 kończy się na niej, gdy żaden próg nie pasuje. Bez niej
	 * oferta dla nieznanego wariantu nie dostałaby żadnej reguły i pipeline
	 * odmówiłby zamiast policzyć rabat zerowy.
	 *
	 * @param array $wiersze Surowe wiersze z formularza.
	 * @return array{rules:array,errors:string[]}
	 */
	public static function validate( array $wiersze ) {
		$reguly = array(
			array(
				'rule_id' => 'R-00',
				'wariant' => null,
				'min_qty' => 0,
				'percent' => 0,
				'method'  => 'total',
			),
		);

		$bledy = array();
		$numer = 0;

		foreach ( $wiersze as $wiersz ) {
			$wariant = isset( $wiersz['wariant'] ) ? sanitize_key( (string) $wiersz['wariant'] ) : '';
			$min_qty = isset( $wiersz['min_qty'] ) ? (int) $wiersz['min_qty'] : 0;
			$percent = isset( $wiersz['percent'] ) ? (float) str_replace( ',', '.', (string) $wiersz['percent'] ) : 0.0;

			// Pusty wiersz to nie błąd — formularz zawsze pokazuje kilka wolnych.
			if ( '' === $wariant ) {
				continue;
			}

			if ( ! in_array( $wariant, MP_OB_D1_Agent_Contract::ALLOWED_WARIANTS, true ) ) {
				/* translators: %s: nazwa wariantu cenowego. */
				$bledy[] = sprintf( __( 'Nieznany wariant cenowy: %s.', 'mp-offer-builder' ), $wariant );
				continue;
			}

			if ( $min_qty < 1 ) {
				$bledy[] = __( 'Próg wolumenu musi być liczbą co najmniej 1 — próg zerowy pokrywa reguła domyślna.', 'mp-offer-builder' );
				continue;
			}

			/*
			 * Górna granica nie jest ostrożnością na wyrost. Rabat 100% daje ofertę
			 * na zero złotych, a ujemny — podwyżkę udającą rabat. Obie liczby
			 * przeszłyby przez resztę pipeline'u bez mrugnięcia.
			 */
			if ( $percent < 0 || $percent >= 100 ) {
				$bledy[] = __( 'Rabat musi mieścić się w przedziale od 0 do 99,99%.', 'mp-offer-builder' );
				continue;
			}

			++$numer;

			$reguly[] = array(
				'rule_id' => sprintf( 'R-%02d', $numer ),
				'wariant' => $wariant,
				'min_qty' => $min_qty,
				'percent' => $percent,
				'method'  => 'total',
			);
		}

		return array(
			'rules'  => $reguly,
			'errors' => $bledy,
		);
	}

	/**
	 * Zapisuje reguły z formularza.
	 *
	 * @return string[] Lista błędów; pusta = zapisano.
	 */
	protected static function maybe_save() {
		if ( ! isset( $_POST['mp_ob_rabaty'] ) && ! isset( $_POST['mp_ob_przywroc'] ) ) {
			return array();
		}

		check_admin_referer( self::ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			return array( __( 'Brak uprawnień do zmiany reguł rabatowych.', 'mp-offer-builder' ) );
		}

		if ( isset( $_POST['mp_ob_przywroc'] ) ) {
			// Kasujemy opcję zamiast zapisywać kopię domyślnych progów — inaczej
			// zamroziłyby się w bazie i przestały nadążać za aktualizacjami wtyczki.
			delete_option( self::OPTION );
			return array();
		}

		$surowe = isset( $_POST['mp_ob_rabaty'] ) ? wp_unslash( $_POST['mp_ob_rabaty'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- każde pole sanityzowane w validate().
		$wynik  = self::validate( is_array( $surowe ) ? $surowe : array() );

		if ( ! empty( $wynik['errors'] ) ) {
			return $wynik['errors'];
		}

		update_option(
			self::OPTION,
			array(
				// Nowa wersja przy KAŻDYM zapisie — patrz nagłówek pliku.
				'version'  => 'cfg-' . gmdate( 'YmdHis' ),
				'rules'    => $wynik['rules'],
				'saved_by' => get_current_user_id(),
			)
		);

		return array();
	}

	/**
	 * Rysuje stronę.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Brak uprawnień do zmiany reguł rabatowych.', 'mp-offer-builder' ) );
		}

		$bledy = self::maybe_save();

		foreach ( $bledy as $blad ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $blad ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- komunikat, nie akcja; zapis sprawdza nonce wyżej.
		if ( empty( $bledy ) && ( isset( $_POST['mp_ob_rabaty'] ) || isset( $_POST['mp_ob_przywroc'] ) ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Reguły rabatowe zapisane. Oferty wystawione wcześniej zachowują rabat policzony według poprzedniej wersji.', 'mp-offer-builder' )
				. '</p></div>';
		}

		$reguly = self::rules();
		$wlasne = array();

		foreach ( $reguly as $regula ) {
			if ( ! empty( $regula['wariant'] ) ) {
				$wlasne[] = $regula;
			}
		}

		// Trzy wolne wiersze na dopisanie kolejnych progów.
		for ( $i = 0; $i < 3; $i++ ) {
			$wlasne[] = array(
				'wariant' => '',
				'min_qty' => '',
				'percent' => '',
			);
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Reguły rabatowe', 'mp-offer-builder' ) . '</h1>';

		echo '<p class="description">'
			. esc_html__( 'Rabat dobierany jest po wariancie cenowym i progu wolumenu — wygrywa reguła o najwyższym progu, który zamówienie osiąga. Gdy żadna nie pasuje, obowiązuje 0%.', 'mp-offer-builder' )
			. '</p>';

		echo '<p><strong>' . esc_html__( 'Obowiązująca wersja słownika:', 'mp-offer-builder' ) . '</strong> <code>'
			. esc_html( self::rules_version() ) . '</code> '
			. esc_html(
				get_option( self::OPTION, array() )
					? __( '(z ustawień)', 'mp-offer-builder' )
					: __( '(wbudowana we wtyczkę)', 'mp-offer-builder' )
			)
			. '</p>';

		echo '<form method="post">';
		wp_nonce_field( self::ACTION );

		echo '<table class="wp-list-table widefat fixed striped" style="max-width:720px;"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Wariant cenowy', 'mp-offer-builder' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Próg (szt.)', 'mp-offer-builder' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Rabat (%)', 'mp-offer-builder' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $wlasne as $i => $regula ) {
			$biezacy = isset( $regula['wariant'] ) ? (string) $regula['wariant'] : '';

			echo '<tr><td><select name="mp_ob_rabaty[' . (int) $i . '][wariant]">';
			echo '<option value="">' . esc_html__( '— (wiersz pusty)', 'mp-offer-builder' ) . '</option>';

			foreach ( MP_OB_D1_Agent_Contract::ALLOWED_WARIANTS as $wariant ) {
				echo '<option value="' . esc_attr( $wariant ) . '"' . selected( $biezacy, $wariant, false ) . '>'
					. esc_html( $wariant ) . '</option>';
			}

			echo '</select></td>';
			echo '<td><input type="number" min="1" step="1" name="mp_ob_rabaty[' . (int) $i . '][min_qty]" value="'
				. esc_attr( (string) ( isset( $regula['min_qty'] ) ? $regula['min_qty'] : '' ) ) . '"></td>';
			echo '<td><input type="number" min="0" max="99.99" step="0.01" name="mp_ob_rabaty[' . (int) $i . '][percent]" value="'
				. esc_attr( (string) ( isset( $regula['percent'] ) ? $regula['percent'] : '' ) ) . '"></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<p class="submit">';
		submit_button( __( 'Zapisz reguły', 'mp-offer-builder' ), 'primary', 'submit', false );
		echo ' ';
		submit_button( __( 'Przywróć wbudowane', 'mp-offer-builder' ), 'secondary', 'mp_ob_przywroc', false );
		echo '</p>';

		echo '</form></div>';
	}
}
