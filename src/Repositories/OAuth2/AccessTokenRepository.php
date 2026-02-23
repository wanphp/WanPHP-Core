<?php

namespace WanPHP\Core\Repositories\OAuth2;


use Exception;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use WanPHP\Core\Entities\AccessTokenEntity;

class AccessTokenRepository implements AccessTokenRepositoryInterface
{
  private CacheInterface $storage;

  public function __construct(CacheInterface $storage)
  {
    $this->storage = $storage;
  }

  /**
   * @param ClientEntityInterface $clientEntity
   * @param array $scopes
   * @param null $userIdentifier
   * @return AccessTokenEntityInterface
   */
  public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, $userIdentifier = null): AccessTokenEntityInterface
  {
    // 创建新访问令牌时调用方法
    // 需要返回 AccessTokenEntityInterface 对象
    // 需要在返回前，向 AccessTokenEntity 传入参数中对应属性
    // 示例代码：
    $accessToken = new AccessTokenEntity();
    $accessToken->setClient($clientEntity);
    foreach ($scopes as $scope) {
      $accessToken->addScope($scope);
    }
    $accessToken->setUserIdentifier($userIdentifier);

    return $accessToken;
  }

  /**
   * @throws Exception
   */
  public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
  {
    $data = [
      'type' => 'access_token',
      'client_id' => $accessTokenEntity->getClient()->getIdentifier(), // 获得客户端标识符
      'user_id' => $accessTokenEntity->getUserIdentifier(), // 获得用户标识符
      'scopes' => array_map(function ($scope) {
        return $scope->getIdentifier();
      }, $accessTokenEntity->getScopes()) // 获得权限范围
    ];

    $this->storage->set($accessTokenEntity->getIdentifier(), $data, $accessTokenEntity->getExpiryDateTime()->getTimestamp() - time());
  }

  /**
   * @throws Exception
   */
  public function revokeAccessToken($tokenId): void
  {
    // 使用刷新令牌创建新的访问令牌时调用此方法
    // 参数为原访问令牌的唯一标识符
    // 可将其在持久化存储中过期
    $this->storage->delete($tokenId);
  }

  /**
   * @throws Exception
   */
  public function isAccessTokenRevoked($tokenId): bool
  {
    // 资源服务器验证访问令牌时将调用此方法
    // 用于验证访问令牌是否已被删除
    // return true 已删除，false 未删除
    try {
      return !$this->storage->has($tokenId);
    } catch (InvalidArgumentException $e) {
      return true;
    }
  }
}
