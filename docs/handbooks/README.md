# TMS handbooks

This directory contains the handbook composition sources and the styling used to publish the TMS documentation as standalone HTML and PDF artifacts.

The existing topic guides in `docs/` stay canonical. Handbook sources use `{{include:...}}` directives so installation, notification, webhook and feature documentation is reused instead of copied. The build script expands those includes before rendering.

## Build

```bash
python3 -m pip install -r docs/handbooks/requirements.txt
python3 tools/build_handbooks.py
```

The default output directory is `build/handbooks/` and contains:

- `tms-user-handbook-ru.html` / `.pdf`;
- `tms-user-handbook-en.html` / `.pdf`;
- `tms-admin-handbook-ru.html` / `.pdf`;
- `tms-admin-handbook-en.html` / `.pdf`;
- `manifest.json` with source dependencies and SHA-256 hashes.

Use `python3 tools/build_handbooks.py --html-only` when a PDF-capable Chrome/Chromium executable is not available.

## Include syntax

A handbook source can embed another Markdown file:

```text
{{include:../USER_GUIDE.ru.md|shift=1|strip_nav}}
```

`shift=N` demotes Markdown headings by `N` levels so an included topic becomes a subsection of the handbook. `strip_nav` removes the bilingual navigation line used by standalone topic guides.

## Screenshot workflow

The User handbook uses a deterministic demo installation instead of production data. The demo runs as the isolated Compose project `tms-handbook` on port `18082`; preparing it destroys only that project's containers and volumes.

```bash
tools/prepare_handbook_demo.sh
python3 -m pip install -r docs/handbooks/screenshots-requirements.txt
python3 tools/capture_handbook_screenshots.py
```

The seed data lives in `docs/handbooks/demo/seed.sql`. It creates matched RU/EN projects, tasks, teams, discussions, reminders, checklists and recurrence examples. Screenshot assets are written to `docs/handbooks/assets/screenshots/{ru,en}/`.

Use `HANDBOOK_DEMO_PASSWORD`, `HANDBOOK_BASE_URL`, `HANDBOOK_PORT` and `HANDBOOK_CHROME` when the defaults do not fit the local environment. The screenshots must remain paired: the Russian handbook shows the Russian UI and the English handbook shows the English UI for the same scenario wherever practical.

Handbook images are embedded as data URIs during the HTML build. This keeps released HTML files standalone while the original PNG files remain explicit handbook dependencies in `manifest.json`.

## Maintenance rule

Behavior and configuration changes are incomplete until the relevant handbook source or one of its included canonical topic guides is updated. Pull requests must declare documentation impact, CI rebuilds the handbooks, and version releases attach the generated HTML/PDF files.

When a UI change materially affects a handbook screenshot, regenerate the localized screenshot pair and inspect both the standalone HTML and A4 PDF output before release.