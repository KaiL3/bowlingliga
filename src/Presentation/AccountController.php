<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Application\AdminAccountManagement;
use Bowlingliga\I18n\Translator;
use Bowlingliga\Security\AccountException;
use Bowlingliga\Security\SessionStore;
use Bowlingliga\Security\TotpSecretEncryptionException;
use Bowlingliga\Security\AccessDeniedException;

/**
 * "Mein Konto" in the admin area: set up and disable the two-factor login,
 * change the own password (R23.3, R23.6–R23.9).
 *
 * The setup runs in two steps: a new secret is generated and kept pending in
 * the session – encrypted only; the page shows it as QR code and for typing
 * in. It only becomes active once a valid code from the app has been entered.
 * This way nobody locks themselves out with a secret that was not
 * transferred.
 */
final class AccountController
{
    /** Session key of the pending secret ("accountId:encrypted"). */
    public const SESSION_TOTP_PROPOSAL = 'totp_proposal';

    public function __construct(
        private readonly AdminAccountManagement $management,
        private readonly SessionStore $session,
        private readonly AccountView $view,
        private readonly QrCode $qrCode = new QrCode(),
        private readonly Translator $t = new Translator(),
    ) {
    }

    public function page(string $csrfField, int $accountId, ?string $message = null, ?string $error = null): string
    {
        try {
            $username = $this->management->username($accountId);
            $active        = $this->management->isTotpActive($accountId);

            $setup = null;
            $pending  = $active ? null : $this->pendingSecret($accountId);
            if ($pending !== null) {
                $data       = $this->management->totpProposalFrom($pending, $username);
                $setup = [
                    'secret' => $data->plaintextSecret,
                    'uri'    => $data->provisioningUri,
                    'qr'     => $this->qrCode->svg($data->provisioningUri),
                ];
            }
        } catch (AccountException | AccessDeniedException | TotpSecretEncryptionException $e) {
            return $this->view->renderError($this->t->error($e));
        }

        return $this->view->render($csrfField, $username, $active, $setup, $message, $error);
    }

    /**
     * Step 1: generate a new secret and keep it pending (not active yet).
     */
    public function startTotp(int $accountId): ActionResult
    {
        return $this->perform(function () use ($accountId): ActionResult {
            if ($this->management->isTotpActive($accountId)) {
                return ActionResult::error($this->t->t('account.totp_already_active'));
            }

            $setup = $this->management->generateTotpProposal($accountId, $this->management->username($accountId));
            $this->session->set(self::SESSION_TOTP_PROPOSAL, $accountId . ':' . $setup->secretEncrypted);

            return ActionResult::success($this->t->t('account.totp_scan'));
        });
    }

    /**
     * Step 2: activate with a valid code (R23.6).
     *
     * @param array<string, mixed> $input
     */
    public function activateTotp(int $accountId, array $input): ActionResult
    {
        return $this->perform(function () use ($accountId, $input): ActionResult {
            $pending = $this->pendingSecret($accountId);
            if ($pending === null) {
                return ActionResult::error($this->t->t('account.totp_not_started'));
            }

            $this->management->activateTotp($accountId, $pending, $this->text($input, 'code'));
            $this->session->remove(self::SESSION_TOTP_PROPOSAL);

            return ActionResult::success($this->t->t('account.totp_activated'));
        });
    }

    public function cancelTotp(): ActionResult
    {
        $this->session->remove(self::SESSION_TOTP_PROPOSAL);

        return ActionResult::success($this->t->t('account.totp_cancelled'));
    }

    /**
     * @param array<string, mixed> $input
     */
    public function deactivateTotp(int $accountId, array $input): ActionResult
    {
        return $this->perform(function () use ($accountId, $input): ActionResult {
            $this->management->deactivateTotp($accountId, $this->text($input, 'password'), $this->text($input, 'code'));

            return ActionResult::success($this->t->t('account.totp_deactivated'));
        });
    }

    /**
     * @param array<string, mixed> $input
     */
    public function changePassword(int $accountId, array $input): ActionResult
    {
        return $this->perform(function () use ($accountId, $input): ActionResult {
            $this->management->changeOwnPassword(
                $accountId,
                $this->text($input, 'current'),
                $this->text($input, 'new'),
                $this->text($input, 'repeat'),
            );

            return ActionResult::success($this->t->t('account.password_changed'));
        });
    }

    /**
     * Logs out all other sessions of the account (e.g. a forgotten login on a
     * foreign device); this session stays logged in.
     */
    public function endOtherSessions(int $accountId): ActionResult
    {
        return $this->perform(function () use ($accountId): ActionResult {
            $this->management->endOtherSessions($accountId);

            return ActionResult::success($this->t->t('account.sessions_ended'));
        });
    }

    /**
     * Pending secret of this account, or null (if it belongs to another
     * account, it is discarded).
     */
    private function pendingSecret(int $accountId): ?string
    {
        $value = $this->session->retrieve(self::SESSION_TOTP_PROPOSAL);
        if (! is_string($value) || ! str_starts_with($value, $accountId . ':')) {
            return null;
        }

        return substr($value, strlen($accountId . ':'));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function text(array $input, string $field): string
    {
        $value = $input[$field] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @param callable(): ActionResult $action
     */
    private function perform(callable $action): ActionResult
    {
        try {
            return $action();
        } catch (AccountException | AccessDeniedException | TotpSecretEncryptionException $e) {
            return ActionResult::error($this->t->error($e));
        }
    }
}
