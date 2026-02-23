<?php

namespace WanPHP\Core\Repositories\OAuth2;


use Exception;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use WanPHP\Core\Entities\ClientEntity;
use WanPHP\Core\Repositories\Repository;
use WanPHP\Core\Entities\ScopeEntity;

class ScopeRepository extends Repository implements ScopeRepositoryInterface
{
  /**
   * @param $identifier
   * @return ScopeEntityInterface|null
   * @throws Exception
   */
  public function getScopeEntityByIdentifier($identifier): ?ScopeEntityInterface
  {
    $identifier = $this->get('identifier', ['identifier' => $identifier]);
    if (!$identifier) return null;
    $scope = new ScopeEntity();
    $scope->setIdentifier($identifier);

    return $scope;
  }

  /**
   * @param array $scopes
   * @param string $grantType
   * @param ClientEntityInterface $clientEntity
   * @param null $userIdentifier
   * @param string|null $authCodeId
   * @return array
   * @throws Exception
   */
  public function finalizeScopes(
    array $scopes, string $grantType, ClientEntityInterface $clientEntity, $userIdentifier = null, ?string $authCodeId = null): array
  {
    $client = $this->em->getRepository(ClientEntity::class);
    $allowed = $client->get('scopes[JSON]', ['client_id' => $clientEntity->getIdentifier()]);

    return array_filter($scopes, function ($scope) use ($allowed) {
      return in_array($scope->getIdentifier(), $allowed);
    });
  }
}
