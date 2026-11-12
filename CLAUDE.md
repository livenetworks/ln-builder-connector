# LN Builder Connector

## Branches
- `development` — all work happens here: full repo (README, composer.json, tests/, bin/).
- `main` — runtime-only plugin, **generated** from `development` by `bin/release.sh`.
  Never commit to `main` directly; the next release would overwrite it.
- Dev-only paths are listed in `.distignore`. A new dev-only file/folder → add it there.

## Release
```
git release --dry-run   # preview what main would get
git release             # tests + build main + push both branches
git release --no-push   # build main locally only
```
`git release` is a repo-local alias (works from `main`, where `bin/` doesn't exist):
```
git config alias.release '!f() { git show development:bin/release.sh | bash -s -- "$@"; }; f'
```
On `development` you can also run `bin/release.sh` directly.
The live WordPress install keeps `main` checked out; the script updates its files in place.

## Tests
`php tests/elementor-roundtrip.php` — Elementor read → lean → write → read must be stable.
