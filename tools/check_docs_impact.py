#!/usr/bin/env python3
from __future__ import annotations

import argparse
import os
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

BEHAVIOR_PREFIXES = (
    "src/",
    "templates/",
    "public/",
    "bin/",
    "database/migrations/",
    "resources/i18n/",
)
BEHAVIOR_FILES = (
    ".env.example",
    "Dockerfile",
    "compose.yaml",
    "compose.portainer.yaml",
)

USER_DOC_PREFIXES = (
    "docs/USER_GUIDE",
    "docs/FAQ",
    "docs/handbooks/user.",
)
ADMIN_DOC_PREFIXES = (
    "docs/ADMINISTRATION",
    "docs/INSTALLATION",
    "docs/notifications",
    "docs/webhooks",
    "docs/ACCOUNT_RECOVERY",
    "docs/handbooks/admin.",
)

DECLARATION_RE = re.compile(
    r"(?im)^\s*Documentation impact:\s*(user|admin|both|none|TODO)\b(?:\s*[-–—:]\s*(.*))?\s*$"
)


def git_changed_files(base: str, head: str) -> list[str]:
    result = subprocess.run(
        ["git", "diff", "--name-only", base, head],
        cwd=ROOT,
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip() or "git diff failed")
    return [line.strip() for line in result.stdout.splitlines() if line.strip()]


def behavior_file(path: str) -> bool:
    return path in BEHAVIOR_FILES or path.startswith(BEHAVIOR_PREFIXES)


def matches_prefix(path: str, prefixes: tuple[str, ...]) -> bool:
    return path.startswith(prefixes)


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Require an explicit documentation-impact decision for product/runtime changes."
    )
    parser.add_argument("--base", default=os.environ.get("DOCS_BASE_SHA", ""))
    parser.add_argument("--head", default=os.environ.get("DOCS_HEAD_SHA", "HEAD"))
    parser.add_argument("--body", default=os.environ.get("DOCS_PR_BODY", ""))
    args = parser.parse_args()

    if not args.base:
        print("docs-impact: no base SHA supplied; nothing to compare", file=sys.stderr)
        return 2

    changed = git_changed_files(args.base, args.head)
    impacted = [path for path in changed if behavior_file(path)]
    if not impacted:
        print("docs-impact: no behavior/configuration surface changed")
        return 0

    match = DECLARATION_RE.search(args.body or "")
    if match is None:
        print(
            "docs-impact: behavior/configuration changed but PR body has no "
            "'Documentation impact:' declaration",
            file=sys.stderr,
        )
        return 1

    decision = match.group(1).lower()
    reason = (match.group(2) or "").strip()
    if decision == "todo":
        print("docs-impact: replace 'Documentation impact: TODO' in the PR body", file=sys.stderr)
        return 1

    user_docs = any(matches_prefix(path, USER_DOC_PREFIXES) for path in changed)
    admin_docs = any(matches_prefix(path, ADMIN_DOC_PREFIXES) for path in changed)

    if decision in {"user", "both"} and not user_docs:
        print(
            "docs-impact: PR declares user documentation impact but changes no "
            "user handbook/canonical user guide",
            file=sys.stderr,
        )
        return 1

    if decision in {"admin", "both"} and not admin_docs:
        print(
            "docs-impact: PR declares administrator documentation impact but changes no "
            "administrator handbook/canonical admin guide",
            file=sys.stderr,
        )
        return 1

    if decision == "none" and len(reason) < 8:
        print(
            "docs-impact: 'none' requires a concrete reason after '-' or ':'",
            file=sys.stderr,
        )
        return 1

    print(f"docs-impact: decision={decision}")
    print("docs-impact: behavior/configuration files:")
    for path in impacted:
        print(f"  - {path}")
    if user_docs:
        print("docs-impact: user documentation changed")
    if admin_docs:
        print("docs-impact: administrator documentation changed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
