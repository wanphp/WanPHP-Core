<?php

namespace WanPHP\Core\Service;


use Exception;
use GuzzleHttp\Client;
use JetBrains\PhpStorm\ArrayShape;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use WanPHP\Core\Database\EntityMetadata;
use WanPHP\Core\Entities\ScopeEntity;
use WanPHP\Core\Entities\UserEntity;
use WanPHP\Core\Database\EntityManager;
use WanPHP\Core\Factory\EntityMetadataFactory;
use WanPHP\Core\Repositories\Repository;
use WanPHP\Core\Repositories\WeiXin\WeChatBase;
use WanPHP\Core\Traits\HttpTrait;
use WanPHP\Core\Worker\AuditLogContext;

class UserService extends Service
{
  use HttpTrait;

  private array $headers = [];
  private Client $client;
  public bool $isClient = false;

  /**
   * @throws InvalidArgumentException
   * @throws Exception
   */
  public function __construct(EntityManager $em, private readonly WeChatBase $weChatBase, private readonly CacheInterface $cache)
  {
    parent::__construct($em);
    $this->isClient = !getenv('OAUTH2_PRIVATE_KEY')
      && getenv('OAUTH2_PUBLIC_KEY')
      && getenv('OAUTH2_CLIENT_ID')
      && getenv('OAUTH2_CLIENT_SECRET')
      && getenv('OAUTH2_SERVER_URI');
    if ($this->isClient) {
      // 当前为客户端
      $this->client = new Client(['base_uri' => getenv('OAUTH2_SERVER_URI') . 'api/']);

      //数据库取缓存
      $cache_access_token_key = getenv('OAUTH2_CLIENT_SECRET') . '_access_token';
      $access_token = $this->cache->get($cache_access_token_key);
      if (!$access_token) {
        $data = [
          'grant_type' => 'client_credentials',
          'client_id' => getenv('OAUTH2_CLIENT_ID'),
          'client_secret' => getenv('OAUTH2_CLIENT_SECRET'),
          'scope' => ''
        ];
        $result = $this->request($this->client, 'POST', getenv('OAUTH2_SERVER_URI') . 'auth/accessToken', ['json' => $data]);
        if (isset($result['access_token'])) {
          $this->cache->set($cache_access_token_key, $result['access_token'], $result['expires_in']);
          $access_token = $result['access_token'];
        }
      }
      $this->headers = [
        'Authorization' => 'Bearer ' . $access_token
      ];
    }
  }

  /**
   * @throws Exception
   */
  protected function repo(): Repository
  {
    return $this->em->getRepository(UserEntity::class);
  }

  protected function meta(): EntityMetadata
  {
    return EntityMetadataFactory::from(UserEntity::class);
  }

  /**
   * @throws Exception
   */
  public function getUser(string $openid): array
  {
    if ($this->isClient) {
      return $this->request($this->client, 'POST', 'wx/client/user/get', [
        'json' => ['openid' => $openid],
        'headers' => $this->headers
      ]);
    }
    return $this->repo()->get('openid,nickname,avatar,name,tel', ['openid' => $openid]);
  }

  /**
   * @throws Exception
   */
  public function getUsers($openid): array
  {
    if ($this->isClient) {
      return $this->request($this->client, 'POST', 'wx/client/user/get', [
        'json' => ['openid' => $openid],
        'headers' => $this->headers
      ]);
    }
    return $this->repo()->select('openid,nickname,avatar,name,tel', ['openid' => $openid]) ?: [];
  }

  /**
   * @throws Exception
   */
  public function getUserInfo($openid): array
  {
    if ($this->isClient) {
      return $this->request($this->client, 'POST', 'wx/client/user/get', [
        'json' => ['openid' => $openid],
        'headers' => $this->headers
      ]);
    }
    $users = [];
    foreach ($this->repo()->select('openid,nickname,avatar', ['openid' => $openid]) as $user) {
      $users[$user['openid']] = ['nickname' => $user['nickname'] ?: $user['openid'], 'avatar' => $user['avatar']];
    }
    return $users;
  }

  /**
   * @throws Exception
   */
  public function getUserList($params): array
  {
    $where = [];
    // 推广用户
    if (!empty($params['share'])) {
      $where['share'] = trim($params['share']);
    }
    // 关键词
    if (!empty($params['search']['value'])) {
      $keyword = trim($params['search']['value']);
      $where['OR'] = [
        'name[~]' => $keyword,
        'nickname[~]' => $keyword,
        'tel[~]' => $keyword,
        'remark[~]' => $keyword
      ];
    }
    $recordsFiltered = $this->getUserCount($where);
    $where['LIMIT'] = [$params['start'] ?? 0, $params['length'] ?? 10];
    $where['ORDER'] = ["createdAt" => "DESC"];

    $users = $this->repo()->select('*', $where);
    return ['users' => $users, 'total' => $recordsFiltered];
  }

