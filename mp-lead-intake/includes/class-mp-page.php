<?php
/**
 * Pod-strona WordPress wtyczki MP Lead Intake.
 *
 * Wymóg unikalny pluginu 1: po aktywacji tworzy pod-stronę z formularzem
 * (shortcode [mp_lead_intake_form]). Strona to zwykła strona WordPress, więc
 * renderuje się w szablonie aktywnego motywu i DZIEDZICZY jego wygląd
 * (nagłówek, stopka, style) — stąd "dopasowanie do motywu". Inne wtyczki
 * (plugin 2/3) mogą dokładać treść przez hook 'mp_lead_intake_after_form'.
 *
 * Oficjalne API: wp_insert_post() https://developer.wordpress.org/reference/functions/wp_insert_post/
 * Dodanie do menu: get_nav_menu_locations() i wp_update_nav_menu_item() —
 * https://developer.wordpress.org/reference/functions/get_nav_menu_locations/
 * https://developer.wordpress.org/reference/functions/wp_update_nav_menu_item/
 * (WordPress NIE dokłada nowych stron do istniejącego, ręcznie zbudowanego
 * menu motywu automatycznie — trzeba to zrobić explicite tym API.)
 *
 * @package MP_Lead_Intake
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tworzenie i usuwanie pod-strony.
 */
class MP_Lead_Intake_Page {

	/** Opcja przechowująca ID utworzonej strony. */
	const OPTION = 'mp_lead_intake_page_id';

	/** Opcja: czy stronę udało się umieścić w co najmniej jednym menu motywu. */
	const OPTION_MENU_OK = 'mp_lead_intake_menu_ok';

	/**
	 * Opcja: KTÓRA z trzech przyczyn zablokowała wpis w menu.
	 *
	 * Flaga 0/1 wystarczała kodowi, ale nie człowiekowi. `add_to_menus()` zwraca
	 * false w trzech różnych sytuacjach, a ostrzeżenie podawało zawsze tę samą,
	 * jedną z nich — administrator motywu, który menu rejestruje poprawnie,
	 * dostawał diagnozę nieprawdziwą i instrukcję naprawy, która nie może pomóc.
	 */
	const OPTION_MENU_REASON = 'mp_lead_intake_menu_reason';

	/** Opcja: ślad po nieudanym utworzeniu pod-strony (pusta = strona jest). */
	const OPTION_PAGE_ERROR = 'mp_lead_intake_page_error';

	/** Powód: motyw nie rejestruje żadnej lokalizacji menu. */
	const MENU_NO_LOCATIONS = 'brak_lokalizacji';

	/** Powód: lokalizacje są, ale żadna nie ma przypisanego menu. */
	const MENU_NO_ASSIGNED = 'lokalizacje_bez_menu';

	/** Powód: wp_update_nav_menu_item() nie zapisało pozycji. */
	const MENU_INSERT_FAILED = 'zapis_nieudany';

	/** Tytuł pod-strony — jedno źródło prawdy (post_title, wpis menu, H1, fallback linku). */
	const TITLE = 'Zapytanie ofertowe';

	/** Krótki opis pod-strony — jedno źródło prawdy (meta description, lead pod H1). */
	const DESCRIPTION = 'Wypełnij formularz zapytania ofertowego, a nasz zespół skontaktuje się z Tobą z indywidualną ofertą.';

	/**
	 * Powód nieudanego zapisu strony — NIGDY pusty.
	 *
	 * `OPTION_PAGE_ERROR` z pustą wartością znaczy w tej klasie „strona jest"
	 * (patrz docblock stałej) — a ślad powstawał z `get_error_message()` bez
	 * sprawdzenia, czy komunikat w ogóle jest. `WP_Error` z samym kodem i bez
	 * treści zwraca pusty łańcuch, więc wtyczka blokująca zapis w ten sposób
	 * zostawiała ślad NIEODRÓŻNIALNY od powodzenia: strony nie ma, panel milczy,
	 * a administrator widzi wyłącznie „Wtyczka włączona".
	 *
	 * Kolejność jest od najbardziej do najmniej konkretnej wskazówki: treść,
	 * potem sam kod błędu (bo i on kieruje do właściwej wtyczki), na końcu zdanie
	 * o braku przyczyny. Każda z nich jest niepusta, więc warunek „pusto = strona
	 * jest" znowu mówi prawdę.
	 *
	 * @param mixed $page_id Wynik `wp_insert_post()`.
	 * @return string
	 */
	private static function powod_awarii( $page_id ) {
		if ( is_wp_error( $page_id ) ) {
			$tresc = trim( (string) $page_id->get_error_message() );

			if ( '' !== $tresc ) {
				return $tresc;
			}

			$kod = trim( (string) $page_id->get_error_code() );

			if ( '' !== $kod ) {
				return sprintf(
					/* translators: %s: kod błędu WP_Error zgłoszony przez WordPressa albo inną wtyczkę. */
					__( 'Zapis strony zablokowany bez opisu; jedyna wskazówka to kod błędu: %s', 'mp-lead-intake' ),
					$kod
				);
			}
		}

		return __( 'WordPress odrzucił zapis strony bez podania przyczyny.', 'mp-lead-intake' );
	}

