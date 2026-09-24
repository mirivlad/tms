#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import html
import json
import os
import re
import shutil
import subprocess
import sys
from pathlib import Path
from typing import Iterable

try:
    import markdown
except ImportError as exc:
    raise SystemExit(
        "Python-Markdown is required. Install it with: "
        "python3 -m pip install -r docs/handbooks/requirements.txt"
    ) from exc

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_OUTPUT = ROOT / "build" / "handbooks"
CSS_PATH = ROOT / "docs" / "handbooks" / "handbook.css"
VERSION_PATH = ROOT / "VERSION"

HANDBOOKS = (
    {
        "id": "user-ru",
        "source": ROOT / "docs" / "handbooks" / "user.ru.md",
        "lang": "ru",
        "title": "TMS — Руководство пользователя",
        "cover_kicker": "Руководство пользователя",
        "cover_title": "Работа в TMS",
        "cover_subtitle": (
            "Практическое руководство по задачам, проектам, командам, "
            "календарю, обсуждениям и уведомлениям."
        ),
        "edition": "Русское издание",
        "toc_title": "Содержание",
        "filename": "tms-user-handbook-ru",
    },
    {
        "id": "user-en",
        "source": ROOT / "docs" / "handbooks" / "user.en.md",
        "lang": "en",
        "title": "TMS — User handbook",
        "cover_kicker": "User handbook",
        "cover_title": "Working with TMS",
        "cover_subtitle": (
            "A practical guide to tasks, projects, teams, calendar planning, "
            "discussions and notifications."
        ),
        "edition": "English edition",
        "toc_title": "Contents",
        "filename": "tms-user-handbook-en",
    },
    {
        "id": "admin-ru",
        "source": ROOT / "docs" / "handbooks" / "admin.ru.md",
        "lang": "ru",
        "title": "TMS — Руководство администратора",
        "cover_kicker": "Руководство администратора",
        "cover_title": "Эксплуатация TMS",
        "cover_subtitle": (
            "Установка, обновление, безопасность, резервное копирование, "
            "уведомления, интеграции и диагностика."
        ),
        "edition": "Русское издание",
        "toc_title": "Содержание",
        "filename": "tms-admin-handbook-ru",
    },
    {
        "id": "admin-en",
        "source": ROOT / "docs" / "handbooks" / "admin.en.md",
        "lang": "en",
        "title": "TMS — Administrator handbook",
        "cover_kicker": "Administrator handbook",
        "cover_title": "Operating TMS",
        "cover_subtitle": (
            "Installation, upgrades, security, backups, notifications, "
            "integrations and troubleshooting."
        ),
        "edition": "English edition",
        "toc_title": "Contents",
        "filename": "tms-admin-handbook-en",
    },
)

INCLUDE_RE = re.compile(r"^\{\{include:(.+)\}\}\s*$")
HEADING_RE = re.compile(r"^(#{1,6})(\s+.+)$")


def parse_include(spec: str) -> tuple[str, int, bool]:
    parts = [part.strip() for part in spec.split("|")]
    path = parts[0]
    shift = 0
    strip_nav = False
    for option in parts[1:]:
        if option.startswith("shift="):
            shift = int(option.split("=", 1)[1])
        elif option == "strip_nav":
            strip_nav = True
        elif option:
            raise ValueError(f"Unknown include option: {option}")
    return path, shift, strip_nav


def transform_markdown(text: str, shift: int, strip_nav: bool) -> str:
    lines = text.splitlines()
    if strip_nav:
        while lines and not lines[0].strip():
            lines.pop(0)
        if lines and lines[0].lstrip().startswith("[English]"):
            lines.pop(0)
            while lines and not lines[0].strip():
                lines.pop(0)

    if shift <= 0:
        return "\n".join(lines)

    output: list[str] = []
    in_fence = False
    for line in lines:
        stripped = line.lstrip()
        if stripped.startswith("```") or stripped.startswith("~~~"):
            in_fence = not in_fence
            output.append(line)
            continue
        if not in_fence:
            match = HEADING_RE.match(line)
            if match:
                hashes, rest = match.groups()
                line = "#" * min(6, len(hashes) + shift) + rest
        output.append(line)
    return "\n".join(output)


