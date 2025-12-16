#!/bin/bash
set -e

# Load environment variables from .env file
set -o allexport
source .env
set +o allexport

# Paths (relative to this script's location)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WEBROOT="$SCRIPT_DIR/webroot"
DEST_DIR="$SCRIPT_DIR/ssl"

# Convert DOMAINS string into array
IFS=' ' read -r -a DOMAIN_ARRAY <<< "$DOMAINS"

echo ">>> Renovando certificados..."
for DOMAIN in "${DOMAIN_ARRAY[@]}"; do
    certbot certonly --webroot -w "$WEBROOT" -d "$DOMAIN" --non-interactive --agree-tos -m "$EMAIL" --deploy-hook "
        cp /etc/letsencrypt/live/$DOMAIN/fullchain.pem $DEST_DIR/${DOMAIN%%.*}-fullchain.pem &&
        cp /etc/letsencrypt/live/$DOMAIN/privkey.pem $DEST_DIR/${DOMAIN%%.*}-privkey.pem &&
        docker exec nginx nginx -s reload
    "
done

echo ">>> Certificados atualizados e nginx recarregado!"