  /**
   * @throws Exception
   */
  public function getUserCount($where): int
  {
    return $this->repo()->count('openid', $where) ?: 0;
  }

  /**
   * @param array $data
   * @return array
   * @throws Exception
   */
  private function addUser(array $data): array
  {
    $openid = $this->repo()->get('openid', ['openid' => $data['openid']]);
    if (!empty($openid)) {
      unset($data['openid']);
      $this->updateUser($openid, $data);
    } else {
      $openid = $this->repo()->insert($data);
    }
    return ['openid' => $openid];
  }

  /**
   * @param string $openid
   * @param array $data
   * @return array
   * @throws Exception
   */
  public function updateUser(string $openid, array $data): array
  {
    if (!empty($openid) && !empty($data)) return ['upNum' => $this->repo()->update($data, ['openid' => $openid]) ?? 0];
    throw new Exception('更新数据有误');
  }

  /**
   * @param string $keyword
   * @param int $page
   * @return array
   * @throws Exception
   */
  #[ArrayShape(['users' => "array", 'total' => "int"])]
  public function searchUsers(string $keyword, int $page = 0): array
  {
    if ($this->isClient) {
      return $this->request($this->client, 'GET', 'wx/client/user/search', [
        'query' => ['q' => $keyword, 'page' => $page],
        'headers' => $this->headers
      ]);
    }
    $where = [];
    $where['OR'] = [
      'name[~]' => $keyword,
      'nickname[~]' => $keyword,
      'tel[~]' => $keyword
    ];
    $total = $this->getUserCount($where);
    $page = (max($page, 1) - 1) * 10;
    $where['LIMIT'] = [$page, 10];
    $where['ORDER'] = ['createdAt' => 'DESC'];

    return [
      'users' => $this->repo()->select('openid,nickname,avatar,name,tel', $where),
      'total' => $total
    ];
  }


  public function userLogin(string $account, string $password): array
  {
    $account = trim($account);
    $password = trim($password);

    // 用户使用密码登录
    try {
      $user = $this->repo()->get('openid,password,status', ['OR' => ['tel' => $account, 'openid' => $account]]);
    } catch (Exception $e) {
      return ['err' => $e->getMessage()];
    }
    if ($user) {
      if (!password_verify($password, $user['password'])) return ['err' => '帐号密码不正确,请核实！'];
      if (!$user['status']) return ['err' => '帐号已被锁定,无法认证，请联系管理员！'];
      return ['openid' => $user['openid']];
    } else {
      return ['err' => '帐号不存在,请核实！'];
    }
  }

  public function oauthRedirect(Request $request, Response $response): Response
  {
    if ($this->isClient) {
      $queryParams = $request->getQueryParams();
      $redirectUri = $request->getUri()->getScheme() . '://' . $request->getUri()->getHost() . $request->getUri()->getPath();
      $_SESSION['client_redirect_uri'] = $redirectUri;
      $scope = $queryParams['scope'] ?? '';
      $state = $queryParams['state'] ?? '';
      $url = getenv('OAUTH2_SERVER_URI') . 'auth/authorize?client_id=' . getenv('OAUTH2_CLIENT_ID') . '&redirect_uri=' . urlencode($redirectUri) . '&response_type=code&scope=' . $scope . '&state=' . $state;
      return $response->withHeader('Location', $url)->withStatus(301);
    }
    if ($this->weChatBase->webAuthorization) {
      $redirectUri = $request->getUri()->getScheme() . '://' . $request->getUri()->getHost() . $request->getUri()->getPath();
      $queryParams = $request->getQueryParams();
      $response_type = $queryParams['response_type'] ?? $queryParams['state'] ?? '';
      $scope = 'snsapi_userinfo';
      if (!empty($queryParams['scope']) && str_contains($queryParams['scope'], 'snsapi_base')) $scope = 'snsapi_base';
      $url = $this->weChatBase->getOauthRedirect($redirectUri, $response_type, $scope);
      return $response->withHeader('Location', $url)->withStatus(301);
    } else {
      // 没有网页授权获取用户基本信息，跳转到公众号关注页面，关注后通过公众号被动回复连接登录
      if (isset($_COOKIE['u_code'])) {
        return $response->withHeader('Location', $request->getUri()->getScheme() . '://' . $request->getUri()->getHost() . '/auth/qrLogin')->withStatus(301);
      }
      if (!empty($queryParams['state'])) $_SESSION['oauth_state'] = $queryParams['state'];
      $redirectUri = 'https://mp.weixin.qq.com/mp/profile_ext?action=home&__biz=' . $this->weChatBase->uin_base64 . '&scene=124#wechat_redirect';
      return $response->withHeader('Location', $redirectUri)->withStatus(301);
    }
  }

