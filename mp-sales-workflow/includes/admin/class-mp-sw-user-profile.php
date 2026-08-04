<?php
/**
 * Konfiguracja handlowca na ekranie profilu użytkownika.
 *
 * Dział 4 dobiera właściciela procesu po usermeta `mp_sw_country`, `mp_sw_langs`
 * i `mp_sw_active` (zob. MP_SW_D2_Reader). Konto z samą rolą „Handlowiec", bez
 * tych pól, nie jest kandydatem dla ŻADNEGO procesu — pipeline kończy się kodem
 * `no_owner`, proces nie powstaje, a zgłoszenie po prostu leży.
 *
 * Do 1.3.9 ustawić te pola dało się wyłącznie spoza panelu: instrukcja
 * techniczna podawała `wp user meta update <id> mp_sw_country PL`, a zasiew demo
 * wołał `update_user_meta()` z poziomu PHP. Klient, któremu PRZECZYTAJ-MNIE.txt
 * każe wgrać wtyczki przez „Wtyczki → Dodaj nową", nie miał jak dokończyć
 * konfiguracji tam, gdzie ją zaczął — a system po instalacji przyjmował
 * zgłoszenia i po cichu nie robił z nimi nic. Dokumentacja nazywała to
 * „konfiguracją wdrożeniową"; z punktu widzenia kogoś, kto ma to uruchomić,
 * była to konfiguracja bez interfejsu.
 *
 * Ekran jest standardowy dla WordPressa (`show_user_profile` / `edit_user_profile`),
 * więc nie wprowadza nowego miejsca do nauczenia się — pola pojawiają się tam,
 * gdzie administrator i tak nadaje rolę.
 *
 * @package MP_Sales_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pola handlowca w profilu użytkownika.
 */
class MP_SW_User_Profile {

	/** Nazwa pola nonce (i zarazem akcji). */
	const NONCE = 'mp_sw_profil_handlowca';

