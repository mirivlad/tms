#!/usr/bin/env python3
from __future__ import annotations

import argparse
import os
from pathlib import Path
from urllib.parse import urlencode

from playwright.sync_api import Page, sync_playwright

DEFAULT_BASE_URL = os.environ.get("HANDBOOK_BASE_URL", "http://127.0.0.1:18082")
DEFAULT_CHROME = os.environ.get("HANDBOOK_CHROME", "/usr/bin/google-chrome")
DEFAULT_PASSWORD = os.environ.get("HANDBOOK_DEMO_PASSWORD", "documentation12")

PROFILES = {
    "ru": {
        "username": "docsru",
        "project_id": 1,
        "team_id": 1,
        "handbook_task_id": 2,
        "weekly_task_id": 6,
    },
    "en": {
        "username": "docsen",
        "project_id": 2,
        "team_id": 2,
        "handbook_task_id": 9,
        "weekly_task_id": 13,
    },
}

def stabilize(page: Page) -> None:
    page.add_style_tag(content="""
        *, *::before, *::after {
            animation: none !important;
            transition: none !important;
            caret-color: transparent !important;
        }
        html { scroll-behavior: auto !important; }
    """)
    page.locator('input[type="month"]').evaluate_all(
        """elements => elements.forEach((element) => {
            const value = element.value;
            element.type = 'text';
            element.value = value;
        })"""
    )
    page.wait_for_timeout(250)


def set_locale(page: Page, locale: str) -> None:
    current = page.locator("html").get_attribute("lang")
    if current == locale:
        return
    selector = f'button[name="locale"][value="{locale}"]'
    button = page.locator(selector).first
    button.click()
    page.wait_for_load_state("networkidle")
    if page.locator("html").get_attribute("lang") != locale:
        raise RuntimeError(f"Failed to switch interface locale to {locale}")


def login(page: Page, base_url: str, username: str, password: str, locale: str) -> None:
    page.goto(f"{base_url}/login", wait_until="networkidle")
    set_locale(page, locale)
    page.locator('input[name="username"]').fill(username)
    page.locator('input[name="password"]').fill(password)
    page.locator('form[action="/login"] button[type="submit"]').click()
    page.wait_for_load_state("networkidle")
    if "/dashboard" not in page.url:
        raise RuntimeError(f"Login failed for {username}: {page.url}")

def capture_page(page: Page, base_url: str, route: str, output: Path) -> None:
    page.goto(f"{base_url}{route}", wait_until="networkidle")
    stabilize(page)
    output.parent.mkdir(parents=True, exist_ok=True)
    page.screenshot(path=str(output), full_page=False)


def capture_locator(page: Page, base_url: str, route: str, selector: str, output: Path) -> None:
    page.goto(f"{base_url}{route}", wait_until="networkidle")
    stabilize(page)
    locator = page.locator(selector)
    locator.scroll_into_view_if_needed()
    page.wait_for_timeout(150)
    output.parent.mkdir(parents=True, exist_ok=True)
    locator.screenshot(path=str(output))


def capture_profile(page: Page, base_url: str, locale: str, profile: dict[str, int | str], out: Path) -> None:
    project_id = int(profile["project_id"])
    team_id = int(profile["team_id"])
    task_id = int(profile["handbook_task_id"])
    weekly_id = int(profile["weekly_task_id"])
    query = urlencode({"project": project_id, "sort": "scheduled_at", "order": "asc", "per_page": 25})

    capture_page(page, base_url, "/dashboard", out / "dashboard.png")
    capture_page(page, base_url, f"/tasks?{query}", out / "tasks.png")
    capture_page(page, base_url, f"/board?project={project_id}", out / "board.png")
    capture_page(page, base_url, f"/calendar?project={project_id}&month=2026-09", out / "calendar.png")
    capture_page(page, base_url, f"/tasks/new?project_id={project_id}", out / "task-new.png")
    capture_page(page, base_url, f"/tasks/{task_id}/edit", out / "task-edit.png")

    capture_locator(
        page,
        base_url,
        f"/tasks/{task_id}/edit",
        "#checklist",
        out / "checklist.png",
    )
    capture_locator(
        page,
        base_url,
        f"/tasks/{weekly_id}/edit",
        "#recurrence",
        out / "recurrence.png",
    )
    capture_page(page, base_url, f"/projects/{project_id}", out / "project.png")
    capture_page(page, base_url, f"/projects/{project_id}/discussion", out / "project-discussion.png")
    capture_page(page, base_url, f"/teams/{team_id}/members", out / "team-members.png")
    capture_page(page, base_url, "/notifications", out / "notifications.png")
    capture_page(page, base_url, "/settings/notifications", out / "notification-settings.png")
    capture_page(page, base_url, "/settings/profile", out / "profile.png")

    if page.locator("html").get_attribute("lang") != locale:
        raise RuntimeError(f"Locale drifted while capturing {locale}")


def main() -> int:
    parser = argparse.ArgumentParser(description="Capture localized TMS screenshots for the handbooks.")
    parser.add_argument("--base-url", default=DEFAULT_BASE_URL)
    parser.add_argument("--output-dir", type=Path, default=Path("docs/handbooks/assets/screenshots"))
    parser.add_argument("--chrome", default=DEFAULT_CHROME)
    parser.add_argument("--password", default=DEFAULT_PASSWORD)
    args = parser.parse_args()

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(
            executable_path=args.chrome,
            headless=True,
            args=["--no-sandbox", "--disable-dev-shm-usage"],
        )

        try:
            for locale, profile in PROFILES.items():
                context = browser.new_context(
                    viewport={"width": 1440, "height": 1000},
                    device_scale_factor=1,
                    locale="ru-RU" if locale == "ru" else "en-US",
                    timezone_id="Europe/Moscow",
                )
                page = context.new_page()
                login(
                    page,
                    args.base_url.rstrip("/"),
                    str(profile["username"]),
                    args.password,
                    locale,
                )
                capture_profile(
                    page,
                    args.base_url.rstrip("/"),
                    locale,
                    profile,
                    args.output_dir / locale,
                )
                context.close()
        finally:
            browser.close()

    print(f"Screenshots written to {args.output_dir}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())