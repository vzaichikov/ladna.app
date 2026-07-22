#!/usr/bin/env bash
set -euo pipefail

usage() {
    printf '%s\n' \
        'Usage:' \
        '  announce-production.sh --preview --uk-file PATH --en-file PATH' \
        '  announce-production.sh --execute --expected-audience-hash HASH --uk-file PATH --en-file PATH'
}

mode="preview"
uk_file=""
en_file=""
expected_audience_hash=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --preview)
            mode="preview"
            shift
            ;;
        --execute)
            mode="execute"
            shift
            ;;
        --uk-file)
            uk_file="${2:-}"
            shift 2
            ;;
        --en-file)
            en_file="${2:-}"
            shift 2
            ;;
        --expected-audience-hash)
            expected_audience_hash="${2:-}"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            printf 'Unknown argument: %s\n' "$1" >&2
            usage >&2
            exit 2
            ;;
    esac
done

if [[ -z "$uk_file" || ! -f "$uk_file" ]]; then
    printf 'Missing Ukrainian message file: %s\n' "$uk_file" >&2
    exit 1
fi

if [[ -z "$en_file" || ! -f "$en_file" ]]; then
    printf 'Missing English message file: %s\n' "$en_file" >&2
    exit 1
fi

if [[ "$mode" == "execute" && ! "$expected_audience_hash" =~ ^[0-9a-f]{64}$ ]]; then
    printf 'Execute mode requires a 64-character --expected-audience-hash.\n' >&2
    exit 1
fi

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
project_root="$(cd "${script_dir}/../../../.." && pwd)"
env_file="${project_root}/.codex/production.env"
ssh_wrapper="${project_root}/.codex/production-ssh"

if [[ ! -f "$env_file" ]]; then
    printf 'Missing %s. Configure production SSH locally first.\n' "$env_file" >&2
    exit 1
fi

if [[ ! -x "$ssh_wrapper" ]]; then
    printf 'Missing executable %s. Configure production SSH locally first.\n' "$ssh_wrapper" >&2
    exit 1
fi

# shellcheck source=/dev/null
source "$env_file"

if [[ -z "${LADNA_PRODUCTION_ROOT:-}" ]]; then
    printf 'LADNA_PRODUCTION_ROOT is not set in %s.\n' "$env_file" >&2
    exit 1
fi

uk_base64="$(base64 < "$uk_file" | tr -d '\n')"
en_base64="$(base64 < "$en_file" | tr -d '\n')"
root_q="$(printf '%q' "$LADNA_PRODUCTION_ROOT")"
mode_q="$(printf '%q' "$mode")"
uk_q="$(printf '%q' "$uk_base64")"
en_q="$(printf '%q' "$en_base64")"
hash_q="$(printf '%q' "$expected_audience_hash")"

"$ssh_wrapper" "LADNA_PRODUCTION_ROOT=${root_q} ANNOUNCE_MODE=${mode_q} UK_BASE64=${uk_q} EN_BASE64=${en_q} EXPECTED_AUDIENCE_HASH=${hash_q} bash -se" <<'REMOTE'
set -euo pipefail

cd "$LADNA_PRODUCTION_ROOT"

if [[ ! -f artisan ]] || ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    printf 'Production root is not a valid Ladna Laravel checkout.\n' >&2
    exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
    printf 'Production worktree is dirty. Refusing to announce from an uncertain source.\n' >&2
    exit 1
fi

source_ref="$(git rev-parse HEAD)"
arguments=(
    env
    LADNA_OWNER_ANNOUNCEMENT_ORIGIN=codex_skill
    php artisan telegram:announce-studio-owners
    "--uk-base64=${UK_BASE64}"
    "--en-base64=${EN_BASE64}"
    "--source-ref=${source_ref}"
    --json
    --no-interaction
)

if [[ "$ANNOUNCE_MODE" == "execute" ]]; then
    arguments+=(
        --execute
        --force
        "--expected-audience-hash=${EXPECTED_AUDIENCE_HASH}"
    )
fi

"${arguments[@]}"
REMOTE
