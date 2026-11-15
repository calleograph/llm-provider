<?php

declare(strict_types=1);

namespace App\Llm\Backup;

use App\Config\LdapConfigRepository;
use App\Config\LdapConfigValidator;
use App\Llm\Access\AccessPolicy;
use App\Llm\Chat\ChatSettings;
use App\Llm\Domain\DomainGroupRepository;
use App\Llm\Domain\LlmDomainRepository;
use App\Llm\Gateway\AdapterRegistry;
use App\Llm\Provider\ProviderRepository;
use App\Llm\Provider\ProviderValidator;
use PDO;

/**
 * Экспорт/восстановление настроек LLM-чата (домены+группы, нейросети,
 * права доступа, общие параметры чата) в один ZIP-архив с JSON внутри.
 *
 * Секреты (пароли служебных учёток LDAP, API-ключи нейросетей,
 * дополнительные HTTP-заголовки) в архив никогда не попадают — после
 * восстановления администратор вводит их заново вручную. Это осознанное
 * ограничение, а не недоработка: архив может передаваться между
 * серверами и не должен становиться носителем секретов.
 *
 * Импорт — upsert по бизнес-ключам (name/domain/model), а не по числовым
 * id, поэтому восстановление работает и на пустой БД, и поверх уже
 * настроенного стенда.
 */
final class ConfigBackupService
{
    private const FORMAT = 'llm_config_backup';
    private const VERSION = 1;

    public function __construct(
        private readonly PDO $pdo,
        private readonly LdapConfigRepository $ldapConfigs,
        private readonly LlmDomainRepository $llmDomains,
        private readonly DomainGroupRepository $groups,
        private readonly ProviderRepository $providers,
        private readonly AccessPolicy $access,
        private readonly ChatSettings $chatSettings,
        private readonly AdapterRegistry $adapters,
    ) {
    }

