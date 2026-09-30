#!/bin/bash
#
# wireview-fault-monitor.sh - Monitor WireView Pro II for faults and
#                              send Unraid notifications on state changes.
#
# Runs as a background process alongside wireviewd. Polls hwmon sysfs
# every 30 seconds and sends notifications via Unraid's notify script
# when fault status changes or temperature exceeds thresholds.
#
# Usage: Started/stopped by rc.wireviewd (not run directly)
#

NOTIFY="/usr/local/emhttp/webGui/scripts/notify"
STATE_FILE="/tmp/wireview-hwmon-fault-state"
POLL_INTERVAL=30
TEMP_WARN_THRESHOLD=80000  # 80°C in millidegrees

# Find the wireview hwmon sysfs path
find_hwmon() {
    local base="/sys/class/hwmon"
    for d in "$base"/hwmon*; do
        [ -f "$d/name" ] && [ "$(cat "$d/name" 2>/dev/null)" = "wireview" ] && echo "$d" && return
    done
}

# Read a sysfs file, print value or empty string on failure
read_sysfs() {
    local val
    val=$(cat "$1" 2>/dev/null)
    [ -n "$val" ] && echo "$val" || echo ""
}

# Load previous state
load_state() {
    prev_fault="0"
    prev_temp_warned="0"
    if [ -f "$STATE_FILE" ]; then
        . "$STATE_FILE"
    fi
}

# Save current state
save_state() {
    cat > "$STATE_FILE" <<EOF
prev_fault=$1
prev_temp_warned=$2
EOF
}

# Names of the faults set in a bitmask, comma separated (bit order as in
# the firmware's FAULT enum)
FAULT_NAMES=("Over-Temp (Chip)" "Over-Temp (Sensor)" "Over-Current" "Wire Over-Current" "Over-Power" "Current Imbalance")
fault_names() {
    local mask="$1" bit names=""
    for bit in 0 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do
        if (( mask & (1 << bit) )); then
            names+="${names:+, }${FAULT_NAMES[$bit]:-Bit $bit}"
        fi
    done
    echo "$names"
}

# Send an Unraid notification
send_notify() {
    local importance="$1" subject="$2" desc="$3"
    if [ -x "$NOTIFY" ]; then
        "$NOTIFY" -e "WireView Pro II" -s "$subject" -d "$desc" -i "$importance" -l "/Utilities/WireviewHwmon"
    fi
}

# Main monitoring loop
while true; do
    sleep "$POLL_INTERVAL"

    hwmon=$(find_hwmon)
    if [ -z "$hwmon" ]; then
        # Device not present, reset state
        save_state 0 0
        continue
    fi

    fault_status=$(read_sysfs "$hwmon/fault_status_raw")
    fault_status=${fault_status:-0}
    temp=$(read_sysfs "$hwmon/temp1_input")
    temp=${temp:-0}

    load_state

    # Fault status transitions
    if [ "$fault_status" != "0" ] && [ "$prev_fault" = "0" ]; then
        send_notify "alert" \
            "GPU Power Fault Detected" \
            "WireView Pro II reports: $(fault_names "$fault_status")"
    elif [ "$fault_status" = "0" ] && [ "$prev_fault" != "0" ]; then
        send_notify "normal" \
            "GPU Power Fault Cleared" \
            "WireView Pro II fault condition has cleared"
    fi

    # High temperature warning
    temp_warned="$prev_temp_warned"
    if [ "$temp" -gt "$TEMP_WARN_THRESHOLD" ] 2>/dev/null && [ "$prev_temp_warned" = "0" ]; then
        temp_c=$((temp / 1000))
        send_notify "warning" \
            "WireView High Temperature" \
            "WireView Pro II onboard temperature: ${temp_c}°C"
        temp_warned="1"
    elif [ "$temp" -le "$TEMP_WARN_THRESHOLD" ] 2>/dev/null && [ "$prev_temp_warned" = "1" ]; then
        temp_warned="0"
    fi

    save_state "$fault_status" "$temp_warned"
done
