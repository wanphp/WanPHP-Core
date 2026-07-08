export async function getFileMD5(file) {
  return new Promise(resolve => {
    const worker = new Worker(new URL('./md5.worker.js', import.meta.url), {type: 'module'});
    worker.postMessage(file);

    worker.onmessage = e => {
      resolve(e.data);
      worker.terminate();
    };
  });
}

export function selectFile({accept = '*/*', multiple = false} = {}) {
  return new Promise(resolve => {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = accept;
    input.multiple = multiple;
    input.style.display = 'none';

    document.body.appendChild(input);

    input.addEventListener('change', () => {
      const files = multiple ? Array.from(input.files) : input.files[0] || null;
      resolve(files);

      document.body.removeChild(input);
    });

    input.click();
  });
}

export async function uploadFile(file, uploadUrl) {
  const chunkSize = 2 * 1024 * 1024; // 2MB

  if (file.size < chunkSize) {
    const fd = new FormData();
    fd.append('file', file);
    const res = await fetch(uploadUrl, {method: 'POST', body: fd});
    return await res.json();
  }

  const md5 = await getFileMD5(file);
  const total = Math.ceil(file.size / chunkSize);
  // 显示上传进度
  showLoading('文件上传中...');

  async function uploadNext(index) {
    const chunk = file.slice(index * chunkSize, (index + 1) * chunkSize);

    const fd = new FormData();
    fd.append('file', chunk, file.name);
    fd.append('type', file.type);
    fd.append('md5', md5);
    fd.append('index', index);
    fd.append('total', total);

    const res = await fetch(uploadUrl, {method: 'POST', body: fd});
    const data = await res.json();

    // 后端返回下一个要传的 index（断点续传）
    if (typeof data.next_index === 'number') {
      const progress = ((data.next_index / total) * 100).toFixed(2);
      Swal.update({
        title: '已上传' + progress + '%'
      });
      Swal.showLoading();
      return uploadNext(data.next_index);
    }
    Swal.close();

    return data;
  }

  return uploadNext(0);
}

export async function compressImageAuto(
  file,
  {
    maxWidth = 1920,
    maxHeight = 1080,
    quality = 0.82,
    maxShort = 800,       // 长海报或截图
    useWebP = true,       // 是否使用WebP
    maxSizeMB = null,     // 1 / 2
    minQuality = 0.6,     // 最低可接受清晰度
    resizeStep = 0.95      // 每次尺寸缩放比例
  } = {}
) {
  if (!file.type.startsWith('image/')) {
    throw new Error('压缩文件不是图片');
  }

  const img = await createImageBitmap(file);
  let {width, height} = img;

  const isLongImage = Math.max(width / height, height / width) >= 3;

  // 初始尺寸限制
  if (isLongImage) {
    // 长图保证最小边不过小
    if (width > height) {
      const scale = Math.min(maxShort / height, 1);
      width = Math.round(width * scale);
      height = maxShort;
    } else {
      const scale = Math.min(maxShort / width, 1);
      width = maxShort;
      height = Math.round(height * scale);
    }
  } else {
    const scale = Math.min(maxWidth / width, maxHeight / height, 1);
    width = Math.round(width * scale);
    height = Math.round(height * scale);
  }

  const canvas = document.createElement('canvas');
  const ctx = canvas.getContext('2d');

  if (useWebP) useWebP = await supportsWebP();

  let mime = useWebP ? 'image/webp' : 'image/jpeg';
  let ext = useWebP ? 'webp' : 'jpg';

  // PNG 保留透明
  if (file.type === 'image/png') {
    mime = 'image/png';
    ext = 'png';
  }

  const targetSize = maxSizeMB ? maxSizeMB * 1024 * 1024 : null;

  let currentQuality = quality;
  let blob;

  const drawAndExport = async () => {
    canvas.width = width;
    canvas.height = height;
    ctx.clearRect(0, 0, width, height);
    ctx.drawImage(img, 0, 0, width, height);
    return new Promise(resolve =>
      canvas.toBlob(resolve, mime, currentQuality)
    );
  };

  blob = await drawAndExport();

  // ---------- 文件大于设定值 优先缩尺寸 防止过小----------
  while (targetSize && blob.size > targetSize && Math.min(width, height) > 640) {
    width = Math.round(width * resizeStep);
    height = Math.round(height * resizeStep);
    blob = await drawAndExport();
  }

  // ---------- 再降质量 ----------
  if (targetSize && blob.size > targetSize) {
    let low = minQuality;
    let high = currentQuality;
    for (let i = 0; i < 8; i++) {
      currentQuality = (low + high) / 2;
      blob = await drawAndExport();

      if (blob.size > targetSize) high = currentQuality;
      else low = currentQuality;
    }
  }

  const newName = file.name.replace(/\.\w+$/, `.${ext}`);

  return new File([blob], newName, {type: mime});
}

// 判断浏览器是否支持webP
async function supportsWebP() {
  if (!self.createImageBitmap) return false;

  const canvas = document.createElement('canvas');
  if (!canvas.toBlob) return false;

  return new Promise(resolve => {
    canvas.toBlob(
      blob => resolve(blob && blob.type === 'image/webp'),
      'image/webp'
    );
  });
}
