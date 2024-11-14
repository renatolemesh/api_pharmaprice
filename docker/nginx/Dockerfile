FROM nginx:alpine

# Instalar Certbot e dependências
RUN apk add --no-cache \
    certbot \
    certbot-nginx \
    && rm -rf /var/cache/apk/*

# Copiar configurações do Nginx para dentro do container
COPY ./docker/nginx /etc/nginx

# Configuração de arquivos SSL (caso necessário)
COPY ./ssl /etc/nginx/ssl