# Documentation maintenance policy

The user and administrator handbooks are part of the TMS product contract.

Canonical sources:

- `docs/USER_GUIDE.md`
- `docs/USER_GUIDE.ru.md`
- `docs/ADMINISTRATION.md`
- `docs/ADMINISTRATION.ru.md`

Generated release artifacts:

- `user-handbook-en.html`
- `user-handbook-en.pdf`
- `user-handbook-ru.html`
- `user-handbook-ru.pdf`
- `admin-handbook-en.html`
- `admin-handbook-en.pdf`
- `admin-handbook-ru.html`
- `admin-handbook-ru.pdf`

## Rule

A change that modifies user-visible behavior, administrator behavior, deployment/runtime configuration or operational procedure is incomplete until the affected handbook sources are updated in the same pull request.

The CI documentation-impact check enforces this for known user/admin paths. The PR checklist requires reviewers to consider documentation impact even when a path rule does not catch a change.

## Building locally

Install the pinned renderer dependencies:

```bash
python3 -m pip install -r docs/handbook-requirements.txt
```

Build all artifacts:

```bash
python3 scripts/build-handbooks.py --output build/handbooks
```

Generated files are build artifacts rather than canonical sources. Do not hand-edit generated HTML or PDF.

## Release behavior

Every stable/preview release rebuilds the handbooks from the exact release commit and attaches the generated HTML/PDF files to the GitHub Release.

If handbook generation fails, the release must fail.
