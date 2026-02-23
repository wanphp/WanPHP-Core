<?php

namespace WanPHP\Core\Repositories\OAuth2;


use Exception;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use WanPHP\Core\Repositories\Repository;
use WanPHP\Core\Entities\ClientEntity;

class ClientRepository extends Repository implements ClientRepositoryInterface
{

  /**
   * 获取客户端对象时调用方法，用于验证客户端
   * @param string $clientIdentifier
   * @return ClientEntity|ClientEntityInterface|null
   * @throws Exception
   */
  public function getClientEntity(string $clientIdentifier): ClientEntityInterface|ClientEntity|null
  {
    $client = $this->get('client_id,name,redirect_uri,confidential', ['client_id' => $clientIdentifier]);
    if (!$client) return null;
    if (isset($_GET['redirect_uri'])) $redirect_uri = $_GET['redirect_uri'];
    // 微信授权回来
    if (isset($_SESSION['authQueryParams']['redirect_uri'])) $redirect_uri = $_SESSION['authQueryParams']['redirect_uri'];
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
      $post = json_decode(file_get_contents('php://input'), true) ?: $_POST;
      if (isset($post['redirect_uri'])) $redirect_uri = $post['redirect_uri'];
    }
    if (isset($redirect_uri) && $redirect_uri) {
      if (str_contains($client['redirect_uri'], ',')) {
        foreach (explode(',', $client['redirect_uri']) as $uri) {
          if (str_starts_with($redirect_uri, $uri)) {
            $client['redirect_uri'] = $redirect_uri;
            break;
          }
        }
      } else {
        if (str_starts_with($redirect_uri, $client['redirect_uri'])) $client['redirect_uri'] = $redirect_uri;
      }
    }
    return new ClientEntity($client);
  }

  /**
   * @param string $clientIdentifier 客户端ID
   * @param string|null $clientSecret 客户端密钥
   * @param string|null $grantType 授权类型
   * @return bool
   * @throws Exception
   */
  public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
  {
    $clientData = $this->get('client_secret,client_ip[JSON],confidential', ['client_id' => $clientIdentifier]);
    // 允许 public client refresh_token
    if (!empty($clientData) && $clientData['confidential'] == 0 && $grantType === 'refresh_token') return true;
    if (empty($clientData['client_secret']) || empty($clientSecret)) return false;
    if ($clientData['client_secret'] == 32) {
      // 老的系统更新client_secret
      $clientData['client_secret'] = password_hash($clientData['client_secret'], PASSWORD_BCRYPT);
      $this->update(['client_secret' => $clientData['client_secret']], ['client_id' => $clientIdentifier]);
    }
    if (!password_verify($clientSecret, $clientData['client_secret'])) return false;
    if (!in_array($grantType, ['authorization_code', 'client_credentials', 'password', 'refresh_token'])) return false;
    $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    if (str_contains($clientIp, ',')) $clientIp = explode(',', $clientIp)[0]; // 只取最前面的
    $clientIp = trim($clientIp);
    if ($grantType == 'client_credentials' && !in_array($clientIp, $clientData['client_ip'])) {
      throw new Exception('Invalid client IP: ' . $clientIp);
    }
    return true;
  }
}