	/**
	 * Wpina ekran i zapis.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'show_user_profile', array( __CLASS__, 'render' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save' ) );
	}

	/**
	 * Rysuje sekcję na ekranie profilu.
	 *
	 * @param WP_User $user Edytowany użytkownik.
	 * @return void
	 */
	public static function render( $user ) {
		if ( ! ( $user instanceof WP_User ) || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$kraj    = (string) get_user_meta( $user->ID, MP_SW_D2_Reader::META_COUNTRY, true );
		$jezyki  = (string) get_user_meta( $user->ID, MP_SW_D2_Reader::META_LANGS, true );
		$zespol  = (string) get_user_meta( $user->ID, MP_SW_D2_Reader::META_TEAM, true );
		$aktywny = '1' === (string) get_user_meta( $user->ID, MP_SW_D2_Reader::META_ACTIVE, true );

		/*
		 * Pola z prefiksem `mp_` pilnuje MP_SW_Meta_Guard i przepuszcza wyłącznie
		 * uprawnienie `promote_users`. Bez tego warunku handlowiec dostałby na
		 * własnym profilu pola, które wyglądają na edytowalne, a zapis kończyłby
		 * się po cichu niczym — formularz pokazywałby wtedy co innego niż baza.
		 * Kto nie może zmieniać, ten widzi wartości wyłączone i powód.
		 */
		$moze = current_user_can( 'promote_users' );

		?>
		<h2><?php esc_html_e( 'Obsługa sprzedaży (MP Sales Workflow)', 'mp-sales-workflow' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Te pola decydują, czy konto może zostać właścicielem procesu sprzedażowego. Bez kraju i języka konto nie jest kandydatem dla żadnego zgłoszenia — sama rola nie wystarczy.', 'mp-sales-workflow' ); ?>
		</p>
		<?php if ( ! $moze ) : ?>
			<p class="description"><strong><?php esc_html_e( 'Tylko do odczytu — zmiana tych pól wymaga uprawnienia „promote_users" (administrator).', 'mp-sales-workflow' ); ?></strong></p>
		<?php endif; ?>
		<?php wp_nonce_field( self::NONCE, self::NONCE ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="mp-sw-country"><?php esc_html_e( 'Obsługiwany kraj', 'mp-sales-workflow' ); ?></label></th>
				<td>
					<input type="text" id="mp-sw-country" name="<?php echo esc_attr( MP_SW_D2_Reader::META_COUNTRY ); ?>"
						value="<?php echo esc_attr( $kraj ); ?>" maxlength="2" size="4" class="regular-text" style="width:5em" <?php disabled( ! $moze ); ?>>
					<p class="description"><?php esc_html_e( 'Kod ISO-3166-1 alfa-2, na przykład PL albo DE.', 'mp-sales-workflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="mp-sw-langs"><?php esc_html_e( 'Obsługiwane języki', 'mp-sales-workflow' ); ?></label></th>
				<td>
					<input type="text" id="mp-sw-langs" name="<?php echo esc_attr( MP_SW_D2_Reader::META_LANGS ); ?>"
						value="<?php echo esc_attr( $jezyki ); ?>" class="regular-text" <?php disabled( ! $moze ); ?>>
					<p class="description"><?php esc_html_e( 'Kody dwuliterowe po przecinku, na przykład: pl,en', 'mp-sales-workflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="mp-sw-team"><?php esc_html_e( 'Zespół', 'mp-sales-workflow' ); ?></label></th>
				<td>
					<input type="text" id="mp-sw-team" name="<?php echo esc_attr( MP_SW_D2_Reader::META_TEAM ); ?>"
						value="<?php echo esc_attr( $zespol ); ?>" class="regular-text" <?php disabled( ! $moze ); ?>>
					<p class="description"><?php esc_html_e( 'Używany przez zakres widoczności „zespół" w rolach.', 'mp-sales-workflow' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Przyjmuje nowe procesy', 'mp-sales-workflow' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( MP_SW_D2_Reader::META_ACTIVE ); ?>" value="1" <?php checked( $aktywny ); ?> <?php disabled( ! $moze ); ?>>
						<?php esc_html_e( 'Tak — konto może dostawać nowe zgłoszenia', 'mp-sales-workflow' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Odznaczenie zostawia bieżące procesy, ale wyłącza konto z przydziału nowych.', 'mp-sales-workflow' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Zapisuje pola po wysłaniu formularza profilu.
	 *
	 * @param int $user_id Identyfikator edytowanego użytkownika.
	 * @return void
	 */
	public static function save( $user_id ) {
		$user_id = (int) $user_id;

		/*
		 * DWA warunki, nie jeden. `edit_user` mowi „wolno ci edytowac ten profil",
		 * ale pola `mp_*` przepuszcza dopiero `promote_users` (MP_SW_Meta_Guard).
		 * Sprawdzenie tylko pierwszego z nich konczyloby sie zapisem, ktory
		 * straznik odrzuca po cichu — a uzytkownik widzialby formularz twierdzacy,
		 * ze zmiana weszla.
		 */
		if ( $user_id <= 0 || ! current_user_can( 'edit_user', $user_id ) || ! current_user_can( 'promote_users' ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		$kraj = isset( $_POST[ MP_SW_D2_Reader::META_COUNTRY ] )
			? self::kraj( sanitize_text_field( wp_unslash( $_POST[ MP_SW_D2_Reader::META_COUNTRY ] ) ) )
			: '';

		$jezyki = isset( $_POST[ MP_SW_D2_Reader::META_LANGS ] )
			? self::jezyki( sanitize_text_field( wp_unslash( $_POST[ MP_SW_D2_Reader::META_LANGS ] ) ) )
			: '';

		$zespol = isset( $_POST[ MP_SW_D2_Reader::META_TEAM ] )
			? trim( sanitize_text_field( wp_unslash( $_POST[ MP_SW_D2_Reader::META_TEAM ] ) ) )
			: '';

		/*
		 * Niezaznaczony checkbox NIE PRZYCHODZI w żądaniu. Gdyby brak pola
		 * znaczył „nie zmieniaj", odznaczenia nie dałoby się nigdy zapisać —
		 * konto raz włączone zostawałoby aktywne na zawsze, a formularz
		 * pokazywałby wtedy co innego niż baza.
		 */
		$aktywny = isset( $_POST[ MP_SW_D2_Reader::META_ACTIVE ] ) ? '1' : '0';

		update_user_meta( $user_id, MP_SW_D2_Reader::META_COUNTRY, $kraj );
		update_user_meta( $user_id, MP_SW_D2_Reader::META_LANGS, $jezyki );
		update_user_meta( $user_id, MP_SW_D2_Reader::META_TEAM, $zespol );
		update_user_meta( $user_id, MP_SW_D2_Reader::META_ACTIVE, $aktywny );
	}

	/**
	 * Kod kraju do postaci, w której szuka go Dział 4.
	 *
	 * Wielkość liter ma znaczenie: „pl" i „PL" byłyby dla wyszukiwania dwoma
	 * różnymi krajami, a wpisujący nie ma powodu o tym wiedzieć.
	 *
	 * @param string $wartosc Wpisana wartość.
	 * @return string Kod ISO-2 wielkimi literami albo pusty ciąg.
	 */
	public static function kraj( $wartosc ) {
		$wartosc = strtoupper( trim( (string) $wartosc ) );

		return preg_match( '/^[A-Z]{2}$/', $wartosc ) ? $wartosc : '';
	}

	/**
	 * Lista języków do postaci, w której szuka jej Dział 4.
	 *
	 * @param string $wartosc Wpisana wartość.
	 * @return string Kody dwuliterowe po przecinku, bez powtórzeń.
	 */
	public static function jezyki( $wartosc ) {
		$kody = array();

		foreach ( explode( ',', (string) $wartosc ) as $kod ) {
			$kod = strtolower( trim( $kod ) );

			if ( preg_match( '/^[a-z]{2}$/', $kod ) && ! in_array( $kod, $kody, true ) ) {
				$kody[] = $kod;
			}
		}

		return implode( ',', $kody );
	}
}