    /** ZIP-архив с config.json внутри, готовый к отдаче через download. */
    public function exportZip(): string
    {
        $data = $this->buildExportData();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $tmpPath = tempnam(sys_get_temp_dir(), 'llm_backup_');
        if ($tmpPath === false) {
            throw new ConfigBackupException('Не удалось создать временный файл архива.');
        }
        @unlink($tmpPath);
        $tmpPath .= '.zip';

        $zip = new \ZipArchive();
        if ($zip->open($tmpPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new ConfigBackupException('Не удалось создать ZIP-архив.');
        }
        $zip->addFromString('config.json', $json);
        $zip->setArchiveComment('LLM chat config backup, exported_at=' . $data['exported_at']);
        $zip->close();

        $content = file_get_contents($tmpPath);
        @unlink($tmpPath);
        if ($content === false) {
            throw new ConfigBackupException('Не удалось прочитать созданный архив.');
        }

        return $content;
    }

    /** @return array<string,mixed> */
    private function buildExportData(): array
    {
        $domains = [];
        foreach ($this->llmDomains->all() as $domain) {
            $config = $domain->config;
            $domains[] = [
                'name' => $config->name,
                'ldaps_url' => $config->ldapsUrl,
                'host' => $config->host,
                'port' => $config->port,
                'base_dn' => $config->baseDn,
                'domain' => $config->domain,
                'netbios_domain' => $config->netbiosDomain,
                'admin_group_dn' => $config->adminGroupDn,
                'protocol_version' => $config->protocolVersion,
                'starttls' => $config->startTls,
                'network_timeout' => $config->networkTimeout,
                'ca_bundle_file' => $config->caBundleFile,
                'llm_enabled' => $domain->llmEnabled,
                'service_username' => $domain->serviceUsername,
                'groups' => array_map(
                    static fn (array $g): array => [
                        'display_name' => (string) $g['display_name'],
                        'group_ref' => (string) $g['group_ref'],
                        'is_enabled' => (bool) $g['is_enabled'],
                        'allow_unanonymized' => (bool) ($g['allow_unanonymized'] ?? false),
                    ],
                    $this->groups->forDomain($config->id),
                ),
            ];
        }

        $providers = [];
        $providerNameById = [];
        foreach ($this->providers->all() as $row) {
            $providerNameById[(int) $row['id']] = (string) $row['name'];
            $providers[] = [
                'name' => (string) $row['name'],
                'provider_type' => (string) $row['provider_type'],
                'base_url' => (string) $row['base_url'],
                'endpoint' => (string) $row['endpoint'],
                'model' => (string) $row['model'],
                'api_version' => $row['api_version'] !== null ? (string) $row['api_version'] : '',
                'connect_timeout' => (int) $row['connect_timeout'],
                'response_timeout' => (int) $row['response_timeout'],
                'max_tokens' => (int) $row['max_tokens'],
                'routing_provider' => $row['routing_provider'] !== null ? (string) $row['routing_provider'] : '',
                'sort_by_price' => (bool) ($row['sort_by_price'] ?? false),
                'max_price_per_mtoken' => isset($row['max_price_per_mtoken']) && $row['max_price_per_mtoken'] !== null ? (float) $row['max_price_per_mtoken'] : null,
                'allow_fallbacks' => (bool) ($row['allow_fallbacks'] ?? true),
                'use_corporate_ca' => (bool) $row['use_corporate_ca'],
                'is_enabled' => (bool) $row['is_enabled'],
                'is_default' => (bool) ($row['is_default'] ?? false),
            ];
        }

        $groupKeyById = [];
        foreach ($this->groups->all() as $g) {
            $groupKeyById[(int) $g['id']] = [
                'domain_fqdn' => (string) $g['domain_fqdn'],
                'group_ref' => (string) $g['group_ref'],
            ];
        }

        $accessMatrix = [];
        foreach ($this->access->matrix() as $groupId => $providerIds) {
            if (!isset($groupKeyById[$groupId])) {
                continue;
            }
            foreach ($providerIds as $providerId) {
                if (!isset($providerNameById[$providerId])) {
                    continue;
                }
                $accessMatrix[] = [
                    'domain_fqdn' => $groupKeyById[$groupId]['domain_fqdn'],
                    'group_ref' => $groupKeyById[$groupId]['group_ref'],
                    'provider_name' => $providerNameById[$providerId],
                ];
            }
        }

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => gmdate('c'),
            'domains' => $domains,
            'providers' => $providers,
            'access_matrix' => $accessMatrix,
            'chat_settings' => $this->chatSettings->all(),
        ];
    }

