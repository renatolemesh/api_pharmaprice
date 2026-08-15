# Backup e restauracao

O banco roda em container no host `172.31.254.150`, acessivel apenas por VPN.
A estrategia e **push do proprio host** via `rclone`: o host nao depende de
nenhuma maquina externa estar ligada e conectada na VPN para o backup do dia
acontecer.

Tres coisas precisam ser verdade para isso ser backup de verdade, e cada uma
tem um mecanismo aqui:

| Risco | Mecanismo |
|---|---|
| Dump truncado aceito como valido | `gzip -t` + marcador `Dump completed` + tamanho minimo |
| Perda do host leva o backup junto | `rclone copy` para nuvem a cada execucao |
| Backup para de rodar em silencio | Ping para healthcheck; ausencia de ping vira alerta |
| Backup existe mas nao restaura | `restore_test.sh` mensal restaura de verdade e confere |

---

## Instalacao (uma vez, no host)

```bash
# 1. dependencias
apt-get update && apt-get install -y rclone gnupg curl

# 2. configurar o destino remoto (Backblaze B2 e o mais barato para isto;
#    S3, Drive ou qualquer outro backend do rclone servem igual)
rclone config
#    -> n (new remote) -> nome: b2 -> escolher o provedor -> credenciais
#    -> testar:  rclone lsd b2:

# 3. senha de criptografia do backup
openssl rand -base64 48 > /root/.pharmaprice_backup_pass
chmod 600 /root/.pharmaprice_backup_pass
```

**Copie o conteudo de `/root/.pharmaprice_backup_pass` para fora do servidor
agora** (gerenciador de senhas, cofre, papel na gaveta). Se essa senha existir
apenas na maquina que o backup deveria proteger, o backup nao protege nada.

```bash
# 4. configuracao
cd /home/api/api_pharmaprice
cp backup.env.example backup.env
chmod 600 backup.env
nano backup.env          # ajustar RCLONE_REMOTE e caminhos

chmod +x backup_db.sh restore_test.sh

# 5. primeira execucao manual
./backup_db.sh
```

## Agendamento

```cron
# crontab -e   (como root, no host)

# Backup diario as 03:00. A camada (diario/semanal/mensal) e escolhida sozinha
# pela data: dia 01 vira mensal, domingo vira semanal, o resto e diario.
0 3 * * * /home/api/api_pharmaprice/backup_db.sh >> /home/api/api_pharmaprice/backups/cron.log 2>&1

# Teste de restauracao no dia 5 de cada mes, as 04:00.
0 4 5 * * /home/api/api_pharmaprice/restore_test.sh >> /home/api/api_pharmaprice/backups/restore.log 2>&1

# Scheduler do Laravel (necessario para coletas:desativar-obsoletos rodar).
* * * * * cd /home/api/api_pharmaprice && docker compose exec -T app php artisan schedule:run >> /dev/null 2>&1
```

## Monitoramento

Crie dois checks em <https://healthchecks.io> (plano gratuito basta) e cole as
URLs em `backup.env`:

- `HEALTHCHECK_URL` — periodo diario, folga de 6h
- `HEALTHCHECK_RESTORE_URL` — periodo mensal, folga de 3 dias

O ponto nao e receber aviso quando o backup falha: o script ja loga isso. E
receber aviso quando o backup **para de dar sinal** — servidor desligado, cron
removido, disco cheio. Essa e a falha que passa despercebida por meses.

---

## Restaurar

### Conferir o que existe

```bash
ls -lh backups/{diario,semanal,mensal}/
rclone ls b2:pharmaprice-backups/          # copias remotas
```

### Baixar do remoto (se o host se perdeu)

```bash
rclone copy b2:pharmaprice-backups/diario/webscrap_2026-08-14_03-00-01.sql.gz.gpg ./
```

### Restaurar por cima da base de producao

```bash
cd /home/api/api_pharmaprice

# 1. parar quem escreve, para nao restaurar sobre gravacao concorrente
docker compose stop app nginx

# 2. restaurar
gpg --batch --decrypt --passphrase-file /root/.pharmaprice_backup_pass \
    backups/diario/webscrap_2026-08-14_03-00-01.sql.gz.gpg \
  | gzip -dc \
  | docker compose exec -T db mysql -uroot -p"$SENHA" webscrap

# 3. religar
docker compose start app nginx
```

Se o backup nao estiver cifrado, pule o `gpg` e comece direto no `gzip -dc`.

### Restaurar em paralelo, sem tocar na producao

```bash
docker compose exec -T db mysql -uroot -p"$SENHA" -e "CREATE DATABASE webscrap_conferencia;"
gpg --batch --decrypt --passphrase-file /root/.pharmaprice_backup_pass ARQUIVO.gpg \
  | gzip -dc \
  | docker compose exec -T db mysql -uroot -p"$SENHA" webscrap_conferencia
```

Use isto quando o objetivo for recuperar dados especificos (um preco apagado
por engano, por exemplo) em vez de voltar a base inteira no tempo.

---

## Teste de restauracao

```bash
./restore_test.sh                      # backup mais recente
./restore_test.sh backups/mensal/X.gz  # arquivo especifico
```

Ele cria `webscrap_restore_teste`, restaura, compara a contagem de
`produtos`, `precos`, `farmacias`, `informacoes_produtos` e `links` com a base
viva, confere se as views voltaram, e derruba o banco de teste no fim.

Tabela abaixo de 90% da contagem viva reprova o teste. A base cresce entre o
dump e o teste, entao restaurada menor que viva e normal; **muito** menor
significa dump parcial.

---

## Quando a base crescer

O dump comprimido tem ~23 MB hoje, e `precos` so cresce (uma linha por mudanca
de preco). Quando o dump passar de uns 500 MB, valem duas mudancas:

1. subir `TAMANHO_MINIMO_BYTES` junto — o piso precisa acompanhar a base para
   continuar detectando dump parcial;
2. arquivar `precos` antigo (particionar por ano ou mover historico com mais de
   N anos para uma tabela fria), em vez de dumpar tudo todo dia.
