# LN Builder Connector

## Branches
- `development` — all work happens here: full repo (README, composer.json, tests/, bin/).
- `main` — runtime-only plugin, **generated** from `development` by `bin/release.sh`.
  Never commit to `main` directly; the next release would overwrite it.
- Dev-only paths are listed in `.distignore`. A new dev-only file/folder → add it there.

## Release
```
bin/release.sh --dry-run   # preview what main would get
bin/release.sh             # tests + build main + push both branches
```
The live WordPress install keeps `main` checked out; the script updates its files in place.

## Tests
`php tests/elementor-roundtrip.php` — Elementor read → lean → write → read must be stable.
