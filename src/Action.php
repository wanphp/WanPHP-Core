<?php

namespace WanPHP\Core;

use Exception;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

abstract class Action
{
  /**
   * @var Request
   */
  protected Request $request;

  /**
   * @var Response
   */
  protected Response $response;

  /**
   * @var array
   */
  protected array $args;

  /**
   * @param Request $request
   * @param Response $response
   * @param array $args
   * @return Response
   * @throws HttpNotFoundException
   */
  public function __invoke(Request $request, Response $response, array $args): Response
  {
    $this->request = $request;
    $this->response = $response;
    $this->args = $args;

    try {
      return $this->action();
    } catch (HttpNotFoundException|Exception $e) {
      throw new HttpNotFoundException($request, $e->getMessage(), $e);
    }
  }

  /**
   * @return Response
   * @throws HttpNotFoundException
   * @throws HttpBadRequestException
   * @throws Exception
   */
  abstract protected function action(): Response;

  /**
   * @return array
   * @throws HttpBadRequestException
   */
  protected function getFormData(): array
  {
    if (str_contains($this->request->getHeaderLine('Content-Type'), 'application/json')) {
      $postData = json_decode(file_get_contents('php://input'), true);
      if (json_last_error() !== JSON_ERROR_NONE) throw new HttpBadRequestException($this->request, '提交JSON串格式错误。');
    } else {
      $postData = $this->request->getParsedBody();
    }

    if (empty($postData) && in_array($this->request->getMethod(), ['PATCH', 'PUT', 'DELETE'])) {
      $rawInput = file_get_contents('php://input'); // 将 a=1&b=2 这种字符串解析为数组
      parse_str($rawInput, $postData);
    }
    return $postData;
  }

  protected function routeContext(): RouteContext
  {
    return RouteContext::fromRequest($this->request);
  }

  protected function urlFor(string $routeName, array $data = [], array $queryParams = []): string
  {
    return RouteContext::fromRequest($this->request)->getRouteParser()->urlFor($routeName, $data, $queryParams);
  }

  /**
   * @param string $name
   * @param null $default
   * @return mixed
   */
  protected function resolveArg(string $name, $default = null): mixed
  {
    return $this->args[$name] ?? $default;
  }

  protected function getLoginUserId(): string
  {
    return $_SESSION['user_openid'] ?? '';
  }

  protected function getLoginUserRoleId(): int
  {
    return intval($_SESSION['role_id'] ?? 0);
  }

  protected function getLoginUserGroupId(): int
  {
    return intval($_SESSION['groupId'] ?? 0);
  }

  protected function getLoginId(): int
  {
    return intval($_SESSION['login_id'] ?? 0);
  }

  /**
   * @return string
   * @throws HttpUnauthorizedException
   */
  protected function getUid(): string
  {
    $openid = $this->request->getAttribute('oauth_user_id');
    if (is_null($openid)) throw new HttpUnauthorizedException($this->request, "未知用户!");
    return $openid;
  }

  /**
   * @return string
   * @throws HttpUnauthorizedException
   */
  protected function getClientID(): string
  {
    $id = $this->request->getAttribute('oauth_client_id');
    if (is_null($id)) throw new HttpUnauthorizedException($this->request, "未知客户端!");
    return $id;
  }

  protected function isClient(): bool
  {
    return !is_null($this->request->getAttribute('oauth_client_id')) && is_null($this->request->getAttribute('oauth_client_id'));
  }

  /**
   * @param array $data
   * @param int $statusCode
   * @return Response
   */
  protected function respondWithData(array $data = [], int $statusCode = 200): Response
  {
    $json = json_encode($data, JSON_PRETTY_PRINT + JSON_UNESCAPED_UNICODE + JSON_NUMERIC_CHECK);
    $this->response->getBody()->write($json);
    return $this->respond($statusCode);
  }

  /**
   * @param null $error
   * @param int $statusCode
   * @return Response
   */
  protected function respondWithError($error = null, int $statusCode = 400): Response
  {
    $json = json_encode(['message' => $error], JSON_PRETTY_PRINT + JSON_UNESCAPED_UNICODE);
    $this->response->getBody()->write($json);
    return $this->respond($statusCode);
  }

  /**
   * @param string $template 模板路径
   * @param array $data
   * @return Response
   * @throws Exception
   */
  protected function respondView(string $template, array $data = []): Response
  {
    $basePath = $this->routeContext()->getBasePath();
    $view = Twig::fromRequest($this->request);
    $view->offsetSet('thisUri', $this->httpHost() . $basePath);
    foreach (glob(ROOT_PATH . '/public/assets/{app-,style-}*.{js,css}', GLOB_BRACE) as $item) {
      $item = str_replace(ROOT_PATH . '/public', '', $item);
      if (str_ends_with($item, '.css')) $view->offsetSet('app_css_path', $item);
      else $view->offsetSet('app_js_path', $item);
    }
    if (!empty($basePath) && is_file(ROOT_PATH . '/var' . $basePath . '/' . $template)) {
      $template = str_replace('/', '@', $basePath) . '/' . $template;
    }

    if (!$this->request->hasHeader('HX-Request') && !str_contains($template, 'login.twig')) {
      $data['content_template'] = $template;
      $template = 'page.twig';
    }

    return $view->render($this->response, $template, $data);
  }

  /**
   * @param $statusCode
   * @return Response
   */
  protected function respond($statusCode): Response
  {
    if ($this->request->hasHeader('HX-Trigger')) $this->response = $this->response->withHeader('HX-Trigger', $this->request->getHeader('HX-Trigger'));
    return $this->response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
  }

