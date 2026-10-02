// Pure formatting / validation helpers (no DOM access, unit-tested with node:test).

export function formatBytes(bytes) {
  if (bytes === null || bytes === undefined || Number.isNaN(Number(bytes))) return '';
  let value = Number(bytes);
  const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
  let i = 0;
  while (value >= 1024 && i < units.length - 1) { value /= 1024; i += 1; }
  return `${i === 0 ? Math.round(value) : value.toFixed(1)} ${units[i]}`;
}

export function formatDate(iso) {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

export function qualityLabel(q) {
  return q === 'best' ? 'Best available' : q;
}

const YOUTUBE_HOSTS = new Set([
  'youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com',
  'youtu.be', 'www.youtu.be', 'youtube-nocookie.com', 'www.youtube-nocookie.com',
]);

// Quick client-side sanity check so obvious mistakes get instant feedback.
// The server repeats (and is the authority on) all validation.
export function looksLikeYoutubeUrl(text) {
  const value = (text || '').trim();
  if (!value) return false;
  try {
    const url = new URL(/^[a-z][a-z0-9+.-]*:\/\//i.test(value) ? value : `https://${value}`);
    return (url.protocol === 'https:' || url.protocol === 'http:') && YOUTUBE_HOSTS.has(url.hostname.toLowerCase());
  } catch {
    return false;
  }
}

// Message shown when the chosen MP3 bitrate is higher than the audio YouTube provides.
export function bitrateNote(selected, sourceKbps) {
  if (!sourceKbps) return '';
  if (Number(selected) > sourceKbps + 8) {
    return `The source audio is about ${sourceKbps} kbps. Converting to ${selected} kbps will not improve its quality.`;
  }
  return `The source audio is about ${sourceKbps} kbps.`;
}