def expand_source(path: Path, stack: tuple[Path, ...] = ()) -> tuple[str, list[Path]]:
    path = path.resolve()
    if path in stack:
        chain = " -> ".join(str(item.relative_to(ROOT)) for item in (*stack, path))
        raise RuntimeError(f"Handbook include cycle: {chain}")
    if not path.is_file():
        raise FileNotFoundError(path)

    dependencies = [path]
    output: list[str] = []
    for line in path.read_text(encoding="utf-8").splitlines():
        match = INCLUDE_RE.match(line)
        if not match:
            output.append(line)
            continue

        rel_path, shift, strip_nav = parse_include(match.group(1))
        include_path = (path.parent / rel_path).resolve()
        included, nested = expand_source(include_path, (*stack, path))
        dependencies.extend(nested)
        output.append(transform_markdown(included, shift, strip_nav))

    return "\n".join(output).rstrip() + "\n", dependencies


def browser_binary() -> str | None:
    override = os.environ.get("HANDBOOK_CHROME")
    if override:
        return override
    for name in ("google-chrome", "google-chrome-stable", "chromium", "chromium-browser"):
        found = shutil.which(name)
        if found:
            return found
    return None


def git_revision() -> str:
    result = subprocess.run(
        ["git", "rev-parse", "HEAD"],
        cwd=ROOT,
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode == 0 and result.stdout.strip():
        return result.stdout.strip()
    return "main"


def handbook_version() -> str:
    if VERSION_PATH.is_file():
        value = VERSION_PATH.read_text(encoding="utf-8").strip()
        if value:
            return value
    return "development"


def rewrite_doc_links(body: str, revision: str) -> str:
    repository = os.environ.get(
        "HANDBOOK_REPOSITORY_URL",
        "https://github.com/mirivlad/tms",
    ).rstrip("/")
    pattern = re.compile(r'href="(?![a-z]+:|#|/)([^"#?]+\.md(?:[?#][^"]*)?)"', re.IGNORECASE)

    def replace(match: re.Match[str]) -> str:
        target = match.group(1)
        return f'href="{repository}/blob/{revision}/docs/{target}"'

    return pattern.sub(replace, body)


def without_source_title(markdown_text: str) -> str:
    return LEADING_H1_RE.sub("", markdown_text, count=1).lstrip()


def render_cover(handbook: dict[str, object], version: str, revision: str) -> str:
    def esc(value: object) -> str:
        return html.escape(str(value))

    short_revision = revision[:10] if revision not in {"main", "development"} else revision
    return f"""
  <section class="cover">
    <div class="cover-top">
      <div class="cover-brand">TMS Documentation</div>
    </div>
    <div class="cover-main">
      <div class="cover-kicker">{esc(handbook["cover_kicker"])}</div>
      <h1 class="cover-title">{esc(handbook["cover_title"])}</h1>
      <p class="cover-subtitle">{esc(handbook["cover_subtitle"])}</p>
      <div class="cover-meta">
        <span>TMS v{esc(version)}</span>
        <span>{esc(handbook["edition"])}</span>
      </div>
    </div>
    <div class="cover-bottom">
      <div class="cover-edition">{esc(handbook["title"])}</div>
      <div class="cover-mark">rev {html.escape(short_revision)}</div>
    </div>
  </section>
"""


def render_html(
    markdown_text: str,
    handbook: dict[str, object],
    css: str,
    revision: str,
    version: str,
) -> str:
    body = markdown.markdown(
        without_source_title(markdown_text),
        extensions=["fenced_code", "tables", "toc", "sane_lists"],
        extension_configs={
            "toc": {
                "permalink": False,
                "toc_depth": "2-4",
                "title": str(handbook["toc_title"]),
            }
        },
        output_format="html5",
    )
    body = rewrite_doc_links(body, revision)
    cover = render_cover(handbook, version, revision)
    title = html.escape(str(handbook["title"]))
    lang = html.escape(str(handbook["lang"]))
    return f"""<!doctype html>
<html lang="{lang}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="generator" content="TMS handbook builder">
  <title>{title}</title>
  <style>
{css}
  </style>
</head>
<body>
  <main class="handbook">
{cover}
    <article class="handbook-content">
{body}
    </article>
    <footer class="document-colophon">
      <strong>{title}</strong><br>
      TMS v{html.escape(version)} · source revision {html.escape(revision)}
    </footer>
  </main>
</body>
</html>
"""


def render_pdf(browser: str, html_path: Path, pdf_path: Path) -> None:
    common = [
        browser,
        "--disable-gpu",
        "--disable-dev-shm-usage",
        "--no-sandbox",
        "--no-pdf-header-footer",
        f"--print-to-pdf={pdf_path}",
        html_path.resolve().as_uri(),
    ]
    attempts = (
        [common[0], "--headless=new", *common[1:]],
        [common[0], "--headless", *common[1:]],
    )
    last_error = ""
    for command in attempts:
        result = subprocess.run(command, text=True, capture_output=True)
        if result.returncode == 0 and pdf_path.is_file():
            break
        last_error = (result.stderr or result.stdout or "").strip()
    else:
        raise RuntimeError(f"Chrome PDF generation failed: {last_error}")

    payload = pdf_path.read_bytes()
    if not payload.startswith(b"%PDF-") or len(payload) < 4096:
        raise RuntimeError(f"Invalid PDF generated: {pdf_path}")


def unique_paths(items: Iterable[Path]) -> list[Path]:
    seen: set[Path] = set()
    output: list[Path] = []
    for item in items:
        item = item.resolve()
        if item not in seen:
            seen.add(item)
            output.append(item)
    return output


def main() -> int:
    parser = argparse.ArgumentParser(description="Build TMS user/admin handbooks as HTML and PDF.")
    parser.add_argument("--output-dir", type=Path, default=DEFAULT_OUTPUT)
    parser.add_argument("--html-only", action="store_true", help="Build HTML only; skip PDF generation.")
    args = parser.parse_args()

    output_dir = args.output_dir.resolve()
    output_dir.mkdir(parents=True, exist_ok=True)
    css = CSS_PATH.read_text(encoding="utf-8")
    browser = None if args.html_only else browser_binary()
    if not args.html_only and browser is None:
        raise SystemExit(
            "No supported Chrome/Chromium executable found. "
            "Set HANDBOOK_CHROME or use --html-only."
        )

    revision = git_revision()
    version = handbook_version()

    manifest: dict[str, object] = {
        "schema": 1,
        "revision": revision,
        "version": version,
        "generated_files": [],
        "handbooks": [],
    }

    for handbook in HANDBOOKS:
        source = handbook["source"]
        assert isinstance(source, Path)
        expanded, dependencies = expand_source(source)
        filename = str(handbook["filename"])
        html_path = output_dir / f"{filename}.html"
        pdf_path = output_dir / f"{filename}.pdf"

        html_document = render_html(
            expanded,
            handbook=handbook,
            css=css,
            revision=revision,
            version=version,
        )
        html_path.write_text(html_document, encoding="utf-8")

        generated = [html_path.name]
        if not args.html_only:
            assert browser is not None
            render_pdf(browser, html_path, pdf_path)
            generated.append(pdf_path.name)

        source_hash = hashlib.sha256(expanded.encode("utf-8")).hexdigest()
        manifest["handbooks"].append(
            {
                "id": handbook["id"],
                "language": handbook["lang"],
                "source": str(source.relative_to(ROOT)),
                "dependencies": [
                    str(dep.relative_to(ROOT)) for dep in unique_paths(dependencies)
                ],
                "source_sha256": source_hash,
                "generated": generated,
            }
        )
        manifest["generated_files"].extend(generated)
        print(f"built {handbook['id']}: {', '.join(generated)}")

    (output_dir / "manifest.json").write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
