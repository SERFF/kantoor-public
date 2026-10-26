#!/bin/bash
# Sends the weekly and 5-hour usage to the office as the Claude desktop app keeps it itself
# (plan-usage-history.json) and, when it is installed, as the Claude Usage app stores it.
# For each window the newest measurement is sent, with the moment it was taken, plus the recent
# history for the forecast.
#
#   kantoor-desktop-usage.sh            check once and send if there is something new
#   kantoor-desktop-usage.sh --install  install as a launchd agent (runs on every change)
#   kantoor-desktop-usage.sh --remove   remove the launchd agent again
#
# The URL and token come from ~/.claude/kantoor.env (see install.sh) or from KANTOOR_URL and KANTOOR_TOKEN.

CONFIG="${KANTOOR_CONFIG:-$HOME/.claude/kantoor.env}"
[ -f "$CONFIG" ] && . "$CONFIG"
KANTOOR_URL="${KANTOOR_URL:-__KANTOOR_URL__}"
KANTOOR_URL="${KANTOOR_URL%/}"
case "$KANTOOR_URL" in __*) KANTOOR_URL="" ;; esac
SOURCE="${KANTOOR_DESKTOP_USAGE:-$HOME/Library/Application Support/Claude/plan-usage-history.json}"
CLAUDE_USAGE="${KANTOOR_CLAUDE_USAGE:-$HOME/Library/Application Support/Claude Usage/history}"
STATE="$HOME/.claude/kantoor-desktop-usage.last"
LABEL="com.kantoor.desktop-usage"
PLIST="$HOME/Library/LaunchAgents/$LABEL.plist"
SELF="$HOME/.claude/hooks/kantoor-desktop-usage.sh"

case "${1:-}" in
    --install)
        mkdir -p "$HOME/Library/LaunchAgents"
        cat > "$PLIST" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key><string>$LABEL</string>
    <key>ProgramArguments</key>
    <array><string>/bin/bash</string><string>$SELF</string></array>
    <key>EnvironmentVariables</key>
    <dict><key>PATH</key><string>/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin</string></dict>
    <key>WatchPaths</key>
    <array><string>$SOURCE</string><string>$CLAUDE_USAGE</string></array>
    <key>StartInterval</key><integer>300</integer>
    <key>ThrottleInterval</key><integer>30</integer>
    <key>RunAtLoad</key><true/>
    <key>StandardOutPath</key><string>/dev/null</string>
    <key>StandardErrorPath</key><string>/dev/null</string>
</dict>
</plist>
EOF
        launchctl bootout "gui/$(id -u)/$LABEL" >/dev/null 2>&1 || true
        launchctl bootstrap "gui/$(id -u)" "$PLIST"
        exit $?
        ;;
    --remove)
        launchctl bootout "gui/$(id -u)/$LABEL" >/dev/null 2>&1 || true
        rm -f "$PLIST" "$STATE"
        exit 0
        ;;
esac

[ -n "$KANTOOR_URL" ] || exit 0
command -v jq >/dev/null 2>&1 || exit 0

desktop=/dev/null
[ -r "$SOURCE" ] && desktop="$SOURCE"
claude_usage=$(ls -t "$CLAUDE_USAGE"/*.json 2>/dev/null | head -n1)
[ -r "$claude_usage" ] || claude_usage=/dev/null
[ "$desktop" = /dev/null ] && [ "$claude_usage" = /dev/null ] && exit 0

token="${KANTOOR_TOKEN:-$(grep -o 'Bearer kh_[A-Za-z0-9]*' "$HOME/.claude/settings.json" 2>/dev/null | head -n1 | cut -d' ' -f2)}"
[ -n "$token" ] || exit 0

now=$(date +%s)
last_stamp=$(sed -n 1p "$STATE" 2>/dev/null)
since=$(sed -n 2p "$STATE" 2>/dev/null)
[ -n "$since" ] || since=$((now - 2419200))

payload=$(jq -n -c --argjson now "$now" --argjson since "$since" \
    --slurpfile d "$desktop" --slurpfile cu "$claude_usage" '
    def epoch: if . < 1000000000 then . + 978307200 else . end;
    def drop($s):
        [range(1; $s | length) as $i
            | select(($s[$i - 1].v - $s[$i].v) >= 15)
            | $s[$i].t] | last;
    def reset($s; $len; $repeats):
        drop($s) as $d
        | if $d == null then null
          elif $d + $len > $now then $d + $len
          elif $repeats then $d + $len * (((($now - $d) / $len) | floor) + 1)
          else null end;
    def window($s; $len; $repeats):
        ($s | last) as $last
        | if $last == null then null
          else reset($s; $len; $repeats) as $r
            | {used_percentage: $last.v, measured_at: $last.t}
              + (if $r == null then {} else {resets_at: ($r | floor), resets_estimated: true} end)
          end;
    (($d[0].samples // []) | last) as $lastDesktop
    | [($d[0].samples // [])[] | select($lastDesktop == null or .org == $lastDesktop.org)] as $desk
    | [($cu[0].snapshots // [])[] | select(.provider == "anthropic")] as $snaps
    | ([$desk[] | select(.u.fh != null) | {t: (.t / 1000 | floor), v: .u.fh}]
        + [$snaps[] | select(.sessionPercentage != null) | {t: (.timestamp | epoch | floor), v: .sessionPercentage}]
        | sort_by(.t)) as $fh
    | ([$desk[] | select(.u.sd != null) | {t: (.t / 1000 | floor), v: .u.sd}]
        + [$snaps[] | select(.weeklyPercentage != null) | {t: (.timestamp | epoch | floor), v: .weeklyPercentage}]
        | sort_by(.t)) as $sd
    | if ($fh | length) == 0 and ($sd | length) == 0 then empty else
        {
            source: "desktop",
            measured_at: ([($fh | last | .t), ($sd | last | .t)] | map(select(. != null)) | max),
            stamp: "\($fh | last | .t)-\($sd | last | .t)",
            rate_limits: {
                five_hour: window($fh; 18000; false),
                seven_day: window($sd; 604800; true)
            },
            history: ([$fh[] | select(.t >= $since) | {measured_at: .t, five_hour: .v}]
                + [$sd[] | select(.t >= $since) | {measured_at: .t, seven_day: .v}])
        }
      end
' 2>/dev/null)
[ -n "$payload" ] || exit 0

stamp=$(printf '%s' "$payload" | jq -r '.stamp')
[ "$stamp" = "$last_stamp" ] && exit 0
newest=$(printf '%s' "$payload" | jq -r '.measured_at')

if printf '%s' "$payload" | jq -c 'del(.stamp)' | curl -sf -m 20 -o /dev/null -X POST \
    -H "Authorization: Bearer ${token}" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    --data-binary @- "${KANTOOR_URL}/api/hooks/usage"; then
    printf '%s\n%s\n' "$stamp" "$((newest - 21600))" > "$STATE"
fi
