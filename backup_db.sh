#!/usr/bin/env bash
#
# Backup do banco com retencao avo-pai-filho e copia fora do host.
#
# O que este script assume, e que a versao anterior nao fazia:
#
#   - dump que termina com exit 0 pode estar truncado. Aqui ele e verificado
#     (gzip -t + marcador "Dump completed" + tamanho minimo) ANTES de contar
#     como backup valido e antes de qualquer expurgo;
#   - copia no mesmo disco do banco nao e backup. So conta depois de subir;
#   - backup que para de rodar em silencio e pior que backup nenhum, porque
#     produz confianca falsa. Por isso o ping de saude no fim.
#
# Configuracao: copie backup.env.example para backup.env e ajuste.
# Restauracao e teste de restauracao: veja BACKUP.md e restore_test.sh.

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
DB_USER="${BACKUP_DB_USER:-root}"
DB_PASS="${DB_PASSWORD:-$(ler_env DB_PASSWORD)}"
DB_NAME="${DB_NAME:-webscrap}"

BACKUP_DIR="${BACKUP_DIR:-$SCRIPT_DIR/backups}"
LOG_FILE="${BACKUP_LOG:-$BACKUP_DIR/backup.log}"
RETENCAO_DIARIA="${RETENCAO_DIARIA:-7}"
RETENCAO_SEMANAL="${RETENCAO_SEMANAL:-4}"
RETENCAO_MENSAL="${RETENCAO_MENSAL:-6}"
TAMANHO_MINIMO_BYTES="${TAMANHO_MINIMO_BYTES:-1000000}"
RCLONE_REMOTE="${RCLONE_REMOTE:-}"
HEALTHCHECK_URL="${HEALTHCHECK_URL:-}"
PASSPHRASE_FILE="${BACKUP_PASSPHRASE_FILE:-}"
PERMITIR_TEXTO_CLARO="${BACKUP_PERMITIR_TEXTO_CLARO:-0}"

mkdir -p "$BACKUP_DIR"/{diario,semanal,mensal}

log() {
    printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" | tee -a "$LOG_FILE"
}

avisar_falha() {
    local motivo="$1"
    log "ERRO: $motivo"
    if [ -n "$HEALTHCHECK_URL" ]; then
        curl -fsS -m 10 --data-raw "$motivo" "${HEALTHCHECK_URL}/fail" >/dev/null 2>&1 || true
    fi
}

trap 'avisar_falha "backup abortado na linha $LINENO"' ERR

# Uma execucao por vez: o cron nao pode empilhar dumps se um dia demorar mais
# que o intervalo.
exec 9>"$BACKUP_DIR/.backup.lock"
if ! flock -n 9; then
    log "Outro backup em andamento. Saindo."
    exit 0
fi

# --------------------------------------------------------------- camada

DIA_DO_MES="$(date +%d)"
DIA_DA_SEMANA="$(date +%u)"   # 7 = domingo

if [ "$DIA_DO_MES" = "01" ]; then
    CAMADA="mensal"
    RETENCAO="$RETENCAO_MENSAL"
elif [ "$DIA_DA_SEMANA" = "7" ]; then
    CAMADA="semanal"
    RETENCAO="$RETENCAO_SEMANAL"
else
    CAMADA="diario"
    RETENCAO="$RETENCAO_DIARIA"
fi

DATA="$(date +%Y-%m-%d_%H-%M-%S)"
TMP_FILE="$BACKUP_DIR/.${DB_NAME}_${DATA}.sql.gz.parcial"

# Restos de execucoes que morreram no meio. Sao dumps incompletos: ocupam disco
# e nunca serao promovidos a backup.
find "$BACKUP_DIR" -maxdepth 1 -name '*.parcial' -delete 2>/dev/null || true
NOME_FINAL="${DB_NAME}_${DATA}.sql.gz"

log "=== Backup ${DB_NAME} (camada: ${CAMADA}) ==="

# ----------------------------------------------------------------- dump

CONTAINER_DB="$(docker compose ps -q db)"
if [ -z "$CONTAINER_DB" ]; then
    avisar_falha "container do banco nao esta rodando"
    exit 1
fi

log "Gerando dump..."
set +e
docker compose exec -T db mysqldump \
    -u"$DB_USER" \
    -p"$DB_PASS" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --events \
    --no-tablespaces \
    --default-character-set=utf8mb4 \
    "$DB_NAME" 2>"$BACKUP_DIR/.mysqldump.err" | gzip > "$TMP_FILE"
# O array inteiro de uma vez: qualquer atribuicao intermediaria (inclusive
# STATUS_DUMP=${PIPESTATUS[0]}) ja reescreve PIPESTATUS, e sob `set -u` a
# leitura seguinte morre com "unbound variable".
STATUS=("${PIPESTATUS[@]}")
STATUS_DUMP=${STATUS[0]}
STATUS_GZIP=${STATUS[1]}
set -e

if [ "$STATUS_DUMP" -ne 0 ] || [ "$STATUS_GZIP" -ne 0 ]; then
    rm -f "$TMP_FILE"
    avisar_falha "mysqldump falhou: $(tail -3 "$BACKUP_DIR/.mysqldump.err" | tr '\n' ' ')"
    exit 1
fi

# --------------------------------------------------------------- verificacao

log "Verificando integridade..."

if ! gzip -t "$TMP_FILE" 2>/dev/null; then
    rm -f "$TMP_FILE"
    avisar_falha "arquivo gzip corrompido"
    exit 1
