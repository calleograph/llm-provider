<?php

declare(strict_types=1);

// Шлюз первого запуска для конфигураций, где document root временно указывает
// на корень проекта. В обычной схеме root .../public используется исходный
// public/admin/setup.php.
require __DIR__ . '/../public/admin/setup.php';
