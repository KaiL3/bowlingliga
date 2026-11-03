# Glossary (German → English)

The website's user interface is German; code, comments and documentation are
English. This glossary is the binding reference for translating domain terms.
Rule numbers (`R12.9` etc.) refer to [domain-logic.md](domain-logic.md).

## 1. Domain terms

| German (UI) | English (code, docs) | Example identifier |
|---|---|---|
| Liga | league | |
| Saison | season | `Season`, `seasonId` |
| Saisonkonfiguration | season configuration | `SeasonConfig` |
| Spieltag | matchday | `Matchday`, `matchdayId` |
| Spieltag-Zähler (Nr. des Spieltags) | matchday number | `matchdayNumber` |
| gespielter Spieltag | played matchday | `isPlayed` |
| Spieltag-Typ: regulär / Positionday / Losentscheid | matchday type: regular / positionday / lot draw | `MatchdayType::REGULAR` |
| Positionday (Platz 1 gegen 2, …) | positionday | `Positionday` |
| Losentscheid (Bahn per Los) | lot draw | `LOT_DRAW` |
| Teampaarung | fixture | `Fixture`, `fixtureId` |
| Paarung (allgemein) | pairing | `Pairing` |
| Heim / Gast | home / away | `homeTeamSeasonId` |
| Bahn | lane | |
| Bahnpaar | lane pair | `LanePair` |
| Bahnzuordnung | lane assignment | `LaneAssignment` |
| Spieler | player | `Player` |
| Team | team | `Team` |
| Team-Saison (Team in einer Saison) | team season | `TeamSeason`, `teamSeasonId` |
| Kader | roster | `Roster` |
| Aufstellung | lineup | `Lineup` |
| Position (1–4) | position | `position` |
| Einzelspiel | game | `Game`, `gameNumber` |
| Serie (3 Spiele eines Abends) | series | `Series` |
| Netto / Brutto | net / gross | `net`, `gross` |
| Pins | pins | `pinsNet` |
| Handicap (HDC) | handicap | `handicap` |
| eingefrorenes Handicap | frozen handicap | `FrozenHandicap` |
| Handicap-Kette | handicap chain | `HandicapChain` |
| Handicap-Formel | handicap formula | `HandicapFormula` |
| Maximal-Handicap | maximum handicap | `maxHandicap` |
| Zielwert (Basis für das HDC, z. B. 190) | target score | `targetScore` |
| Durchschnitt / Schnitt | average | `average` |
| Schnittliste | average list | `AverageList` |
| Erstspieltag (erster Abend ohne Schnitt) | first matchday | `isFirstMatchday` |
| Vorsaison-Bestand | previous-season record | `PreviousSeasonRecord` |
| Ligatabelle | league table | `LeagueTable` |
| Tagestabelle | matchday table | `MatchdayTable` |
| Rang | rank | `rank` |
| Punkte / Spielpunkte | points / game points | `gamePoints` |
| Grundpunkte | base points | `basePoints` |
| Zusatzpunkte / Bonuspunkte | bonus points | `bonusPoints` |
| Gesamtpunkte | total points | `totalPoints` |
| Ehrungen | honours | `Honours` |
| Ehrungs-Kategorie | honour category | `HonourCategory` |
| Platz / Platzierung | place / placement | `Placement` |
| Geisterteam | ghost team | `GhostTeam` |
| Blindspieler | blind player | `BlindPlayer` |
| herangezogener Spieler (für Blind) | substituted player | `substitutePlayerId` |
| Spielzettel | score sheet | `ScoreSheet` |
| Original-Spielzettel (Foto/Scan) | original score sheet | `OriginalScoreSheet` |
| virtueller Spielzettel | virtual score sheet | `VirtualScoreSheet` |
| Druck-Spielzettel | printed score sheet | `PrintScoreSheet` |
| Ergebniserfassung | result entry | `ResultEntry` |
| Entwurf / bestätigen | draft / confirm | `saveDraft`, `confirm` |
| Ausfüllstatus: offen / in Bearbeitung / vollständig | completion status: open / in progress / complete | `CompletionStatus::OPEN` |
| prüfbedürftig | needs review | `needsReview` |
| Startgeld | entry fee | `entryFee` |
| Blindspieler-Gebühr | blind fee | `blindFee` |
| Saisonplanung / Spielplan | season planning / schedule | `SeasonPlanning`, `Schedule` |
| Saisonplaner | season planner | `SeasonPlanner` |
| Stammdaten | master data | `MasterData` |
| Adminkonto | admin account | `AdminAccount` |
| Anmeldung / Abmeldung | login / logout | |
| Zwei-Faktor-Anmeldung | two-factor authentication | `twoFactor`, `Totp…` |
| Fehlversuch / Sperre | failed attempt / lockout | `failedAttempts`, `lockedUntil` |
| Schreibzugriff | write access | `WriteAccess` |
| Startseiten-Inhalt / Meldung | home page post | `HomePagePost` |
| Spielregeln | rules | `Rules` |
| Datenschutzerklärung | privacy policy | `PrivacyPolicy` |
| Impressum | legal notice | `LegalNotice` |
| Darstellung (Stil Lane/Bento, Modus hell/dunkel/auto) | appearance (style, mode light/dark/auto) | `Appearance` |
| Rohdaten | raw data | `RawData` |
| Übersicht | overview | `Overview` |
| Teamseite | team page | |

