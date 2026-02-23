<?php

namespace WanPHP\Core\Repositories\OAuth2;


use Exception;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use WanPHP\Core\Entities\UserEntity;
use WanPHP\Core\Service\UserService;

readonly class UserRepository implements UserRepositoryInterface
{

  public function __construct(private UserService $user)
  {
  }

  /**
   * @param string $username
   * @param string $password
   * @param string $grantType
   * @param ClientEntityInterface $clientEntity
   * @return UserEntity|UserEntityInterface|null
   * @throws Exception
   * @throws OAuthServerException
   */
  public function getUserEntityByUserCredentials(string $username, string $password, string $grantType, ClientEntityInterface $clientEntity): UserEntity|UserEntityInterface|null
  {
    // 验证用户时调用此方法
    // 用于验证用户信息是否符合
    // 可以验证是否为用户可使用的授权类型($grantType)与客户端($clientEntity)
    // 验证成功返回 UserEntityInterface 对象

    $user = $this->user->userLogin($username, $password);
    if (!empty($user['openid'])) {
      $user = new UserEntity();
      $user->setIdentifier($user['openid']);
      return $user;
    } else {
      throw new OAuthServerException($user['err'], 3, 'invalid_request', 400);
    }
  }
}
