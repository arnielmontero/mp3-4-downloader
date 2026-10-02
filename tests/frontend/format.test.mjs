// Frontend unit tests (node --test tests/frontend). No browser or build step needed.
import test from 'node:test';
import assert from 'node:assert/strict';
import { formatBytes, qualityLabel, looksLikeYoutubeUrl, bitrateNote } from '../../frontend/assets/js/format.js';

test('formatBytes', () => {
  assert.equal(formatBytes(0), '0 B');
  assert.equal(formatBytes(1536), '1.5 KiB');
  assert.equal(formatBytes(5 * 1024 ** 3), '5.0 GiB');
  assert.equal(formatBytes(null), '');
  assert.equal(formatBytes(undefined), '');
});

test('qualityLabel', () => {
  assert.equal(qualityLabel('best'), 'Best available');
  assert.equal(qualityLabel('720p'), '720p');
});

test('looksLikeYoutubeUrl accepts YouTube forms', () => {
  for (const u of ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://youtu.be/dQw4w9WgXcQ', 'youtube.com/watch?v=x', 'https://m.youtube.com/watch?v=x', ' https://music.youtube.com/watch?v=x ']) {
    assert.equal(looksLikeYoutubeUrl(u), true, u);
  }
});

test('looksLikeYoutubeUrl rejects everything else', () => {
  for (const u of ['', '   ', 'hello', 'http://localhost/', 'http://127.0.0.1/', 'https://evil.com/?u=youtube.com', 'https://www.youtube.com.evil.com/x',
    'file:///etc/passwd', 'ftp://youtube.com/x', 'javascript:alert(1)', 'https://vimeo.com/1', null, undefined]) {
    assert.equal(looksLikeYoutubeUrl(u), false, String(u));
  }
});

test('bitrateNote is honest about source quality', () => {
  assert.equal(bitrateNote(192, null), '');
  assert.match(bitrateNote(320, 130), /will not improve/);
  assert.doesNotMatch(bitrateNote(128, 130), /will not improve/);
});
