#!/usr/bin/env bash
set -euo pipefail

usage() {
    printf '%s\n' \
        'Usage:' \
        '  announce-production.sh --preview --message-file PATH' \
        '  announce-production.sh --execute --expected-target-hash HASH --message-file PATH'
}

mode="preview"
message_file=""
expected_target_hash=""

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
        --message-file)
            message_file="${2:-}"
            shift 2
            ;;
        --expected-target-hash)
            expected_target_hash="${2:-}"
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

if [[ -z "$message_file" || ! -f "$message_file" ]]; then
    printf 'Missing Ukrainian message file: %s\n' "$message_file" >&2
    exit 1
fi

if [[ "$mode" == "execute" && ! "$expected_target_hash" =~ ^[0-9a-f]{64}$ ]]; then
    printf 'Execute mode requires a 64-character --expected-target-hash.\n' >&2
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

message_base64="$(base64 < "$message_file" | tr -d '\n')"
root_q="$(printf '%q' "$LADNA_PRODUCTION_ROOT")"
mode_q="$(printf '%q' "$mode")"
message_q="$(printf '%q' "$message_base64")"
hash_q="$(printf '%q' "$expected_target_hash")"

"$ssh_wrapper" "LADNA_PRODUCTION_ROOT=${root_q} ANNOUNCE_MODE=${mode_q} MESSAGE_BASE64=${message_q} EXPECTED_TARGET_HASH=${hash_q} bash -se" <<'REMOTE'
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
    LADNA_FOUNDERS_ANNOUNCEMENT_ORIGIN=codex_skill
    php artisan telegram:announce-ladna-founders
    "--message-base64=${MESSAGE_BASE64}"
    "--source-ref=${source_ref}"
    --json
    --no-interaction
)

if [[ "$ANNOUNCE_MODE" == "execute" ]]; then
    arguments+=(
        --execute
        --force
        "--expected-target-hash=${EXPECTED_TARGET_HASH}"
    )
fi

"${arguments[@]}"
REMOTE
