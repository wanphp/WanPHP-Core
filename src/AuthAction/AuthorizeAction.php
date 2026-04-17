<?php

namespace WanPHP\Core\AuthAction;


use Defuse\Crypto\Crypto;
use Defuse\Crypto\Exception\BadFormatException;
use Defuse\Crypto\Exception\EnvironmentIsBrokenException;
use Defuse\Crypto\Key;
use Exception;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Throwable;
use WanPHP\Core\Action;
use WanPHP\Core\Attribute\Route;
use WanPHP\Core\Entities\UserEntity;
use WanPHP\Core\Service\UserService;

class AuthorizeAction extends Action
{
  protected Key $encryptionKey;
  protected bool $webAuthorization; // 公众号是否有网页授权获取用户基本信息权限

  /**
   * @throws EnvironmentIsBrokenException
   * @throws BadFormatException
   */
  public function __construct(
    protected AuthorizationServer  $server,
    protected readonly UserService $userService
  )
  {
    $this->encryptionKey = Key::loadFromAsciiSafeString(getenv('APP_ENCRYPTION_KEY')); //如果通过 generate-defuse-key 脚本生成的字符串，可使用此方法传入
    $this->webAuthorization = getenv('WECHAT_OFFICIAL_ACCOUNT_USER_AUTH') === 'true';
  }

