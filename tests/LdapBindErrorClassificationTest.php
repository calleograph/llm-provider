<?php

declare(strict_types=1);

namespace App\Tests;

use App\Ldap\LdapClient;
use App\Ldap\LdapErrorCode;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Проверяет классификацию diagnostic message контроллера домена при неудачном
 * bind — в частности, что data 773 (ERROR_PASSWORD_MUST_CHANGE, "User must
 * change password at next logon" / pwdLastSet=0) распознаётся ОТДЕЛЬНО от
 * data 532 (обычный истёкший пароль) и от простого неверного пароля, так как
 * для 773 приложение должно переключиться на отдельный сценарий (kpasswd),
 * а не просто показать пользователю отказ.
 */
final class LdapBindErrorClassificationTest extends TestCase
{
    private function classify(int $errno, string $diagnostic): string
    {
        $method = new ReflectionMethod(LdapClient::class, 'classifyBindError');

        return $method->invoke(null, $errno, $diagnostic);
    }

    public function testData773IsClassifiedAsPasswordMustChange(): void
    {
        $diagnostic = '80090308: LdapErr: DSID-0C0903AA, comment: AcceptSecurityContext error, data 773, v4f7c';
        $this->assertSame(
            LdapErrorCode::PASSWORD_MUST_CHANGE,
            $this->classify(49, $diagnostic)
        );
    }

    public function testData532IsClassifiedAsPasswordExpiredNotMustChange(): void
    {
        $diagnostic = '80090308: LdapErr: DSID-0C0903AA, comment: AcceptSecurityContext error, data 532, v4f7c';
        $this->assertSame(
            LdapErrorCode::PASSWORD_EXPIRED,
            $this->classify(49, $diagnostic)
        );
    }

    public function testData775IsClassifiedAsAccountLocked(): void
    {
        $diagnostic = '80090308: LdapErr: DSID-0C0903AA, comment: AcceptSecurityContext error, data 775, v4f7c';
        $this->assertSame(
            LdapErrorCode::ACCOUNT_LOCKED,
            $this->classify(49, $diagnostic)
        );
    }

    /**
     * Реальный продакшн-инцидент: учётная запись в защищённой группе AD
     * "Protected Users" получает на bind data 52f (ERROR_ACCOUNT_RESTRICTION)
     * независимо от корректности пароля. Раньше классифицировалось как обычный
     * INVALID_CREDENTIALS ("неверный пароль"), хотя пароль мог быть верным —
     * simple bind отклонён самим ограничением на вход, а не паролем.
     */
    public function testData52fIsClassifiedAsAccountRestrictionNotInvalidCredentials(): void
    {
        $diagnostic = '80090308: LdapErr: DSID-0C090559, comment: AcceptSecurityContext error, data 52f, v65f4';

        $this->assertSame(
            LdapErrorCode::ACCOUNT_RESTRICTION,
            $this->classify(49, $diagnostic)
        );
    }

    public function testPlainInvalidCredentialsWithoutDataCode(): void
    {
        $diagnostic = '80090308: LdapErr: DSID-0C0903AA, comment: AcceptSecurityContext error, data 52e, v4f7c';
        $this->assertSame(
            LdapErrorCode::INVALID_CREDENTIALS,
            $this->classify(49, $diagnostic)
        );
    }

    public function testMustChangeIsNotConfusedWithLockedOrDisabled(): void
    {
        $diagnostic = 'data 773';
        $result = $this->classify(49, $diagnostic);

        $this->assertNotSame(LdapErrorCode::ACCOUNT_LOCKED, $result);
        $this->assertNotSame(LdapErrorCode::ACCOUNT_DISABLED, $result);
        $this->assertNotSame(LdapErrorCode::PASSWORD_EXPIRED, $result);
        $this->assertNotSame(LdapErrorCode::INVALID_CREDENTIALS, $result);
        $this->assertSame(LdapErrorCode::PASSWORD_MUST_CHANGE, $result);
    }
}