fi

TAMANHO="$(stat -c%s "$TMP_FILE")"
if [ "$TAMANHO" -lt "$TAMANHO_MINIMO_BYTES" ]; then
    rm -f "$TMP_FILE"
    avisar_falha "dump menor que o minimo esperado (${TAMANHO} bytes < ${TAMANHO_MINIMO_BYTES})"
    exit 1
fi

# mysqldump interrompido no meio produz um .sql valido ate onde escreveu. O
# marcador final e a unica prova barata de que o dump chegou ao fim.
if ! gzip -dc "$TMP_FILE" | tail -c 2048 | grep -q 'Dump completed'; then
    rm -f "$TMP_FILE"
    avisar_falha "dump truncado: marcador 'Dump completed' ausente"
    exit 1
fi

log "Dump integro: $(du -h "$TMP_FILE" | cut -f1)"

# --------------------------------------------------------------- criptografia

if [ -n "$PASSPHRASE_FILE" ]; then
    if [ ! -r "$PASSPHRASE_FILE" ]; then
        rm -f "$TMP_FILE"
        avisar_falha "BACKUP_PASSPHRASE_FILE definido mas ilegivel: $PASSPHRASE_FILE"
        exit 1
    fi
    log "Cifrando..."
    gpg --batch --yes --symmetric --cipher-algo AES256 \
        --passphrase-file "$PASSPHRASE_FILE" \
        --output "${TMP_FILE}.gpg" "$TMP_FILE"
    rm -f "$TMP_FILE"
    TMP_FILE="${TMP_FILE}.gpg"
    NOME_FINAL="${NOME_FINAL}.gpg"
elif [ -n "$RCLONE_REMOTE" ] && [ "$PERMITIR_TEXTO_CLARO" != "1" ]; then
    # A base inteira indo para um terceiro sem cifra precisa ser escolha
    # explicita, nao esquecimento de configuracao.
    rm -f "$TMP_FILE"
    avisar_falha "envio para nuvem sem criptografia bloqueado. Defina BACKUP_PASSPHRASE_FILE ou BACKUP_PERMITIR_TEXTO_CLARO=1"
    exit 1
fi

ARQUIVO="$BACKUP_DIR/$CAMADA/$NOME_FINAL"
mv "$TMP_FILE" "$ARQUIVO"
log "Backup local: $ARQUIVO"

# --------------------------------------------------------------- envio

ENVIADO=0
if [ -n "$RCLONE_REMOTE" ]; then
    log "Enviando para ${RCLONE_REMOTE}/${CAMADA}/ ..."
    if rclone copy "$ARQUIVO" "${RCLONE_REMOTE}/${CAMADA}/" \
        --transfers 1 --retries 3 --low-level-retries 10 --stats-one-line; then
        ENVIADO=1
        log "Envio concluido."
    else
        # Nao aborta: o backup local existe e e melhor que nada. Mas o alarme
        # dispara, porque copia no mesmo host nao protege contra perda do host.
        avisar_falha "falha ao enviar backup para ${RCLONE_REMOTE} (copia local preservada em ${ARQUIVO})"
        exit 1
    fi
else
    log "AVISO: RCLONE_REMOTE nao configurado. Backup existe apenas neste host."
fi

# --------------------------------------------------------------- expurgo

expurgar_local() {
    local dir="$1" manter="$2"
    local total
    total="$(find "$dir" -maxdepth 1 -type f -name '*.sql.gz*' | wc -l)"
    if [ "$total" -le "$manter" ]; then
        return 0
    fi
    find "$dir" -maxdepth 1 -type f -name '*.sql.gz*' -printf '%T@ %p\n' \
        | sort -rn | tail -n +$((manter + 1)) | cut -d' ' -f2- \
        | while read -r antigo; do
            log "Expurgando local: $(basename "$antigo")"
            rm -f "$antigo"
        done
}

expurgar_local "$BACKUP_DIR/diario" "$RETENCAO_DIARIA"
expurgar_local "$BACKUP_DIR/semanal" "$RETENCAO_SEMANAL"
expurgar_local "$BACKUP_DIR/mensal" "$RETENCAO_MENSAL"

if [ "$ENVIADO" = "1" ]; then
    # `rclone delete --min-age` em vez de `sync`: sync espelha o local, entao um
    # acidente que esvazie o diretorio local apagaria o remoto junto.
    rclone delete "${RCLONE_REMOTE}/diario"  --min-age "$((RETENCAO_DIARIA * 24 + 24))h"  2>/dev/null || true
    rclone delete "${RCLONE_REMOTE}/semanal" --min-age "$((RETENCAO_SEMANAL * 7 + 1))d"   2>/dev/null || true
    rclone delete "${RCLONE_REMOTE}/mensal"  --min-age "$((RETENCAO_MENSAL * 31 + 1))d"   2>/dev/null || true
fi

# --------------------------------------------------------------- fim

trap - ERR

if [ -n "$HEALTHCHECK_URL" ]; then
    curl -fsS -m 10 --retry 3 \
        --data-raw "camada=${CAMADA} tamanho=$(du -h "$ARQUIVO" | cut -f1) enviado=${ENVIADO}" \
        "$HEALTHCHECK_URL" >/dev/null 2>&1 || log "AVISO: ping de saude falhou"
fi

log "=== Backup concluido: $(basename "$ARQUIVO") ($(du -h "$ARQUIVO" | cut -f1)) ==="
