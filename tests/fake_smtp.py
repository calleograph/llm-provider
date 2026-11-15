"""Поддельный SMTP-сервер для тестов (аналог tests/fixtures/fake_smtp.php): одно соединение в потоке,
сценарий {auth: код, rcpt: код, data: код}; wire — всё, что прислал клиент."""

from __future__ import annotations

import contextlib
import socket
import threading


class FakeSmtp:
    def __init__(self, script: dict[str, int] | None = None):
        self.script = script or {}
        self.wire = ""
        self._srv = socket.create_server(("127.0.0.1", 0))
        self._srv.settimeout(10)
        self.port = self._srv.getsockname()[1]
        self._thread = threading.Thread(target=self._serve, daemon=True)
        self._thread.start()

    def close(self) -> None:
        self._srv.close()
        self._thread.join(5)

    def _serve(self) -> None:
        try:
            conn, _ = self._srv.accept()
        except OSError:
            return
        with conn, conn.makefile("rb") as f, contextlib.suppress(OSError):   # клиент может закрыть, не дождавшись 221
            say = lambda line: conn.sendall((line + "\r\n").encode())  # noqa: E731
            say("220 fake.example.com ESMTP")
            in_data = False
            auth, rcpt = self.script.get("auth", 235), self.script.get("rcpt", 250)
            for raw in f:
                line = raw.decode("utf-8", errors="replace")
                self.wire += line
                cmd = line.strip().upper()
                if in_data:
                    if line.strip() == ".":
                        in_data = False
                        say(f"{self.script.get('data', 250)} queued")
                elif cmd.startswith("EHLO"):
                    say("250-fake.example.com\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME")
                elif cmd.startswith("AUTH PLAIN"):
                    say(f"{auth} ok" if auth == 235 else f"{auth} 5.7.8 Authentication credentials invalid")
                elif cmd.startswith("MAIL FROM"):
                    say("250 ok")
                elif cmd.startswith("RCPT TO"):
                    say(f"{rcpt} ok" if rcpt == 250 else f"{rcpt} 4.7.1 try again later")
                elif cmd == "DATA":
                    in_data = True
                    say("354 go")
                elif cmd == "QUIT":
                    say("221 bye")
                    return
                else:
                    say("250 ok")
