# Documentation maintenance contract

TMS treats user and administrator documentation as part of shipped behavior, not as follow-up work.

## Canonical sources

- End-user behavior: `docs/USER_GUIDE.md`, `docs/USER_GUIDE.ru.md` and focused user-facing topic guides.
- Administration and deployment: `docs/ADMINISTRATION*.md`, `docs/INSTALLATION*.md`, `docs/notifications*.md`, `docs/webhooks*.md`, `docs/ACCOUNT_RECOVERY*.md`.
- Handbook composition and practical runbooks: `docs/handbooks/user.*.md` and `docs/handbooks/admin.*.md`.
- Handbook screenshots: `docs/handbooks/assets/screenshots/{ru,en}/`, generated only from the isolated documentation demo dataset.
- Generated HTML/PDF files are build/release artifacts and are not edited by hand.

The handbook sources include canonical topic guides with `{{include:...}}`, so a correction to a focused guide propagates into the distributable manual.

## Definition of done

A behavior, configuration, deployment, security or operational change is incomplete until its documentation impact is handled in the same pull request.

Every pull request that changes a documented product/runtime surface must contain one line in its description:

```text
Documentation impact: user
Documentation impact: admin
Documentation impact: both
Documentation impact: none - <why no handbook change is needed>
```

`TODO` or a missing declaration fails the documentation-impact check.

When `user`, `admin` or `both` is selected, CI requires the corresponding canonical/handbook source to change in the pull request. `none` is allowed only with an explicit reason and remains a reviewer decision.

## Surfaces considered behavior/configuration

CI treats changes under application code, templates, browser assets, migrations, CLI/workers, i18n catalogs, Compose/Docker deployment files and `.env.example` as documentation-impacting by default.

Pure tests, refactoring outside those surfaces, CI maintenance and documentation-only changes do not force a handbook edit unless their actual behavior changes.

## Build and release

Install the documentation build dependency and generate manuals with:

```bash
python3 -m pip install -r docs/handbooks/requirements.txt
python3 tools/build_handbooks.py
```

The build produces English and Russian User/Admin manuals as standalone HTML and PDF under `build/handbooks/`, plus `manifest.json`.

Pull-request CI rebuilds the manuals and uploads them as a workflow artifact. Version releases rebuild the same sources and attach all HTML/PDF manuals to the GitHub release.

## Review checklist

Reviewers should verify:

1. The declared documentation impact matches the code/configuration change.
2. New or changed user workflows are reflected in the user handbook.
3. New environment variables, workers, deployment steps, secrets, backup requirements or troubleshooting paths are reflected in the administrator handbook.
4. Examples use current UI names and supported commands.
5. Both RU and EN sources remain meaningfully aligned.
6. UI changes that make a handbook screenshot materially stale regenerate the matching RU/EN screenshot pair from the documentation demo environment.
7. Screenshots contain only fictional documentation data and use the same interface language as their handbook.
8. Generated manuals build successfully in CI and screenshot-heavy PDFs remain readable at normal A4 scale.
9. Screenshots are placed next to the instruction or reference text they explain; standalone screenshot-only pages are avoided unless they are an intentional, visibly designed chapter opener.