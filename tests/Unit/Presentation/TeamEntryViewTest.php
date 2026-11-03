<?php

declare(strict_types=1);

namespace Bowlingliga\Tests\Unit\Presentation;

use Bowlingliga\I18n\Translator;
use Bowlingliga\Presentation\TeamEntryController;
use PHPUnit\Framework\TestCase;

/**
 * Team page of the guided result entry by the teams (R37): steps, one game
 * per step with the own players, filled blind positions, overview,
 * confirmation and escaping.
 */
final class TeamEntryViewTest extends TestCase
{
    private TeamEntryFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new TeamEntryFixture();
    }

    public function testFirstStepShowsTheOwnPlayersOfGameOneAndEscapes(): void
    {
        $html = $this->fixture->html('home');

        self::assertStringContainsString('&lt;b&gt;Adler&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>Adler</b>', $html);
        self::assertStringContainsString('aria-current="step"', $html);
        self::assertStringContainsString('Spiel 1 – wer spielt?', $html);
        self::assertStringContainsString('<select name="lineup[11]" id="lineup-11" class="js-mark">', $html);
        self::assertStringContainsString('name="nets[11]" id="net-11"', $html);
        self::assertStringNotContainsString('name="lineup[21]"', $html, 'The opponent\'s players are not part of the own entry.');
        self::assertStringNotContainsString('fällt aus (blind)', $html, 'Nobody can drop out of the first game.');
        self::assertStringContainsString('<input type="hidden" name="game" value="1">', $html);
        self::assertStringContainsString('name="next" value="2"', $html);
        self::assertStringContainsString('<script src="/assets/js/team-entry.js" defer></script>', $html);
    }

    public function testTheNextGameTakesOverTheLineupAndShowsTheBlindPositions(): void
    {
        $this->fixture->playedGames->averages = [15 => 160.0];
        $this->fixture->playedGames->games = [15 => 8];
        $this->fixture->enterGame('home', 1, 170, 3);

        $html = $this->fixture->html('home', 2);

        self::assertStringContainsString('Die Aufstellung aus Spiel 1 ist übernommen', $html);
        self::assertStringContainsString('<option value="plays" selected>spielt</option>', $html);
        self::assertStringContainsString('<option value="blind">fällt aus (blind)</option>', $html, 'A player of game 1 can drop out.');
        self::assertStringContainsString('Automatisch blind aufgefüllt', $html);
        self::assertStringContainsString('blind für Spieler 15', $html);
        self::assertStringContainsString('<strong>150</strong>', $html, 'Average 160 − 10.');
    }

    public function testASubstitutedPlayerCannotBeChosenAgain(): void
    {
        $this->fixture->enterGame('home', 1, 170);
        $this->fixture->entry->saveGame($this->fixture->code('home'), 2, [12 => 'plays', 13 => 'plays', 14 => 'plays', 15 => 'plays'], [12 => '1', 13 => '1', 14 => '1', 15 => '1']);

        $html = $this->fixture->html('home', 3);

        self::assertStringContainsString('ausgewechselt – spielt nicht mehr', $html);
        self::assertStringNotContainsString('name="lineup[11]"', $html);
    }

    public function testOverviewShowsTheTotalsAndSending(): void
    {
        $this->fixture->enterAll('home', 170);

        $html = $this->fixture->html('home', TeamEntryController::STEP_OVERVIEW);

        self::assertStringContainsString('Übersicht &lt;b&gt;Adler&lt;/b&gt;', $html);
        self::assertStringContainsString('<span class="team-entry__value">2040</span>', $html, '12 × 170 net in total.');
        self::assertStringContainsString('action="/entry/submit"', $html);
        self::assertStringContainsString('Spiel 2 ändern', $html);
    }

    public function testTheConfirmationStepLetsTheOpponentCheckTheSentEntry(): void
    {
        $this->fixture->enterAll('home', 170);
        $this->fixture->entry->submit($this->fixture->code('home'));

        $home = $this->fixture->html('home');
        self::assertStringContainsString('data-refresh="1"', $home, 'After sending the confirmation step opens and refreshes.');
        self::assertStringContainsString('Eure Eingabe ist abgeschickt.', $home);
        self::assertStringContainsString('Falken prüft eure Eingabe.', $home);
        self::assertStringContainsString('action="/entry/withdraw"', $this->fixture->html('home', TeamEntryController::STEP_OVERVIEW));

        $away = $this->fixture->html('away', TeamEntryController::STEP_STATUS);
        self::assertStringContainsString('Eingabe von &lt;b&gt;Adler&lt;/b&gt; prüfen', $away);
        self::assertStringContainsString('action="/entry/confirm"', $away);
        self::assertStringContainsString('action="/entry/reject"', $away);
        self::assertStringContainsString('hat seine Eingabe abgeschickt. Ihr könnt sie unter', $this->fixture->html('away', 1), 'A hint on the other steps.');
    }

    public function testAfterBothConfirmationsNothingIsEditable(): void
    {
        foreach (['home' => 170, 'away' => 160] as $side => $net) {
            $this->fixture->enterAll($side, $net);
            $this->fixture->entry->submit($this->fixture->code($side));
        }
        $this->fixture->entry->confirm($this->fixture->code('home'));
        $this->fixture->entry->confirm($this->fixture->code('away'));
        $this->fixture->store->fixtures[TeamEntryFixture::FIXTURE]['status'] = 'complete';

        $html = $this->fixture->html('home', 1);

        self::assertStringNotContainsString('<select', $html);
        self::assertStringNotContainsString('<input type="number"', $html);
        self::assertStringContainsString('Die Ergebnisse stehen fest', $html);
        self::assertStringContainsString('Zwischenstand (brutto)', $html);
    }

    public function testAgainstTheGhostTeamSendingIsTheConfirmation(): void
    {
        $this->fixture->store->fixtures[TeamEntryFixture::FIXTURE]['away'] = ['team_season_id' => 20, 'team_name' => 'Geisterteam', 'is_ghost_team' => true, 'players' => []];
        $this->fixture->enterAll('home', 170);

        $html = $this->fixture->html('home', TeamEntryController::STEP_OVERVIEW);

        self::assertStringContainsString('Abschicken – Ergebnisse stehen dann fest', $html);
        self::assertStringNotContainsString('Bestätigung</span>', $html, 'No confirmation step against the ghost team.');
        self::assertStringContainsString('Zwischenstand (brutto)', $html, 'The ghost team\'s fixed values count against it.');
    }

    // --- Languages ---

    public function testTeamPageAndAdminCardsInEnglishWithoutGermanLeftovers(): void
    {
        $fixture = new TeamEntryFixture('en');
        $fixture->playedGames->averages = [15 => 160.0];
        $fixture->playedGames->games = [15 => 8];
        $home = $fixture->code('home');
        $away = $fixture->code('away');

        $error = $fixture->controller->action('save', ['code' => $home, 'game' => '1', 'next' => '2', 'lineup' => [11 => 'plays'], 'nets' => []])['error'];
        $fixture->enterGame('home', 1, 170, 3);
        $html = $fixture->html('home', 2);
        $fixture->enterAll('home', 170);
        $html .= $fixture->html('home', TeamEntryController::STEP_OVERVIEW);
        $fixture->entry->submit($home);
        $html .= $fixture->html('home', TeamEntryController::STEP_OVERVIEW)
            . $fixture->html('home', TeamEntryController::STEP_STATUS)
            . $fixture->html('away', 1)
            . $fixture->html('away', TeamEntryController::STEP_STATUS)
            . $fixture->controller->adminFixtureCard(TeamEntryFixture::FIXTURE, '', 'https://example.org');
        $fixture->now = '2026-09-04 12:00:00';
        $html .= $fixture->controller->page($home)['html'];

        self::assertSame('Game 1: the result of Anna is missing.', $error);
        self::assertStringContainsString('Matchday 3 · Wed 2 Sep 2026 · Lanes 1/2', $html);
        self::assertStringContainsString('Game 2 – who plays?', $html);
        self::assertStringContainsString('Free position – blind for Spieler 15', $html);
        self::assertStringContainsString('Falken is checking your entry.', $html);
        self::assertStringContainsString('Check the entry of &lt;b&gt;Adler&lt;/b&gt;', $html);
        self::assertStringContainsString('Result entry by the teams', $html);
        self::assertStringContainsString('on the evening of the match (2 Sep 2026)', $html);

        self::assertDoesNotMatchRegularExpression('/\b(team_entry|common)\.[a-z_.]+/', strip_tags($html), 'No key without its text.');
        $german = Translator::catalog('de');
        $english = Translator::catalog('en');
        foreach ($german as $key => $text) {
            if (mb_strlen($text) >= 8 && $text !== $english[$key] && ! str_contains($text, '{')) {
                self::assertStringNotContainsString(htmlspecialchars($text), $html, "German text of $key");
            }
        }
    }
}
