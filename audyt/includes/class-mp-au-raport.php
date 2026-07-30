<?php
/**
 * Raport z przebiegu audytu.
 *
 * Format jest celowo dwojaki: JSON do porownywania przebiegow i do CI, oraz
 * tekst po polsku dla czlowieka. Raport, ktorego nie da sie porownac z
 * poprzednim, nie odpowie na pytanie „czy naprawilismy, czy tylko przesunelismy".
 *
 * @package MP_Audyt
 */

declare( strict_types = 1 );

/**
 * Buduje i zapisuje raport.
 */
final class MP_AU_Raport {

	/** @var MP_AU_Kontekst */
	private $kontekst;

	/** @var array */
	private $przebiegi;

	/** @var array */
	private $workspace;

	/**
	 * @param MP_AU_Kontekst $kontekst  Kontekst.
	 * @param array          $przebiegi Przebiegi dzialow.
	 * @param array          $workspace Raport wystawienia worktree.
	 */
	public function __construct( MP_AU_Kontekst $kontekst, array $przebiegi, array $workspace ) {
		$this->kontekst  = $kontekst;
		$this->przebiegi = $przebiegi;
		$this->workspace = $workspace;
	}

	/**
	 * Ustalenia pogrupowane wg wagi, z pominieciem odrzuconych.
	 *
	 * @return array<string,MP_AU_Ustalenie[]>
	 */
	public function wedlug_wagi(): array {
		$grupy = array(
			MP_AU_Ustalenie::KRYTYCZNE  => array(),
			MP_AU_Ustalenie::SREDNIE    => array(),
			MP_AU_Ustalenie::DROBNE     => array(),
			MP_AU_Ustalenie::OBSERWACJA => array(),
		);

		foreach ( $this->kontekst->ustalenia() as $u ) {
			if ( MP_AU_Ustalenie::ODRZUCONE === $u->status ) {
				continue;
			}

			$grupy[ $u->waga ][] = $u;
		}

		return $grupy;
	}

	/**
	 * @return bool
	 */
	public function ma_krytyczne(): bool {
		return ! empty( $this->wedlug_wagi()[ MP_AU_Ustalenie::KRYTYCZNE ] );
	}

	/**
	 * Werdykt — swiadomie tylko trzy stany, bez zmyslonych procentow.
	 *
	 * Ocena liczbowa („projekt na 87%") byla by fikcja: nie ma skali, w ktorej
	 * te liczby cokolwiek znacza. Werdykt odpowiada na pytanie, ktore ma sens:
	 * czy to wolno wdrozyc.
	 *
	 * @return string
	 */
	public function werdykt(): string {
		$grupy = $this->wedlug_wagi();

		foreach ( $this->przebiegi as $p ) {
			if ( empty( $p['bramka']['zaliczona'] ) ) {
				return 'BRAK WERDYKTU — audyt niekompletny (bramka dzialu ' . $p['dzial'] . ' niezaliczona)';
			}
		}

		if ( ! empty( $grupy[ MP_AU_Ustalenie::KRYTYCZNE ] ) ) {
			return 'NO GO — ' . count( $grupy[ MP_AU_Ustalenie::KRYTYCZNE ] ) . ' ustalen krytycznych';
		}

		if ( ! empty( $grupy[ MP_AU_Ustalenie::SREDNIE ] ) ) {
			return 'GO WITH MINOR FIXES — ' . count( $grupy[ MP_AU_Ustalenie::SREDNIE ] ) . ' ustalen sredniej wagi';
		}

		return 'GO';
	}

	/**
	 * @param string $katalog Katalog docelowy.
	 * @return void
	 */
	public function zapisz( string $katalog ): void {
		if ( ! is_dir( $katalog ) && ! mkdir( $katalog, 0777, true ) && ! is_dir( $katalog ) ) {
			return;
		}

		$dane = array(
			'data'        => gmdate( 'c' ),
			'workspace'   => $this->workspace,
			'model'       => array(
				'tryb'      => $this->kontekst->model->tryb(),
				'dostepny'  => $this->kontekst->model->dostepny(),
				'zapytania' => $this->kontekst->model->ile_zapytan(),
			),
			'odczyty'     => $this->kontekst->odczyty(),
			'przebiegi'   => $this->przebiegi,
			'sprzezenia'  => $this->kontekst->pobierz( 'sprzezenia', array() ),
			'werdykt'     => $this->werdykt(),
			'ustalenia'   => array_map(
				static function ( MP_AU_Ustalenie $u ) {
					return $u->do_tablicy();
				},
				$this->kontekst->ustalenia()
			),
		);

		file_put_contents(
			$katalog . '/raport-' . gmdate( 'Ymd-His' ) . '.json',
			(string) json_encode( $dane, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);

		file_put_contents( $katalog . '/raport-ostatni.json', (string) json_encode( $dane, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		file_put_contents( $katalog . '/raport-ostatni.txt', $this->podsumowanie_tekstowe() );
	}

	/**
	 * @return string
	 */
	public function podsumowanie_tekstowe(): string {
		$grupy = $this->wedlug_wagi();
		$out   = "=== WYNIK AUDYTU ===\n\n";

		$etykiety = array(
			MP_AU_Ustalenie::KRYTYCZNE  => 'KRYTYCZNE',
			MP_AU_Ustalenie::SREDNIE    => 'SREDNIE',
			MP_AU_Ustalenie::DROBNE     => 'DROBNE',
			MP_AU_Ustalenie::OBSERWACJA => 'OBSERWACJE',
		);

		foreach ( $grupy as $waga => $lista ) {
			if ( empty( $lista ) ) {
				continue;
			}

			$out .= $etykiety[ $waga ] . ' (' . count( $lista ) . "):\n";

			foreach ( $lista as $u ) {
				$out .= '  [' . $u->para . '] ' . $u->opis . "\n";

				if ( '' !== $u->plik ) {
					$out .= '        ' . $u->plik . ( $u->linia > 0 ? ':' . $u->linia : '' ) . "\n";
				}

				$out .= '        status: ' . $u->status . "\n";

				if ( '' !== $u->scenariusz ) {
					$out .= '        skutek: ' . $this->zawin( $u->scenariusz, 70, '                ' ) . "\n";
				}

				if ( ! empty( $u->maskuje ) ) {
					$out .= '        UWAGA: maskuje ' . count( $u->maskuje ) . " innych ustalen — naprawiac RAZEM\n";
				}

				if ( '' !== $u->naprawa ) {
					$out .= '        naprawa: ' . $u->naprawa . "\n";
				}

				$out .= "\n";
			}
		}

		if ( array_sum( array_map( 'count', $grupy ) ) === 0 ) {
			$out .= "Zadnych ustalen.\n\n";
		}

		$out .= 'odczytow plikow: ' . $this->kontekst->odczyty() . "\n";
		$out .= 'zapytan do modelu: ' . $this->kontekst->model->ile_zapytan() . "\n";
		$out .= "\nWERDYKT: " . $this->werdykt() . "\n";

		return $out;
	}

	/**
	 * Zawija tekst z wcieciem.
	 *
	 * @param string $tekst   Tekst.
	 * @param int    $szer    Szerokosc.
	 * @param string $wciecie Wciecie kolejnych wierszy.
	 * @return string
	 */
	private function zawin( string $tekst, int $szer, string $wciecie ): string {
		return str_replace( "\n", "\n" . $wciecie, wordwrap( $tekst, $szer, "\n", false ) );
	}
}
