"""Почта: настройки SMTP, поэтапный клиент, очередь писем, уведомления администраторам (перенос src/Mail)."""

from .alerts import Alerts
from .queue import MailQueue
from .settings import MailSettings
from .smtp import SmtpClient, SmtpException

__all__ = ["Alerts", "MailQueue", "MailSettings", "SmtpClient", "SmtpException"]
