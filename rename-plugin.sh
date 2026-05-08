#!/bin/bash
# rename-plugin.sh
# Rinomina il plugin N-Genius da "ngenius" a "ngenius-embedded"
# per evitare conflitti con il plugin ufficiale.
#
# IMPORTANTE: lanciare dalla root del repo (dove c'è la cartella ngenius/).

set -e  # Stop al primo errore

echo "🔍 Verifico di essere nella cartella giusta..."
if [ ! -d "ngenius" ]; then
  echo "❌ ERRORE: cartella ngenius/ non trovata. Lanciare lo script dalla root del repo."
  exit 1
fi
echo "✅ Cartella ngenius/ trovata."

echo ""
echo "📝 Eseguo find-and-replace nei file PHP, JS, CSS, JSON, txt..."

# Funzione helper per fare sed cross-platform (Mac vs Linux)
# Su Mac sed richiede una stringa vuota dopo -i, su Linux no.
sed_inplace() {
  if [[ "$OSTYPE" == "darwin"* ]]; then
    sed -i '' "$@"
  else
    sed -i "$@"
  fi
}

# Trova tutti i file rilevanti, escludendo vendor/ e node_modules/
FILES=$(find ngenius resources -type f \( \
  -name "*.php" -o \
  -name "*.js" -o \
  -name "*.css" -o \
  -name "*.json" -o \
  -name "*.txt" -o \
  -name "*.md" \
  \) ! -path "*/vendor/*" ! -path "*/node_modules/*")

for file in $FILES; do
  # Constanti (es. NETWORK_INTERNATIONAL_NGENIUS_VERSION → NGENIUS_EMBEDDED_VERSION)
  sed_inplace 's/NETWORK_INTERNATIONAL_NGENIUS_/NGENIUS_EMBEDDED_/g' "$file"

  # Classi (es. NetworkInternationalNgeniusGateway → NgeniusEmbeddedGateway)
  sed_inplace 's/NetworkInternationalNgenius/NgeniusEmbedded/g' "$file"

  # Funzioni minuscole (es. network_international_ngenius_ → ngenius_embedded_)
  sed_inplace 's/network_international_ngenius_/ngenius_embedded_/g' "$file"

  # Gateway ID nel webhook (es. wc-api=ngeniusonline → wc-api=ngenius_embedded)
  sed_inplace 's/ngeniusonline/ngenius_embedded/g' "$file"

  # Plugin display name
  sed_inplace 's/N-Genius Online by Network/N-Genius Embedded/g' "$file"
  sed_inplace 's/N-Genius by Network/N-Genius Embedded/g' "$file"
done

echo "✅ Find-and-replace completato sui file."

echo ""
echo "📁 Rinomino i file PHP che hanno 'network-international-ngenius' nel nome..."

# Rinomina i file PHP
find ngenius -type f -name "*network-international-ngenius*.php" | while read oldfile; do
  newfile=$(echo "$oldfile" | sed 's/network-international-ngenius/ngenius-embedded/g')
  mv "$oldfile" "$newfile"
  echo "  $oldfile → $newfile"
done

echo "✅ File rinominati."

echo ""
echo "📁 Rinomino la cartella ngenius/ in ngenius-embedded/..."
mv ngenius ngenius-embedded
echo "✅ Cartella rinominata."

echo ""
echo "🎉 Rinomina completata!"
echo ""
echo "Prossimi passi:"
echo "  1. Esegui: git status"
echo "     (Vedrai una marea di file modificati/rinominati)"
echo "  2. Esegui: git diff --stat"
echo "     (Riepilogo delle modifiche per file)"
echo "  3. Quando soddisfatto: git add -A && git commit -m 'Rename plugin to ngenius-embedded'"
