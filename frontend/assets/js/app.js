import { api, ApiError } from './api.js';
import { formatBytes, formatDate, looksLikeYoutubeUrl } from './format.js';

const $ = (id) => document.getElementById(id);
const POLL_MS = 1000;
const STORAGE_KEY = 'ytd.activeJobs';
const TERMINAL = ['completed', 'failed', 'cancelled'];
const DEFAULT_BITRATE = 192;

// jobs: job id -> { el, done }   (every download keeps its own card in the sidebar; any number can run at once)
const state = { jobs: new Map(), timer: null, polling: false };

// ---- theme: follow the OS light/dark preference (no inline script, CSP friendly) ----
const media = window.matchMedia('(prefers-color-scheme: dark)');
const applyTheme = () => document.documentElement.setAttribute('data-bs-theme', media.matches ? 'dark' : 'light');
applyTheme();
media.addEventListener('change', applyTheme);

// ---------------------------------------------------------------- small helpers
function show(el, visible = true) { el.classList.toggle('d-none', !visible); }

function setBusy(button, busy, label) {
  button.disabled = busy;
  show(button.querySelector('.spinner-border'), busy);
  if (label !== undefined) button.querySelector('.label').textContent = label;
}

function friendly(err) {
  return err instanceof ApiError ? err.message : 'Something went wrong. Please try again.';
}

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function button(label, className, onClick) {
  const b = el('button', `btn btn-sm ${className}`, label);
  b.type = 'button';
  b.addEventListener('click', onClick);
  return b;
}

function remember() {
  try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify([...state.jobs.keys()])); } catch { /* storage may be unavailable */ }
}
function recall() {
  try {
    const ids = JSON.parse(sessionStorage.getItem(STORAGE_KEY) || '[]');
    return Array.isArray(ids) ? ids.filter((i) => /^[a-f0-9]{32}$/.test(i)) : [];
  } catch { return []; }
}

// ---------------------------------------------------------------- search (main display)
async function onSearch(event) {
  event.preventDefault();
  const text = $('searchInput').value.trim();
  const error = $('searchError');
  show(error, false);
  if (!text) { error.textContent = 'Please type something to search for.'; show(error, true); return; }

  const isLink = /^(https?:)?\/\//i.test(text) || /^(www\.)?(youtube\.com|youtu\.be|m\.youtube\.com|music\.youtube\.com)/i.test(text);
  if (isLink && !looksLikeYoutubeUrl(text)) { error.textContent = 'Please enter a valid YouTube URL.'; show(error, true); return; }

  setBusy($('searchBtn'), true, 'Searching...');
  $('results').replaceChildren();
  show($('resultsInfo'), false);
  try {
    let results;
    if (isLink) {
      const info = await api.analyze(text); // a pasted link becomes a single result
      results = [{
        video_id: info.video_id, title: info.title, uploader: info.uploader, duration_formatted: info.duration_formatted,
        thumbnail: info.thumbnail, webpage_url: info.webpage_url, mp4: info.formats.mp4, mp3: info.formats.mp3,
      }];
    } else {
      results = (await api.search(text)).results;
    }
    renderResults(results, text);
  } catch (err) {
    error.textContent = friendly(err);
    show(error, true);
  } finally {
    setBusy($('searchBtn'), false, 'Search');
  }
}

function renderResults(results, query) {
  const info = $('resultsInfo');
  info.textContent = results.length ? `${results.length} result${results.length === 1 ? '' : 's'}` : `No results for "${query}".`;
  show(info, true);
  $('results').replaceChildren(...results.map(resultRow));
}

function resultRow(r) {
  const li = el('li', 'result-row');
  const img = el('img', 'result-thumb');
  img.alt = '';
  img.loading = 'lazy';
  img.referrerPolicy = 'no-referrer';
  if (r.thumbnail) img.src = r.thumbnail;

  const body = el('div', 'result-body');
  body.append(el('div', 'result-title', r.title));
  body.append(el('div', 'text-body-secondary small', [r.uploader, r.duration_formatted].filter(Boolean).join(' · ')));

  const actions = el('div', 'result-actions');
  const select = el('select', 'form-select form-select-sm');
  select.setAttribute('aria-label', `Format for ${r.title}`);
  select.style.width = 'auto';
  for (const [value, label, ok] of [['mp3', 'MP3 (audio)', r.mp3 !== false], ['mp4', 'MP4 (video)', r.mp4 !== false]]) {
    if (!ok) continue;
    const option = el('option', '', label);
    option.value = value;
    select.append(option);
  }
  const dl = el('button', 'btn btn-success btn-sm', 'Download');
  dl.type = 'button';
  dl.setAttribute('aria-label', `Download ${r.title}`);
  dl.addEventListener('click', () => startDownload(r, select.value, dl));
  actions.append(select, dl);

  li.append(img, body, actions);
  return li;
}

