#!/bin/bash
# deploy-production.sh (FSBHOA AC Core): deploys all the access control plugins on this server.
# ~/deploy-production.sh is a link to this file. Safe to run again: it's the routine update too.
#
#   1. Clones each plugin's repo into this user's home, or pulls it (--no-pull: deploy what's
#      checked out, e.g. after a quick fix made here).
#   2. Links each plugin folder into WordPress (links, so a quick fix works in place).
#   3. Runs each service plugin's "build.sh install": builds the Go services, installs them and
#      their systemd units, restarts what changed.
#   4. Lists plugins that aren't active (it never activates one) and the services' state.
#
# The service configs (/var/lib/fsbhoa/*.json) come from the plugins' settings pages, not from
# here. While the V1 plugin (fsbhoa-access-control) is active it only builds, and installs
# nothing: the V2 cutover replaces V1.
#
# Usage: ~/deploy-production.sh [--no-pull]
# First time on a server: git clone <core repo> ~/fsbhoa_ac_core && ~/fsbhoa_ac_core/deploy-production.sh
set -euo pipefail

REPO_DIR="$HOME"
WP_DIR=/var/www/html
PLUGINS_DIR="$WP_DIR/wp-content/plugins"
GIT_BASE="${FSBHOA_GIT_BASE:-git@github.com:dkeeney}"
V1_PLUGIN=fsbhoa-access-control

# repo | WordPress plugin folder | "services" if it has a build.sh (core first: the others use it)
REPOS=(
    "fsbhoa_ac_core|fsbhoa_ac_core|services"
    "fsbhoa_ac_uhppote|fsbhoa_ac_uhppote|services"
    "fsbhoa_ac_kiosk|fsbhoa_ac_kiosk|services"
    "fsbhoa_ac_zebra|fsbhoa_ac_zebra|services"
    "fsbhoa_ac_vehicle|fsbhoa_ac_vehicle|services"
    "fsbhoa_ac_doorking|fsbhoa_ac_doorking|"
    "fsbhoa_ac_import|fsbhoa-ac-import|"
)

PULL=1
case "${1:-}" in
    "") ;;
    --no-pull) PULL=0 ;;
    *) echo "Usage: $0 [--no-pull]"; exit 1 ;;
esac
if [ "$(id -u)" -eq 0 ]; then
    echo "Run this as the user the services run as (it uses sudo itself), not as root."
    exit 1
fi
for cmd in git go wp; do
    command -v "$cmd" >/dev/null || { echo "$cmd is not installed (wp: wp-cli, https://wp-cli.org)."; exit 1; }
done
[ -d "$PLUGINS_DIR" ] || { echo "No WordPress at $WP_DIR."; exit 1; }
wpc() { wp --path="$WP_DIR" --skip-plugins --skip-themes "$@"; }

echo "=== FSBHOA access control deployment on $(hostname), as $(id -un) ==="

echo ""
echo "--- 1. Repositories ---"
for r in "${REPOS[@]}"; do
    IFS='|' read -r repo folder services <<< "$r"
    dir="$REPO_DIR/$repo"
    if [ ! -d "$dir/.git" ]; then
        echo "   [CLONE] $repo"
        git clone -q "$GIT_BASE/$repo.git" "$dir"
        continue
    fi
    dirty="$(git -C "$dir" status --porcelain --untracked-files=no)"
    if [ -n "$dirty" ]; then
        echo "   [LOCAL] $repo has changes made here (commit them to the repo too):"
        echo "$dirty" | sed 's/^/           /'
    fi
    if [ "$PULL" = 1 ]; then
        if ! out="$(git -C "$dir" pull -q --ff-only 2>&1)"; then
            echo "   [FAIL] $repo could not be pulled:"
            echo "$out" | sed 's/^/           /'
            echo "Resolve it in $dir (or run with --no-pull), then run this again."
            exit 1
        fi
    fi
    echo "   [OK] $repo at $(git -C "$dir" log -1 --format='%h %s' | cut -c1-70)"
done

if wpc plugin is-active "$V1_PLUGIN" 2>/dev/null; then
    echo ""
    echo "--- V1 ($V1_PLUGIN) is active: building only, nothing is installed ---"
    for r in "${REPOS[@]}"; do
        IFS='|' read -r repo folder services <<< "$r"
        if [ -n "$services" ]; then "$REPO_DIR/$repo/build.sh"; fi
    done
    echo ""
    echo "Everything builds. The V2 cutover installs it and replaces V1."
    exit 0
fi

echo ""
echo "--- 2. WordPress plugin links ---"
for r in "${REPOS[@]}"; do
    IFS='|' read -r repo folder services <<< "$r"
    link="$PLUGINS_DIR/$folder"
    if [ -L "$link" ]; then
        if [ "$(readlink -f "$link")" != "$(readlink -f "$REPO_DIR/$repo")" ]; then
            echo "   [FAIL] $link points to $(readlink "$link"), not $REPO_DIR/$repo. Fix it by hand."
            exit 1
        fi
        echo "   [OK] $folder"
    elif [ -e "$link" ]; then
        echo "   [FAIL] $link is a folder, not a link. Move it away by hand."
        exit 1
    else
        sudo ln -s "$REPO_DIR/$repo" "$link"
        echo "   [LINK] $folder -> $REPO_DIR/$repo"
    fi
done

echo ""
echo "--- 3. Services ---"
for r in "${REPOS[@]}"; do
    IFS='|' read -r repo folder services <<< "$r"
    if [ -n "$services" ]; then "$REPO_DIR/$repo/build.sh" install; fi
done

echo ""
echo "--- 4. Check ---"
inactive=0
for r in "${REPOS[@]}"; do
    IFS='|' read -r repo folder services <<< "$r"
    if ! wpc plugin is-active "$folder" 2>/dev/null; then
        echo "   [INACTIVE] plugin $folder: activate it in WordPress (Plugins page)"
        inactive=1
    fi
done
if [ "$inactive" = 0 ]; then echo "   All plugins active"; fi
systemctl list-units 'fsbhoa_*' --type=service --all --no-legend --plain | awk '{printf "   %-24s %s %s\n", $1, $3, $4}'

# ~/deploy-production.sh is a link to this file
self="$(readlink -f "${BASH_SOURCE[0]}")"
if [ "$(readlink -f "$HOME/deploy-production.sh" 2>/dev/null)" != "$self" ]; then
    [ -e "$HOME/deploy-production.sh" ] && mv "$HOME/deploy-production.sh" "$HOME/deploy-production.sh.old"
    ln -s "$self" "$HOME/deploy-production.sh"
    echo "   ~/deploy-production.sh now links to $self"
fi
echo ""
echo "=== Deployment done ==="
