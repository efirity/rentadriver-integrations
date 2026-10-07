import { createHmac, timingSafeEqual, randomBytes, createHash, createCipheriv, createDecipheriv } from 'node:crypto';
export function verifySlack(secret, headers, body, now = Date.now()) {
  const timestamp = headers['x-slack-request-timestamp'], signature = headers['x-slack-signature'];
  if (!/^\d{10}$/.test(timestamp || '') || Math.abs(now / 1000 - Number(timestamp)) > 300 || !/^v0=[a-f0-9]{64}$/.test(signature || '')) return false;
  const expected = 'v0=' + createHmac('sha256', secret).update(`v0:${timestamp}:`).update(body).digest('hex');
  return timingSafeEqual(Buffer.from(signature), Buffer.from(expected));
}
export const random = () => randomBytes(32).toString('base64url');
export const hash = value => createHash('sha256').update(value).digest('hex');
export const uuid = value => typeof value === 'string' && /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(value);
export function seal(key, value) {
  const iv = randomBytes(12), cipher = createCipheriv('aes-256-gcm', key, iv);
  const data = Buffer.concat([cipher.update(JSON.stringify(value), 'utf8'), cipher.final()]);
  return Buffer.concat([iv, cipher.getAuthTag(), data]).toString('base64');
}
export function unseal(key, value) {
  const data = Buffer.from(value, 'base64'), decipher = createDecipheriv('aes-256-gcm', key, data.subarray(0, 12));
  decipher.setAuthTag(data.subarray(12, 28));
  return JSON.parse(Buffer.concat([decipher.update(data.subarray(28)), decipher.final()]).toString('utf8'));
}