## 2. Naming patterns

| German suffix / word | English | Example |
|---|---|---|
| …Leser (lesender Port) | …Reader | `SpielerKennzahlenLeser` → `PlayerStatsReader` |
| …Speicher (schreibender Port) | …Store | `KaderSpeicher` → `RosterStore` |
| …Repository | …Repository | `SpieltagRepository` → `MatchdayRepository` |
| …Verwaltung (Anwendungsfall) | …Management | `SpielerVerwaltung` → `PlayerManagement` |
| …Dienst, …_Service | …Service | `Punkte_Service` → `PointsService` |
| …Modul | …Module | `HandicapModul` → `HandicapModule` |
| …Ergebnis | …Result | `LoginErgebnis` → `LoginResult` |
| …Eingabe | …Input | `DurchschnittEingabe` → `AverageInput` |
| …Zeile | …Row | `Tabellenzeile` → `TableRow` |
| …Vorschau | …Preview | `Planungsvorschau` → `PlanningPreview` |
| …Kontext | …Context | |
| …Pruefer | …Verifier | `TotpPruefer` → `TotpVerifier` |
| …Verschluesselung | …Encryption | |
| …Exception | …Exception | |
| finde…, findeAlle… | find…, findAll… | `findeAlleFuerSaison` → `findAllForSeason` |
| erstelle, speichere, loesche | create, save, delete | |
| pruefe…, istX, hatX | check…/verify…, isX, hasX | |
| rendere… | render… | |

## 3. Namespaces

| German | English |
|---|---|
| `Application\Ergebniserfassung` | `Application\ResultEntry` |
| `Application\Oeffentlich`, `Persistence\Oeffentlich` | `Application\PublicSite`, `Persistence\PublicSite` |
| `Application\Saisonplanung`, `Persistence\Saisonplanung` | `…\SeasonPlanning` |
| `Persistence\Saisonverwaltung` | `Persistence\SeasonManagement` |
| `Persistence\Stammdaten` | `Persistence\MasterData` |
| `Domain\Bahn` | `Domain\Lane` |
| `Domain\Druck`, `Druck`, `Persistence\Druck` | `…\Printing` |
| `Domain\Durchschnitt` | `Domain\Average` |
| `Domain\Ehrungen` | `Domain\Honours` |
| `Domain\Geisterteam` | `Domain\GhostTeam` |
| `Domain\Punkte` | `Domain\Points` |
| `Domain\Saisonplaner` | `Domain\SeasonPlanner` |
| `Domain\Schnittliste` | `Domain\AverageList` |
| `Domain\Tabelle` | `Domain\Table` |
| unchanged | `Aggregation`, `Cms`, `Cache`, `Ocr`, `Handicap`, `Positionday`, `Security`, `Presentation` |

## 4. Database

The tables and columns had German names until October 2026; this is how
the German terms map to them.