	/**
	 * Powód awarii ZAKOŃCZONY instrukcją naprawy.
	 *
	 * Bliźniaczy komunikat z `komunikat_o_stanie()` zawsze kończy się tym, co
	 * administrator ma zrobić — ten podawał samą przyczynę i milkł. Człowiek
	 * dowiadywał się, że coś nie wyszło, i zostawał z tym sam, mimo że wyjście
	 * jest proste i mieści się w jednym zdaniu.
	 *
	 * Krótki kod ze stałej, nie z ręki: rada ma dotyczyć kodu, który wtyczka
	 * naprawdę rejestruje.
	 *
	 * @param mixed $page_id Wynik `wp_insert_post()`.
	 * @return string
	 */
	private static function powod_z_rada( $page_id ) {
		return self::powod_awarii( $page_id ) . ' ' . sprintf(
			/* translators: %s: krótki kod formularza wraz z nawiasami. */
			__( 'Utwórz stronę ręcznie i wstaw na niej krótki kod %s.', 'mp-lead-intake' ),
			'[' . MP_Lead_Intake_Form::SHORTCODE . ']'
		);
	}

	/**
	 * Co powiedzieć administratorowi o stanie strony z formularzem.
	 *
	 * JEDNO ŹRÓDŁO dla `create()` i `refresh_menu_status()`. Wcześniej każda
	 * z nich miała własną wersję zdania i różniły się one w rzeczy najważniejszej:
	 * `create()` wskazywała MIEJSCE W PANELU (dla kosza „Strony → Kosz", bo
	 * „Wszystkie strony" wpisów w koszu nie pokazuje), a `refresh_menu_status()`
	 * kończyła na „Przywróć ją do publikacji". Ta druga wykonuje się przy każdym
	 * wejściu do panelu, więc po chwili NADPISYWAŁA komunikat pełniejszy tym
	 * uboższym — naprawa z poprzedniego wydania żyła do pierwszego odświeżenia
	 * strony.
	 *
	 * @param WP_Post|null $wpis Wpis strony albo null, gdy strony już nie ma.
	 * @return string
	 */
	private static function komunikat_o_stanie( $wpis ) {
		if ( ! $wpis instanceof WP_Post ) {
			// Krótki kod podany WPROST: administrator, który tej strony nie
			// zakładał, nie ma skąd go znać, a bez niego rada jest pusta.
			// Wstawiany ze stałej — inaczej rada mówiłaby o krótkim kodzie,
			// którego wtyczka po zmianie nazwy już nie rejestruje.
			return sprintf(
				/* translators: %s: krótki kod formularza wraz z nawiasami. */
				__( 'Strona z formularzem została usunięta. Opublikuj nową stronę zawierającą krótki kod %s.', 'mp-lead-intake' ),
				'[' . MP_Lead_Intake_Form::SHORTCODE . ']'
			);
		}

		/*
		 * ETYKIETA, NIE SLUG.
		 *
		 * Zdanie wstawiało surowy `post_status`, więc administrator czytał polską
		 * radę z angielskim wtrętem („ma status „pending””), którego nie ma jak
		 * odnieść do czegokolwiek w panelu — panel pokazuje „Oczekujące na
		 * przegląd". Etykieta pochodzi z tego samego rejestru, z którego bierze
		 * ją WordPress, więc działa też dla statusów dokładanych przez inne
		 * wtyczki (PublishPress i podobne). Slug zostaje wyłącznie jako awaryjna
		 * wartość, gdy status nie jest w ogóle zarejestrowany.
		 */
		$obiekt   = get_post_status_object( $wpis->post_status );
		$etykieta = ( $obiekt && ! empty( $obiekt->label ) ) ? $obiekt->label : $wpis->post_status;

		/*
		 * MIEJSCE, KTÓRE NAPRAWDĘ POKAZUJE TEN WPIS.
		 *
		 * Kosz ma w panelu WŁASNY widok. „Strony → Wszystkie strony" wpisów
		 * w koszu nie pokazuje, więc dla statusu `trash` byłaby to rada nie do
		 * wykonania — ta sama klasa błędu co usunięte wcześniej „aktywuj wtyczkę
		 * ponownie".
		 *
		 * Ten sam zarzut dotyczy jednak KAŻDEGO statusu z wyłączonym
		 * `show_in_admin_all_list` — lista „Wszystkie strony" pomija je z definicji.
		 * Wcześniej odsyłaliśmy tam wszystko poza koszem, więc na instalacji
		 * z własnymi statusami wskazówka prowadziła na listę bez tego wpisu.
		 * Dla takich statusów podajemy identyfikator: `post.php?post=<ID>` działa
		 * niezależnie od tego, na której liście wpis się pokazuje.
		 *
		 * Status NIEZAREJESTROWANY idzie tą samą drogą, i to z dwóch powodów.
		 * Po pierwsze lista „Wszystkie strony" filtruje po statusach znanych
		 * WordPressowi, więc wpisu w statusie po wyłączonej wtyczce tam nie ma.
		 * Po drugie tylko w tym przypadku do zdania trafia surowy slug — nie ma
		 * skąd wziąć etykiety — więc administrator czyta nazwę, której nie znajdzie
		 * w panelu; identyfikator jest wtedy jedyną informacją, z którą da się
		 * cokolwiek zrobić.
		 */
		if ( 'trash' === $wpis->post_status ) {
			$gdzie = __( 'Strony → Kosz', 'mp-lead-intake' );
		} elseif ( $obiekt && ! empty( $obiekt->show_in_admin_all_list ) ) {
			$gdzie = __( 'Strony → Wszystkie strony', 'mp-lead-intake' );
		} else {
			$gdzie = sprintf(
				/* translators: %d: identyfikator wpisu WordPressa. */
				__( 'edytorze wpisu o identyfikatorze %d (lista „Wszystkie strony" statusów tego rodzaju nie pokazuje)', 'mp-lead-intake' ),
				(int) $wpis->ID
			);
		}

		return sprintf(
			/* translators: 1: etykieta statusu wpisu, np. „Szkic”. 2: miejsce w panelu, np. „Strony → Kosz”. */
			__( 'Strona z formularzem istnieje, ale ma status „%1$s" zamiast „opublikowana" — klienci jej nie zobaczą. Przywróć ją do publikacji w %2$s.', 'mp-lead-intake' ),
			$etykieta,
			$gdzie
		);
	}

