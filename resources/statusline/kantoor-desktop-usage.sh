#!/bin/bash
# Sends the weekly and 5-hour usage that the Claude desktop app keeps track of itself to the office.
# The app now and then writes a measurement to plan-usage-history.json; this script only passes on
# a new measurement, with the moment the app took it, plus the recent history for the forecast.
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
    <array><string>$SOURCE</string></array>
    <key>StartInterval</key><integer>300</integer>
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
[ -r "$SOURCE" ] || exit 0
command -v jq >/dev/null 2>&1 || exit 0

token="${KANTOOR_TOKEN:-$(grep -o 'Bearer kh_[A-Za-z0-9]*' "$HOME/.claude/settings.json" 2>/dev/null | head -n1 | cut -d' ' -f2)}"
[ -n "$token" ] || exit 0

payload=$(jq -c --argjson now "$(date +%s)" '
    def drop($s; $k):
        [range(1; $s | length) as $i
            | select($s[$i - 1].u[$k] != null and $s[$i].u[$k] != null and ($s[$i - 1].u[$k] - $s[$i].u[$k]) >= 15)
            | $s[$i].t / 1000] | last;
    def reset($s; $k; $len; $repeats):
        drop($s; $k) as $d
        | if $d == null then null
          elif $d + $len > $now then $d + $len
          elif $repeats then $d + $len * (((($now - $d) / $len) | floor) + 1)
          else null end;
    def window($s; $k; $len; $repeats):
        ($s | last | .u[$k]) as $used
        | if $used == null then null
          else reset($s; $k; $len; $repeats) as $r
            | {used_percentage: $used}
              + (if $r == null then {} else {resets_at: ($r | floor), resets_estimated: true} end)
          end;
    (.samples // []) as $all
    | ($all | last) as $last
    | if $last == null then empty else
        [$all[] | select(.org == $last.org)] as $s
        | {
            source: "desktop",
            measured_at: ($last.t / 1000 | floor),
            rate_limits: {
                five_hour: window($s; "fh"; 18000; false),
                seven_day: window($s; "sd"; 604800; true)
            },
            history: [$s[] | select(.t / 1000 >= $now - 2419200)
                | {measured_at: (.t / 1000 | floor), five_hour: .u.fh, seven_day: .u.sd}]
          }
      end
' "$SOURCE" 2>/dev/null)
[ -n "$payload" ] || exit 0

stamp=$(printf '%s' "$payload" | jq -r '.measured_at')
[ "$stamp" = "$(cat "$STATE" 2>/dev/null)" ] && exit 0

if curl -sf -m 10 -o /dev/null -X POST \
    -H "Authorization: Bearer ${token}" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    --data-binary "$payload" "${KANTOOR_URL}/api/hooks/usage"; then
    printf '%s\n' "$stamp" > "$STATE"
fi