  private function saveAccessToken(array $access_token): void
  {
    setcookie(
      'access_token',
      $access_token['access_token'],
      [
        'expires' => time() + $access_token['expires_in'],
        'path' => '/' . (getenv('CLIENT_APP_PATH') ?? ''),
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict'
      ]
    );
    setcookie(
      'refresh_token',
      $access_token['refresh_token'],
      [
        'expires' => time() + 2592000,
        'path' => '/' . (getenv('CLIENT_APP_PATH') ?? ''),
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict'
      ]
    );
  }

  /**
   * @throws Exception
   */
  public function getOauthAccessToken(string $code): array
  {
    if ($this->isClient) {
      $access_token = $this->request(new Client(), 'POST', getenv('OAUTH2_SERVER_URI') . 'auth/accessToken', [
        'json' => [
          'grant_type' => 'authorization_code',
          'client_id' => getenv('OAUTH2_CLIENT_ID'),
          'client_secret' => getenv('OAUTH2_CLIENT_SECRET'),
          'redirect_uri' => $_SESSION['client_redirect_uri'],
          'code' => $code
        ]
      ]);
      unset($_SESSION['client_redirect_uri']);
      $this->saveAccessToken($access_token);
      return $access_token;
    }
    return $this->weChatBase->getOauthAccessToken($code);
  }

  /**
   * @throws Exception
   */
  public function refreshToken(string $refresh_token): array
  {
    if ($this->isClient) {
      $access_token = $this->request(new Client(), 'POST', getenv('OAUTH2_SERVER_URI') . 'auth/accessToken', [
        'json' => [
          'grant_type' => 'refresh_token',
          'refresh_token' => $refresh_token,
          'client_id' => getenv('OAUTH2_CLIENT_ID'),
          'client_secret' => getenv('OAUTH2_CLIENT_SECRET')
        ]
      ]);
      $this->saveAccessToken($access_token);
      return $access_token;
    }
    // 服务端刷新微信授权Token
    return [];
  }

  /**
   * @throws Exception
   */
  public function getOauthUserinfo(string $code): array
  {
    $accessToken = $this->getOauthAccessToken($code);
    if ($this->isClient) {
      unset($_SESSION['redirect_uri']);
      return $this->request($this->client, 'POST', 'wx/client/user/get', [
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken['access_token']
        ]
      ]);
    }
    //用户基本数据
    $data = ['openid' => $accessToken['openid']];
    //需要用户授权
    if ($accessToken['scope'] == 'snsapi_userinfo') {
      $weUser = $this->weChatBase->getOauthUserinfo($accessToken['access_token'], $accessToken['openid']);
      if (isset($weUser['openid'])) {
        $data['avatar'] = $weUser['headimgurl'];
        $data['nickname'] = $weUser['nickname'];
      }
    }
    $this->addUser($data);
    return $data;
  }

  /**
   * @throws Exception
   */
  public function getOauthScope(array $scopeIds): array
  {
    if ($this->isClient) {
      return $this->request($this->client, 'POST', 'client/getScopes', [
        'json' => ['scopeIds' => $scopeIds],
        'headers' => $this->headers
      ]);
    }
    $scopes = $this->em->getRepository(ScopeEntity::class)->select('scopes[JSON]', ['identifier' => $scopeIds]);
    if (!empty($scopes)) return array_merge(...$scopes);
    return [];
  }

  /**
   * @throws Exception
   */
  public function userProfile(string $openid): array
  {
    return $this->repo()->get('name,tel,nickname,avatar', ['openid' => $openid]);
  }

  /**
   * @param array $openidArr
   * @param array $msgData
   * @return array
   * @throws Exception
   */
  public function sendMessage(array $openidArr, array $msgData): array
  {
    if ($this->isClient) {
      return $this->request($this->client, 'POST', 'wx/client/sendMsg', [
        'json' => ['users' => $openidArr, 'msgData' => $msgData],
        'headers' => $this->headers
      ]);
    }
    if (empty($msgData)) return ['errCode' => '1', 'msg' => '无模板信息内容'];
    //取用户openid
    if (!empty($openidArr)) {
      if (empty($msgData['template_id'])) return ['errCode' => '1', 'msg' => '无模板ID,请先获取模板ID'];
      $ok = 0;
      foreach ($openidArr as $openid) {
        $msgData['touser'] = $openid;
        try {
          $this->weChatBase->sendTemplateMessage($msgData);
          $ok++;
        } catch (Exception $exception) {
          AuditLogContext::markDebug($exception->getMessage(), $msgData);
        }
      }
      return ['errCode' => '0', 'ok' => $ok];
    } else {
      return ['errCode' => '1', 'msg' => '未检测到用户OPENID'];
    }
  }
}