	/**
	 * Tworzy pod-stronę, jeśli jeszcze nie istnieje (idempotentnie).
	 *
	 * @return void
	 */
	public static function create() {
		$existing = (int) get_option( self::OPTION );
		$wpis     = $existing ? get_post( $existing ) : null;

		if ( $wpis instanceof WP_Post && 'publish' === $wpis->post_status ) {
			// Strona już istnieje — nie duplikujemy, ale ewentualnie dołóż do
			// menu (np. motyw dostał przypisane menu PO utworzeniu strony).
			delete_option( self::OPTION_PAGE_ERROR );
			update_option( self::OPTION_MENU_OK, self::add_to_menus( $existing ) ? 1 : 0 );
			return;
		}

		/*
		 * WPIS W KOSZU TO NIE JEST ISTNIEJĄCA STRONA.
		 *
		 * Warunek brzmiał `if ( $existing && get_post( $existing ) )` — bez
		 * porównania `post_status`. `get_post()` oddaje jednak także wpis w koszu,
		 * w szkicu i prywatny, a gałąź kończyła się `return`, więc żaden ślad nie
		 * powstawał i `maybe_admin_notice()` milczało. Gorzej: `add_to_menus()`
		 * dokładało pozycję menu wskazującą na wpis w koszu i ZWRACAŁO SUKCES,
		 * więc `OPTION_MENU_OK` szła na 1. Panel wyglądał dokładnie tak jak przy
		 * pełnym powodzeniu, klient klikał w menu i trafiał donikąd, a jedyna
		 * informacja, jaką dostawał człowiek, to „Wtyczka włączona".
		 *
		 * Nie odtwarzamy strony po cichu: wpis w koszu bywa świadomą decyzją
		 * administratora, a szkic — pracą w toku. Mówimy, co widzimy, i zostawiamy
		 * decyzję jemu.
		 */

		/*
		 * Komunikat NIE odsyła już do ponownej aktywacji — ta droga nie może
		 * zadziałać. Ponowna aktywacja wchodzi dokładnie w tę samą gałąź (wpis nadal
		 * istnieje, status nadal inny niż publish), nadpisuje ten sam ślad i kończy
		 * się `return`; strona nie zostaje odtworzona. Rada odsyłająca człowieka do
		 * czynności bez skutku jest gorsza niż jej brak, bo każe mu uwierzyć, że
		 * zrobił, co trzeba.
		 */
		if ( $wpis instanceof WP_Post ) {
			/*
			 * Sam ślad nie wystarczy — bez zgaszonej flagi nikt go nie zobaczy.
			 *
			 * `maybe_admin_notice()` wychodzi, gdy OPTION_MENU_OK ma wartość inną
			 * niż '0', a opcja nieustawiona zwraca domyślne '1'. Bliźniacza
			 * `refresh_menu_status()` w dokładnie tym samym stanie flagę zeruje;
			 * ta gałąź zostawiała ją zapaloną, więc panel wyglądał na zdrowy przy
			 * zapisanym powodzie awarii. Mechanizm wyciszenia opisuje komentarz
			 * o dwie gałęzie niżej — jako powód poprzedniej naprawy.
			 */
			update_option( self::OPTION_MENU_OK, 0 );

			/*
			 * POZYCJA MENU ZNIKA RAZEM Z OPUBLIKOWANA STRONA.
			 *
			 * Ta galaz zapisywala slad dla administratora i wychodzila, zostawiajac
			 * NIETKNIETA pozycje menu dolozona wczesniej przez `add_to_menus()`.
			 * Administrator widzial ostrzezenie w panelu, ale GOSC dalej widzial
			 * w menu motywu „Zapytanie ofertowe" i trafial na 404 albo na wpis
			 * w koszu. Ostrzezenie dla jednej osoby nie naprawia tego, co widza
			 * wszyscy pozostali.
			 *
			 * Kasowanie jest bezpieczne: gdy strona wroci do publikacji,
			 * `refresh_menu_status()` i `create()` dokladaja pozycje z powrotem.
			 */
			self::remove_from_menus( $existing );

			update_option( self::OPTION_PAGE_ERROR, self::komunikat_o_stanie( $wpis ) );

			return;
		}

		/*
		 * Drugi argument `$wp_error` = true, bo bez niego `wp_insert_post()`
		 * oddaje przy porażce zwykłe 0 — gałąź `is_wp_error()` niżej była martwa,
		 * a administrator dostawał „bez podania przyczyny" TAKŻE wtedy, gdy
		 * WordPress przyczynę znał i podawał.
		 */
		$page_id = wp_insert_post(
			array(
				'post_title'   => self::TITLE,
				'post_name'    => 'zapytanie-ofertowe',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				// Krótki kod ZE STAŁEJ, nie z ręki: zmiana nazwy w
				// MP_Lead_Intake_Form rozjeżdżała treść zakładanej strony
				// z tym, co wtyczka faktycznie rejestruje, a przy okazji
				// z wyszukiwaniem w adopt_existing_page() niżej.
				'post_content' => '[' . MP_Lead_Intake_Form::SHORTCODE . ']',
			),
			true
		);

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( self::OPTION, (int) $page_id );
			delete_option( self::OPTION_PAGE_ERROR );
			update_option( self::OPTION_MENU_OK, self::add_to_menus( (int) $page_id ) ? 1 : 0 );

			return;
		}

