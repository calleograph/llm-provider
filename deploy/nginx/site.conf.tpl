# managed-by: llm-provider:@@PROJECT@@
# Отдельный site-файл экземпляра «@@PROJECT@@», создан install.sh. Чужие сайты не изменяются.
# Удаление: удалите этот файл и симлинк в sites-enabled, затем nginx -t && systemctl reload nginx.
@@REDIRECT@@
server {
    listen @@LISTEN@@;
    server_name @@SERVER_NAME@@;
@@TLS@@
    client_max_body_size 130m;
    server_tokens off;

    # Голосовой ввод — WebSocket
    location = /ws/dictation {
        proxy_pass http://127.0.0.1:@@APP_PORT@@;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header Origin $http_origin;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 180s;
        proxy_buffering off;
    }

    location / {
        proxy_pass http://127.0.0.1:@@APP_PORT@@;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        # X-Forwarded-For перезаписывается (не дополняется): клиент не может подставить чужой адрес
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 900s;
        proxy_send_timeout 900s;
    }
}
