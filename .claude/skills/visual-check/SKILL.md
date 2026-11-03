---
name: visual-check
description: Check pages and print PDFs of the league website as screenshots via Firefox headless (desktop and phone, style Lane/Bento, light/dark, admin pages too). Use after UI, CSS, JavaScript or print changes or whenever the appearance should be checked – there is no Node.
---

# Visual check via screenshot

Checks the real rendering against the database from `config/database.php`
**without changing data** (GET only). Files of this skill: `router.php`,
`screenshot.php`. Both are plain PHP, so they need neither Node nor shell
scripts (PowerShell scripts are often blocked by the execution policy on
Windows).

`$PHP` below stands for the PHP binary: `PHP=${PHP:-php}`, i.e. set `PHP`
to the full path if `php` is not on the PATH. Firefox is found in its
default location; `FIREFOX` overrides the path.

## Procedure

1. **Start the server** in the background on its own port 8123 (port 8000
   is usually the developer's own server):
   ```bash
   BOWLINGLIGA_STYLE=bento BOWLINGLIGA_MODE=dark $PHP -S 127.0.0.1:8123 -t public .claude/skills/visual-check/router.php
   ```
   - `-t public` is required, otherwise the server does not deliver
     CSS/JS (404).
   - `BOWLINGLIGA_STYLE` = `lane` | `bento`, `BOWLINGLIGA_MODE` = `auto` |
     `light` | `dark`, `BOWLINGLIGA_LANG` = `de` | `en` (language of the
     texts; without it Firefox headless asks for German).
   - For admin pages additionally `BOWLINGLIGA_ADMIN=<id>`; read the id of
     an admin account afresh beforehand (skill `db-query`:
     `SELECT id, username FROM admin_account`). With `BOWLINGLIGA_ADMIN` the
     router rejects everything except GET.
   - Restart the server for a different style.
2. **Screenshot** (target in a temporary directory, not in the project):
   ```bash
   $PHP .claude/skills/visual-check/screenshot.php http://127.0.0.1:8123/ <tmp>/home-desktop.png
   $PHP .claude/skills/visual-check/screenshot.php http://127.0.0.1:8123/ <tmp>/home-phone.png 390
   ```
   Arguments: URL, target, width (default 1280, phone 390), height (1500).
   Time limit 40 s and a separate Firefox profile – a direct Firefox call
   has hung before.
3. **Look at the PNG** and name anything conspicuous.
4. **Clean up:** only end the processes you started – the server on port
   8123 (by its PID; on Windows e.g. find it with
   `Get-CimInstance Win32_Process -Filter "Name='php.exe'"` and the `8123`
   in its command line). Never the server on 8000 or a Firefox of the user.

## PDFs (printed score sheets)

1. Start the server with `BOWLINGLIGA_ADMIN`, fetch the PDF and save it:
   `curl -s -o <tmp>/sheet.pdf "http://127.0.0.1:8123/admin/print/..."`
   (route see [docs/routes.md](../../../docs/routes.md)).
2. Check the page count: count `/Type /Page` (without `/Pages`) in the PDF.
3. Look at it: `screenshot.php file:///<absolute path>/sheet.pdf <target.png>`
   or open the PDF directly.

mPDF renders differently from a browser (`<pagebreak />`, widths on cells,
no child selectors, `text-rotate`): see the section "mPDF quirks" in
[docs/development.md](../../../docs/development.md).

## Reference

The virtual and the printed score sheet reproduce the league's paper score
sheet; `ScoreSheetCalculatorTest` recalculates a real one. When changing the
sheet layout, compare with the original if you have it (maintainers keep it
under `.local/`, which is not versioned).