// ---------------------------------------------------------------- downloads (right sidebar, several at once)
async function startDownload(result, format, trigger) {
  const body = { url: result.webpage_url, format };
  if (format === 'mp4') body.quality = 'best';
  else body.bitrate = DEFAULT_BITRATE;

  show($('failCard'), false);
  trigger.disabled = true;
  try {
    const job = await api.startDownload(body);
    addJobCard(job.job_id, result.title, format);
    renderJob(job);
    startPolling();
    trigger.textContent = 'Added';
    window.setTimeout(() => { trigger.textContent = 'Download'; trigger.disabled = false; }, 1500);
  } catch (err) {
    trigger.disabled = false;
    showFailure(friendly(err));
  }
}

function updateSidebar() {
  const count = state.jobs.size;
  show($('jobsEmpty'), count === 0);
  $('jobCount').textContent = String(count);
  show($('jobCount'), count > 0);
}

function addJobCard(jobId, title, format) {
  const card = el('div', 'card card-body job-card p-2');
  card.dataset.jobId = jobId;
  const heading = el('div', 'fw-semibold text-break job-title', `${title} (${format.toUpperCase()})`);

  const bar = el('div', 'progress my-2');
  bar.style.height = '1.25rem';
  const fill = el('div', 'progress-bar');
  fill.setAttribute('role', 'progressbar');
  fill.setAttribute('aria-label', `Progress of ${title}`);
  fill.setAttribute('aria-valuemin', '0');
  fill.setAttribute('aria-valuemax', '100');
  bar.append(fill);

  const status = el('div', 'job-status small');
  status.setAttribute('role', 'status');
  status.setAttribute('aria-live', 'polite');
  const stats = el('div', 'text-body-secondary small job-stats');

  const actions = el('div', 'd-flex gap-2 flex-wrap mt-2 job-actions');
  const cancel = button('Cancel', 'btn-outline-danger', () => onCancel(jobId));
  cancel.setAttribute('aria-label', `Cancel ${title}`);
  actions.append(cancel);

  card.append(heading, bar, status, stats, actions);
  $('jobList').prepend(card);
  state.jobs.set(jobId, { el: { card, fill, status, stats, actions, cancel }, title, done: false });
  remember();
  updateSidebar();
}

function startPolling() {
  if (!state.timer) state.timer = window.setInterval(poll, POLL_MS);
  poll();
}

function stopPollingIfIdle() {
  const active = [...state.jobs.values()].some((j) => !j.done);
  if (!active && state.timer) { window.clearInterval(state.timer); state.timer = null; }
}

async function poll() {
  if (state.polling) return;
  const active = [...state.jobs.entries()].filter(([, j]) => !j.done);
  if (!active.length) { stopPollingIfIdle(); return; }
  state.polling = true;
  try {
    await Promise.all(active.map(async ([id, j]) => {
      try {
        renderJob(await api.status(id));
      } catch (err) {
        if (err instanceof ApiError && err.status === 404) {
          j.done = true;
          j.el.status.textContent = 'This download is no longer available.';
          j.el.cancel.remove();
          j.el.actions.append(button('Dismiss', 'btn-link', () => removeCard(id)));
        }
        // transient network errors: keep polling
      }
    }));
  } finally {
    state.polling = false;
    stopPollingIfIdle();
  }
}

function renderJob(job) {
  const entry = state.jobs.get(job.job_id);
  if (!entry) return;
  const { fill, status, stats, cancel } = entry.el;
  const terminal = TERMINAL.includes(job.status);

  if (job.indeterminate && !terminal) {
    fill.classList.add('indeterminate', 'progress-bar-striped');
    fill.removeAttribute('aria-valuenow');
    fill.textContent = job.status === 'processing' ? 'Processing...' : '';
  } else {
    fill.classList.remove('indeterminate', 'progress-bar-striped');
    fill.style.width = `${job.progress}%`;
    fill.setAttribute('aria-valuenow', String(Math.round(job.progress)));
    fill.textContent = `${Math.round(job.progress)}%`;
  }
  fill.classList.toggle('bg-success', job.status === 'completed');
  fill.classList.toggle('bg-danger', job.status === 'failed');

  status.textContent = job.message;
  const parts = [];
  if (job.downloaded_bytes > 0) parts.push(job.total ? `${job.downloaded} of ${job.total}` : job.downloaded);
  if (job.speed) parts.push(job.speed);
  if (job.eta) parts.push(`ETA ${job.eta}`);
  stats.textContent = parts.join(' · ');
  cancel.disabled = job.message === 'Cancelling...';

  if (terminal && !entry.done) {
    entry.done = true;
    onTerminal(job, entry);
  }
}

