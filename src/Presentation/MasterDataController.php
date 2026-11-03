<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\AdminAccountManagement;
use Bowlingliga\Application\MatchdayManagement;
use Bowlingliga\Application\PlayerManagement;
use Bowlingliga\Application\SeasonAdministration;
use Bowlingliga\Application\TeamManagement;
use Bowlingliga\I18n\Translator;
use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\AccountException;
use Bowlingliga\Security\ValidationException;

/**
 * Controller of the master data management in the admin area (R28.10).
 *
 * Connects the writing use cases (tasks 21.1–21.4) for players, teams,
 * seasons/matchdays and admin accounts with the reading repositories for the
 * display and the {@see MasterDataView}. The controller:
 * - renders the overview from the currently persisted master data,
 * - processes the writing actions (create/remove) via the use cases, which in
 *   turn validate server-side (task 19) and check write access (R5.1),
 * - catches the domain exceptions and translates them into success or error
 *   messages for the user.
 *
 * CSRF protection of the forms (R22.5) is enforced by the calling front
 * controller before an action runs; this controller assumes that a valid
 * token has already been checked.
 */
final class MasterDataController
{
    public function __construct(
        private readonly PlayerManagement $playerManagement,
        private readonly TeamManagement $teamManagement,
        private readonly SeasonAdministration $seasonAdministration,
        private readonly MatchdayManagement $matchdayManagement,
        private readonly AdminAccountManagement $accountManagement,
        private readonly MasterDataReader $reader,
        private readonly MasterDataView $view,
        private readonly Translator $t = new Translator(),
    ) {
    }

    /**
     * Renders the master data overview for the selected season.
     *
     * @param string      $csrfField Prepared hidden CSRF field (R22.5).
     * @param int|null    $seasonId  Selected season (for team/matchday lists).
     * @param string|null $message   Success message of a previous action.
     * @param string|null $error     Error message of a previous action.
     */
    /** @var array<string, string> action → tab on which its result is shown */
    private const TAB_OF_ACTION = [
        'player-create'   => 'players',
        'player-remove' => 'players',
        'season-create'    => 'seasons',
        'season-remove'  => 'seasons',
        'team-create'      => 'teams',
        'team-remove'    => 'teams',
        'roster-assign'    => 'teams',
        'roster-remove'   => 'teams',
        'matchday-create'  => 'matchdays',
        'account-create'     => 'accounts',
        'account-remove'   => 'accounts',
    ];

    /**
     * Tab on which the overview appears after an action, so that one stays in
     * the same area after saving.
     */
    public static function tabForAction(string $action): string
    {
        return self::TAB_OF_ACTION[$action] ?? 'players';
    }

    public function overview(
        string $csrfField,
        ?int $seasonId,
        ?string $message = null,
        ?string $error = null,
        string $tab = 'players',
        string $importArea = '',
    ): string {
        return $this->view->render(
            $csrfField,
            $this->reader->allPlayers(),
            $seasonId !== null ? $this->reader->seasonTeams($seasonId) : [],
            $this->reader->allSeasons(),
            $seasonId !== null ? $this->reader->seasonMatchdays($seasonId) : [],
            $this->reader->allAccounts(),
            $seasonId,
            $message,
            $error,
            $seasonId !== null ? $this->reader->seasonRoster($seasonId) : [],
            $tab,
            $importArea,
        );
    }

