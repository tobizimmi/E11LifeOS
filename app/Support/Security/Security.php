<?php

namespace App\Support\Security;

/**
 * Shared security settings.
 */
class Security
{
    /**
     * Route middleware for pages with health data, the emergency folder and online banking:
     * the password has to be entered again when it was last confirmed more than 15 minutes ago.
     */
    public const SENSITIVE = 'password.confirm:password.confirm,900';
}