| German table | English table | Renamed columns |
|---|---|---|
| `spieler` | `player` | `geschlecht` → `gender` |
| `team` | `team` | – |
| `saison` | `season` | `startdatum` → `start_date`, `enddatum` → `end_date`, `anzahl_spieltage` → `matchday_count` |
| `saison_konfig` | `season_config` | `saison_id` → `season_id`, `bahnanzahl` → `lane_count`, `startgeld` → `entry_fee`, `blindspieler_gebuehr` → `blind_fee`, `maximal_handicap` → `max_handicap`, `handicap_formel` → `handicap_formula`, `zielwert` → `target_score` |
| `team_saison` | `team_season` | `saison_id` → `season_id`, `teamname` → `team_name`, `ist_geisterteam` → `is_ghost_team` |
| `kader` | `roster` | `team_saison_id` → `team_season_id`, `spieler_id` → `player_id` |
| `bahnpaar` | `lane_pair` | `saison_id` → `season_id`, `bahn_a` → `lane_a`, `bahn_b` → `lane_b` |
| `spieltag` | `matchday` | `saison_id` → `season_id`, `spieltag_zaehler` → `matchday_number`, `datum` → `date`, `spieltag_typ` → `matchday_type`, `ist_gespielt` → `is_played` |
| `teampaarung` | `fixture` | `spieltag_id` → `matchday_id`, `team_saison_heim_id` → `home_team_season_id`, `team_saison_gast_id` → `away_team_season_id`, `bahnpaar_id` → `lane_pair_id`, `ausfuellstatus` → `completion_status` |
| `aufstellung` | `lineup` | `teampaarung_id` → `fixture_id`, `team_saison_id` → `team_season_id`, `spieler_id` → `player_id`, `ist_blind` → `is_blind` |
| `einzelspiel_ergebnis` | `game_result` | `aufstellung_id` → `lineup_id`, `einzelspiel_nr` → `game_number`, `ist_blind` → `is_blind` |
| `eingefrorenes_handicap` | `frozen_handicap` | `spieltag_id` → `matchday_id`, `spieler_id` → `player_id`, `hdc_wert` → `handicap` |
| `vorsaison_bestand` | `previous_season_record` | `spieler_id` → `player_id`, `anzahl_spiele` → `game_count`, `summe_netto_pins` → `net_pins_total` |
| `original_spielzettel` | `original_score_sheet` | `teampaarung_id` → `fixture_id`, `datei` → `file`, `mime_typ` → `mime_type` |
| `adminkonto` | `admin_account` | `benutzername` → `username`, `passwort_hash` → `password_hash`, `totp_secret_verschluesselt` → `totp_secret_encrypted`, `totp_letzter_zeitschritt` → `totp_last_time_step`, `gesperrt_bis` → `locked_until`, `fehlversuche` → `failed_attempts` |
| `anmeldeversuch` | `login_attempt` | `adminkonto_id` → `admin_account_id`, `zeitpunkt` → `attempted_at`, `erfolgreich` → `successful` |
| `startseiten_inhalt` | `home_page_post` | `titel` → `title` |
| `spielregeln` | `rules` | – |
| `datenschutz` | `privacy_policy` | – |
| `impressum` | `legal_notice` | – |
| `cache_durchschnitt`, `cache_ligatabelle`, `cache_tagestabelle`, `cache_ehrungen` | `cache_average`, `cache_league_table`, `cache_matchday_table`, `cache_honours` | `cache_schluessel` → `cache_key`, `saison_id` → `season_id`, `ab_spieltag_zaehler` → `from_matchday_number`, `nutzdaten` → `payload`, `roh_hash` → `raw_hash`, `berechnet_am` → `computed_at` |
| `schema_migration` | `schema_migration` | `eingespielt_am` → `applied_at` |

`id` and `text` columns keep their names.

### Stored values

| Column | German value | English value | UI label (German) |
|---|---|---|---|
| `player.gender` | `männlich`, `weiblich` | `male`, `female` | männlich, weiblich |
| `fixture.completion_status` | `offen`, `in Bearbeitung`, `vollständig` | `open`, `in_progress`, `complete` | offen, in Bearbeitung, vollständig |
| `matchday.matchday_type` | `regulär`, `Positionday`, `Losentscheid` | `regular`, `positionday`, `lot_draw` | regulär, Positionday, Losentscheid |

## 5. Unchanged

- The German texts shown to visitors and admins (German UI; English texts in `lang/en.php`).
- URLs (`/matchday/7`, `/admin/results`, …) and HTML form field names are English since 2026-10-25.
- Requirement numbers and the property numbers 1–23.
