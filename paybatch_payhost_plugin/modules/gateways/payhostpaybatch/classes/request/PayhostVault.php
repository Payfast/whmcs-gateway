<?php

namespace Payhost\classes\request;

class PayhostVault
{
    protected bool $vaulting;
    protected string $vaultId;
    private static string $ns = 'ns1';

    /**
     * @param string $vaultId
     * @param bool $vaulting
     */
    public function __construct(string $vaultId, bool $vaulting)
    {
        $this->vaulting = $vaulting;
        $this->vaultId  = $vaultId;
    }

    public function getVault(): string
    {
        if ($this->vaulting !== true) {
            return '';
        }

        $token     = $this->vaultId;
        $namespace = self::$ns;
        if ($token == null || $token == '') {
            // If token is not already stored under member then add element to request a vault transaction
            return <<<VAULT
<!-- Vault Detail -->
    <$namespace:Vault>true</$namespace:Vault>
VAULT;
        } else {
            // Return the Vault element with valid token
            return <<<VAULT
    <$namespace:VaultId>$token</$namespace:VaultId>
VAULT;
        }
    }

}