  #[Route(path: "/auth/authorize", methods: ["GET", "POST"], description: "获取授权码或访问令牌", name: "auth.authorize")]
  #[Route(path: "/auth/qrLogin", methods: ["GET"], description: "微信扫码授权", name: "auth.qrLogin")]
  #[OA\Get(
    path: "/auth/authorize",
    operationId: "userAuthorize",
    summary: "微信公众号用户登录，获取授权码或访问令牌",
    security: [],
    tags: ["Auth"],
    parameters: [
      new OA\Parameter(
        name: "response_type",
        description: "授权类型，固定为 code",
        in: "query",
        required: true,
        schema: new OA\Schema(type: "string", example: "code")
      ),
      new OA\Parameter(
        name: "client_id",
        description: "客户端ID，由服务端分配",
        in: "query",
        required: true,
        schema: new OA\Schema(type: "string")
      ),
      new OA\Parameter(
        name: "redirect_uri",
        description: "重定向URI，未填写时使用预先注册的URI（请使用 urlEncode）",
        in: "query",
        schema: new OA\Schema(type: "string", format: "uri")
      ),
      new OA\Parameter(
        name: "scope",
        description: "授权范围，以空格分隔",
        in: "query",
        schema: new OA\Schema(type: "string")
      ),
      new OA\Parameter(
        name: "state",
        description: "CSRF令牌，建议使用并存储于用户会话中以便验证",
        in: "query",
        schema: new OA\Schema(type: "string")
      ),
    ],
    responses: [
      new OA\Response(response: 302, description: "授权成功，重定向至客户端的回调地址 (redirect_uri)", headers: [
        new OA\Header(
          header: "Location",
          description: "包含授权码 (code) 和状态 (state) 的完整回调 URL",
          schema: new OA\Schema(
            type: "string",
            example: "https://client.com/callback?code=AUTH_CODE_HERE&state=STATE_PARAM"
          )
        )
      ]),
      new OA\Response(response: 400, description: "请求失败")
    ]
  )]
  /**
   * @throws Throwable
   */
  protected function action(): Response
  {
    $queryParams = $this->request->getQueryParams();
    $isWeiXin = str_contains($this->request->getServerParams()['HTTP_USER_AGENT'], 'MicroMessenger');
    try {
      //使用微信公众号授权登录
      if (isset($queryParams['state']) &&
        is_string($queryParams['state']) &&
        !in_array($queryParams['state'], ['code', 'token']) && $isWeiXin
      ) {
        // 检查cookie中是否有记录用户信息
        if (isset($_COOKIE['u_code'])) {
          $openid = Crypto::decrypt($_COOKIE['u_code'], $this->encryptionKey);
        } else {
          // 将 $queryParams 存放在当前会话(session)中，用于验证完回调回来时验证 HTTP 请求
          $_SESSION['authQueryParams'] = $queryParams;
          // 跳转到微信，获取OPENID
          return $this->userService->oauthRedirect($this->request, $this->response);
        }
      }
      if (isset($queryParams['code'])) {//微信公众号认证回调
        if ($this->webAuthorization) {
          $user = $this->userService->getOauthUserinfo($queryParams['code']);
          $openid = $user['openid'];
        } else {
          // 没有网页授权获取用户基本信息，通过，公众号被动回复连接登录
          $openid = Crypto::decrypt($queryParams['code'], $this->encryptionKey);
        }
        // 公众号授权扫码验证
        if (!empty($openid) && $isWeiXin && $this->routeContext()->getRoute()->getName() == 'auth.qrLogin') {
          $_SESSION['login_openid'] = $openid;
          if (!$this->webAuthorization) {
            // 保存到cookie,保存一年
            $expire_time = time() + (365 * 24 * 60 * 60);
            setcookie('u_code', $queryParams['code'], $expire_time, '/', '', true, true);
          }
          return $this->respondWxMsg('success', '授权成功', '您已成功授权，详情查看客户端扫码页面！');
        }
      } else {
        //用户自定义登录方式
        switch ($this->request->getMethod()) {
          case  'POST':
            if (!empty($_SESSION['login_openid'])) {
              $openid = $_SESSION['login_openid'];
              unset($_SESSION['login_openid']);
            } else {
              $data = $this->getFormData();
              if (empty($data['account'])) return $this->respondWithError('帐号为绑定手机号或用户ID！');
              if (empty($data['password'])) return $this->respondWithError('密码不能为空！');

              $res = $this->userService->userLogin($data['account'], $data['password']);
              if (!empty($res['openid'])) {
                $openid = $res['openid'];
              } else {
                return $this->respondWxMsg('warn', '授权失败', $res['err']);
              }
            }
            break;
          case 'GET':
            if (!isset($openid)) {
              $qrUrl = $this->urlFor('auth.qrCode');
              $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <title>微信扫码授权登录</title>
  <meta name="viewport" content="width=device-width,initial-scale=1,user-scalable=0">
  <style>
    body {
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
      background-color: #f8f8f8;
    }
    .container {
      display: flex;
      text-align: center;
      background: white;
      padding: 30px;
      border-radius: 8px;
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
    }
    .h2 {
      font-size: 17px;
      margin-bottom: 16px;
      color: #333;
    }
    .qr img {
      width: 220px;
      height: 220px;
      border-radius: 8px;
      background: #fff;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    .qr h2 .logo {
      display: inline-block;
      vertical-align: bottom;
      font-size: 24px;
      width: 1em;
      height: 1em;
      margin-right: 8px;
      background-size: cover;
      background-repeat: no-repeat;
      background-image: url("data:image/svg+xml;charset=utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' xmlns:xlink='http://www.w3.org/1999/xlink' width='24' height='24' viewBox='0 0 24 24'%3E  %3Cdefs%3E    %3Cpath id='0f20791c-6774-4e52-920f-6b6d8404b4dc-a' d='M6.724 0h10.552c2.338 0 3.186.243 4.04.7A4.766 4.766 0 0 1 23.3 2.684c.458.855.701 1.703.701 4.04v10.553c0 2.338-.243 3.186-.7 4.04a4.766 4.766 0 0 1-1.983 1.983c-.855.458-1.703.701-4.04.701H6.723c-2.338 0-3.186-.243-4.04-.7A4.766 4.766 0 0 1 .7 21.316c-.457-.854-.7-1.702-.7-4.039V6.723c0-2.338.243-3.186.7-4.04A4.766 4.766 0 0 1 2.684.7C3.538.243 4.386 0 6.723 0z'/%3E    %3ClinearGradient id='0f20791c-6774-4e52-920f-6b6d8404b4dc-b' x1='50%25' x2='50%25' y1='0%25' y2='100%25'%3E      %3Cstop offset='0%25' stop-color='%2302E36F'/%3E      %3Cstop offset='100%25' stop-color='%2305CD65'/%3E      %3Cstop offset='100%25' stop-color='%2307C160'/%3E    %3C/linearGradient%3E  %3C/defs%3E  %3Cg fill='none' fill-rule='evenodd'%3E    %3Cmask id='0f20791c-6774-4e52-920f-6b6d8404b4dc-c' fill='%23fff'%3E      %3Cuse xlink:href='%230f20791c-6774-4e52-920f-6b6d8404b4dc-a'/%3E    %3C/mask%3E    %3Cpath fill='url(%230f20791c-6774-4e52-920f-6b6d8404b4dc-b)' d='M0 0h24v24H0z' mask='url(%230f20791c-6774-4e52-920f-6b6d8404b4dc-c)'/%3E    %3Cpath fill='%23FFF' d='M19.095 17.63c1.141-.826 1.87-2.05 1.87-3.408 0-2.49-2.423-4.51-5.411-4.51-2.989 0-5.411 2.02-5.411 4.51 0 2.49 2.422 4.51 5.41 4.51.618 0 1.214-.089 1.767-.248a.543.543 0 0 1 .447.06l1.184.683c.033.02.065.034.104.034.1 0 .18-.08.18-.18 0-.045-.017-.09-.028-.132l-.244-.91a.36.36 0 0 1 .132-.409M13.75 13.5a.721.721 0 1 1 0-1.442.721.721 0 0 1 0 1.443M9.493 4.734c3.24 0 5.925 1.977 6.414 4.562a7.206 7.206 0 0 0-.353-.01c-3.27 0-5.922 2.21-5.922 4.936 0 .46.077.904.218 1.326a7.687 7.687 0 0 1-2.476-.288.651.651 0 0 0-.536.071l-1.421.82a.245.245 0 0 1-.125.041.216.216 0 0 1-.217-.216c0-.054.021-.107.035-.158l.292-1.092a.433.433 0 0 0-.159-.49C3.876 13.243 3 11.775 3 10.145c0-2.989 2.907-5.412 6.493-5.412zm7.865 7.323a.721.721 0 1 1 0 1.443.721.721 0 0 1 0-1.443zM7.328 7.548a.866.866 0 1 0 0 1.732.866.866 0 0 0 0-1.732zm4.33 0a.866.866 0 1 0 0 1.731.866.866 0 0 0 0-1.73z' mask='url(%230f20791c-6774-4e52-920f-6b6d8404b4dc-c)'/%3E  %3C/g%3E%3C/svg%3E")
    }
    .login{
      flex: 1;
      width: 280px;
      margin-left: 30px;
      padding-left: 30px;
      border-left: solid 1px #dfdfdf;
    } 
    .login input {
      width: 100%;
      padding: 10px;
      margin-bottom: 15px;
      border: 1px solid #ddd;
      border-radius: 4px;
      box-sizing: border-box;
    }
    .login button {
      width: 100%;
      padding: 10px;
      background-color: #1d4ed8;
      color: white;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-weight: bold;
      transition: background-color 0.2s;
    }
    .login button:hover {
      background-color: #1e40af;
    }
    .expired-message {
      color: red;
      font-size: 1.2em;
      font-weight: bold;
      width: 220px;
      height: 220px;
      line-height: 220px;
      border-radius: 8px;
      background-color: gainsboro;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }
  </style>
</head>
<body>
  <div class="container">
    <div id="qr-container" class="qr">
      <h2><i class="logo"></i> 微信扫码授权登录</h2>
      <img id="qr-img" src="$qrUrl" alt="登录二维码">
    </div>
    <div class="login">
        <h2>手机号密码授权登录</h2>
        <form action="" method="POST">
            <input type="tel" name="account" placeholder="请输入手机号或用户ID" required>
            <input type="password" name="password" placeholder="请输入密码" required>
            <button type="submit">登 录</button>
        </form>
    </div>
  </div>
  <script>
    // 模拟：轮询登录状态接口
    let checkNum = 0;
    const checkLogin = async () => {
      const res = await fetch('/auth/checkLogin',{method:'post'});
      const data = await res.json();

      if (data && data.logged) {
        const form = document.createElement('form');
        form.method = 'POST';
        document.body.appendChild(form);
        form.submit();
      } else {
        // 每 2 秒检查一次
        if (checkNum > 30) {
          const qrImg = document.getElementById('qr-img');
          if (qrImg) {
            qrImg.style.display = 'none';
          }
          const qrContainer = document.getElementById('qr-container'); 
          if (qrContainer) {
            const message = document.createElement('p');
            message.textContent = '二维码已过期！';
            message.className = 'expired-message';
            qrContainer.appendChild(message);
          }
        }else{
          checkNum++;
          setTimeout(checkLogin, 2000);
        }
      }
    };

    checkLogin();
  </script>
</body>
</html>
HTML;
              $this->response->getBody()->write($html);
              return $this->response;
            }
        }
      }

      // 在会话(session)中取出验证的用户queryParams
      if (isset($_SESSION['authQueryParams'])) {
        $this->request = $this->request->withQueryParams($_SESSION['authQueryParams']);
      }
      $authRequest = $this->server->validateAuthorizationRequest($this->request);

      // 设置用户实体(userEntity)
      if (!empty($openid)) {
        $userEntity = new UserEntity();
        $userEntity->setIdentifier($openid);
        $authRequest->setUser($userEntity);

        $authRequest->setAuthorizationApproved(true);
      } else {
        $authRequest->setAuthorizationApproved(false);
      }

      if (isset($_SESSION['authQueryParams'])) unset($_SESSION['authQueryParams']);
      // 完成后重定向至客户端请求重定向地址
      return $this->server->completeAuthorizationRequest($authRequest, $this->response);
    } catch (OAuthServerException $exception) {
      return $this->respondWxMsg('warn', '授权失败！', '您未授权成功，系统无法识别您的身份，请选择使用完整服务。' . $exception->getMessage());
    } catch (Exception $exception) {
      return $this->respondWithError($exception->getMessage());
    }
  }
}