		/*
		 * NIEUDANE UTWORZENIE STRONY NIE MOŻE BYĆ CISZĄ.
		 *
		 * Gałąź `else` nie istniała: przy porażce `wp_insert_post()` nie zapisywano
		 * ani strony, ani śladu. Jedyne ostrzeżenie w adminie było przy tym
		 * WYCISZONE domyślką — `maybe_admin_notice()` wychodzi, gdy OPTION_MENU_OK
		 * ma wartość inną niż '0', a opcja nieustawiona zwraca domyślne '1'.
		 * Administrator widział komunikat WordPressa „Wtyczka włączona" i zakładał,
		 * że formularz działa; klienci nie mieli gdzie go wypełnić.
		 *
		 * Zapisujemy powód, a nie samą flagę: przy WP_Error jest to komunikat
		 * tego, kto zapis zablokował, i to jedyna wskazówka, jaką administrator
		 * dostanie.
		 */
		update_option( self::OPTION_MENU_OK, 0 );

		update_option( self::OPTION_PAGE_ERROR, self::powod_z_rada( $page_id ) );
	}

	/**
	 * Ponownie sprawdza i odświeża OPTION_MENU_OK. Flaga ustawiana w create()
	 * jest z natury "lepka": jeśli admin PO fakcie przypisze menu do lokalizacji
	 * motywu (albo przełączy się na motyw, który rejestruje menu), bez tego
	 * odświeżenia flaga zostałaby nieaktualna — fallback (bufor HTML na KAŻDEJ
	 * stronie frontendu + ostrzeżenie w adminie) działałby dłużej niż potrzeba.
	 * Podpięte pod 'switch_theme' i 'wp_update_nav_menu'.
	 *
	 * @return void
	 */
	public static function refresh_menu_status() {
		$page_id = (int) get_option( self::OPTION );
		$wpis    = $page_id ? get_post( $page_id ) : null;

		// Bez zapisanej strony nie ma o czym mówić — ten stan opisuje aktywacja,
		// a nie odświeżanie statusu menu.
		if ( ! $page_id ) {
			return;
		}

		/*
		 * Ten sam warunek co w create(): dokładanie do menu wpisu w koszu albo
		 * szkicu daje pozycję prowadzącą donikąd i melduje sukces.
		 *
		 * Samo `return` było jednak CISZĄ w tym samym stanie awaryjnym, który
		 * `create()` opisuje komunikatem. `OPTION_MENU_OK` zostawało na 1 z czasów,
		 * gdy strona była opublikowana, więc panel wyglądał na w pełni zdrowy,
		 * a formularza nie było. Metoda wykrywa awarię — więc ma ją zapisać.
		 */
		if ( ! $wpis instanceof WP_Post || 'publish' !== $wpis->post_status ) {
			update_option( self::OPTION_MENU_OK, 0 );
			update_option( self::OPTION_PAGE_ERROR, self::komunikat_o_stanie( $wpis instanceof WP_Post ? $wpis : null ) );

			return;
		}

		// Stan zdrowy kasuje slad po awarii — inaczej komunikat przezylby naprawe,
		// dokladnie tak jak przed U-13.
		delete_option( self::OPTION_PAGE_ERROR );

		update_option( self::OPTION_MENU_OK, self::add_to_menus( $page_id ) ? 1 : 0 );
	}

	/**
	 * Czy formularz stoi już na jakiejś opublikowanej stronie — i jeśli tak,
	 * przyjmujemy ją za swoją.
	 *
	 * Ostrzeżenie o nieutworzonej stronie podaje DWIE drogi naprawy: ponowną
	 * aktywację wtyczki albo ręczne założenie strony ze skrótem. Gaszone było
	 * tylko przez pierwszą — jedyne `delete_option( OPTION_PAGE_ERROR )` stało
	 * w gałęzi sukcesu `create()`. Administrator, który wybrał drugą, miał
	 * działający formularz i wiszący na każdym ekranie panelu komunikat, że
	 * formularza nie ma. Komunikat, który przeczy temu, co człowiek właśnie zrobił,
	 * uczy ignorowania wszystkich komunikatów.
	 *
	 * Sprawdzamy więc STAN FAKTYCZNY, a nie sam ślad po błędzie. Zapytanie idzie
	 * wyłącznie wtedy, gdy ślad istnieje, i gaśnie razem z nim.
	 *
	 * @return bool Czy znaleziono stronę i skasowano ślad po błędzie.
	 */
	public static function adopt_existing_page() {
		/*
		 * `s` to WYSZUKIWARKA WordPressa, nie sprawdzenie obecności skrótu:
		 * przeszukuje tytuł, zajawkę i treść, dzieli frazę na słowa i dopasowuje
		 * częściowo. Strona z instrukcją („wstaw krótki kod [mp_lead_intake_form]
		 * tam, gdzie ma stanąć formularz") pasowała tak samo dobrze jak strona
		 * z formularzem. Po takim trafieniu gaśnie ostrzeżenie mówiące prawdę,
		 * a do menu trafia pozycja prowadząca na stronę bez formularza.
		 *
		 * Zapytanie zostaje — zawęża zbiór kandydatów jednym zapytaniem do bazy —
		 * ale rozstrzyga dopiero `has_shortcode()` na treści, czyli ta sama funkcja,
		 * którą WordPress decyduje, czy skrót zostanie WYKONANY.
		 */
		$kandydaci = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'numberposts'      => 20,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				's'                => '[' . MP_Lead_Intake_Form::SHORTCODE . ']',
				'suppress_filters' => false,
			)
		);

		$page_id = 0;

		foreach ( (array) $kandydaci as $kandydat ) {
			$wpis = get_post( (int) $kandydat );

			if ( $wpis instanceof WP_Post && has_shortcode( (string) $wpis->post_content, MP_Lead_Intake_Form::SHORTCODE ) ) {
				$page_id = (int) $wpis->ID;
				break;
			}
		}

		if ( $page_id <= 0 ) {
			return false;
		}

		update_option( self::OPTION, $page_id );
		delete_option( self::OPTION_PAGE_ERROR );
		update_option( self::OPTION_MENU_OK, self::add_to_menus( $page_id ) ? 1 : 0 );

		return true;
	}

	/**
	 * Dokłada stronę do KAŻDEJ lokalizacji menu motywu, która ma przypisane
	 * menu (get_nav_menu_locations()) — inaczej podstrona byłaby "niewidoczna"
	 * dla klienta mimo poprawnego utworzenia. Idempotentne: pomija lokalizację,
	 * jeśli strona już jest w danym menu (sprawdzenie po object_id).
	 * Wyłączalne filtrem 'mp_lead_intake_add_page_to_menu' (domyślnie true).
	 *
	 * UWAGA: część motywów (zwłaszcza własnoręcznie pisanych, jak niestandardowe
	 * szablony klienta) w ogóle NIE rejestruje menu przez register_nav_menu() —
	 * renderują nawigację na sztywno w PHP. Dla takich motywów get_nav_menu_locations()
	 * zwraca pustą tablicę i NIE ISTNIEJE żaden bezpieczny, generyczny sposób
	 * doklejenia linku (modyfikowanie plików motywu przez plugin byłoby kruche —
	 * zniknęłoby przy każdej aktualizacji motywu). W takim wypadku zwracamy false,
	 * a create() zapisuje to w OPTION_MENU_OK — maybe_admin_notice() poinformuje
	 * administratora wprost, zamiast pozostawić go w niewiedzy (Golden Rule: bez
	 * teatru bezpieczeństwa/automatyzacji — jawna informacja zamiast cichej porażki).
	 *
	 * @param int $page_id ID strony.
	 * @return bool True, jeśli strona jest (lub została dodana) w co najmniej
	 *              jednym menu motywu; false, gdy motyw nie ma żadnej lokalizacji
	 *              menu do wykorzystania.
	 */
	private static function add_to_menus( $page_id ) {
		if ( ! apply_filters( 'mp_lead_intake_add_page_to_menu', true ) ) {
			return true; // Świadomie wyłączone filtrem — nie traktujemy jako porażki.
		}

		$locations = get_nav_menu_locations();
		if ( empty( $locations ) ) {
			/*
			 * Pustka z `get_nav_menu_locations()` opisuje DWIE rozne sytuacje, bo ta
			 * funkcja zwraca PRZYPISANIA, a nie rejestracje: motyw bez lokalizacji
			 * oraz motyw z lokalizacja, do ktorej nikt nie przypisal menu. Druga to
			 * stan kazdego motywu swiezo po instalacji — czyli ten, ktory widuje sie
			 * najczesciej. Do 1.3.7 obie dostawaly komunikat „Twoj motyw nie
			 * rejestruje standardowego menu WordPressa", co dla drugiej jest
			 * nieprawda i kieruje administratora do naprawy, ktorej nie ma co robic.
			 *
			 * O rejestracje pyta sie `get_registered_nav_menus()`. Rozroznienie
			 * istnialo juz w kodzie (P1-G9), ale bylo osiagalne wylacznie dla
			 * ksztaltu `array( 'primary' => 0 )` — takiego WordPress w tej sytuacji
			 * nie produkuje, wiec galaz z wlasciwym komunikatem byla martwa.
			 */
			$zarejestrowane = function_exists( 'get_registered_nav_menus' )
				? (array) get_registered_nav_menus()
				: array();

			update_option(
				self::OPTION_MENU_REASON,
				empty( $zarejestrowane ) ? self::MENU_NO_LOCATIONS : self::MENU_NO_ASSIGNED
			);

			return false;
		}

		$added_anywhere = false;
		$any_assigned   = false;

		foreach ( array_unique( $locations ) as $menu_id ) {
			$menu_id = (int) $menu_id;
			if ( $menu_id <= 0 ) {
				// Lokalizacja zarejestrowana przez motyw, ale administrator nie
				// przypisał do niej menu. To zupełnie inna sprawa niż motyw bez
				// lokalizacji — i naprawia ją kto inny, w innym miejscu panelu.
				continue;
			}

			$any_assigned = true;

			$items   = wp_get_nav_menu_items( $menu_id );
			$in_menu = false;
			if ( is_array( $items ) ) {
				foreach ( $items as $item ) {
					if ( 'page' === $item->object && (int) $item->object_id === $page_id ) {
						$in_menu = true;
						break;
					}
				}
			}
			if ( $in_menu ) {
				$added_anywhere = true;
				continue;
			}

			$result = wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-title'     => self::TITLE,
					'menu-item-object-id' => $page_id,
					'menu-item-object'    => 'page',
					'menu-item-type'      => 'post_type',
					'menu-item-status'    => 'publish',
				)
			);
			if ( $result && ! is_wp_error( $result ) ) {
				$added_anywhere = true;
			}
		}

		if ( $added_anywhere ) {
			delete_option( self::OPTION_MENU_REASON );

			return true;
		}

		// Trzy różne porażki, trzy różne rzeczy do zrobienia przez człowieka.
		update_option(
			self::OPTION_MENU_REASON,
			$any_assigned ? self::MENU_INSERT_FAILED : self::MENU_NO_ASSIGNED
		);

		return false;
	}

	/**
	 * Ostrzeżenie w panelu admina, gdy nie udało się automatycznie dodać strony
	 * do żadnego menu (motyw nie rejestruje menu WP) — jawna informacja zamiast
	 * cichej porażki, niezależnie od tego, czy zadziała fallback HTML poniżej
	 * (fallback jest "best effort" — admin i tak dostaje pewny, ręczny link).
	 * Wyłączalne filtrem 'mp_lead_intake_show_menu_notice'.
	 *
	 * @return void
	 */
	public static function maybe_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		/*
		 * NAJPIERW SPRAWA POWAŻNIEJSZA: BRAK SAMEJ STRONY.
		 *
		 * Ta gałąź nie istniała, a wyjście poniżej („OPTION_MENU_OK inne niż '0'")
		 * wyciszało wszystko, bo przy nieudanym utworzeniu strony flaga menu nie
		 * była nawet ustawiana i domyślka '1' kończyła metodę. Administrator nie
		 * miał jak się dowiedzieć, że kluczowa funkcja wtyczki nie powstała.
		 */
		$blad_strony = (string) get_option( self::OPTION_PAGE_ERROR, '' );

		// Zanim cokolwiek powiemy — sprawdzamy, czy problem nadal istnieje.
		// Administrator mógł w międzyczasie założyć stronę ręcznie, czyli wykonać
		// DRUGĄ z dwóch dróg, które ten komunikat sam mu podaje.
		if ( '' !== $blad_strony && self::adopt_existing_page() ) {
			$blad_strony = '';
		}

		if ( '' !== $blad_strony ) {
			/*
			 * Krótki kod MUSI się tu pokazać. Wcześniej zdanie kończyło się dwukropkiem
			 * („wstawiając na niej krótki kod:"), a w bloku <code> stała treść błędu
			 * WordPressa — człowiek dostawał więc obietnicę kodu do przeklejenia,
			 * a w miejscu tego kodu komunikat awarii. Jedyna rzecz, którą miał zrobić
			 * ręcznie, była jedyną, której nie dostał.
			 */
			printf(
				// `is-dismissible` jak przy ostrzeżeniu o menu niżej. Komunikatu bez
				// tej klasy nie da się zamknąć — wisiał na każdym ekranie panelu.
				'<div class="notice notice-error is-dismissible"><p><strong>MP Lead Intake:</strong> %s <code>%s</code></p><p>%s <code>%s</code></p></div>',
				esc_html__( 'Strona z formularzem zapytania ofertowego NIE POWSTAŁA podczas aktywacji, więc klienci nie mają gdzie go wypełnić. Wyłącz i włącz wtyczkę ponownie albo utwórz stronę ręcznie, wstawiając na niej krótki kod:', 'mp-lead-intake' ),
				esc_html( '[' . MP_Lead_Intake_Form::SHORTCODE . ']' ),
				esc_html__( 'Powód zgłoszony przez WordPress:', 'mp-lead-intake' ),
				esc_html( $blad_strony )
			);

			return;
		}

		if ( '0' !== (string) get_option( self::OPTION_MENU_OK, '1' ) ) {
			return; // Brak flagi porażki (albo jeszcze nie ustawiona, albo sukces).
		}
		if ( ! apply_filters( 'mp_lead_intake_show_menu_notice', true ) ) {
			return;
		}

		$url = self::url();
		if ( '' === $url ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>MP Lead Intake:</strong> %s <a href="%s" target="_blank" rel="noopener">%s</a></p></div>',
			esc_html( self::menu_notice_text() ),
			esc_url( $url ),
			esc_html( $url )
		);
	}

	/**
	 * Treść ostrzeżenia o menu — dobrana do RZECZYWISTEJ przyczyny.
	 *
	 * Wcześniej komunikat był jeden: „Twój motyw nie rejestruje standardowego menu
	 * WordPressa". `add_to_menus()` zwraca jednak false w trzech sytuacjach, a dwie
	 * z nich nie mają z rejestracją menu nic wspólnego. Administrator motywu, który
	 * menu rejestruje poprawnie, dostawał diagnozę nieprawdziwą i instrukcję,
	 * która nie mogła pomóc — a prawdziwa naprawa (przypisanie menu do lokalizacji)
	 * jest jednym kliknięciem w Wygląd → Menu.
	 *
	 * @return string
	 */
	private static function menu_notice_text() {
		$reason = (string) get_option( self::OPTION_MENU_REASON, self::MENU_NO_LOCATIONS );

		if ( self::MENU_NO_ASSIGNED === $reason ) {
			/*
			 * Bez zdania o „spróbowała dołożyć". W tej gałęzi żadna próba się NIE
			 * odbyła: pętla po lokalizacjach robi `continue` zanim dojdzie do
			 * wp_update_nav_menu_item(), więc ten powód powstaje właśnie dlatego,
			 * że nie było gdzie wstawiać. Zdanie zostało tu przeklejone z gałęzi
			 * „motyw nie rejestruje menu", gdzie fallback po elemencie <nav>
			 * naprawdę działa — i kazało człowiekowi szukać na stronie linku,
			 * którego nikt nie próbował dodać.
			 */
			return __( 'Twój motyw ma lokalizacje menu, ale żadna nie ma PRZYPISANEGO menu — nie było więc gdzie wstawić linku. Przypisz menu w Wygląd → Menu, a link do formularza dołoży się sam przy następnej aktywacji. Możesz też dodać ręcznie link do:', 'mp-lead-intake' );
		}

		if ( self::MENU_INSERT_FAILED === $reason ) {
			return __( 'Menu motywu jest przypisane, ale WordPress nie zapisał w nim pozycji z linkiem do formularza (mogła to zablokować inna wtyczka albo uprawnienia). Dodaj ręcznie link do:', 'mp-lead-intake' );
		}

		return __( 'Twój motyw nie rejestruje standardowego menu WordPressa — wtyczka spróbowała automatycznie dołożyć link do formularza w wykrytym menu strony (element <nav>). Sprawdź na stronie, czy się pojawił; jeśli nie, dodaj ręcznie link do:', 'mp-lead-intake' );
	}

	/**
	 * Uruchamia bufor wyjścia frontendu, który dołoży link do menu w renderowanym
	 * HTML-u (zob. inject_menu_link_html()) — TYLKO gdy oficjalne API menu (wyżej)
	 * zawiodło, bo motyw nie rejestruje żadnej lokalizacji menu. Zero kosztu/ryzyka
	 * dla motywów, dla których oficjalne API już zadziałało.
	 *
	 * Pominięte celowo dla feedów i REST — tam wstrzyknięcie HTML-u zepsułoby
	 * format odpowiedzi (XML/JSON). Wyłączalne filtrem
	 * 'mp_lead_intake_menu_html_fallback'.
	 *
	 * @return void
	 */
	public static function maybe_start_menu_buffer() {
		if ( is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( '0' !== (string) get_option( self::OPTION_MENU_OK, '1' ) ) {
			return;
		}
		if ( ! apply_filters( 'mp_lead_intake_menu_html_fallback', true ) ) {
			return;
		}
		ob_start( array( __CLASS__, 'inject_menu_link_html' ) );
	}

	/**
	 * Dokłada link do formularza w pierwszym elemencie <nav> znalezionym w
	 * wyrenderowanym HTML-u strony — dla motywów, które nie rejestrują menu
	 * przez register_nav_menu() (więc oficjalne API WP nic tu nie może zrobić).
	 *
	 * Celowo NIE używamy DOMDocument na całej stronie: pełne parsowanie i
	 * ponowna serializacja całego dokumentu (encoding, DOCTYPE, znaczniki
	 * <script> ze znakami specjalnymi) może subtelnie uszkodzić inne części
	 * strony klienta. Zamiast tego: precyzyjnie dopasowany, ograniczony do
	 * fragmentu <nav>...</nav> wzorzec regex + preg_replace_callback (bez
	 * interpretacji backreference w treści), z insercją w JEDNYM znanym
	 * miejscu. Brak dopasowania = brak zmian w HTML-u (fail-safe) — wtedy
	 * jedyną informacją zostaje ostrzeżenie w adminie (maybe_admin_notice()).
	 * Idempotentne (znacznik data-mp-lead-intake pilnuje przed duplikatem).
	 *
	 * @param string $html Pełny HTML strony (callback ob_start()).
	 * @return string HTML, ewentualnie z dołożonym linkiem.
	 */
	public static function inject_menu_link_html( $html ) {
		if ( ! is_string( $html ) || false !== strpos( $html, 'data-mp-lead-intake' ) ) {
			return $html;
		}

		$url = self::url();
		if ( '' === $url ) {
			return $html;
		}

		$href = esc_url( $url );
		// Literał, nie self::TITLE — WPCS (WordPress.WP.I18n) wymaga w __()/esc_html__()
		// dosłownego stringa do wyciągania tłumaczeń, stąd ta jedna świadoma duplikacja.
		$label = esc_html__( 'Zapytanie ofertowe', 'mp-lead-intake' );
		$count = 0;

		// Wariant A: <nav> zawiera listę <ul>...</ul> — dołóż <li><a> jako
		// ostatni element listy (dziedziczy stylowanie CSS listy motywu).
		$with_list = preg_replace_callback(
			'/(<nav\b[^>]*>.*?)(<\/ul>\s*<\/nav>)/is',
			function ( $m ) use ( $href, $label ) {
				return $m[1] . '<li><a href="' . $href . '" data-mp-lead-intake="1">' . $label . '</a></li>' . $m[2];
			},
			$html,
			1,
			$count
		);
		if ( $count > 0 && null !== $with_list ) {
			return $with_list;
		}

		// Wariant B: <nav> bez listy (płaskie linki wprost w <nav>) — dołóż
		// <a> tuż przed zamknięciem </nav>.
		$flat = preg_replace_callback(
			'/(<nav\b[^>]*>.*?)(<\/nav>)/is',
			function ( $m ) use ( $href, $label ) {
				return $m[1] . '<a href="' . $href . '" data-mp-lead-intake="1">' . $label . '</a>' . $m[2];
			},
			$html,
			1,
			$count
		);
		if ( $count > 0 && null !== $flat ) {
			return $flat;
		}

		return $html; // Brak <nav> w markupie motywu — zostaje samo ostrzeżenie w adminie.
	}

	/**
	 * Meta description pod-strony formularza — SEO. Motyw klienta (jak w tym
	 * projekcie widać na przykładzie kredyt-kompas) może nie mieć żadnej wtyczki
	 * SEO ani własnej logiki meta description; ta pod-strona i tak powinna mieć
	 * poprawny opis w wynikach wyszukiwania. Działa TYLKO na tej jednej stronie
	 * (is_page), więc nie koliduje z ewentualną wtyczką SEO zainstalowaną później
	 * dla pozostałych stron — a i tak można wyłączyć filtrem
	 * 'mp_lead_intake_seo_meta_description'.
	 *
	 * @return void
	 */
	public static function maybe_meta_description() {
		$page_id = (int) get_option( self::OPTION );
		if ( ! $page_id || ! is_page( $page_id ) ) {
			return;
		}
		if ( ! apply_filters( 'mp_lead_intake_seo_meta_description', true ) ) {
			return;
		}

		printf(
			'<meta name="description" content="%s">' . "\n",
			// Literał (nie self::DESCRIPTION) — wymóg WPCS I18n, jak wyżej.
			esc_attr__( 'Wypełnij formularz zapytania ofertowego, a nasz zespół skontaktuje się z Tobą z indywidualną ofertą.', 'mp-lead-intake' )
		);
	}

	/**
	 * Usuwa pod-stronę i opcję (wywoływane przy deinstalacji).
	 *
	 * @return void
	 */
	public static function remove() {
		$page_id = (int) get_option( self::OPTION );
		if ( $page_id ) {
			self::remove_from_menus( $page_id );
			wp_delete_post( $page_id, true );
		}
		delete_option( self::OPTION );
		delete_option( self::OPTION_MENU_OK );
	}

	/**
	 * Usuwa wpisy menu wskazujące na stronę (across wszystkie menu, nie tylko
	 * przypisane lokalizacje) — inaczej po deinstalacji zostałby "martwy" link.
	 *
	 * @param int $page_id ID strony.
	 * @return void
	 */
	private static function remove_from_menus( $page_id ) {
		$menus = wp_get_nav_menus();
		if ( empty( $menus ) ) {
			return;
		}

		foreach ( $menus as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id );
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				if ( 'page' === $item->object && (int) $item->object_id === $page_id ) {
					wp_delete_post( $item->ID, true );
				}
			}
		}
	}

	/**
	 * Zwraca URL pod-strony (lub '' gdy brak).
	 *
	 * @return string
	 */
	public static function url() {
		$page_id = (int) get_option( self::OPTION );

		if ( $page_id < 1 ) {
			return '';
		}

		/*
		 * Adres strony NIEPUBLICZNEJ to brak adresu.
		 *
		 * `get_permalink()` oddaje adres dla KAŻDEGO statusu — szkic i kosz też
		 * go mają. Zapasowe wstrzykiwanie linku do `<nav>` uruchamia się na flagę
		 * `OPTION_MENU_OK = 0`, a tę ustawia także gałąź „strony nie ma albo nie
		 * jest opublikowana". Bez tego sprawdzenia menu witryny pokazywało więc
		 * odwiedzającym odnośnik do strony, po którym dostawali 404 — dokładnie
		 * w chwili, gdy panel mówił administratorowi coś przeciwnego
		 * („przywróć stronę do publikacji").
		 *
		 * Oba miejsca czytające tę metodę traktują pusty ciąg jako „nie ma czego
		 * pokazać" i same z siebie wtedy milkną.
		 */
		$wpis = get_post( $page_id );

		if ( ! $wpis instanceof WP_Post || 'publish' !== $wpis->post_status ) {
			return '';
		}

		return (string) get_permalink( $page_id );
	}
}