  /**
   * @param string $icon success 成功，warn 警告， info 提示， waiting 等待
   * @param string $title
   * @param string $message
   * @return Response
   */
  protected function respondWxMsg(string $icon, string $title, string $message): Response
  {
    $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <title>{$title}</title>
  <meta name="viewport" content="width=device-width,initial-scale=1,user-scalable=0">
  <link rel="stylesheet" href="/assets/css/weui.min.css">
  <style>
  body,html {
    height: 100%;
    -webkit-tap-highlight-color: transparent
  }

  body {
    font-family: system-ui,-apple-system,Helvetica Neue,sans-serif
    background-color: var(--weui-BG-0)
  }
  </style>
  <script>
    function closePage() {
      if (typeof WeixinJSBridge !== "undefined") {
        WeixinJSBridge.call('closeWindow');
      } else {
        history.back();
      }
    }
    document.addEventListener('WeixinJSBridgeReady', function() {
      console.log('WeixinJSBridgeReady');
    });
  </script>
</head>
<body>
  <div class="weui-msg">
    <div class="weui-msg__icon-area">
      <i class="weui-icon-{$icon} weui-icon_msg"></i>
    </div>
    <div class="weui-msg__text-area">
      <h2 class="weui-msg__title">{$title}</h2>
      <p class="weui-msg__desc">{$message}</p>
    </div>
    <div class="weui-msg__opr-area">
      <p class="weui-btn-area">
        <a href="javascript:closePage();" class="weui-btn weui-btn_default">关闭页面</a>
      </p>
    </div>
  </div>
</body>
</html>
HTML;

    $this->response->getBody()->write($html);
    return $this->response;
  }

  protected function httpHost(): string
  {
    return $this->request->getUri()->getScheme() . '://' . $this->request->getUri()->getHost();
  }

  protected function isAjax(): bool
  {
    return $this->request->getHeaderLine("X-Requested-With") == "XMLHttpRequest";
  }

  protected function isPost(): bool
  {
    return $this->request->getMethod() == 'POST';
  }

  protected function isGet(): bool
  {
    return $this->request->getMethod() == 'GET';
  }

  //更新所有
  protected function isPut(): bool
  {
    return $this->request->getMethod() == 'PUT';
  }

  //更新属性
  protected function isPatch(): bool
  {
    return $this->request->getMethod() == 'PATCH';
  }

  protected function isDelete(): bool
  {
    return $this->request->getMethod() == 'DELETE';
  }

  protected function getIP(): string
  {
    $serverParams = $this->request->getServerParams();
    $ipAddress = '';

    // 检查是否存在代理服务器IP
    if (!empty($serverParams['HTTP_CLIENT_IP'])) {
      $ipAddress = $serverParams['HTTP_CLIENT_IP'];
    } elseif (!empty($serverParams['HTTP_X_FORWARDED_FOR'])) {
      // 多个代理服务器的情况下，获取最后一个IP地址
      $ipList = explode(',', $serverParams['HTTP_X_FORWARDED_FOR']);
      $ipAddress = trim(end($ipList));
    } elseif (!empty($serverParams['HTTP_X_FORWARDED'])) {
      $ipAddress = $serverParams['HTTP_X_FORWARDED'];
    } elseif (!empty($serverParams['HTTP_X_CLUSTER_CLIENT_IP'])) {
      $ipAddress = $serverParams['HTTP_X_CLUSTER_CLIENT_IP'];
    } elseif (!empty($serverParams['HTTP_FORWARDED_FOR'])) {
      $ipAddress = $serverParams['HTTP_FORWARDED_FOR'];
    } elseif (!empty($serverParams['HTTP_FORWARDED'])) {
      $ipAddress = $serverParams['HTTP_FORWARDED'];
    } elseif (!empty($serverParams['REMOTE_ADDR'])) {
      // 如果以上都不存在，使用REMOTE_ADDR
      $ipAddress = $serverParams['REMOTE_ADDR'];
    }

    return $ipAddress;
  }

  protected function getLimit(): array
  {
    $params = $this->request->getQueryParams();
    return [$params['start'] ?? 0, $params['length'] ?? 10];
  }

  protected function getOrder(): array
  {
    $params = $this->request->getQueryParams();
    $order = [];
    if (isset($params['order'])) foreach ($params['order'] as $param) {
      $order[$params['columns'][$param['column']]['data']] = strtoupper($param['dir']);
    }
    return $order;
  }

  /**
   * 根据原图路径生成缩略图访问路径
   *
   * @param string $imagePath 原图访问路径，如 /image/202601/xxx.png
   * @param string $spec      缩略规格，如 120x120 / w120 / h120 / 120x120_watermark-br-4
   * @param string|null $forceExt  强制扩展名（可选，如 webp）
   * @return string
   */
  protected function thumb(string $imagePath, string $spec, ?string $forceExt = null): string
  {
    // 统一路径
    $imagePath = '/' . ltrim($imagePath, '/');

    // 期望格式：/image/{ym}/{hash}.{ext}
    if (!preg_match(
      '#^/image/(?<ym>\d{6})/(?<hash>[a-z0-9]+)\.(?<ext>jpg|jpeg|png|gif|webp)$#i',
      $imagePath,
      $m
    )) {
      throw new InvalidArgumentException('Invalid image path: ' . $imagePath);
    }

    $ym   = $m['ym'];
    $hash = $m['hash'];
    $ext  = strtolower($forceExt ?? $m['ext']);

    return "/image/thumb/{$ym}/{$hash}/{$spec}.{$ext}";
  }
}
