#!/usr/bin/env bash
# =============================================================================
#  cron-wallet-sync.sh — synchronizace bankovních pohybů z Wallet (BudgetBakers)
#  Frekvence: každých 30 minut (Wallet sám synchronizuje banky několikrát denně)
#  Inkrementální import + automatické párování plateb pro suppliery s tokenem.
#
#  crontab:
#    10,40 * * * *  /var/www/myinvoice.cz/cmd/cron-wallet-sync.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec php "$PROJECT_ROOT/api/bin/cron-wallet-sync.php" "$@" \
    >> "$LOG_DIR/wallet-sync-$(date +%Y-%m-%d).log" 2>&1
