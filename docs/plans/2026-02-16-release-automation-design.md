# Release Automation: Pre-tag Hook + GitHub Action

## Date: 2026-02-16

## Problem

CHANGELOG.md was stale since v0.4.0-alpha while the project reached v0.6.1. No mechanism existed to prevent releasing without updating the CHANGELOG, and no automation existed to create GitHub Releases from tags.

## Solution

Two complementary components:

### 1. `bin/tag-release` — Local validation script

- Receives version as argument: `bin/tag-release 0.7.0`
- Validates that `CHANGELOG.md` contains `## [0.7.0]`
- Blocks tag creation if CHANGELOG entry is missing
- Creates annotated git tag `v0.7.0`
- Optionally pushes tag to origin

### 2. `.github/workflows/release.yml` — GitHub Action

- Triggers on tag push matching `v*`
- Extracts the corresponding section from CHANGELOG.md
- Creates a GitHub Release with those notes
- No external dependencies (uses native `gh` CLI)

## Flow

```text
bin/tag-release 0.7.0
  -> Validates CHANGELOG contains ## [0.7.0]
  -> Creates git tag -a v0.7.0
  -> Pushes tag to origin
    -> GitHub Action triggers on v0.7.0
      -> Extracts v0.7.0 notes from CHANGELOG.md
      -> Creates GitHub Release with extracted notes
```

## Files

| File | Purpose |
|------|---------|
| `bin/tag-release` | Bash script, validates CHANGELOG and creates tag |
| `.github/workflows/release.yml` | GitHub Action, creates release from tag |

## Design Decisions

- **Script over git hook**: Git has no native `pre-tag` hook. A wrapper script is more portable and can be committed to the repo.
- **CHANGELOG as source of truth**: The Action reads from CHANGELOG.md rather than generating notes from commits. This ensures human-curated release notes.
- **Blocking behavior**: The script exits with error if CHANGELOG is missing the version entry. No `--force` bypass.
