#!/usr/bin/env python3
from __future__ import annotations

import argparse
import html
import re
from pathlib import Path

import markdown
from weasyprint import HTML

ROOT = Path(__file__).resolve().parents[1]
SPECS = (
    ("docs/USER_GUIDE.md", "user-handbook-en", "en", "TMS User Handbook"),
    ("docs/USER_GUIDE.ru.md", "user-handbook-ru", "ru", "Руководство пользователя TMS"),
    ("docs/ADMINISTRATION.md", "admin-handbook-en", "en", "TMS Administrator Handbook"),
    ("docs/ADMINISTRATION.ru.md", "admin-handbook-ru", "ru", "Руководство администратора TMS"),
)


def render_markdown(source: Path) -> str:
    text = source.read_text(encoding="utf-8")
    rendered = markdown.markdown(
        text,
        extensions=["extra", "toc", "sane_lists"],
        extension_configs={"toc": {"title": "Contents"}},
        output_format="html5",
    )
    base = "https://github.com/mirivlad/tms/blob/main/docs/"
    rendered = re.sub(
        r'href="(?!https?://|#)([^"]+\.md)(#[^"]*)?"',
        lambda m: f'href="{base}{html.escape(m.group(1), quote=True)}{m.group(2) or ""}"',
        rendered,
    )
    return rendered


def build(output: Path) -> None:
    output.mkdir(parents=True, exist_ok=True)
    css = (ROOT / "docs/handbook.css").read_text(encoding="utf-8")
    version = (ROOT / "VERSION").read_text(encoding="utf-8").strip()

    for source_rel, stem, lang, title in SPECS:
        source = ROOT / source_rel
        body = render_markdown(source)
        document = f"""<!doctype html>
<html lang="{lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{html.escape(title)}</title>
<style>{css}</style>
</head>
<body>
<div class="manual-meta"><strong>TMS {html.escape(version)}</strong> · canonical source: <code>{html.escape(source_rel)}</code></div>
{body}
</body>
</html>
"""
        html_path = output / f"{stem}.html"
        pdf_path = output / f"{stem}.pdf"
        html_path.write_text(document, encoding="utf-8")
        HTML(string=document, base_url=str(ROOT)).write_pdf(str(pdf_path))

        if html_path.stat().st_size < 5000:
            raise RuntimeError(f"{html_path} is unexpectedly small")
        if pdf_path.stat().st_size < 10000:
            raise RuntimeError(f"{pdf_path} is unexpectedly small")


def main() -> None:
    parser = argparse.ArgumentParser(description="Build TMS user/admin handbooks as HTML and PDF.")
    parser.add_argument("--output", default="build/handbooks")
    args = parser.parse_args()
    build((ROOT / args.output).resolve())


if __name__ == "__main__":
    main()
