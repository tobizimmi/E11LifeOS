<?php

namespace App\Support\Security\Passkeys;

use Illuminate\Support\Facades\Session;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyAuthenticationOptionsAction;
use Spatie\LaravelPasskeys\Support\Config;
use Spatie\LaravelPasskeys\Support\Serializer;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Sign-in options: user verification on the device is required. For the second step after the
 * password only the passkeys of that member are offered.
 */
class AuthenticationOptions extends GeneratePasskeyAuthenticationOptionsAction
{
    /**
     * @param  list<string>  $credentialIds  raw credential ids allowed (empty = any passkey of this site)
     */
    public function execute(array $credentialIds = []): string
    {
        $options = new PublicKeyCredentialRequestOptions(
            challenge: random_bytes(32),
            rpId: Config::getRelyingPartyId(),
            allowCredentials: array_map(fn (string $id) => PublicKeyCredentialDescriptor::create(PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY, $id), $credentialIds),
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
        );

        $json = Serializer::make()->toJson($options);
        Session::put('passkey-authentication-options', $json);

        return $json;
    }
}
