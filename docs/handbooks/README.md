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

## Maintenance rule

Behavior and configuration changes are incomplete until the relevant handbook source or one of its included canonical topic guides is updated. Pull requests must declare documentation impact, CI rebuilds the handbooks, and version releases attach the generated HTML/PDF files.
