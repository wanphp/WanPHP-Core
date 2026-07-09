<?php

namespace WanPHP\Core\Service;

use Exception;
use finfo;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Slim\Psr7\UploadedFile;
use WanPHP\Core\Database\EntityMetadata;
use WanPHP\Core\Entities\FilesEntity;
use WanPHP\Core\Database\EntityManager;
use WanPHP\Core\Factory\EntityMetadataFactory;
use WanPHP\Core\Repositories\Repository;
use ZipArchive;

class UploaderService extends Service
{
  private string $filepath;
  private array $mimeTypes = [
    // images
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',

    // documents
    'application/pdf' => 'pdf',
    'text/plain' => 'txt',

    // audio
    'audio/mpeg' => 'mp3',

    // video
    'video/mp4' => 'mp4',

    // Office (现代格式)
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',

    // Office (旧格式)
    'application/msword' => 'doc',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.ms-powerpoint' => 'ppt'
  ];

  public function __construct(EntityManager $em)
  {
    parent::__construct($em);
    $this->filepath = ROOT_PATH . getenv('APP_UPLOAD_FILE_PATH');
    if (getenv('APP_ALLOW_FILE_TYPE')) {
      $mimeTypes = json_decode(getenv('APP_ALLOW_FILE_TYPE'), true);
      if (json_last_error() === JSON_ERROR_NONE) {
        $this->mimeTypes = array_merge($this->mimeTypes, $mimeTypes);
      }
    }
  }

  public function setUploadPath(string $path): void
  {
    $this->filepath = $path;
  }

  /**
   * @inheritDoc
   */
  protected function repo(): Repository
  {
    return $this->em->getRepository(FilesEntity::class);
  }

  /**
   * @inheritDoc
   */
  protected function meta(): EntityMetadata
  {
    return EntityMetadataFactory::from(FilesEntity::class);
  }

