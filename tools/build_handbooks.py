#!/usr/bin/env python3
from __future__ import annotations

import argparse
import base64
import hashlib
import html
import json
import mimetypes
import os
import re
import shutil
import subprocess
import sys
from html.parser import HTMLParser
from pathlib import Path
from typing import Iterable

try:
    import markdown
    import pyphen
except ImportError as exc:
    raise SystemExit(
        "Handbook build dependencies are missing. Install them with: "
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
LEADING_H1_RE = re.compile(r"^#\s+[^\n]+\n+")
IMG_SRC_RE = re.compile(r'(<img\b[^>]*\bsrc=")([^"]+)(")', re.IGNORECASE)


REFERENCE_FIGURES: dict[str, tuple[dict[str, str], ...]] = {
    "user-ru": (
        {"heading": "Основные представления", "image": "assets/screenshots/ru/board.png", "caption": "Доска показывает тот же набор задач как Kanban по статусам."},
        {"heading": "Создание и редактирование задач", "image": "assets/screenshots/ru/task-edit.png", "caption": "Полная форма редактирования задачи: проект, статус, ответственный, приоритет и даты."},
        {"heading": "Плановое время и срок", "image": "assets/screenshots/ru/calendar.png", "caption": "Календарь сводит плановое время и сроки в один временной контекст."},
        {"heading": "Чек-листы", "image": "assets/screenshots/ru/checklist.png", "caption": "Чек-лист остаётся частью задачи и показывает прогресс по коротким шагам."},
        {"heading": "Сохранённые представления задач", "image": "assets/screenshots/ru/tasks.png", "caption": "Фильтры и Saved Views находятся прямо над плотным списком задач."},
        {"heading": "Повторяющиеся задачи", "image": "assets/screenshots/ru/recurrence.png", "caption": "Настройка повторения хранится рядом с обычными параметрами задачи."},
        {"heading": "Проекты", "image": "assets/screenshots/ru/project.png", "caption": "Обзор проекта объединяет состояние задач, workflow, файлы и обсуждения."},
        {"heading": "Команды", "image": "assets/screenshots/ru/team-members.png", "caption": "Состав команды, роли и ожидающие приглашения управляются в одном месте."},
        {"heading": "Обсуждения", "image": "assets/screenshots/ru/project-discussion.png", "caption": "Обсуждение проекта хранит рабочий контекст рядом с самим проектом."},
        {"heading": "Уведомления", "image": "assets/screenshots/ru/notification-settings.png", "caption": "Настройки определяют, какие рабочие события и напоминания действительно нужны."},
    ),
    "user-en": (
        {"heading": "Main views", "image": "assets/screenshots/en/board.png", "caption": "Board presents the same work as a status-based Kanban."},
        {"heading": "Creating and editing tasks", "image": "assets/screenshots/en/task-edit.png", "caption": "The full task editor keeps project, status, assignee, priority and dates together."},
        {"heading": "Planned time and deadline", "image": "assets/screenshots/en/calendar.png", "caption": "Calendar brings planned work and deadlines into one time context."},
        {"heading": "Checklists", "image": "assets/screenshots/en/checklist.png", "caption": "A checklist stays inside the task and tracks short execution steps."},
        {"heading": "Saved task views", "image": "assets/screenshots/en/tasks.png", "caption": "Filters and Saved Views sit directly above the dense task list."},
        {"heading": "Recurring tasks", "image": "assets/screenshots/en/recurrence.png", "caption": "Recurrence settings live alongside the task's ordinary fields."},
        {"heading": "Projects", "image": "assets/screenshots/en/project.png", "caption": "Project overview combines task state, workflow, files and discussions."},
        {"heading": "Teams", "image": "assets/screenshots/en/team-members.png", "caption": "Team membership, roles and pending invitations are managed together."},
        {"heading": "Discussions", "image": "assets/screenshots/en/project-discussion.png", "caption": "Project discussion keeps work context next to the project itself."},
        {"heading": "Notifications", "image": "assets/screenshots/en/notification-settings.png", "caption": "Notification settings decide which work events and reminders are useful."},
    ),
}


def parse_include(spec: str) -> tuple[str, int, bool, bool]:
    parts = [part.strip() for part in spec.split("|")]
    path = parts[0]
    shift = 0
    strip_nav = False
    strip_title = False
    for option in parts[1:]:
        if option.startswith("shift="):
            shift = int(option.split("=", 1)[1])
        elif option == "strip_nav":
            strip_nav = True
        elif option == "strip_title":
            strip_title = True
        elif option:
            raise ValueError(f"Unknown include option: {option}")
    return path, shift, strip_nav, strip_title


def transform_markdown(text: str, shift: int, strip_nav: bool, strip_title: bool) -> str:
    lines = text.splitlines()
    if strip_nav:
        while lines and not lines[0].strip():
            lines.pop(0)
        if lines and lines[0].lstrip().startswith("[English]"):
            lines.pop(0)
            while lines and not lines[0].strip():
                lines.pop(0)

    if strip_title:
        while lines and not lines[0].strip():
            lines.pop(0)
        if lines and re.match(r"^#\s+", lines[0]):
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

        rel_path, shift, strip_nav, strip_title = parse_include(match.group(1))
        include_path = (path.parent / rel_path).resolve()
        included, nested = expand_source(include_path, (*stack, path))
        dependencies.extend(nested)
        output.append(transform_markdown(included, shift, strip_nav, strip_title))

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


HYPHENATABLE_WORD_RE = re.compile(r"[A-Za-zА-Яа-яЁё]{6,}")
HYPHENATION_LANGS = {
    "ru": "ru_RU",
    "en": "en_US",
}


class ProseHyphenator(HTMLParser):
    """Insert soft hyphen opportunities into prose without touching code or links."""

    PROSE_TAGS = {"p", "li"}
    EXCLUDED_TAGS = {"a", "code", "kbd", "pre", "samp", "script", "style"}

    def __init__(self, dictionary: pyphen.Pyphen) -> None:
        super().__init__(convert_charrefs=False)
        self.dictionary = dictionary
        self.output: list[str] = []
        self.prose_depth = 0
        self.excluded_depth = 0

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        self.output.append(self.get_starttag_text() or f"<{tag}>")
        if tag in self.PROSE_TAGS:
            self.prose_depth += 1
        if tag in self.EXCLUDED_TAGS:
            self.excluded_depth += 1

    def handle_startendtag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        self.output.append(self.get_starttag_text() or f"<{tag}>")

    def handle_endtag(self, tag: str) -> None:
        self.output.append(f"</{tag}>")
        if tag in self.EXCLUDED_TAGS:
            self.excluded_depth = max(0, self.excluded_depth - 1)
        if tag in self.PROSE_TAGS:
            self.prose_depth = max(0, self.prose_depth - 1)

    def handle_data(self, data: str) -> None:
        if self.prose_depth and not self.excluded_depth:
            data = HYPHENATABLE_WORD_RE.sub(
                lambda match: self.dictionary.inserted(match.group(0), hyphen="\u00ad"),
                data,
            )
        self.output.append(data)

    def handle_entityref(self, name: str) -> None:
        self.output.append(f"&{name};")

    def handle_charref(self, name: str) -> None:
        self.output.append(f"&#{name};")

    def handle_comment(self, data: str) -> None:
        self.output.append(f"<!--{data}-->")

    def handle_decl(self, decl: str) -> None:
        self.output.append(f"<!{decl}>")

    def handle_pi(self, data: str) -> None:
        self.output.append(f"<?{data}>")


def hyphenate_prose(body: str, lang: str) -> str:
    dictionary_name = HYPHENATION_LANGS.get(lang)
    if dictionary_name is None:
        return body
    parser = ProseHyphenator(pyphen.Pyphen(lang=dictionary_name))
    parser.feed(body)
    parser.close()
    return "".join(parser.output)


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


def inline_local_images(body: str, source_dir: Path) -> tuple[str, list[Path]]:
    dependencies: list[Path] = []

    def replace(match: re.Match[str]) -> str:
        prefix, src, suffix = match.groups()
        if src.startswith(("data:", "http://", "https://", "/", "#")):
            return match.group(0)

        image_path = (source_dir / src).resolve()
        try:
            image_path.relative_to(ROOT)
        except ValueError as exc:
            raise RuntimeError(f"Handbook image escapes repository root: {src}") from exc
        if not image_path.is_file():
            raise FileNotFoundError(f"Handbook image not found: {image_path}")

        mime, _ = mimetypes.guess_type(image_path.name)
        if mime is None or not mime.startswith("image/"):
            raise RuntimeError(f"Unsupported handbook image type: {image_path}")

        dependencies.append(image_path)
        encoded = base64.b64encode(image_path.read_bytes()).decode("ascii")
        return f"{prefix}data:{mime};base64,{encoded}{suffix}"

    return IMG_SRC_RE.sub(replace, body), dependencies



def inject_reference_figures(body: str, handbook_id: str) -> str:
    """Place handbook screenshots at the end of the canonical section they explain."""
    entries = REFERENCE_FIGURES.get(handbook_id, ())
    for entry in entries:
        heading = entry["heading"]
        heading_re = re.compile(
            r'<h3\b[^>]*>\s*' + re.escape(heading) + r'\s*</h3>',
            re.IGNORECASE,
        )
        match = heading_re.search(body)
        if match is None:
            raise RuntimeError(
                f"Reference figure target heading not found for {handbook_id}: {heading}"
            )

        section_tail = body[match.end():]
        next_heading = re.search(r'\n<h[23]\b', section_tail, re.IGNORECASE)
        section_end = next_heading.start() if next_heading else len(section_tail)
        section_body = section_tail[:section_end]
        block_ends = [
            found.end()
            for pattern in (r"</p>", r"</ul>", r"</ol>")
            if (found := re.search(pattern, section_body, re.IGNORECASE))
        ]
        relative_insert = min(block_ends) if block_ends else 0
        insert_at = match.end() + relative_insert
        caption = html.escape(entry["caption"])
        src = html.escape(entry["image"], quote=True)
        figure = (
            '\n<figure class="reference-figure">\n'
            f'  <img src="{src}" alt="{caption}">\n'
            f'  <figcaption>{caption}</figcaption>\n'
            '</figure>\n'
        )
        body = body[:insert_at] + figure + body[insert_at:]
    return body


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
    source_dir: Path,
) -> tuple[str, list[Path]]:
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
    body = inject_reference_figures(body, str(handbook["id"]))
    body = hyphenate_prose(body, str(handbook["lang"]))
    body = rewrite_doc_links(body, revision)
    body, image_dependencies = inline_local_images(body, source_dir)
    cover = render_cover(handbook, version, revision)
    title = html.escape(str(handbook["title"]))
    lang = html.escape(str(handbook["lang"]))
    document = f"""<!doctype html>
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
    return document, image_dependencies


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

        html_document, image_dependencies = render_html(
            expanded,
            handbook=handbook,
            css=css,
            revision=revision,
            version=version,
            source_dir=source.parent,
        )
        html_path.write_text(html_document, encoding="utf-8")

        generated = [html_path.name]
        if not args.html_only:
            assert browser is not None
            render_pdf(browser, html_path, pdf_path)
            generated.append(pdf_path.name)

        source_digest = hashlib.sha256(expanded.encode("utf-8"))
        for image_path in unique_paths(image_dependencies):
            source_digest.update(image_path.read_bytes())
        source_hash = source_digest.hexdigest()
        all_dependencies = unique_paths([*dependencies, *image_dependencies])
        manifest["handbooks"].append(
            {
                "id": handbook["id"],
                "language": handbook["lang"],
                "source": str(source.relative_to(ROOT)),
                "dependencies": [
                    str(dep.relative_to(ROOT)) for dep in all_dependencies
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