#!/usr/bin/env bash
# Execute the real deploy script with every external operation replaced by a stub.
set -Eeuo pipefail
project="$(cd "$(dirname "$0")/../.." && pwd)"
fixture="$(mktemp -d)"
trap 'rm -rf -- "$fixture"' EXIT
mkdir -p "$fixture/bin" "$fixture/remote/backend"
export DEPLOY_TEST_ROOT="$fixture"
cat > "$fixture/bin/stub" <<'STUB'
#!/usr/bin/env bash
set -eu
name="${0##*/}"
printf '%s %s\n' "$name" "$*" >> "$DEPLOY_TEST_ROOT/calls"
case "$name" in
  git)
    if [[ "${1-}" == branch ]]; then printf 'main\n'; fi
    ;;
  ssh)
    script="${2//\/home\/jdr-compagnon\/www\/jdr-companion/$DEPLOY_TEST_ROOT/remote}"
    bash -c "$script"
    ;;
  php)
    if [[ "$*" == *app:dnd:check-reference* ]]; then exit "$DEPLOY_TEST_CHECK_EXIT"; fi
    ;;
esac
STUB
chmod +x "$fixture/bin/stub"
for name in git docker tar scp ssh composer php mkdir rm curl; do
  cp "$fixture/bin/stub" "$fixture/bin/$name"
done
for code in 1 0; do
  export DEPLOY_TEST_CHECK_EXIT="$code"
  : > "$fixture/calls"
  status=0
  (cd "$fixture" && PATH="$fixture/bin:$PATH" bash "$project/deploy.sh") > "$fixture/output" 2>&1 || status=$?
  grep -q '^php bin/console app:dnd:check-reference' "$fixture/calls"
  if [[ "$code" == 1 ]]; then
    [[ "$status" != 0 ]]
    ! grep -q '^tar -xzf' "$fixture/calls"
    ! grep -q '^curl ' "$fixture/calls"
    ! grep -q 'Déploiement terminé' "$fixture/output"
  else
    [[ "$status" == 0 ]]
    grep -q '^tar -xzf' "$fixture/calls"
    grep -q '^curl ' "$fixture/calls"
    grep -q 'Déploiement terminé' "$fixture/output"
  fi
  ! grep -Eq '^php .*app:dnd:(import-|initialize-reference)' "$fixture/calls"
done
printf 'OK: deploy gate success/failure; all network, database and build commands stubbed.\n'
