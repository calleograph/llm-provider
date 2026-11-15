<?php

declare(strict_types=1);

namespace App\Tests;

use App\Http\ErrorMessages;
use App\Ldap\LdapErrorCode;
use PHPUnit\Framework\TestCase;

final class ErrorMessagesTest extends TestCase
{
    public function testPasswordMustChangeHasDedicatedUserFacingMessage(): void
    {
        $message = ErrorMessages::forCode(LdapErrorCode::PASSWORD_MUST_CHANGE);

        $this->assertNotEmpty($message);
    }

    public function testPasswordMustChangeMessageDoesNotLeakLdapInternals(): void
    {
        $message = ErrorMessages::forCode(LdapErrorCode::PASSWORD_MUST_CHANGE);

        $this->assertStringNotContainsString('773', $message);
        $this->assertStringNotContainsString('LDAP', $message);
        $this->assertStringNotContainsString('DSID', $message);
    }

    public function testPasswordMustChangeIsDistinctFromPasswordExpired(): void
    {
        $mustChange = ErrorMessages::forCode(LdapErrorCode::PASSWORD_MUST_CHANGE);
        $expired = ErrorMessages::forCode(LdapErrorCode::PASSWORD_EXPIRED);

        $this->assertNotSame($mustChange, $expired);
    }

    public function testUnknownCodeFallsBackToGenericMessage(): void
    {
        $message = ErrorMessages::forCode('some_unmapped_code');
        $this->assertNotEmpty($message);
    }

    public function testAccountRestrictionHasDistinctMessage(): void
    {
        $message = ErrorMessages::forCode(LdapErrorCode::ACCOUNT_RESTRICTION);
        $serverUnavailable = ErrorMessages::forCode(LdapErrorCode::SERVER_UNAVAILABLE);
        $invalidCredentials = ErrorMessages::forCode(LdapErrorCode::INVALID_CREDENTIALS);

        $this->assertNotSame($message, $serverUnavailable);
        $this->assertNotSame($message, $invalidCredentials);
    }
}