function onTerminal(job, entry) {
  const { actions, cancel, status } = entry.el;
  cancel.remove();
  if (job.status === 'completed') {
    status.textContent = `Complete: ${job.filename || ''}`;
    const link = el('a', 'btn btn-sm btn-success', 'Download File');
    link.href = api.fileUrl(job.job_id);
    link.setAttribute('download', job.filename || '');
    actions.append(link, button('Open Folder', 'btn-outline-secondary', openHistoryTab));
    loadHistory();
  } else if (job.status === 'failed') {
    status.textContent = job.error?.message || job.message;
    status.classList.add('text-danger');
  } else {
    status.textContent = 'The download was cancelled.';
  }
  actions.append(button('Dismiss', 'btn-link', () => removeCard(job.job_id)));
  stopPollingIfIdle();
}

function removeCard(id) {
  const entry = state.jobs.get(id);
  if (!entry) return;
  entry.el.card.remove();
  state.jobs.delete(id);
  remember();
  updateSidebar();
}

function showFailure(message) {
  const box = $('failCard');
  box.textContent = message;
  show(box, true);
}

async function onCancel(id) {
  const entry = state.jobs.get(id);
  if (!entry) return;
  entry.el.cancel.disabled = true;
  try {
    renderJob(await api.cancel(id));
  } catch (err) {
    entry.el.cancel.disabled = false;
    showFailure(friendly(err));
  }
}

// ---------------------------------------------------------------- history
async function loadHistory() {
  const error = $('historyError');
  show(error, false);
  try {
    renderHistory(await api.history());
  } catch (err) {
    error.textContent = friendly(err);
    show(error, true);
  }
}

function cell(text, className) {
  const td = document.createElement('td');
  td.textContent = text;
  if (className) td.className = className;
  return td;
}

function renderHistory(items) {
  const body = $('historyBody');
  body.replaceChildren();
  show($('historyEmpty'), items.length === 0);
  show($('historyTable'), items.length > 0);

  for (const item of items) {
    const tr = document.createElement('tr');
    tr.append(
      cell(item.title || item.filename || '(untitled)', 'history-title'),
      cell(item.format.toUpperCase() + (item.format === 'mp3' && item.bitrate ? ` ${item.bitrate}k` : '')),
      cell(formatDate(item.created_at)),
      cell(item.status === 'failed' ? `failed: ${item.error?.message || ''}` : item.status),
      cell(item.filesize ? formatBytes(item.filesize) : '', 'text-end text-nowrap'),
    );
    const actions = document.createElement('td');
    actions.className = 'text-nowrap text-end';
    if (item.file_available) {
      const link = el('a', 'btn btn-sm btn-outline-success me-1', 'Download again');
      link.href = api.fileUrl(item.job_id);
      link.setAttribute('download', item.filename || '');
      actions.append(link);
    }
    if (!['queued', 'analyzing', 'downloading', 'processing'].includes(item.status)) {
      const del = button('Delete', 'btn-outline-danger', () => removeHistory(item.job_id));
      del.setAttribute('aria-label', `Delete ${item.title || 'download'} from history`);
      actions.append(del);
    }
    tr.append(actions);
    body.append(tr);
  }
}

async function removeHistory(id) {
  try {
    await api.removeHistory(id);
    await loadHistory();
  } catch (err) {
    const error = $('historyError');
    error.textContent = friendly(err);
    show(error, true);
  }
}

// ---------------------------------------------------------------- about
async function loadAbout() {
  try {
    const about = await api.about();
    $('aboutApp').textContent = about.name;
    $('aboutVersion').textContent = about.version;
    $('aboutYtdlp').textContent = about.yt_dlp_version || 'not available';
    $('aboutFfmpeg').textContent = about.ffmpeg_version || 'not available';
    const l = about.limits;
    $('aboutLimits').textContent = `up to ${Math.round(l.max_video_duration_seconds / 60)} min, ${formatBytes(l.max_download_size_bytes)} per file, ${l.max_concurrent_downloads} at a time (more wait in the queue)`;
  } catch { /* the about box is informational only */ }
}

function openHistoryTab() {
  window.bootstrap?.Tab.getOrCreateInstance($('tab-history')).show();
  loadHistory();
}

// ---------------------------------------------------------------- wiring
$('searchForm').addEventListener('submit', onSearch);
$('refreshHistory').addEventListener('click', loadHistory);
$('tab-history').addEventListener('shown.bs.tab', loadHistory);
$('tab-about').addEventListener('shown.bs.tab', loadAbout);
updateSidebar();

// Resume the sidebar for unfinished downloads after a page reload.
for (const id of recall()) {
  try {
    const job = await api.status(id);
    addJobCard(id, job.title || 'Download', job.format);
    renderJob(job);
  } catch { /* job expired or removed */ }
}
if (state.jobs.size) startPolling();
