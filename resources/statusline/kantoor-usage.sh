#!/bin/bash
# Status line wrapper for Claude Code: sends the weekly and 5-hour usage to the office
# and then passes the JSON on to your own status line (KANTOOR_STATUSLINE).
#
# Usage in ~/.claude/settings.json:
#   "statusLine": { "type": "command", "command": "KANTOOR_URL=https://kantoor.example.com KANTOOR_STATUSLINE='your-statusline-command' ~/.claude/hooks/kantoor-usage.sh" }
#
# KANTOOR_URL is required; without it nothing is sent and the input is only passed on.
# The token comes from KANTOOR_TOKEN or is read from the Kantoor hooks in settings.json.

input=$(cat)

KANTOOR_URL="${KANTOOR_URL%/}"
STATE="${HOME}/.claude/kantoor-usage.last"

limits=$(printf '%s' "$input" | jq -c '.rate_limits // empty | {five_hour, seven_day}' 2>/dev/null)

if [ -n "$KANTOOR_URL" ] && [ -n "$limits" ]; then
    now=$(date +%s)
    last_sent=0
    last_value=""
    if [ -f "$STATE" ]; then
        last_sent=$(head -n1 "$STATE" 2>/dev/null || echo 0)
        last_value=$(sed -n 2p "$STATE" 2>/dev/null)
    fi
    value=$(printf '%s' "$limits" | jq -c '[.five_hour.used_percentage, .seven_day.used_percentage] | map(if . == null then null else floor end)')

    if [ "$value" != "$last_value" ] || [ $((now - last_sent)) -ge 600 ]; then
        printf '%s\n%s\n' "$now" "$value" > "$STATE"
        token="${KANTOOR_TOKEN:-$(grep -o 'Bearer kh_[A-Za-z0-9]*' "${HOME}/.claude/settings.json" 2>/dev/null | head -n1 | cut -d' ' -f2)}"
        if [ -n "$token" ]; then
            (curl -s -m 3 -o /dev/null -X POST \
                -H "Authorization: Bearer ${token}" -H 'Content-Type: application/json' \
                --data-binary "{\"rate_limits\":${limits}}" "${KANTOOR_URL}/api/hooks/usage" >/dev/null 2>&1 &)
        fi
    fi
fi

if [ -n "$KANTOOR_STATUSLINE" ]; then
    printf '%s' "$input" | eval "$KANTOOR_STATUSLINE"
fi
