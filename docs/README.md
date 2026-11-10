# Developer documentation

Status: 2026-11-10 · 920 tests green · rules in domain-logic.md · new design (public and admin area), admin area "Saison", printing per matchday

This documentation describes the architecture, data model, domain logic and
development status of the bowling league website. It is meant for everyone
who continues working on the code – in particular as an entry point for later
development sessions, without having to reread the whole code base.

## Contents

| File | Purpose |
|---|---|
| [architecture.md](architecture.md) | Layers, composition root, ports/adapters, request flow, security, transactions, cache, frontend |
| [data-model.md](data-model.md) | Tables, relationships, status fields, migrations |
| [domain-logic.md](domain-logic.md) | **Authoritative domain rules** R1–R38 (numbers referenced in the code) and where they live in the code |
| [testing.md](testing.md) | Test strategy and the correctness properties 1–23 of the property tests |
| [routes.md](routes.md) | All public and admin routes, which class serves them, what has no UI yet |
| [operations.md](operations.md) | Installation and update on Debian (`deploy/install.sh`), backup, emergencies |
| [data-transfer.md](data-transfer.md) | Copying the complete league data from one database into another (e.g. local demo → online demo) |
| [development.md](development.md) | Local environment, databases, tests, conventions, recipe for new admin areas |
| [glossary.md](glossary.md) | Binding German → English glossary of the domain terms |
| [CONTRIBUTING.md](../CONTRIBUTING.md) | How to propose changes, rules for contributions, adding a language or a text of the user interface |

## Key facts at a glance

- **Stack:** PHP ≥ 8.4, MariaDB with InnoDB (MySQL is not supported: the
  migrations use MariaDB syntax), no framework.
  Own router, composition root in [public/index.php](../public/index.php).
  Libraries: mPDF (printing), HTML Purifier (CMS), otphp (2FA), php-qrcode
  (QR code of the 2FA setup, rendered locally as SVG); the Tesseract
  wrapper for the deferred OCR is only suggested, not installed. Tests: PHPUnit 13 + eris.
- **Architecture:** Ports & Adapters. `Domain` = pure calculation logic,
  `Application` = use cases with port interfaces, `Persistence` = PDO
  adapters, `Presentation` = controllers/views (HTML as PHP strings).
- **Raw data is the single source of truth.** Standings, averages and
  honours are computed from the raw data on every request. Frozen handicaps
  count as raw data.
- **Rules:** [domain-logic.md](domain-logic.md) is the authoritative
  description of the domain rules; `(R12.9)` in the code means rule 9 of
  section R12 there. The correctness properties of the property tests are in
  [testing.md](testing.md). (The original German specification was dissolved
  into these files on 2026-10-25; it remains in the Git history.)
- **Local databases:** the development database from `config/database.php`
  may hold real league data – do not change it unnecessarily.
  `bowlingliga_test` is rebuilt by the integration tests for every test.
- **Language:** Code, identifiers, comments and this documentation are
  English; the user interface is German and English (catalogs in `lang/`,
  see [CONTRIBUTING.md](../CONTRIBUTING.md#adding-a-language)). Domain terms are translated
  according to the [glossary](glossary.md) (e.g. `ResultRecording`,
  `areAllComplete`).
