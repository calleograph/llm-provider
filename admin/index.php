<?php

declare(strict_types=1);

/**
 * Точка входа административной панели в корне проекта — тонкий шлюз к
 * public/admin/index.php, см. пояснение в /index.php и README.
 *
 * Физически должен находиться именно в подкаталоге admin/ рядом с корневым
 * index.php (а не в самом корне), чтобы относительные ссылки внутри HTML
 * (index.php?section=..., ../assets/...) резолвились браузером корректно
 * относительно фактического URL /admin/index.php.
 */

require __DIR__ . '/../public/admin/index.php';
