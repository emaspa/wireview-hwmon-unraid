#!/bin/bash
#
# wireview-fault-monitor.sh - Monitor WireView Pro II for faults and
#                              send Unraid notifications on state changes.
#
# Runs as a background process alongside wireviewd. Polls hwmon sysfs
# every 30 seconds and sends notifications via Unraid's notify script
# when fault status changes or temperature exceeds thresholds. Watches
# every WireView (wireview-hwmon 1.9.0 gives each its own hwmon device,
# "wireview", "wireview1", ...); with several, a notification names the
# unit.
#
# Usage: Started/stopped by rc.wireviewd (not run directly)
#

NOTIFY="/usr/local/emhttp/webGui/scripts/notify"
# One state file per WireView: <prefix>-<hwmon name>
STATE_PREFIX="/tmp/wireview-hwmon-fault-state"
POLL_INTERVAL=30
TEMP_WARN_THRESHOLD=80000  # 80°C in millidegrees

# Every wireview hwmon sysfs path, one per line
find_hwmons() {
    local base="/sys/class/hwmon" d name
    for d in "$base"/hwmon*; do
        name=$(cat "$d/name" 2>/dev/null)
        [[ "$name" =~ ^wireview[0-9]*$ ]] && echo "$d"
    done
}

# Read a sysfs file, print value or empty string on failure
read_sysfs() {
    local val
    val=$(cat "$1" 2>/dev/null)
    [ -n "$val" ] && echo "$val" || echo ""
}

# Load a WireView's previous state ($1 = its state file)
load_state() {
    prev_fault="0"
    prev_temp_warned="0"
    if [ -f "$1" ]; then
        . "$1"
    fi
}

# Save a WireView's current state ($1 = its state file)
save_state() {
    cat > "$1" <<EOF
prev_fault=$2
prev_temp_warned=$3
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

# Check one WireView ($1 = its hwmon path, $2 = how notifications name it)
check_device() {
    local hwmon="$1" who="$2" state fault_status temp temp_warned temp_c
    state="$STATE_PREFIX-$(cat "$hwmon/name" 2>/dev/null)"

    fault_status=$(read_sysfs "$hwmon/fault_status_raw")
    fault_status=${fault_status:-0}
    temp=$(read_sysfs "$hwmon/temp1_input")
    temp=${temp:-0}

    load_state "$state"

    # Fault status transitions
    if [ "$fault_status" != "0" ] && [ "$prev_fault" = "0" ]; then
        send_notify "alert" \
            "GPU Power Fault Detected" \
            "$who reports: $(fault_names "$fault_status")"
    elif [ "$fault_status" = "0" ] && [ "$prev_fault" != "0" ]; then
        send_notify "normal" \
            "GPU Power Fault Cleared" \
            "$who fault condition has cleared"
    fi

    # High temperature warning
    temp_warned="$prev_temp_warned"
    if [ "$temp" -gt "$TEMP_WARN_THRESHOLD" ] 2>/dev/null && [ "$prev_temp_warned" = "0" ]; then
        temp_c=$((temp / 1000))
        send_notify "warning" \
            "WireView High Temperature" \
            "$who onboard temperature: ${temp_c}°C"
        temp_warned="1"
    elif [ "$temp" -le "$TEMP_WARN_THRESHOLD" ] 2>/dev/null && [ "$prev_temp_warned" = "1" ]; then
        temp_warned="0"
    fi

    save_state "$state" "$fault_status" "$temp_warned"
}

# Main monitoring loop
while true; do
    sleep "$POLL_INTERVAL"

    mapfile -t hwmons < <(find_hwmons)
    if [ "${#hwmons[@]}" -eq 0 ]; then
        # No device present, reset state
        rm -f "$STATE_PREFIX"-*
        continue
    fi

    for hwmon in "${hwmons[@]}"; do
        who="WireView Pro II"
        # With several, the label names the unit ("WireView Pro II 3245").
        if [ "${#hwmons[@]}" -gt 1 ]; then
            label=$(read_sysfs "$hwmon/label")
            who="${label:-WireView Pro II ($(cat "$hwmon/name" 2>/dev/null))}"
        fi
        check_device "$hwmon" "$who"
    done
done
