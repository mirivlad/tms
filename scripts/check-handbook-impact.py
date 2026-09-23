#!/usr/bin/env python3
from __future__ import annotations

import subprocess
import sys
from pathlib import Path

USER_DOCS = {"docs/USER_GUIDE.md", "docs/USER_GUIDE.ru.md"}
ADMIN_DOCS = {"docs/ADMINISTRATION.md", "docs/ADMINISTRATION.ru.md"}

USER_PREFIXES = (
    "src/Http/Controller/",
    "src/Domain/Task/",
    "src/Domain/Project/",
    "src/Domain/Team/",
    "src/Domain/Discussion/",
    "src/Domain/Checklist/",
    "src/Domain/Recurrence/",
    "src/Domain/SavedView/",
    "src/Domain/Notification/",
    "src/Domain/Status/",
    "src/Domain/TaskType/",
    "src/Domain/Customer/",
    "src/Domain/CustomField/",
    "src/Application/RecurringTaskRunner.php",
    "src/Application/TaskEventNotificationConsumer.php",
    "src/Application/DiscussionNotificationService.php",
    "templates/",
    "public/assets/",
    "resources/i18n/",
)

ADMIN_PREFIXES = (
    "src/Http/Controller/Admin",
    "src/Http/Controller/NotificationAdminController.php",
    "src/Http/Controller/WebhookAdminController.php",
    "src/Http/Controller/TelegramWebhookController.php",
    "src/Infrastructure/",
    "bin/",
    "docker/",
    "compose.yaml",
    "compose.portainer.yaml",
    ".env.example",
    "database/migrations/",
    "templates/admin/",
    "templates/notifications/admin.twig",
)

USER_EXCLUDES = (
    "src/Http/Controller/Admin",
    "src/Http/Controller/NotificationAdminController.php",
    "src/Http/Controller/WebhookAdminController.php",
    "src/Http/Controller/TelegramWebhookController.php",
    "templates/admin/",
    "templates/notifications/admin.twig",
    "public/assets/webhooks.css",
)


def changed_files(base: str, head: str) -> set[str]:
    output = subprocess.check_output(
        ["git", "diff", "--name-only", f"{base}...{head}"],
        text=True,
    )
    return {line.strip() for line in output.splitlines() if line.strip()}


def starts_with_any(path: str, prefixes: tuple[str, ...]) -> bool:
    return any(path == prefix or path.startswith(prefix) for prefix in prefixes)


def only_portainer_image_pin_changed(path: str, base: str, head: str) -> bool:
    if path != "compose.portainer.yaml":
        return False
    diff = subprocess.check_output(
        ["git", "diff", "--unified=0", f"{base}...{head}", "--", path],
        text=True,
    )
    changed_lines = [
        line[1:].strip()
        for line in diff.splitlines()
        if line.startswith(("+", "-")) and not line.startswith(("+++", "---"))
    ]
    image_pin_marker = "image: &tms-image " + "$" + "{TMS_IMAGE:-ghcr.io/mirivlad/tms:v"
    return bool(changed_lines) and all(
        image_pin_marker in line
        for line in changed_lines
    )


def main() -> int:
    if len(sys.argv) != 3:
        print("usage: check-handbook-impact.py <base-sha> <head-sha>", file=sys.stderr)
        return 2

    changed = changed_files(sys.argv[1], sys.argv[2])
    user_impact = any(
        starts_with_any(path, USER_PREFIXES) and not starts_with_any(path, USER_EXCLUDES)
        for path in changed
    )
    admin_impact = any(
        starts_with_any(path, ADMIN_PREFIXES)
        and not only_portainer_image_pin_changed(path, sys.argv[1], sys.argv[2])
        for path in changed
    )

    errors: list[str] = []
    if user_impact and not USER_DOCS.issubset(changed):
        missing = sorted(USER_DOCS - changed)
        errors.append(
            "User-facing behavior changed without updating both user handbook sources: "
            + ", ".join(missing)
        )
    if admin_impact and not ADMIN_DOCS.issubset(changed):
        missing = sorted(ADMIN_DOCS - changed)
        errors.append(
            "Administrator/runtime behavior changed without updating both administrator handbook sources: "
            + ", ".join(missing)
        )

    if errors:
        print("Documentation impact check failed:", file=sys.stderr)
        for error in errors:
            print(f"- {error}", file=sys.stderr)
        print(
            "Update the affected EN/RU handbook sources in the same PR. "
            "If the code change is purely internal, narrow this script's path rules in the same reviewed PR.",
            file=sys.stderr,
        )
        return 1

    print(
        f"Documentation impact check passed: {len(changed)} changed files, "
        f"user_impact={user_impact}, admin_impact={admin_impact}."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
