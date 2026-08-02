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
		if ( ! self::rules_are_custom() ) {
			return MP_OB_D5_Agent_Discount_Rules::RULES;
		}

		return get_option( self::OPTION, array() )['rules'];
	}

	/**
	 * Czy obowiązują reguły z ustawień (a nie wbudowane).
	 *
	 * Jedno miejsce, bo pytają o to dwa: `rules()` — żeby wybrać słownik — i
	 * nagłówek ekranu, żeby napisać, skąd te reguły są. Liczone osobno rozjeżdżały
	 * się przy opcji niespójnej (np. z wersją, ale bez reguł): pipeline liczył
	 * według wbudowanych, a strona twierdziła „z ustawień".
	 *
	 * @return bool
	 */
	public static function rules_are_custom() {
		$zapisane = get_option( self::OPTION, array() );

		return ! empty( $zapisane['rules'] ) && is_array( $zapisane['rules'] );
	}

	/**
	 * Wersja obowiązującego słownika reguł.
	 *
	 * @return string
	 */
	public static function rules_version() {
		$zapisane = get_option( self::OPTION, array() );

		/*
		 * Wersja opisuje SŁOWNIK, którym policzono rabat — więc bierze się z tego
		 * samego warunku, co sam słownik. Liczona z samej obecności klucza
		 * `version` potrafiła ostemplować ofertę znacznikiem konfiguracji, według
		 * której nic nie liczono (opcja z wersją, ale bez reguł). Znacznik miał
		 * odpowiadać na pytanie „dlaczego ta oferta ma taki rabat" — a odpowiadał
		 * nieprawdę.
		 */
		if ( ! self::rules_are_custom() || empty( $zapisane['version'] ) ) {
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
	 * Każdy błąd nazywa WIERSZ, którego dotyczy. Bez tego dwa błędne wiersze dawały
	 * dwa identyczne notice'y — użytkownik widział, że coś jest nie tak dwa razy,
	 * ale nie miał jak ustalić gdzie.
	 *
	 * @param array $wiersze Surowe wiersze z formularza.
	 * @return array{rules:array,errors:string[],wlasne:int}
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
		$nr    = 0;

		foreach ( $wiersze as $wiersz ) {
			// Numer rośnie także dla wierszy pustych — ma wskazywać wiersz TABELI,
			// tak jak widzi ją użytkownik, a nie kolejność reguł po odsianiu.
			++$nr;

			$wariant = isset( $wiersz['wariant'] ) ? sanitize_key( (string) $wiersz['wariant'] ) : '';
			$min_qty = isset( $wiersz['min_qty'] ) ? (int) $wiersz['min_qty'] : 0;
			$percent = isset( $wiersz['percent'] ) ? (float) str_replace( ',', '.', (string) $wiersz['percent'] ) : 0.0;

			// Pusty wiersz to nie błąd — formularz zawsze pokazuje kilka wolnych.
			if ( '' === $wariant ) {
				continue;
			}

			if ( ! in_array( $wariant, MP_OB_D1_Agent_Contract::ALLOWED_WARIANTS, true ) ) {
				$bledy[] = sprintf(
					/* translators: 1: numer wiersza tabeli, 2: nazwa wariantu cenowego. */
					__( 'Wiersz %1$d: nieznany wariant cenowy: %2$s.', 'mp-offer-builder' ),
					$nr,
					$wariant
				);
				continue;
			}

			if ( $min_qty < 1 ) {
				$bledy[] = sprintf(
					/* translators: %d: numer wiersza tabeli. */
					__( 'Wiersz %d: próg wolumenu musi być liczbą co najmniej 1 — próg zerowy pokrywa reguła domyślna.', 'mp-offer-builder' ),
					$nr
				);
				continue;
			}

			/*
			 * Górna granica nie jest ostrożnością na wyrost. Rabat 100% daje ofertę
			 * na zero złotych, a ujemny — podwyżkę udającą rabat. Obie liczby
			 * przeszłyby przez resztę pipeline'u bez mrugnięcia.
			 */
			if ( $percent < 0 || $percent >= 100 ) {
				$bledy[] = sprintf(
					/* translators: %d: numer wiersza tabeli. */
					__( 'Wiersz %d: rabat musi mieścić się w przedziale od 0 do 99,99%%.', 'mp-offer-builder' ),
					$nr
				);
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
			// Ile reguł podał CZŁOWIEK. R-00 dokładamy sami, więc sama liczebność
			// `rules` nigdy nie spada do zera i nie odróżnia „zero reguł" od „jedna".
			'wlasne' => $numer,
		);
	}

	/**
	 * Zapisuje reguły z formularza.
	 *
	 * Metoda oddaje CO ZROBIŁA, a nie tylko listę błędów. Wcześniej ekran miał do
	 * dyspozycji wyłącznie „były błędy / nie było", więc jeden zielony komunikat
	 * o zapisie obsługiwał wszystko: także przywrócenie wbudowanych (czyli
	 * skasowanie opcji) i zapis, który się nie powiódł.
	 *
	 * @return array{akcja:string,errors:string[],wiersze:array}
	 */
	protected static function maybe_save() {
		$nic = array(
			'akcja'   => 'brak',
			'errors'  => array(),
			'wiersze' => array(),
		);

		if ( ! isset( $_POST['mp_ob_rabaty'] ) && ! isset( $_POST['mp_ob_przywroc'] ) ) {
			return $nic;
		}

		check_admin_referer( self::ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			return array_merge(
				$nic,
				array(
					'akcja'  => 'blad',
					'errors' => array( __( 'Brak uprawnień do zmiany reguł rabatowych.', 'mp-offer-builder' ) ),
				)
			);
		}

		if ( isset( $_POST['mp_ob_przywroc'] ) ) {
			// Kasujemy opcję zamiast zapisywać kopię domyślnych progów — inaczej
			// zamroziłyby się w bazie i przestały nadążać za aktualizacjami wtyczki.
			delete_option( self::OPTION );

			return array_merge( $nic, array( 'akcja' => 'przywrocenie' ) );
		}

		$surowe = isset( $_POST['mp_ob_rabaty'] ) ? wp_unslash( $_POST['mp_ob_rabaty'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- każde pole sanityzowane w validate().
		$surowe = is_array( $surowe ) ? $surowe : array();
		$wynik  = self::validate( $surowe );

		/*
		 * Pusta tabela to NIE jest konfiguracja „rabat zerowy dla wszystkich".
		 *
		 * Walidacja dokłada regułę R-00 (catch-all, 0%), więc wyczyszczenie
		 * wszystkich wierszy dawało konfigurację z jedną regułą: zero procent dla
		 * każdego wariantu. Zapis kończył się zielonym komunikatem, a rabaty
		 * znikały z całego sklepu. Nagłówek tego pliku obiecuje przy tym, że pusta
		 * konfiguracja znaczy „reguły wbudowane" — tej ścieżki nie dało się tędy
		 * osiągnąć, bo zapisywana opcja pusta nigdy nie była.
		 *
		 * Nie zgadujemy, o co chodziło: obie intencje mają swój przycisk.
		 */
		if ( empty( $wynik['errors'] ) && 0 === (int) $wynik['wlasne'] ) {
			return array_merge(
				$nic,
				array(
					'akcja'   => 'blad',
					'errors'  => array( __( 'Nie podano żadnej reguły. Żeby wrócić do reguł wbudowanych, użyj przycisku „Przywróć wbudowane"; pusta tabela nie jest zapisywana jako „rabat 0% dla wszystkich".', 'mp-offer-builder' ) ),
					'wiersze' => $surowe,
				)
			);
		}

		if ( ! empty( $wynik['errors'] ) ) {
			return array_merge(
				$nic,
				array(
					'akcja'   => 'blad',
					'errors'  => $wynik['errors'],
					'wiersze' => $surowe,
				)
			);
		}

		$nowa = array(
			// Nowa wersja przy KAŻDYM zapisie — patrz nagłówek pliku.
			'version'  => 'cfg-' . gmdate( 'YmdHis' ),
			'rules'    => $wynik['rules'],
			'saved_by' => get_current_user_id(),
		);

		/*
		 * `update_option()` oddaje false także wtedy, gdy nowa wartość jest równa
		 * starej — dlatego samo false nie wystarczy za dowód awarii. Rozstrzyga
		 * odczyt: jeśli w bazie stoi co innego, niż mieliśmy zapisać, zapis nie
		 * doszedł do skutku i nie ma o czym meldować sukcesu.
		 */
		if ( ! update_option( self::OPTION, $nowa ) && get_option( self::OPTION ) !== $nowa ) {
			return array_merge(
				$nic,
				array(
					'akcja'   => 'blad',
					'errors'  => array( __( 'Nie udało się zapisać reguł — baza odrzuciła zmianę. Spróbuj ponownie, a jeśli problem wraca, zgłoś to administratorowi.', 'mp-offer-builder' ) ),
					'wiersze' => $surowe,
				)
			);
		}

		return array_merge( $nic, array( 'akcja' => 'zapis' ) );
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

		$wynik = self::maybe_save();

		foreach ( $wynik['errors'] as $blad ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $blad ) . '</p></div>';
		}

		if ( 'blad' === $wynik['akcja'] ) {
			// Odrzucona jest CAŁA paczka, nie sam błędny wiersz. Bez tego zdania
			// użytkownik z jednym czerwonym notice'em zakładał, że reszta zmian
			// jednak weszła.
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Nic nie zostało zapisane — popraw wskazane wiersze i zapisz ponownie. Dotychczasowe reguły obowiązują bez zmian.', 'mp-offer-builder' )
				. '</p></div>';
		}

		if ( 'zapis' === $wynik['akcja'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Reguły rabatowe zapisane. Oferty wystawione wcześniej zachowują rabat policzony według poprzedniej wersji.', 'mp-offer-builder' )
				. '</p></div>';
		}

		if ( 'przywrocenie' === $wynik['akcja'] ) {
			// Ta gałąź NICZEGO nie zapisała — skasowała opcję. Komunikat o „zapisie"
			// był tu nieprawdą, a przy okazji gubił jedyną informację, która się
			// liczy: od teraz obowiązują progi wbudowane w tę wersję wtyczki.
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Przywrócono reguły wbudowane. Ustawienia zostały skasowane, a rabaty liczy teraz słownik z wtyczki. Oferty wystawione wcześniej zachowują rabat policzony według poprzedniej wersji.', 'mp-offer-builder' )
				. '</p></div>';
		}

		/*
		 * Po błędzie formularz pokazuje TO, CO CZŁOWIEK WPISAŁ.
		 *
		 * Odrysowanie z bazy oznaczało, że komunikat mówił o wartości, której na
		 * ekranie już nie ma — a cała reszta poprawek z tej samej paczki znikała
		 * bez śladu i bez słowa.
		 */
		$wlasne = array();

		if ( 'blad' === $wynik['akcja'] ) {
			foreach ( $wynik['wiersze'] as $wiersz ) {
				$wlasne[] = array(
					'wariant' => isset( $wiersz['wariant'] ) ? sanitize_key( (string) $wiersz['wariant'] ) : '',
					'min_qty' => isset( $wiersz['min_qty'] ) ? sanitize_text_field( (string) $wiersz['min_qty'] ) : '',
					'percent' => isset( $wiersz['percent'] ) ? sanitize_text_field( (string) $wiersz['percent'] ) : '',
				);
			}
		} else {
			foreach ( self::rules() as $regula ) {
				if ( ! empty( $regula['wariant'] ) ) {
					$wlasne[] = $regula;
				}
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
				self::rules_are_custom()
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