    /**
     * @return array{domains_created:int,domains_updated:int,groups_created:int,groups_updated:int,
     *               providers_created:int,providers_updated:int,access_links:int,warnings:string[]}
     */
    public function importZip(string $zipContent, string $actor): array
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'llm_restore_');
        if ($tmpPath === false) {
            throw new ConfigBackupException('Не удалось создать временный файл для разбора архива.');
        }
        try {
            if (file_put_contents($tmpPath, $zipContent) === false) {
                throw new ConfigBackupException('Не удалось сохранить загруженный архив.');
            }
            @chmod($tmpPath, 0600);

            $zip = new \ZipArchive();
            if ($zip->open($tmpPath) !== true) {
                throw new ConfigBackupException('Файл не является корректным ZIP-архивом.');
            }
            $json = $zip->getFromName('config.json');
            $zip->close();
            if ($json === false) {
                throw new ConfigBackupException('В архиве нет config.json.');
            }
        } finally {
            @unlink($tmpPath);
        }

        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ConfigBackupException('config.json повреждён или не является корректным JSON.');
        }
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw new ConfigBackupException('Это не архив резервной копии настроек LLM-чата.');
        }

        return $this->applyImport($data, $actor);
    }

    /**
     * @param array<string,mixed> $data
     * @return array{domains_created:int,domains_updated:int,groups_created:int,groups_updated:int,
     *               providers_created:int,providers_updated:int,access_links:int,warnings:string[]}
     */
    private function applyImport(array $data, string $actor): array
    {
        $stats = [
            'domains_created' => 0, 'domains_updated' => 0,
            'groups_created' => 0, 'groups_updated' => 0,
            'providers_created' => 0, 'providers_updated' => 0,
            'access_links' => 0,
            'warnings' => [],
        ];

        // Без внешней транзакции: LdapConfigRepository::create()/setActive()
        // уже открывают собственные транзакции при upsert домена, а PDO
        // (sqlite) не поддерживает вложенные — оборачивать здесь весь импорт
        // ещё раз нельзя. Импорт делает upsert по бизнес-ключам, поэтому
        // частичное применение при ошибке безопасно: повторный запуск того
        // же архива доводит состояние до конца, ничего не дублируя.
        try {
            $domainIdByFqdn = [];
            foreach ((array) ($data['domains'] ?? []) as $domainData) {
                if (!is_array($domainData) || !isset($domainData['name'], $domainData['domain'])) {
                    $stats['warnings'][] = 'Пропущена запись домена без имени.';
                    continue;
                }
                $name = (string) $domainData['name'];
                $existing = null;
                foreach ($this->ldapConfigs->all() as $c) {
                    if ($c->name === $name) {
                        $existing = $c;
                        break;
                    }
                }
                $payload = [
                    'name' => $name,
                    'ldaps_url' => (string) ($domainData['ldaps_url'] ?? ''),
                    'host' => (string) ($domainData['host'] ?? ''),
                    'port' => (int) ($domainData['port'] ?? 636),
                    'base_dn' => (string) ($domainData['base_dn'] ?? ''),
                    'domain' => (string) ($domainData['domain'] ?? ''),
                    'netbios_domain' => (string) ($domainData['netbios_domain'] ?? ''),
                    'admin_group_dn' => (string) ($domainData['admin_group_dn'] ?? ''),
                    'protocol_version' => (int) ($domainData['protocol_version'] ?? 3),
                    'starttls' => (bool) ($domainData['starttls'] ?? false),
                    'network_timeout' => (int) ($domainData['network_timeout'] ?? 8),
                    'ca_bundle_file' => $domainData['ca_bundle_file'] !== null ? (string) $domainData['ca_bundle_file'] : null,
                ];

                $domainErrors = LdapConfigValidator::validateFields($payload);
                if ($domainErrors !== []) {
                    $stats['warnings'][] = 'Домен «' . $name . '»: ' . implode(' ', $domainErrors) . ' Пропущен.';
                    continue;
                }

                if ($existing !== null) {
                    $this->ldapConfigs->update($existing->id, $payload);
                    $domainId = $existing->id;
                    $stats['domains_updated']++;
                } else {
                    $domainId = $this->ldapConfigs->create($payload, false);
                    $stats['domains_created']++;
                }

                $this->llmDomains->saveLlmSettings(
                    $domainId,
                    (bool) ($domainData['llm_enabled'] ?? false),
                    (string) ($domainData['service_username'] ?? ''),
                    null,
                    false,
                );
                $domainIdByFqdn[(string) $domainData['domain']] = $domainId;

                foreach ((array) ($domainData['groups'] ?? []) as $groupData) {
                    if (!is_array($groupData) || !isset($groupData['group_ref'])) {
                        continue;
                    }
                    $groupRef = (string) $groupData['group_ref'];
                    $displayName = (string) ($groupData['display_name'] ?? $groupRef);
                    $groupError = DomainGroupRepository::validateDisplayName($displayName) ?? DomainGroupRepository::validateRef($groupRef);
                    if ($groupError !== null) {
                        $stats['warnings'][] = 'Группа «' . $displayName . '» (' . $name . '): ' . $groupError . ' Пропущена.';
                        continue;
                    }
                    $existingGroups = $this->groups->forDomain($domainId);
                    $existingGroup = null;
                    foreach ($existingGroups as $g) {
                        if (strcasecmp((string) $g['group_ref'], $groupRef) === 0) {
                            $existingGroup = $g;
                            break;
                        }
                    }
                    $enabled = (bool) ($groupData['is_enabled'] ?? true);
                    $allowUnanonymized = (bool) ($groupData['allow_unanonymized'] ?? false);
                    if ($existingGroup !== null) {
                        $this->groups->update((int) $existingGroup['id'], $displayName, $groupRef, $enabled);
                        $this->groups->setAllowUnanonymized((int) $existingGroup['id'], $allowUnanonymized);
                        $stats['groups_updated']++;
                    } else {
                        $newGroupId = $this->groups->create($domainId, $displayName, $groupRef, $enabled, $actor);
                        if ($allowUnanonymized) {
                            $this->groups->setAllowUnanonymized($newGroupId, true);
                        }
                        $stats['groups_created']++;
                    }
                }
            }

            $providerIdByName = [];
            foreach ($this->providers->all() as $row) {
                $providerIdByName[(string) $row['name']] = (int) $row['id'];
            }
            foreach ((array) ($data['providers'] ?? []) as $providerData) {
                if (!is_array($providerData) || !isset($providerData['name'])) {
                    $stats['warnings'][] = 'Пропущена запись нейросети без имени.';
                    continue;
                }
                $normalized = ProviderValidator::normalize(array_map(
                    static fn ($v) => is_bool($v) ? ($v ? '1' : '') : $v,
                    $providerData,
                ));
                $errors = ProviderValidator::validate($normalized, $this->adapters);
                if ($errors !== []) {
                    $stats['warnings'][] = 'Нейросеть «' . (string) $providerData['name'] . '»: ' . implode(' ', $errors) . ' Пропущена.';
                    continue;
                }

                $name = $normalized['name'];
                if (isset($providerIdByName[$name])) {
                    $providerId = $providerIdByName[$name];
                    $this->providers->update($providerId, $normalized, null, false);
                    $stats['providers_updated']++;
                } else {
                    $providerId = $this->providers->create($normalized, '', null);
                    $providerIdByName[$name] = $providerId;
                    $stats['providers_created']++;
                }
                // is_default не проходит через normalize()/update() (отдельная
                // операция, обеспечивающая единственность) — применяется поверх.
                if (!empty($providerData['is_default'])) {
                    $this->providers->setDefault($providerId);
                }
                $stats['warnings'][] = 'Нейросеть «' . $name . '»: API-ключ не восстановлен из архива — введите заново в разделе «Нейросети».';
            }

            $groupIdByKey = [];
            foreach ($this->groups->all() as $g) {
                $groupIdByKey[strtolower((string) $g['domain_fqdn']) . '|' . strtolower((string) $g['group_ref'])] = (int) $g['id'];
            }
            $providerIdsByGroup = [];
            foreach ((array) ($data['access_matrix'] ?? []) as $link) {
                if (!is_array($link) || !isset($link['domain_fqdn'], $link['group_ref'], $link['provider_name'])) {
                    continue;
                }
                $key = strtolower((string) $link['domain_fqdn']) . '|' . strtolower((string) $link['group_ref']);
                $groupId = $groupIdByKey[$key] ?? null;
                $providerId = $providerIdByName[(string) $link['provider_name']] ?? null;
                if ($groupId === null || $providerId === null) {
                    $stats['warnings'][] = 'Право доступа пропущено: не найден домен/группа/нейросеть (' . (string) $link['domain_fqdn'] . ' / ' . (string) $link['group_ref'] . ' / ' . (string) $link['provider_name'] . ').';
                    continue;
                }
                $providerIdsByGroup[$groupId][] = $providerId;
            }
            foreach ($providerIdsByGroup as $groupId => $providerIds) {
                $this->access->setGroupProviders($groupId, array_unique($providerIds));
                $stats['access_links'] += count(array_unique($providerIds));
            }

            if (is_array($data['chat_settings'] ?? null)) {
                $values = [];
                foreach ($data['chat_settings'] as $key => $value) {
                    $values[(string) $key] = (string) $value;
                }
                $settingsErrors = ChatSettings::validate($values);
                if ($settingsErrors === []) {
                    $this->chatSettings->save($values);
                } else {
                    $stats['warnings'][] = 'Общие параметры чата не восстановлены: ' . implode(' ', $settingsErrors);
                }
            }

        } catch (\Throwable $e) {
            throw new ConfigBackupException('Восстановление прервано из-за ошибки (уже применённые изменения сохранены): ' . $e->getMessage());
        }

        return $stats;
    }
}
