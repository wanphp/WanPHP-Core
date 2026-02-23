<?php

namespace WanPHP\Core\AuthAction;

use BaconQrCode\Writer;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\SimpleCache\CacheInterface;
use WanPHP\Core\Action;
use WanPHP\Core\Attribute\Route;
use WanPHP\Core\Repositories\WeiXin\MiniProgram;
use WanPHP\Core\Service\UserService;
use WanPHP\Core\Worker\AuditLogContext;

class AuthQrCodeAction extends Action
{
  public function __construct(
    private readonly MiniProgram     $miniProgram,
    private readonly Writer          $writer,
    private readonly CacheInterface  $cache,
    private readonly UserService $user)
  {
  }

  #[Route(path: '/authCode', description: '授权二维码', name: 'auth.qrCode')]
  protected function action(): Response
  {
    $scene = bin2hex(random_bytes(16));
    $this->cache->set($scene, session_id(), 60);
    if (getenv('WECHAT_OFFICIAL_ACCOUNT_APPID')) { // 公众号
      $baseUrl = $this->urlFor('auth.qrLogin', [], ['tk' => $scene]);
      $body = $this->writer->writeString($this->httpHost() . $baseUrl);
      $this->response->getBody()->write($body);
      return $this->response->withHeader('Content-Type', 'image/svg+xml')->withStatus(200);
    } else {
      // 小程序授权
      $result = $this->miniProgram->getUnlimitedQRCode($scene, 'pages/auth/confirm');
      $this->response->getBody()->write($result['body']);
      return $this->response->withHeader('Content-Type', 'image/jpeg')->withStatus(200);
    }
  }

  /**
   * 确认授权
   * @throws Exception
   */
  #[Route(path: "/auth/confirmAuth", methods: ["POST"], description: "小程序扫码授权", name: "auth.confirmAuth")]
  public function confirmAuth(Request $request, Response $response, array $args): Response
  {
    $this->request = $request;
    $this->response = $response;
    $this->args = $args;

    $openid = $this->getUid();
    if ($openid) {
      $params = $this->getFormData();
      $code = $params['code'] ?? '';
      if (empty($code)) return $this->respondWithError('未知授权码');
      $session_id = $this->cache->get($code);
      if (empty($session_id)) return $this->respondWithError('授权码已过期');
      if ($session_id != session_id()) {
        session_unset();
        session_destroy();
        session_id($session_id);
        session_start();
      }
      // 授权登录
      $user = $this->user->getUser($openid);
      if ($user['status']) {
        $_SESSION['login_openid'] = $openid;
        AuditLogContext::markActor(type: 'user', id: $openid);
        AuditLogContext::markChanged(resource: null, id: null, action: 'login');
        return $this->respondWithData(['res' => '授权登录成功！！']);
      } else {
        return $this->respondWithError('帐号已被锁定，无法登录！！');
      }
    }
    return $this->respondWithError('小程序认证超时！');
  }

}