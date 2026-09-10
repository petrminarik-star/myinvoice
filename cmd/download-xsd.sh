#!/usr/bin/env bash
# Stáhne XSD schémata do api/xsd/: EPO MFČR výkazy (DPH/KH/SH/DPFO/DPPO) +
# ISDOC 6.0.2 (formát faktur). EPO se mění typicky 1× ročně (leden), ISDOC
# zřídka. Default check-in má aktuální verze.
#
# Použití:
#   bash cmd/download-xsd.sh           — stáhne všechna schémata (EPO + ISDOC)
#   bash cmd/download-xsd.sh dphkh1    — stáhne jen jedno EPO schema
#   bash cmd/download-xsd.sh isdoc     — stáhne jen ISDOC schema
#
# Zdroje:
#   EPO:   https://adisspr.mfcr.cz/dpr/adis/idpr_pub/epo2_info/popis_struktury_seznam.faces
#   ISDOC: https://mv.gov.cz/isdoc/clanek/aktualni-verze.aspx

set -euo pipefail

DIR="$(cd "$(dirname "$0")/.." && pwd)/api/xsd"
BASE="https://adisspr.mfcr.cz/adis/jepo/schema"
ISDOC_URL="https://isdoc.cz/6.0.2/xsd/isdoc-invoice-6.0.2.xsd"
FORMS=("dphdp3" "dphkh1" "dphshv" "dpfdp5" "dppdp9" "isdoc")

mkdir -p "$DIR"

if [[ $# -gt 0 ]]; then
    FORMS=("$@")
fi

for form in "${FORMS[@]}"; do
    if [[ "$form" == "isdoc" ]]; then
        url="$ISDOC_URL"
        target="${DIR}/isdoc-invoice-6.0.2.xsd"
    else
        url="${BASE}/${form}_epo2.xsd"
        target="${DIR}/${form}.xsd"
    fi
    echo "→ ${form}: ${url}"
    if curl -sSfL "$url" -o "$target.tmp"; then
        # Sanity check: musí začínat XML deklarací
        if head -c 20 "$target.tmp" | grep -q '<?xml'; then
            mv "$target.tmp" "$target"
            size=$(wc -c < "$target")
            echo "  ✓ ${target} (${size} bytes)"
        else
            rm -f "$target.tmp"
            echo "  ✗ ${form}: stažený soubor není XML (možná 404 HTML)"
        fi
    else
        rm -f "$target.tmp" 2>/dev/null || true
        echo "  ✗ ${form}: stažení selhalo"
    fi
done

echo
echo "Hotovo. Schémata v: ${DIR}"
echo "Aplikace je při generování XML automaticky validuje a archivuje výsledek v tax_submissions."
