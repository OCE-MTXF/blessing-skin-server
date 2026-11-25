<?php

namespace App\Services\Auth;

use App\Models\User;
use Laravel\Passport\Bridge\AccessTokenRepository;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

final class AccountAccessTokens extends AccessTokenRepository
{
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface
    {
        $user = $userIdentifier !== null ? User::find($userIdentifier) : null;
        if (!$user || !app(AccountSecurity::class)->allowsOAuth($user)) {
            throw OAuthServerException::invalidGrant();
        }

        return parent::getNewToken($clientEntity, $scopes, $userIdentifier);
    }
}
