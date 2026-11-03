<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\I18n\Translator;

/**
 * Renders the admin area "Liga" with the settings of the whole installation
 * (R38): for now the league name.
 *
 * The form carries the hidden CSRF token (R22.5); all values are escaped.
 * Free of side effects, returns an HTML fragment.
 */
final class LeagueSettingsView
{
    use ViewTexts;

    public function __construct(private readonly Translator $t = new Translator())
    {
    }

    /**
     * @param string $name Stored name, or after an error the submitted input.
     */
    public function renderEdit(string $csrfField, string $name, ?string $message = null, ?string $error = null): string
    {
        $value = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return AdminComponents::pageHeader($this->plain('admin.area.league.title'), $this->plain('layout.admin_area'))
            . AdminComponents::notes($message, $error)
            . <<<HTML
                <section class="card">
                <form class="form" method="post" action="/admin/league/save">
                    {$csrfField}
                    <div class="form__field">
                        <label for="league-name">{$this->text('field.league_name')}</label>
                        <input type="text" id="league-name" name="name" value="{$value}" maxlength="100" required aria-describedby="league-name-note">
                        <small id="league-name-note">{$this->text('league.name_note')}</small>
                    </div>
                    <button type="submit" class="button">{$this->text('common.save')}</button>
                </form>
                </section>
                HTML;
    }
}
