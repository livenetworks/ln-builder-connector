#!/usr/bin/env bash
# Build the runtime-only `main` branch from `development`.
#
#   bin/release.sh            run tests, build main from development, push both branches
#   bin/release.sh --dry-run  show what main would contain, change nothing
#   bin/release.sh --no-push  build main locally, don't push
#
# main = development tree minus the paths listed in .distignore (read from development).
# Never commit to main by hand: every release commit replaces main's tree entirely.
set -euo pipefail

SRC=development
DST=main
REMOTE=origin
DRY=0
PUSH=1
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY=1 ;;
    --no-push) PUSH=0 ;;
    *) echo "Unknown option: $arg" >&2; exit 2 ;;
  esac
done

cd "$(git rev-parse --show-toplevel)"
CURRENT=$(git symbolic-ref --short -q HEAD || echo "")

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "Working tree has uncommitted changes. Commit them to $SRC first." >&2
  exit 1
fi

git fetch -q "$REMOTE" "$SRC" "$DST"
for b in "$SRC" "$DST"; do
  if ! git merge-base --is-ancestor "$REMOTE/$b" "$b"; then
    echo "Local $b is behind or diverged from $REMOTE/$b. Pull/rebase it first." >&2
    exit 1
  fi
done

SRC_SHA=$(git rev-parse --short "$SRC")

# Run the CLI checks from the development tree (works even when main is checked out)
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
git archive "$SRC" | tar -x -C "$TMP"
if compgen -G "$TMP/tests/*.php" > /dev/null; then
  for t in "$TMP"/tests/*.php; do
    php "$t" > "$TMP/test.out" 2>&1 || { cat "$TMP/test.out"; echo "Test failed: ${t#$TMP/}" >&2; exit 1; }
  done
  echo "Tests passed."
fi
find "$TMP" -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null

# Build main's tree in a throw-away index: development tree minus .distignore paths
export GIT_INDEX_FILE="$TMP/index"
git read-tree "$SRC"
mapfile -t STRIP < <(git show "$SRC:.distignore" 2>/dev/null | sed -e 's/#.*//' -e 's/[[:space:]]*$//' -e 's#/$##' | grep -v '^$' || true)
if [ "${#STRIP[@]}" -gt 0 ]; then
  git rm -r -q --cached --ignore-unmatch -- "${STRIP[@]}"
fi
TREE=$(git write-tree)
unset GIT_INDEX_FILE

if [ "$TREE" = "$(git rev-parse "$DST^{tree}")" ]; then
  echo "$DST already matches $SRC ($SRC_SHA). Nothing to release."
  [ "$PUSH" = 1 ] && [ "$DRY" = 0 ] && git push -q "$REMOTE" "$SRC" && echo "Pushed $SRC."
  exit 0
fi

echo "Changes $DST -> new release from $SRC@$SRC_SHA:"
git diff --stat "$DST" "$TREE"
[ "$DRY" = 1 ] && exit 0

MSG="release: sync from $SRC@$SRC_SHA

$(git log --format='- %s' "$(git rev-parse "$DST")..$SRC" -- 2>/dev/null | grep -v '^- release:' | head -50 || true)"
COMMIT=$(git commit-tree "$TREE" -p "$DST" -m "$MSG")
git update-ref "refs/heads/$DST" "$COMMIT"

# If main is checked out here (the live plugin folder), bring the files up to date
if [ "$CURRENT" = "$DST" ]; then
  git reset -q --hard "$DST"
fi
echo "$DST -> $(git rev-parse --short "$DST")"

if [ "$PUSH" = 1 ]; then
  git push -q "$REMOTE" "$SRC" "$DST"
  echo "Pushed $SRC and $DST to $REMOTE."
fi