    /**
     * Runs a writing master data action and returns the {@see ActionResult}
     * with a success or error message.
     *
     * The domain exceptions of the use cases (validation, access protection,
     * domain conflicts) are caught and translated into messages for the user;
     * on an error the underlying data stays unchanged.
     *
     * @param array<string, mixed> $input Raw form data (usually $_POST).
     */
    public function process(string $action, array $input): ActionResult
    {
        try {
            return match ($action) {
                'player-create'  => $this->playerCreate($input),
                'player-remove' => $this->playerRemove($input),
                'season-create'   => $this->seasonCreate($input),
                'season-remove' => $this->seasonRemove($input),
                'team-create'     => $this->teamCreate($input),
                'team-remove'   => $this->teamRemove($input),
                'matchday-create' => $this->matchdayCreate($input),
                'account-create'    => $this->accountCreate($input),
                'account-remove'  => $this->accountRemove($input),
                'roster-assign'   => $this->rosterAssign($input),
                'roster-remove'  => $this->rosterRemove($input),
                default            => ActionResult::error($this->t->t('common.unknown_action')),
            };
        } catch (AccessDeniedException $e) {
            return ActionResult::error($this->t->error($e));
        } catch (ValidationException | AccountException $e) {
            return ActionResult::error($this->t->error($e));
        } catch (\RuntimeException $e) {
            // Domain management exceptions (team/season) already carry
            // messages phrased for the user.
            return ActionResult::error($this->t->error($e));
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function playerCreate(array $input): ActionResult
    {
        $this->playerManagement->createPlayer(
            (string) ($input['name'] ?? ''),
            (string) ($input['gender'] ?? ''),
        );

        return ActionResult::success($this->t->t('master_data.player_created'));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function playerRemove(array $input): ActionResult
    {
        $this->playerManagement->removePlayer($this->integer($input, 'player_id'));

        return ActionResult::success($this->t->t('master_data.player_removed'));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function seasonCreate(array $input): ActionResult
    {
        $this->seasonAdministration->createSeason(
            (string) ($input['name'] ?? ''),
            (string) ($input['start_date'] ?? ''),
            (string) ($input['end_date'] ?? ''),
        );

        return ActionResult::success($this->t->t('master_data.season_created'));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function seasonRemove(array $input): ActionResult
    {
        $this->seasonAdministration->deleteSeason($this->integer($input, 'season_id'));

        return ActionResult::success($this->t->t('master_data.season_removed'));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function teamCreate(array $input): ActionResult
    {
        $this->teamManagement->createTeam(
            $this->integer($input, 'season_id'),
            (string) ($input['team_name'] ?? ''),
        );

        return ActionResult::success($this->t->t('master_data.team_created'));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function teamRemove(array $input): ActionResult
    {
        $this->teamManagement->removeTeamFromSeason($this->integer($input, 'team_season_id'));

        return ActionResult::success($this->t->t('master_data.team_removed'));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function matchdayCreate(array $input): ActionResult
    {
        $this->matchdayManagement->createMatchday(
            $this->integer($input, 'season_id'),
            $this->integer($input, 'matchday_number'),
            (string) ($input['date'] ?? ''),
            (string) ($input['matchday_type'] ?? MatchdayManagement::TYPE_REGULAR),
        );

        return ActionResult::success($this->t->t('master_data.matchday_created'));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function accountCreate(array $input): ActionResult
    {
        $this->accountManagement->createAccount(
            (string) ($input['username'] ?? ''),
            (string) ($input['password'] ?? ''),
        );

        return ActionResult::success($this->t->t('master_data.account_created'));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function accountRemove(array $input): ActionResult
    {
        $this->accountManagement->removeAccount($this->integer($input, 'account_id'));

        return ActionResult::success($this->t->t('master_data.account_removed'));
    }

    /**
     * Assigns a player to the roster of a team of the season (R21.1, R6.6).
     *
     * @param array<string, mixed> $input
     */
    private function rosterAssign(array $input): ActionResult
    {
        $this->playerManagement->assignPlayerToTeamSeason(
            $this->integer($input, 'team_season_id'),
            $this->integer($input, 'player_id'),
        );

        return ActionResult::success($this->t->t('master_data.roster_added'));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function rosterRemove(array $input): ActionResult
    {
        $this->playerManagement->removeTeamSeasonAssignment($this->integer($input, 'roster_id'));

        return ActionResult::success($this->t->t('master_data.roster_removed'));
    }

    /**
     * Reads a required integer value from the form data.
     *
     * @param array<string, mixed> $input
     *
     * @throws ValidationException if the value is missing or not a positive
     *         integer.
     */
    private function integer(array $input, string $field): int
    {
        $value = filter_var($input[$field] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value <= 0) {
            throw ValidationException::forField($field, 'validation.invalid_value');
        }

        return $value;
    }
}
