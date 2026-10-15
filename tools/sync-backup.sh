#!/bin/bash
# sync-backup.sh — mirror the working tree to /home/user/BACKUP.
#
# The operator asked for every artefact to exist in two places, because in past
# sessions work was lost to a bad glob or an over-eager clean. Git is the primary
# safety net; this is the second, and it is deliberately dumb: rsync the whole
# tree, then write a manifest with the commit and a checksum, so a restore can be
# verified rather than hoped for.
#
# Usage:  bash tools/sync-backup.sh [label]
#
# Excludes the same generated/dependency directories the workspace snapshot does,
# plus the runtime dirs that hold operator secrets (.admin.key, .*.key, var/).
# Those are NOT backed up on purpose: a backup directory is not a secret store,
# and copying keys into a second location doubles the places they can leak from.

set -euo pipefail

SRC="${SRC:-/home/user/revoap}"
DST="${DST:-/home/user/BACKUP/revoap}"
LABEL="${1:-snapshot}"

mkdir -p "$DST"

EXCLUDES=(
    --exclude '.git/'
    --exclude 'node_modules/'
    --exclude 'vendor/'
    --exclude 'var/'
    --exclude 'runtime/'
    --exclude 'cache/*'
    --exclude 'logs/*'
    --exclude '.admin.key'
    --exclude '.bothub.key'
    --exclude '.openrouter.key'
    --exclude '.openai.key'
    --exclude '.anthropic.key'
    --exclude '*.key'
    --exclude '.env'
    --exclude '.httpauth'
    --exclude '.config.php'
    --exclude '.sql.php'
    --exclude '.Debian/'
    --exclude '.dev_agent/'
    --exclude '.zwork/'
    --exclude '.ragraf/'
    --exclude '.pytest_cache/'
    --exclude '__pycache__/'
    --exclude 'assets/dist/revoap.dev.css'
)

# rsync intermittently fails with `change_dir "$SRC": No such file or directory`
# inside this sandbox even though the directory exists and the identical command
# succeeds inline. `cp -a` does not. So the copy is `cp -a` followed by an
# explicit prune of the excluded material — two steps instead of one, but
# deterministic, and the verification below proves the result either way.
#
# Order matters: copy everything, then delete what must not be mirrored. Pruning
# first and copying second would need the exclusion list expressed twice (once
# for cp, once for the pruner) and the two would drift.
rm -rf "$DST"
mkdir -p "$DST"
cp -a "$SRC/." "$DST/" 2>/dev/null || true

# Prune: VCS metadata, dependencies, build output, runtime state, and every piece
# of secret material. A backup directory is not a secret store — mirroring
# .admin.key or .*.key would double the places a key can leak from, and backups
# are exactly the artefact that ends up on a laptop or in an object store.
PRUNE=(
    '.git' 'node_modules' 'vendor' 'var' 'runtime'
    '.admin.key' '.bothub.key' '.openrouter.key' '.openai.key' '.anthropic.key'
    '.env' '.httpauth' '.config.php' '.sql.php'
    '.Debian' '.dev_agent' '.zwork' '.ragraf' '.pytest_cache'
)
for name in "${PRUNE[@]}"; do
    rm -rf "$DST/$name"
done
# Wildcard prunes, which the array above cannot express.
find "$DST" -name '*.key' -delete 2>/dev/null || true
find "$DST" -name '__pycache__' -type d -prune -exec rm -rf {} + 2>/dev/null || true
find "$DST" -path '*/cache/*' ! -name '.htaccess' ! -name '.gitkeep' -type f -delete 2>/dev/null || true
find "$DST" -path '*/logs/*' ! -name '.gitkeep' -type f -delete 2>/dev/null || true
rm -f "$DST/assets/dist/revoap.dev.css"

# Verification. An unverifiable backup is worse than none, because it is
# believed: check the tree's identity files are present, that the count is
# plausible, and that nothing excluded survived.
copied="$(find "$DST" -type f 2>/dev/null | wc -l | tr -d ' ')"
if [ "$copied" -lt 50 ]; then
    echo "backup FAILED: only $copied files copied" >&2
    exit 1
fi
for must in composer.json index.php config.php ui/tokens.css i18n/en.json assets/dist/revoap.css; do
    if [ ! -f "$DST/$must" ]; then
        echo "backup incomplete: missing $must" >&2
        exit 1
    fi
done
leaked="$(find "$DST" \( -name '.git' -o -name '*.key' -o -name '.env' -o -name '.httpauth' -o -name '.config.php' \) -print 2>/dev/null | head -3)"
if [ -n "$leaked" ]; then
    echo "backup FAILED: excluded material survived: $leaked" >&2
    exit 1
fi

# Manifest: what was copied, from which commit, and a checksum to verify a
# restore against. Without this a backup is a pile of files with no provenance.
COMMIT="$(cd "$SRC" && git rev-parse --short HEAD 2>/dev/null || echo unknown)"
BRANCH="$(cd "$SRC" && git branch --show-current 2>/dev/null || echo unknown)"
DIRTY="$(cd "$SRC" && git status --porcelain 2>/dev/null | wc -l | tr -d ' ')"
FILES="$(find "$DST" -type f | wc -l | tr -d ' ')"
BYTES="$(du -sb "$DST" 2>/dev/null | cut -f1)"

cat > "$DST/BACKUP-MANIFEST.txt" <<EOF
RevoAp backup manifest
----------------------
label        : $LABEL
taken_at     : $(date -u +%Y-%m-%dT%H:%M:%SZ)
source       : $SRC
branch       : $BRANCH
commit       : $COMMIT
dirty_files  : $DIRTY
files_copied : $FILES
bytes        : $BYTES
excluded     : git metadata, node_modules, vendor, var/, runtime/, cache/*,
                logs/*, and every secret material (.admin.key, .*key, .env,
                .httpauth, .config.php, .sql.php). Secrets are deliberately NOT
                mirrored: a backup directory is not a secret store.
EOF

# A checksum over the tracked-file list, so a restore can be compared cheaply.
( cd "$DST" && find . -type f ! -name BACKUP-MANIFEST.txt -print0 \
    | sort -z | xargs -0 sha256sum 2>/dev/null | sha256sum | cut -d' ' -f1 ) \
    > "$DST/BACKUP-CHECKSUM.txt" 2>/dev/null || echo "unavailable" > "$DST/BACKUP-CHECKSUM.txt"

echo "backup ok: $DST"
echo "  commit=$COMMIT branch=$BRANCH dirty=$DIRTY files=$FILES checksum=$(cat "$DST/BACKUP-CHECKSUM.txt")"
