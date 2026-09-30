<?php

namespace App\Support\Security\Passkeys;

use Spatie\LaravelPasskeys\Actions\GeneratePasskeyRegisterOptionsAction;
use Webauthn\AuthenticatorSelectionCriteria;

/**
 * Registration options for a new passkey: the device must verify the user (Face ID, fingerprint,
 * PIN), so a passkey alone is enough to sign in, and a long random challenge.
 */
class RegisterOptions extends GeneratePasskeyRegisterOptionsAction
{
    public function authenticatorSelection(): AuthenticatorSelectionCriteria
    {
        return new AuthenticatorSelectionCriteria(
            null,
            AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
        );
    }

    protected function challenge(): string
    {
        return random_bytes(32);
    }
}
