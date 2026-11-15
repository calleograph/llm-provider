<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config\LdapConfigValidator;
use PHPUnit\Framework\TestCase;

final class LdapConfigValidatorTest extends TestCase
{
    private function validData(): array
    {
        return [
            'name' => 'Production AD',
            'ldaps_url' => 'ldaps://dc1.corp.local:636/',
            'host' => 'dc1.corp.local',
            'port' => 636,
            'base_dn' => 'DC=corp,DC=local',
            'domain' => 'corp.local',
            'netbios_domain' => 'CORP',
            'admin_group_dn' => 'CN=Отдел ИТ,OU=Отдел ИТ,OU=АУП,OU=CORP,DC=corp,DC=local',
            'protocol_version' => 3,
            'network_timeout' => 8,
        ];
    }

    public function testValidConfigPasses(): void
    {
        $this->assertNull(LdapConfigValidator::validate($this->validData()));
    }

    public function testRejectsEmptyName(): void
    {
        $data = $this->validData();
        $data['name'] = '';
        $this->assertNotNull(LdapConfigValidator::validate($data));
    }

    public function testRejectsNonLdapsUrl(): void
    {
        $data = $this->validData();
        $data['ldaps_url'] = 'ldap://dc1.corp.local:389/';
        $this->assertNotNull(LdapConfigValidator::validate($data));
    }

    public function testRejectsInvalidPort(): void
    {
        $data = $this->validData();
        $data['port'] = 0;
        $this->assertNotNull(LdapConfigValidator::validate($data));

        $data['port'] = 70000;
        $this->assertNotNull(LdapConfigValidator::validate($data));
    }

    public function testRejectsInvalidProtocolVersion(): void
    {
        $data = $this->validData();
        $data['protocol_version'] = 1;
        $this->assertNotNull(LdapConfigValidator::validate($data));

        $data['protocol_version'] = 4;
        $this->assertNotNull(LdapConfigValidator::validate($data));
    }

    public function testAcceptsProtocolVersion2And3(): void
    {
        $data = $this->validData();
        $data['protocol_version'] = 2;
        $this->assertNull(LdapConfigValidator::validate($data));

        $data['protocol_version'] = 3;
        $this->assertNull(LdapConfigValidator::validate($data));
    }

    public function testRejectsMissingBaseDn(): void
    {
        $data = $this->validData();
        $data['base_dn'] = '';
        $this->assertNotNull(LdapConfigValidator::validate($data));
    }

    public function testRejectsInvalidNetworkTimeout(): void
    {
        $data = $this->validData();
        $data['network_timeout'] = 0;
        $this->assertNotNull(LdapConfigValidator::validate($data));

        $data['network_timeout'] = 500;
        $this->assertNotNull(LdapConfigValidator::validate($data));
    }

    public function testValidateFieldsReturnsEmptyArrayForValidData(): void
    {
        $this->assertSame([], LdapConfigValidator::validateFields($this->validData()));
    }

    public function testValidateFieldsIdentifiesSpecificFieldForEmptyName(): void
    {
        $data = $this->validData();
        $data['name'] = '';
        $errors = LdapConfigValidator::validateFields($data);

        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayNotHasKey('host', $errors);
    }

    public function testValidateFieldsIdentifiesSpecificFieldForBadPort(): void
    {
        $data = $this->validData();
        $data['port'] = 0;
        $errors = LdapConfigValidator::validateFields($data);

        $this->assertArrayHasKey('port', $errors);
        $this->assertCount(1, $errors);
    }

    public function testValidateFieldsReportsMultipleErrorsSimultaneously(): void
    {
        $data = $this->validData();
        $data['name'] = '';
        $data['host'] = '';
        $data['port'] = -1;
        $errors = LdapConfigValidator::validateFields($data);

        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('host', $errors);
        $this->assertArrayHasKey('port', $errors);
    }

    public function testValidateReturnsFirstErrorFromValidateFields(): void
    {
        $data = $this->validData();
        $data['name'] = '';
        $data['host'] = '';

        $single = LdapConfigValidator::validate($data);
        $fields = LdapConfigValidator::validateFields($data);

        $this->assertSame(array_values($fields)[0], $single);
    }
}
