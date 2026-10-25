#!/bin/bash
# Installs the Kantoor hooks for Claude Code on this machine.
#
#   curl -fsSL __KANTOOR_URL__/install.sh | bash -s -- kh_yourtoken
#   curl -fsSL __KANTOOR_URL__/install.sh | bash -s -- --remove
#
# Backs up ~/.claude/settings.json first, shows the change and asks before writing it.

set -euo pipefail

URL="${KANTOOR_URL:-__KANTOOR_URL__}"
URL="${URL%/}"
ARG="${1:-}"
CLAUDE_DIR="$HOME/.claude"
HOOKS="$CLAUDE_DIR/hooks"
SETTINGS="$CLAUDE_DIR/settings.json"
ENV_FILE="$CLAUDE_DIR/kantoor.env"
HOOK="$HOOKS/kantoor-hook.sh"
USAGE="$HOOKS/kantoor-usage.sh"

say() { printf '%s\n' "$*"; }
fail() { printf 'Error: %s\n' "$*" >&2; exit 1; }

for tool in jq curl diff; do
    command -v "$tool" >/dev/null 2>&1 || fail "$tool is missing. On a Mac: brew install $tool"
done

ask() {
    local answer=""
    if [ -n "${KANTOOR_YES:-}" ]; then
        return 0
    fi
    if [ -r /dev/tty ]; then
        printf '%s [y/N] ' "$1" > /dev/tty
        read -r answer < /dev/tty || true
    fi
    case "$answer" in j|J|ja|y|Y|yes) return 0 ;; *) return 1 ;; esac
}

MODE=install
TOKEN=""
if [ "$ARG" = "--remove" ]; then
    MODE=remove
else
    TOKEN="$ARG"
    if [ -z "$TOKEN" ] && [ -f "$ENV_FILE" ]; then
        TOKEN=$(sed -n 's/^KANTOOR_TOKEN=//p' "$ENV_FILE" | tr -d "'" | head -n1)
    fi
    if [ -z "$TOKEN" ] && [ -f "$SETTINGS" ]; then
        TOKEN=$(grep -o 'Bearer kh_[A-Za-z0-9]*' "$SETTINGS" | head -n1 | cut -d' ' -f2 || true)
    fi
    if [ -z "$TOKEN" ] && [ -r /dev/tty ]; then
        printf 'Hook token (starts with kh_): ' > /dev/tty
        read -r TOKEN < /dev/tty || true
    fi
    case "$TOKEN" in kh_*) ;; *) fail "not a valid hook token. Create one with: php artisan kantoor:hook-token 'Name of your Mac'" ;; esac

    code=$(curl -s -o /dev/null -w '%{http_code}' -m 10 -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' "$URL/api/hooks/claude-code/requests/00000000-0000-4000-8000-000000000000" || true)
    [ "$code" = 200 ] || fail "the office at $URL does not accept this token (HTTP $code)."
fi

mkdir -p "$HOOKS"
[ -s "$SETTINGS" ] || printf '{}\n' > "$SETTINGS"
jq empty "$SETTINGS" 2>/dev/null || fail "$SETTINGS is not valid JSON. Fix that first."

new=$(jq --arg hook "$HOOK" --arg usage "$USAGE" --arg mode "$MODE" '
    def ours: ((.command // "") | (contains("kantoor-hook.sh") or contains("/api/hooks/claude-code")));
    def entry($matcher; $command; $timeout):
        (if $matcher then { matcher: $matcher } else {} end)
        + { hooks: [{ type: "command", command: $command, timeout: $timeout }] };
    def quoted($path): ($path | tojson);

    .hooks = ((.hooks // {})
        | with_entries(.value |= (map(.hooks |= map(select(ours | not))) | map(select((.hooks | length) > 0))))
        | with_entries(select((.value | length) > 0)))
    | if $mode == "remove" then
        (if ((.statusLine.command // "") == quoted($usage)) then del(.statusLine) else . end)
        | (if (.hooks | length) == 0 then del(.hooks) else . end)
      else
        reduce ("SessionStart", "UserPromptSubmit", "Notification", "Stop", "SubagentStart", "SubagentStop", "SessionEnd", "PreCompact") as $event
            (.; .hooks[$event] = ((.hooks[$event] // []) + [entry(null; quoted($hook); 5)]))
        | .hooks.PreToolUse = ((.hooks.PreToolUse // []) + [entry("*"; quoted($hook); 5), entry("AskUserQuestion"; quoted($hook) + " --wait"; 3600)])
        | .hooks.PostToolUse = ((.hooks.PostToolUse // []) + [entry("*"; quoted($hook); 5)])
        | .hooks.PermissionRequest = ((.hooks.PermissionRequest // []) + [entry("*"; quoted($hook) + " --wait"; 3600)])
        | if ((.statusLine.command // "") | contains("kantoor-usage.sh")) then .
          elif .statusLine.command then .statusLine.command = ("KANTOOR_STATUSLINE=" + (.statusLine.command | @sh) + " " + quoted($usage))
          else .statusLine = { type: "command", command: quoted($usage) }
          end
      end
' "$SETTINGS")

say "Changes to $SETTINGS:"
if diff -u "$SETTINGS" <(printf '%s\n' "$new") | sed -E 's/kh_[A-Za-z0-9]+/kh_…/g'; then
    say "(no changes)"
fi
say ""

if ! ask "Apply?"; then
    say "Nothing changed."
    exit 0
fi

backup="$SETTINGS.kantoor-backup-$(date +%Y%m%d%H%M%S)"
cp "$SETTINGS" "$backup"

if [ "$MODE" = remove ]; then
    printf '%s\n' "$new" > "$SETTINGS"
    rm -f "$HOOK"
    say "The Kantoor hooks have been removed. Backup: $backup"
    say "Your status line and $ENV_FILE were left in place; remove them yourself if you like."
    exit 0
fi

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
curl -fsSL -m 20 "$URL/hooks/kantoor-hook.sh" -o "$tmp/kantoor-hook.sh" || fail "could not download kantoor-hook.sh."
curl -fsSL -m 20 "$URL/hooks/kantoor-usage.sh" -o "$tmp/kantoor-usage.sh" || fail "could not download kantoor-usage.sh."
bash -n "$tmp/kantoor-hook.sh" && bash -n "$tmp/kantoor-usage.sh" || fail "the downloaded scripts are not valid."
install -m 755 "$tmp/kantoor-hook.sh" "$HOOK"
install -m 755 "$tmp/kantoor-usage.sh" "$USAGE"

umask 077
{
    [ -f "$ENV_FILE" ] && grep -v -E '^KANTOOR_(URL|TOKEN)=' "$ENV_FILE" || true
    printf "KANTOOR_URL='%s'\n" "$URL"
    printf "KANTOOR_TOKEN='%s'\n" "$TOKEN"
} > "$tmp/kantoor.env"
install -m 600 "$tmp/kantoor.env" "$ENV_FILE"

printf '%s\n' "$new" > "$SETTINGS"

say "Done. New Claude Code sessions now show up in the office."
say "Backup of your old settings: $backup"
say "Permission requests and questions go through the office while you are away from your Mac."
say "Always or never? Set KANTOOR_APPROVE=always or never in $ENV_FILE."

