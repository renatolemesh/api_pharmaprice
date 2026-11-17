# Banco, backup e restauracao

O banco e Postgres 17 (servico `db` do `docker-compose.yml`, imagem
`pgvector/pgvector:pg17`). Ate novembro de 2026 era MySQL 8; ver
"Migracao do MySQL" no fim.

## Backup

Quem faz o backup e o servidor de coleta, nao este repositorio:
`WebScrap/operacao/backup.sh`, no cron do root as 06:00. Ele roda `pg_dump`
dentro do container `api_pharmaprice-db-1`, grava
`/home/chatbot/backups/banco/pharmaprice_AAAA-MM-DD.sql.gz` e manda para o
Google Drive (`gdrive:pharmaprice-backups`) com `rclone`.

Um dump so e guardado se terminar com a linha
`PostgreSQL database dump complete` e passar do tamanho minimo. Sem o marcador,
o arquivo esta truncado e e apagado na hora, em vez de ficar parecendo backup.

`WebScrap/operacao/restaurar.sh` levanta tudo num servidor novo a partir
desses backups.

## Restaurar

### Por cima da base de producao

```bash
cd /home/chatbot/pharmaprice/api_pharmaprice

# 1. parar quem escreve
docker compose stop app nginx

# 2. recriar o banco vazio e restaurar
docker compose exec -T db sh -c 'dropdb -U "$POSTGRES_USER" "$POSTGRES_DB" && createdb -U "$POSTGRES_USER" "$POSTGRES_DB"'
gunzip -c /home/chatbot/backups/banco/pharmaprice_AAAA-MM-DD.sql.gz \
  | docker compose exec -T db sh -c 'psql -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"'

# 3. religar
docker compose start app nginx
```

`ON_ERROR_STOP=1` e o que faz uma restauracao com erro falhar: sem ele o
`psql` segue em frente e sai com sucesso.

### Em paralelo, sem tocar na producao

Para recuperar dados especificos (um preco apagado por engano, por exemplo):

```bash
docker compose exec -T db createdb -U pharmaprice conferencia
gunzip -c ARQUIVO.sql.gz \
  | docker compose exec -T db psql -q -v ON_ERROR_STOP=1 -U pharmaprice -d conferencia
# ... consultar ...
docker compose exec -T db dropdb -U pharmaprice conferencia
```

## Acesso ao banco

Porta e Adminer so escutam em 127.0.0.1 no servidor. Por tunel SSH:

```bash
ssh -L 5434:127.0.0.1:5434 -L 8080:127.0.0.1:8080 root@servidor
# Postgres em localhost:5434, Adminer em http://localhost:8080
```

## Migracao do MySQL (novembro de 2026)

O schema do Postgres vem de `database/migrations/2026_11_18_000000_schema_pharmaprice.php`,
que substituiu as migrations da epoca do MySQL. Os dados foram copiados com:

```bash
docker compose --profile legado up -d mysql-legado   # o MySQL antigo, com os dados de sempre
docker compose exec app php artisan migrate --force
docker compose exec app php artisan banco:copiar-do-mysql
```

O comando confere contagem de linhas e somas de preco tabela a tabela, e sai
com erro se origem e destino nao baterem. `--conferir` repete so a conferencia.

Os dados do MySQL continuam em `.docker/mysql/dbdata`. Backups de antes da
migracao (`.sql.gz` terminando em `Dump completed`) so restauram num MySQL:
subir o `mysql-legado`, restaurar nele e rodar `banco:copiar-do-mysql --substituir`.
