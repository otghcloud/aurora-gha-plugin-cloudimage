<img src="https://otgh-static-assets.s3.otgh.cloud/branding/logos/otgh_cloud_2024.png" alt="OTGH Cloud" width="200px" />

# Contributing

Thank you for your interest in contributing to this project.

This document provides some general guidance before opening your first issue or pull request.

---

## Table of Contents

- [Branching](#branching)
- [Code Style](#code-style)
- [Commit Messages](#commit-messages)
- [Issues](#issues)
- [Pull Request Titles](#pull-request-titles)

---

## Branching

Work on a feature branch and open a PR targeting `main`.

## Code Style

- **Markdown**: Validated with `markdownlint-cli2` (config at `.github/rules/.markdownlint.jsonc`).

## Commit Messages

Individual commit messages within a PR follow the same prefix convention and format as PR titles.

## Issues

You can submit issues or enhancement requests [by visiting our issues page](https://github.com/otghcloud/aurora-gha-manager-pro/issues).

## Pull Request Titles

Every pull request title **must** follow this format:

```text
<prefix>(<optional-scope>): <Description starting with a capital letter>
```

- The scope is optional and free-form.
- A colon and a single space separate the prefix from the description.
- The description starts with a **capital letter**.
- The prefix and scope are **always lowercase**.
- **No trailing period.**

### Allowed Prefixes

| Prefix | When to use |
| --- | --- |
| `build:` | Build system, dependencies, or packaging |
| `chore:` | Maintenance tasks that do not fit any other category |
| `ci:` | Changes limited to GitHub Actions workflows or CI scripts |
| `docs:` | Documentation-only changes |
| `feat:` | A new user-facing feature or capability |
| `fix:` | Something was broken and is now corrected |
| `improve:` | An enhancement to existing behavior that is neither a bug fix nor a new feature |
| `refactor:` | Internal restructuring with no behavior change |
| `style:` | Cosmetic or formatting changes with no logic impact |
| `test:` | Test additions or corrections only |

### Examples

```text
docs: Update initial documentation
```

## Why This Matters

Following the conventions above helps keep our codebase tidy and readable.

Our release workflow depends on structured commit/pull request titles for accurately producing version numbers and release notes.

[core]: https://github.com/otghcloud/aurora-gha-manager