  /**
   *
   * @throws Exception
   */
  public function uploadFile(array $formData, UploadedFile $uploadedFile): array
  {
    //大文件分块上传
    if (isset($formData['total']) && $formData['total'] > 0) {
      // 上传第一片，检查文件是否上传过
      if ($formData['index'] == 0) {
        $fileMd5 = $formData['md5'];
        $file = $this->repo()->get('id,type,url', ['md5' => $fileMd5]);
        if (isset($file['id'])) {
          $file['host'] = $formData['host'];
          return $file;
        }
      }
      $tmpPath = $this->filepath . '/tmp/' . $formData['md5']; // 上传文件分片临时目录
      $this->saveChunk($uploadedFile, $tmpPath, $formData['index']);

      $uploaded = $this->isCompleted($tmpPath, $formData['total']);
      // 所有分片上传完成
      if ($uploaded === true) {
        $extension = strtolower(pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION));
        $filename = sprintf('%s.%0.8s', bin2hex(random_bytes(8)), $extension);
        // 将文件合并到临时目录
        $tmpFile = $this->filepath . '/tmp/' . $filename;
        $this->merge($tmpPath, $tmpFile, $formData['total']);

        // 检查上传文件
        $file = $this->validateFile($tmpFile);
        $extension = $file['ext'];
        $fileType = $file['type'];
        $filename = sprintf('%s.%0.8s', bin2hex(random_bytes(8)), $extension);
        $filePath = $file['savePath'] . DIRECTORY_SEPARATOR . $filename;
        // 移动重命名上传文件
        rename($tmpFile, $this->filepath . $filePath);

        $data = [
          'name' => pathinfo($uploadedFile->getClientFilename(), PATHINFO_FILENAME),
          'type' => $fileType,
          'md5' => $formData['md5'],
          'size' => filesize($this->filepath . $filePath),
          'extension' => $extension,
          'url' => $filePath,
          'openid' => $formData['openid'] ?: 0,
          'uptime' => time()
        ];
      } else {
        return ['next_index' => $uploaded, 'msg' => "继续上传下一块文件！"];
      }
    } else {
      // 文件已上传过
      $fileMd5 = md5_file($uploadedFile->getFilePath());
      $file = $this->repo()->get('id,type,url', ['md5' => $fileMd5]);
      if (isset($file['id'])) {
        $file['host'] = $formData['host'];
        return $file;
      }

      // 检查上传文件
      $file = $this->validateFile($uploadedFile->getFilePath());
      $extension = $file['ext'];
      $fileType = $file['type'];
      $filename = sprintf('%s.%0.8s', bin2hex(random_bytes(8)), $extension);
      $filePath = $file['savePath'] . DIRECTORY_SEPARATOR . $filename;

      $data = [
        'name' => pathinfo($uploadedFile->getClientFilename(), PATHINFO_FILENAME),
        'type' => $fileType,
        'md5' => $fileMd5,
        'size' => $uploadedFile->getSize(),
        'extension' => $extension,
        'url' => $filePath,
        'openid' => $formData['openid'] ?: 0,
        'uptime' => time()
      ];
      $uploadedFile->moveTo($this->filepath . $filePath);
    }
    return ['id' => $this->save($data), 'type' => $data['type'], 'host' => $formData['host'], 'url' => $data['url']];
  }

  /**
   * 校验文件真实类型并返回扩展名
   * @throws Exception
   */
  private function validateFile(string $filePath): array
  {
    if (!is_file($filePath) || filesize($filePath) === 0) {
      $this->deleteInvalidFile($filePath);
      throw new Exception('文件无效');
    }

    $mime = new finfo(FILEINFO_MIME_TYPE)->file($filePath);
    if ($mime === false || !isset($this->mimeTypes[$mime])) {
      $this->deleteInvalidFile($filePath);
      throw new Exception("不支持的文件类型：$mime");
    }

    if (str_starts_with($mime, 'application/vnd.openxmlformats')) {
      $zip = new ZipArchive();
      if ($zip->open($filePath) !== true) {
        $this->deleteInvalidFile($filePath);
        throw new Exception('非法 Office 文件');
      }
      $zip->close();
    }

    if (str_starts_with($mime, 'image/') && @getimagesize($filePath) === false) {
      $this->deleteInvalidFile($filePath);
      throw new Exception('非法图片文件');
    }

    $type = explode('/', $mime);
    if ($type[0] == 'application') $filepath = '/' . $this->mimeTypes[$mime] . date('/Ym');
    else $filepath = '/' . $type[0] . date('/Ym');
    if (!is_dir($this->filepath . $filepath)) mkdir($this->filepath . $filepath, 0755, true);

    return ['type' => $mime, 'ext' => $this->mimeTypes[$mime], 'savePath' => $filepath];
  }

  private function deleteInvalidFile(string $filePath): void
  {
    if (is_file($filePath)) {
      @unlink($filePath);
    }
  }

  /**
   * 保存单个分片
   * @throws Exception
   */
  private function saveChunk(UploadedFile $file, string $uploadTmpPath, int $chunkIndex): void
  {
    if (!is_dir($uploadTmpPath) && !mkdir($uploadTmpPath, 0755, true)) {
      throw new Exception('无法创建分片目录');
    }

    $path = $uploadTmpPath . '/' . $chunkIndex . '.part';

    if (file_exists($path)) {
      // 已上传过，支持断点续传
      return;
    }

    $file->moveTo($path);
  }

  /**
   * 是否所有分片都已上传
   */
  private function isCompleted(string $uploadTmpPath, int $totalChunks): bool|int
  {
    for ($i = 0; $i < $totalChunks; $i++) {
      if (!file_exists($uploadTmpPath . '/' . $i . '.part')) {
        return $i;
      }
    }

    return true;
  }

  /**
   * 合并分片
   * @throws Exception
   */
  private function merge(string $uploadTmpPath, string $filePath, int $totalChunks): void
  {
    if (!is_dir($uploadTmpPath)) {
      throw new Exception('分片不存在');
    }

    $out = fopen($filePath, 'ab');
    for ($i = 0; $i < $totalChunks; $i++) {
      $part = $uploadTmpPath . '/' . $i . '.part';

      if (!file_exists($part)) {
        fclose($out);
        throw new Exception("缺少分片 $i");
      }

      fwrite($out, file_get_contents($part));
    }

    fclose($out);

    // 清理分片
    array_map('unlink', glob($uploadTmpPath . '/*.part'));
    rmdir($uploadTmpPath);
  }

  /**
   * @throws Exception
   * @throws GuzzleException
   */
  public function downloadFile(string $url): array
  {
    if (str_starts_with($url, '//')) $url = 'https' . $url;
    $test = parse_url($url);
    $client = new Client([
      'headers' => ['Referer' => $test['host'] ?? '']
    ]);
    $filename = sprintf('%s.%0.8s', bin2hex(random_bytes(8)), 'dat');
    if (!is_dir($this->filepath . '/tmp/')) mkdir($this->filepath . '/tmp/', 0755, true);
    $downloadFile = $this->filepath . '/tmp/' . $filename;
    $client->request('GET', $url, ['sink' => $downloadFile]);

    $fileMD5 = md5_file($downloadFile);
    $file = $this->repo()->get('id,type,url', ['md5' => $fileMD5]);
    if (isset($file['id'])) {
      unlink($downloadFile);
      return $file;
    }
    // 检查下载文件
    try {
      $file = $this->validateFile($downloadFile);
    } catch (Exception $e) {
      return ['errMsg' => $e->getMessage()];
    }

    $extension = $file['ext'];
    $fileType = $file['type'];
    $filename = sprintf('%s.%0.8s', bin2hex(random_bytes(8)), $extension);
    $filePath = $file['savePath'] . DIRECTORY_SEPARATOR . $filename;

    if (rename($downloadFile, $this->filepath . $filePath)) {
      $data = [
        'name' => '来源' . $test['host'],
        'type' => $fileType,
        'md5' => $fileMD5,
        'size' => filesize($this->filepath . $filePath),
        'extension' => $extension,
        'url' => $filePath,
        'openid' => 0,
        'uptime' => time()
      ];
      return ['id' => $this->save($data), 'type' => $data['type'], 'url' => $data['url']];
    }
    return ['errMsg' => '下载失败'];
  }

  /**
   * @throws Exception
   */
  public function setName(int $id, string $name): int
  {
    return $this->repo()->update(['name' => $name], ['id' => $id], false);
  }

  /**
   * @throws Exception
   */
  public function getDownloadFile(int $id): array
  {
    $file = $this->repo()->get('name,url', ['id' => $id]);
    if (is_file($this->filepath . $file['url'])) return $file;
    return [];
  }


  /**
   * @throws Exception
   */
  public function delFile(int|string $file, bool $deleteFile = true): int
  {
    if (is_numeric($file)) {
      $id = intval($file);
      if ($deleteFile) $filepath = $this->repo()->get('url', ['id' => $id]);
      $delNum = $this->repo()->delete(['id' => $id]);
      if ($delNum) {
        if (!empty($filepath) && is_file($this->filepath . $filepath)) unlink($this->filepath . $filepath); //删除文件
        return $delNum;
      } else {
        return 0;
      }
    } else {
      $id = $this->repo()->get('id', ['url' => $file]);
      if ($id) {
        $delNum = $this->repo()->delete(['id' => $id]);
        if ($delNum) {
          if ($deleteFile && is_file($this->filepath . $file)) unlink($this->filepath . $file); //删除文件
          return $delNum;
        } else {
          return 0;
        }
      } else {
        return 0;
      }
    }
  }

  /**
   * 允许上传文件类型
   * @param array $mimeTypes
   */
  public function allowFileType(array $mimeTypes): void
  {
    $this->mimeTypes = array_merge($this->mimeTypes, $mimeTypes);
  }

}
