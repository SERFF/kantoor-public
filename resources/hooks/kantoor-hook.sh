#!/bin/bash
# Kantoor hook for Claude Code.
# Reports every session to the office and lets you answer permission requests and questions there.
# Settings live in ~/.claude/kantoor.env (see install.sh). Always fails silently.
#
#   KANTOOR_URL        address of the office
#   KANTOOR_TOKEN      hook token of this machine
#   KANTOOR_APPROVE    away (default): only through the office while you are away from your Mac
#                      always: always through the office, never: never
#   KANTOOR_AWAY_AFTER seconds without mouse or keyboard before you count as away (120)
#   KANTOOR_WAIT       how long the hook waits for your answer, in seconds (1800)
#   KANTOOR_FILES      0 = do not send file names
#   KANTOOR_DETAILS    0 = do not send the command or path with a permission request

CONFIG="${KANTOOR_CONFIG:-$HOME/.claude/kantoor.env}"
[ -f "$CONFIG" ] && . "$CONFIG"

[ -n "$KANTOOR_URL" ] && [ -n "$KANTOOR_TOKEN" ] || exit 0
command -v jq >/dev/null 2>&1 || exit 0
command -v curl >/dev/null 2>&1 || exit 0

input=$(cat)
event=$(printf '%s' "$input" | jq -r '.hook_event_name // empty' 2>/dev/null)
tool=$(printf '%s' "$input" | jq -r '.tool_name // empty' 2>/dev/null)
[ -n "$event" ] || exit 0

API="${KANTOOR_URL%/}/api/hooks/claude-code"
AUTH="Authorization: Bearer $KANTOOR_TOKEN"
APPROVE="${KANTOOR_APPROVE:-away}"

idle_seconds() {
    if command -v ioreg >/dev/null 2>&1; then
        ioreg -c IOHIDSystem 2>/dev/null | awk '/HIDIdleTime/ { print int($NF / 1000000000); exit }'
    else
        echo 999999
    fi
}

blocking=false
if [ "$1" = "--wait" ]; then
    blocking=true
elif [ "$event" = "PreToolUse" ] && [ "$tool" = "AskUserQuestion" ]; then
    exit 0
fi

wait=false
if [ "$blocking" = true ]; then
    case "$APPROVE" in
        always) wait=true ;;
        away) [ "$(idle_seconds)" -ge "${KANTOOR_AWAY_AFTER:-120}" ] && wait=true ;;
    esac
fi

files=true; [ "${KANTOOR_FILES:-1}" = 0 ] && files=false
details=true; [ "${KANTOOR_DETAILS:-1}" = 0 ] && details=false

body=$(printf '%s' "$input" | jq -c --argjson wait "$wait" --argjson files "$files" --argjson details "$details" '
    def count_lines: if type == "string" and . != "" then (rtrimstr("\n") | split("\n") | length) else 0 end;
    def patch: [(.tool_response.structuredPatch // [])[] | (.lines // [])[]];
    def edit_lines:
        if (patch | length) > 0 then
            { added: ([patch[] | select(startswith("+"))] | length), removed: ([patch[] | select(startswith("-"))] | length) }
        elif .tool_name == "Write" then { added: (.tool_input.content | count_lines), removed: 0 }
        elif .tool_name == "MultiEdit" then
            { added: ([(.tool_input.edits // [])[] | .new_string | count_lines] | add // 0),
              removed: ([(.tool_input.edits // [])[] | .old_string | count_lines] | add // 0) }
        else { added: (.tool_input.new_string | count_lines), removed: (.tool_input.old_string | count_lines) }
        end;
    {
        session_id, hook_event_name, cwd, source, tool_name, message, agent_id, agent_type,
        permission_suggestions: (if .hook_event_name == "PermissionRequest" then .permission_suggestions else null end),
        wait: $wait,
        tool_input: ((.tool_input // {}) | {
            file_path: (if $files then .file_path else null end),
            command: (if $details and $wait then .command else null end),
            url: (if $details and $wait then .url else null end),
            description, subagent_type, questions
        } | with_entries(select(.value != null))),
        lines: (if .hook_event_name == "PostToolUse" and ((.tool_name // "") | IN("Edit", "MultiEdit", "Write", "NotebookEdit")) then edit_lines else null end)
    } | with_entries(select(.value != null))
' 2>/dev/null) || exit 0

if [ "$wait" != true ]; then
    curl -s -m 2 -o /dev/null -X POST -H "$AUTH" -H 'Content-Type: application/json' --data-binary "$body" "$API" >/dev/null 2>&1
    exit 0
fi

request=$(curl -s -m 5 -X POST -H "$AUTH" -H 'Content-Type: application/json' -H 'Accept: application/json' --data-binary "$body" "$API" 2>/dev/null | jq -r '.request // empty' 2>/dev/null)
[ -n "$request" ] || exit 0

cancel() {
    curl -s -m 3 -o /dev/null -X DELETE -H "$AUTH" -H 'Accept: application/json' "$API/requests/$request" >/dev/null 2>&1
}
trap 'cancel; exit 0' TERM INT HUP

deadline=$(( $(date +%s) + ${KANTOOR_WAIT:-1800} ))
while [ "$(date +%s)" -lt "$deadline" ]; do
    sleep 1
    if [ "$APPROVE" = away ] && [ "$(idle_seconds)" -lt 2 ]; then
        cancel
        exit 0
    fi
    reply=$(curl -s -m 5 -H "$AUTH" -H 'Accept: application/json' "$API/requests/$request" 2>/dev/null) || continue
    case "$(printf '%s' "$reply" | jq -r '.status // empty' 2>/dev/null)" in
        answered) printf '%s' "$reply" | jq -c '.output'; exit 0 ;;
        released|gone) exit 0 ;;
    esac
done

cancel
exit 0

