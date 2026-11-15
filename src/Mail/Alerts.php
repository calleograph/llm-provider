<?php

declare(strict_types=1);

namespace App\Mail;

use App\Config\AppSettings;

/** Уведомления администраторам по почте: не чаще раза в час на один повод (модель, «все недоступны»). */
final class Alerts
{
    public function __construct(private readonly MailSettings $settings, private readonly MailQueue $queue, private readonly AppSettings $app)
    {
    }

    /** @param array<string,string> $vars */
    public function notify(string $template, array $vars, string $throttleKey): void
    {
        if (!$this->settings->isConfigured() || $this->settings->get('alerts_enabled') !== '1') {
            return;
        }
        $key = 'alert_last_' . substr(hash('sha256', $template . '|' . $throttleKey), 0, 16);
        if (time() - (int) $this->app->get($key, '0') < 3600) {
            return;
        }
        $this->app->set($key, (string) time());
        foreach ($this->settings->alertRecipients() as $to) {
            $this->queue->enqueue($template, $to, $vars);
        }
    }
}
