import SparkMD5 from 'spark-md5';

self.onmessage = async e => {
  const file = e.data;
  const chunkSize = 2 * 1024 * 1024;
  const spark = new SparkMD5.ArrayBuffer();

  let offset = 0;
  while (offset < file.size) {
    const chunk = file.slice(offset, offset + chunkSize);
    const buffer = await chunk.arrayBuffer();
    spark.append(buffer);
    offset += chunkSize;
  }

  self.postMessage(spark.end());
};