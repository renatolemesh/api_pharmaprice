#!/usr/bin/env bash
#
# Teste de restauracao.
#
# Backup nunca testado nao e backup, e fe. Este script pega o backup mais
# recente, restaura num banco descartavel dentro do proprio container e compara
# a contagem das tabelas principais com a base viva. No fim, derruba o banco de
# teste.
#
# Uso:  ./restore_test.sh [caminho_do_backup]
#       sem argumento, usa o backup mais recente encontrado em backups/.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

if [ -f "$SCRIPT_DIR/backup.env" ]; then
    set -a
    # shellcheck disable=SC1091
    . "$SCRIPT_DIR/backup.env"
    set +a
fi

ler_env() {
    [ -f "$SCRIPT_DIR/.env" ] || return 0
    grep -E "^$1=" "$SCRIPT_DIR/.env" | head -1 | cut -d= -f2- | tr -d '"'"'"
}

DB_NAME="${DB_DATABASE:-$(ler_env DB_DATABASE)}"
DB_NAME="${DB_NAME:-webscrap}"
DB_USER="${BACKUP_DB_USER:-root}"
DB_PASS="${DB_PASSWORD:-$(ler_env DB_PASSWORD)}"
BACKUP_DIR="${BACKUP_DIR:-$SCRIPT_DIR/backups}"
PASSPHRASE_FILE="${BACKUP_PASSPHRASE_FILE:-}"
HEALTHCHECK_RESTORE_URL="${HEALTHCHECK_RESTORE_URL:-}"

DB_TESTE="${DB_NAME}_restore_teste"
TABELAS=(produtos precos farmacias informacoes_produtos links)

log() { printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }

falhar() {
    log "FALHA: $*"
    if [ -n "$HEALTHCHECK_RESTORE_URL" ]; then
        curl -fsS -m 10 --data-raw "$*" "${HEALTHCHECK_RESTORE_URL}/fail" >/dev/null 2>&1 || true
    fi
    exit 1
}

mysql_teste() {
    docker compose exec -T db mysql -u"$DB_USER" -p"$DB_PASS" --default-character-set=utf8mb4 "$@"
}

# ------------------------------------------------------------- escolher backup

ARQUIVO="${1:-}"
if [ -z "$ARQUIVO" ]; then
    ARQUIVO="$(find "$BACKUP_DIR" -type f -name '*.sql.gz*' -printf '%T@ %p\n' \
        | sort -rn | head -1 | cut -d' ' -f2-)"
fi

[ -n "$ARQUIVO" ] && [ -f "$ARQUIVO" ] || falhar "nenhum backup encontrado em $BACKUP_DIR"

log "Testando restauracao de: $ARQUIVO"

CONTAINER_DB="$(docker compose ps -q db)"
[ -n "$CONTAINER_DB" ] || falhar "container do banco nao esta rodando"

# --------------------------------------------------------------- restaurar

limpar() {
    log "Removendo banco de teste ${DB_TESTE}..."
    mysql_teste -e "DROP DATABASE IF EXISTS \`${DB_TESTE}\`;" >/dev/null 2>&1 || true
}
trap limpar EXIT

log "Criando banco de teste ${DB_TESTE}..."
mysql_teste -e "DROP DATABASE IF EXISTS \`${DB_TESTE}\`; CREATE DATABASE \`${DB_TESTE}\` CHARACTER SET utf8mb4;"

log "Restaurando (pode demorar)..."
if [[ "$ARQUIVO" == *.gpg ]]; then
    [ -n "$PASSPHRASE_FILE" ] || falhar "backup cifrado mas BACKUP_PASSPHRASE_FILE nao configurado"
    gpg --batch --quiet --decrypt --passphrase-file "$PASSPHRASE_FILE" "$ARQUIVO" \
        | gzip -dc | mysql_teste "$DB_TESTE"
else
    gzip -dc "$ARQUIVO" | mysql_teste "$DB_TESTE"
fi

# --------------------------------------------------------------- conferir

log "Comparando contagens com a base viva..."

contar() {
    mysql_teste -N -B -e "SELECT COUNT(*) FROM \`$1\`.\`$2\`;" 2>/dev/null || echo "erro"
}

PROBLEMAS=0
printf '\n%-25s %14s %14s   %s\n' "TABELA" "VIVA" "RESTAURADA" "RESULTADO"
printf '%s\n' "-------------------------------------------------------------------------"

for tabela in "${TABELAS[@]}"; do
    vivo="$(contar "$DB_NAME" "$tabela")"
    restaurado="$(contar "$DB_TESTE" "$tabela")"

    if [ "$restaurado" = "erro" ] || [ -z "$restaurado" ]; then
        resultado="AUSENTE"
        PROBLEMAS=$((PROBLEMAS + 1))
    elif [ "$restaurado" -eq 0 ] 2>/dev/null; then
        resultado="VAZIA"
        PROBLEMAS=$((PROBLEMAS + 1))
    elif [ "$vivo" = "erro" ]; then
        resultado="ok (sem base viva para comparar)"
    else
        # A base viva cresce entre o dump e agora, entao restaurada <= viva e o
        # esperado. O que nao pode e faltar volume: abaixo de 90% indica dump
        # parcial, nao crescimento normal.
        minimo=$((vivo * 90 / 100))
        if [ "$restaurado" -lt "$minimo" ]; then
            resultado="SUSPEITO (<90% da viva)"
            PROBLEMAS=$((PROBLEMAS + 1))
        else
            resultado="ok"
        fi
    fi

    printf '%-25s %14s %14s   %s\n' "$tabela" "$vivo" "$restaurado" "$resultado"
done

echo

# A view precisa existir tambem: e dela que /api/precos depende.
VIEWS="$(mysql_teste -N -B -e "SELECT COUNT(*) FROM information_schema.views WHERE table_schema='${DB_TESTE}';")"
if [ "$VIEWS" -lt 1 ]; then
    log "AVISO: nenhuma view restaurada (latest_precos_view e usada por /api/precos)"
    PROBLEMAS=$((PROBLEMAS + 1))
else
    log "Views restauradas: $VIEWS"
fi

if [ "$PROBLEMAS" -gt 0 ]; then
    falhar "restauracao com $PROBLEMAS problema(s) - o backup nao esta confiavel"
fi

log "Restauracao validada com sucesso."

if [ -n "$HEALTHCHECK_RESTORE_URL" ]; then
    curl -fsS -m 10 --data-raw "restore ok: $(basename "$ARQUIVO")" \
        "$HEALTHCHECK_RESTORE_URL" >/dev/null 2>&1 || true
fi
