<?php

namespace App\Support\Security\Passkeys;

use Spatie\LaravelPasskeys\Actions\ConfigureCeremonyStepManagerFactoryAction;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

/**
 * Passkeys only work over HTTPS; on a local development machine http://localhost is accepted too.
 */
class CeremonyFactory extends ConfigureCeremonyStepManagerFactoryAction
{
    public function execute(): CeremonyStepManagerFactory
    {
        $factory = parent::execute();

        if (app()->environment('local', 'testing')) {
            $factory->setSecuredRelyingPartyId(['localhost']);
        }

        return $factory;
    }
}
