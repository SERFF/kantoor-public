#!/bin/bash
# Status line wrapper for Claude Code: sends the weekly and 5-hour usage to the office
# and then passes the JSON on to your own status line (KANTOOR_STATUSLINE).
#
# Usage in ~/.claude/settings.json:
#   "statusLine": { "type": "command", "command": "KANTOOR_URL=https://kantoor.example.com KANTOOR_STATUSLINE='your-statusline-command' ~/.claude/hooks/kantoor-usage.sh" }
#
# KANTOOR_URL is required; without it nothing is sent and the input is only passed on.
# The URL and token come from ~/.claude/kantoor.env (see install.sh), from KANTOOR_URL and
# KANTOOR_TOKEN, or, for older installs, from the Kantoor hooks in settings.json.
#
# Claude Code only refreshes rate_limits on API activity, so a session that stays open without
# doing anything keeps showing old numbers. This script therefore remembers per session since
# when the numbers have been unchanged and sends that moment along: the office always keeps the
# newest measurement.

input=$(cat)

CONFIG="${KANTOOR_CONFIG:-$HOME/.claude/kantoor.env}"
[ -f "$CONFIG" ] && . "$CONFIG"
KANTOOR_URL="${KANTOOR_URL:-__KANTOOR_URL__}"
KANTOOR_URL="${KANTOOR_URL%/}"
case "$KANTOOR_URL" in __*) KANTOOR_URL="" ;; esac
STATE_DIR="${HOME}/.claude/kantoor-usage"

limits=$(printf '%s' "$input" | jq -c '.rate_limits // empty | {five_hour, seven_day}' 2>/dev/null)

if [ -n "$KANTOOR_URL" ] && [ -n "$limits" ]; then
    session=$(printf '%s' "$input" | jq -r '.session_id // "default"' 2>/dev/null | tr -cd 'A-Za-z0-9_-')
    STATE="${STATE_DIR}/${session:-default}"
    mkdir -p "$STATE_DIR"
    now=$(date +%s)
    last_sent=0
    last_value=""
    first_seen=$now
    if [ -f "$STATE" ]; then
        last_sent=$(sed -n 1p "$STATE" 2>/dev/null)
        last_value=$(sed -n 2p "$STATE" 2>/dev/null)
        first_seen=$(sed -n 3p "$STATE" 2>/dev/null)
    fi
    value=$(printf '%s' "$limits" | jq -c '[.five_hour.used_percentage, .five_hour.resets_at, .seven_day.used_percentage, .seven_day.resets_at] | map(if type == "number" then floor else . end)')

    if [ "$value" != "$last_value" ]; then
        first_seen=$now
    fi

    if [ "$value" != "$last_value" ] || [ $((now - ${last_sent:-0})) -ge 600 ]; then
        printf '%s\n%s\n%s\n' "$now" "$value" "${first_seen:-$now}" > "$STATE"
        token="${KANTOOR_TOKEN:-$(grep -o 'Bearer kh_[A-Za-z0-9]*' "${HOME}/.claude/settings.json" 2>/dev/null | head -n1 | cut -d' ' -f2)}"
        if [ -n "$token" ]; then
            (curl -s -m 3 -o /dev/null -X POST \
                -H "Authorization: Bearer ${token}" -H 'Content-Type: application/json' \
                --data-binary "{\"source\":\"statusline\",\"measured_at\":${first_seen:-$now},\"rate_limits\":${limits}}" "${KANTOOR_URL}/api/hooks/usage" >/dev/null 2>&1 &)
        fi
        find "$STATE_DIR" -type f -mtime +2 -delete 2>/dev/null
    fi
fi

if [ -n "$KANTOOR_STATUSLINE" ]; then
    printf '%s' "$input" | eval "$KANTOOR_STATUSLINE"
fi
