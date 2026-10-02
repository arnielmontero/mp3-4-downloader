import { api, ApiError } from './api.js';
import { bitrateNote, formatBytes, formatDate, looksLikeYoutubeUrl, qualityLabel } from './format.js';

const $ = (id) => document.getElementById(id);
const POLL_MS = 1000;
const STORAGE_KEY = 'ytd.activeJob';

const state = { info: null, jobId: null, timer: null, polling: false };

// ---- theme: follow the OS light/dark preference (no inline script, CSP friendly) ----
const media = window.matchMedia('(prefers-color-scheme: dark)');
const applyTheme = () => document.documentElement.setAttribute('data-bs-theme', media.matches ? 'dark' : 'light');
applyTheme();
media.addEventListener('change', applyTheme);

// ---------------------------------------------------------------- small UI helpers
function show(el, visible = true) { el.classList.toggle('d-none', !visible); }

function setBusy(button, busy, label) {
  button.disabled = busy;
  show(button.querySelector('.spinner-border') || document.createElement('i'), busy);
  if (label !== undefined && button.querySelector('.label')) button.querySelector('.label').textContent = label;
}

function showUrlError(message) {
  const box = $('urlError');
  box.textContent = message || '';
  show(box, Boolean(message));
  $('urlInput').setAttribute('aria-invalid', message ? 'true' : 'false');
}

function friendly(err) {
  if (err instanceof ApiError) return err.message;
  return 'Something went wrong. Please try again.';
}

function remember(id) {
  try { id ? sessionStorage.setItem(STORAGE_KEY, id) : sessionStorage.removeItem(STORAGE_KEY); } catch { /* storage may be unavailable */ }
}
function recall() {
  try { return sessionStorage.getItem(STORAGE_KEY); } catch { return null; }
}

// ---------------------------------------------------------------- analyze
async function onAnalyze(event) {
  event.preventDefault();
  const url = $('urlInput').value.trim();
  showUrlError('');
  if (!url) { showUrlError('Please enter a valid YouTube URL.'); return; }
  if (!looksLikeYoutubeUrl(url)) { showUrlError('Please enter a valid YouTube URL.'); return; }

  resetResults();
  setBusy($('analyzeBtn'), true, 'Analyzing...');
  try {
    state.info = await api.analyze(url);
    renderInfo(state.info);
  } catch (err) {
    showUrlError(friendly(err));
  } finally {
    setBusy($('analyzeBtn'), false, 'Analyze');
  }
}

function resetResults() {
  state.info = null;
  show($('optionsForm'), false);
  show($('doneCard'), false);
  show($('failCard'), false);
  if (!state.jobId) show($('progressCard'), false);
}

function renderInfo(info) {
  const thumb = $('thumb');
  if (info.thumbnail) { thumb.src = info.thumbnail; thumb.hidden = false; } else { thumb.removeAttribute('src'); thumb.hidden = true; }
  $('infoTitle').textContent = info.title || '';
  $('infoDuration').textContent = info.duration_formatted || 'Unknown';
  $('infoUploader').textContent = info.uploader || 'Unknown';

  const mp4 = $('fmtMp4');
  const mp3 = $('fmtMp3');
  mp4.disabled = !info.formats.mp4;
  mp3.disabled = !info.formats.mp3;
  (info.formats.mp4 ? mp4 : mp3).checked = true;

  const select = $('qualitySelect');
  select.replaceChildren(...info.qualities.map((q) => {
    const option = document.createElement('option');
    option.value = q;
    option.textContent = qualityLabel(q);
    return option;
  }));
  $('bitrateSelect').value = String(info.default_mp3_bitrate || 192);
  syncFormat();
  show($('optionsForm'), true);
  $('optionsForm').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function selectedFormat() {
  return document.querySelector('input[name="format"]:checked')?.value || 'mp4';
}

function syncFormat() {
  const mp3 = selectedFormat() === 'mp3';
  show($('qualityGroup'), !mp3);
  show($('bitrateGroup'), mp3);
  $('bitrateNote').textContent = mp3 && state.info ? bitrateNote($('bitrateSelect').value, state.info.source_audio_bitrate) : '';
}

// ---------------------------------------------------------------- download
async function onDownload(event) {
  event.preventDefault();
  if (!state.info) return;
  const format = selectedFormat();
  const body = { url: state.info.webpage_url, format };
  if (format === 'mp4') body.quality = $('qualitySelect').value;
  else body.bitrate = Number($('bitrateSelect').value);

  show($('failCard'), false);
  show($('doneCard'), false);
  $('downloadBtn').disabled = true;
  try {
    const job = await api.startDownload(body);
    state.jobId = job.job_id;
    remember(job.job_id);
    renderProgress(job);
    startPolling();
  } catch (err) {
    $('downloadBtn').disabled = false;
    showFailure(friendly(err));
  }
}

function startPolling() {
  stopPolling();
  state.timer = window.setInterval(poll, POLL_MS);
  poll();
}

function stopPolling() {
  if (state.timer) window.clearInterval(state.timer);
  state.timer = null;
}

async function poll() {
  if (state.polling || !state.jobId) return;
  state.polling = true;
  try {
    renderProgress(await api.status(state.jobId));
  } catch (err) {
    if (err instanceof ApiError && err.status === 404) { finishJob(); showFailure('This download is no longer available.'); }
    // transient network errors: keep polling
  } finally {
    state.polling = false;
  }
}

function renderProgress(job) {
  show($('progressCard'), true);
  const bar = $('progressBar');
  const terminal = ['completed', 'failed', 'cancelled'].includes(job.status);

  if (job.indeterminate && !terminal) {
    bar.classList.add('indeterminate', 'progress-bar-striped');
    bar.removeAttribute('aria-valuenow');
    bar.textContent = job.status === 'processing' ? 'Processing...' : '';
  } else {
    bar.classList.remove('indeterminate', 'progress-bar-striped');
    bar.style.width = `${job.progress}%`;
    bar.setAttribute('aria-valuenow', String(Math.round(job.progress)));
    bar.textContent = `${Math.round(job.progress)}%`;
  }
  bar.classList.toggle('bg-success', job.status === 'completed');
  bar.classList.toggle('bg-danger', job.status === 'failed');

  $('statusText').textContent = job.message;
  const parts = [];
  if (job.downloaded_bytes > 0) parts.push(job.total ? `${job.downloaded} of ${job.total}` : job.downloaded);
  if (job.speed) parts.push(job.speed);
  if (job.eta) parts.push(`ETA ${job.eta}`);
  $('statsText').textContent = parts.join(' · ');

  $('cancelBtn').disabled = terminal || job.message === 'Cancelling...';
  show($('cancelBtn'), !terminal);

  if (terminal) onTerminal(job);
}

function onTerminal(job) {
  stopPolling();
  finishJob();
  $('downloadBtn').disabled = false;
  if (job.status === 'completed') {
    $('doneFile').textContent = job.filename || '';
    $('fileBtn').href = api.fileUrl(job.job_id);
    $('fileBtn').setAttribute('download', job.filename || '');
    show($('doneCard'), true);
    show($('progressCard'), false);
    loadHistory();
  } else if (job.status === 'failed') {
    show($('progressCard'), false);
    showFailure(job.error?.message || job.message);
  } else {
    show($('progressCard'), false);
    showFailure('The download was cancelled.', 'alert-secondary');
  }
}

function finishJob() {
  state.jobId = null;
  remember(null);
}

function showFailure(message, cls = 'alert-danger') {
  const box = $('failCard');
  box.className = `alert ${cls}`;
  box.textContent = message;
  show(box, true);
}

async function onCancel() {
  if (!state.jobId) return;
  $('cancelBtn').disabled = true;
  try {
    renderProgress(await api.cancel(state.jobId));
  } catch (err) {
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

function renderHistory(items, highlightId = null) {
  const body = $('historyBody');
  body.replaceChildren();
  show($('historyEmpty'), items.length === 0);
  show($('historyTable'), items.length > 0);

  for (const item of items) {
    const tr = document.createElement('tr');
    if (item.job_id === highlightId) tr.className = 'table-active';
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
      const link = document.createElement('a');
      link.className = 'btn btn-sm btn-outline-success me-1';
      link.href = api.fileUrl(item.job_id);
      link.setAttribute('download', item.filename || '');
      link.textContent = 'Download again';
      actions.append(link);
    }
    if (!['queued', 'analyzing', 'downloading', 'processing'].includes(item.status)) {
      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'btn btn-sm btn-outline-danger';
      del.textContent = 'Delete';
      del.setAttribute('aria-label', `Delete ${item.title || 'download'} from history`);
      del.addEventListener('click', () => removeHistory(item.job_id));
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
    $('aboutLimits').textContent = `up to ${Math.round(l.max_video_duration_seconds / 60)} min, ${formatBytes(l.max_download_size_bytes)} per file, ${l.max_concurrent_downloads} at a time`;
  } catch { /* the about box is informational only */ }
}

function openHistoryTab() {
  const trigger = $('tab-history');
  window.bootstrap?.Tab.getOrCreateInstance(trigger).show();
  loadHistory();
}

// ---------------------------------------------------------------- wiring
$('analyzeForm').addEventListener('submit', onAnalyze);
$('optionsForm').addEventListener('submit', onDownload);
$('optionsForm').addEventListener('change', syncFormat);
$('cancelBtn').addEventListener('click', onCancel);
$('folderBtn').addEventListener('click', openHistoryTab);
$('newBtn').addEventListener('click', () => { show($('doneCard'), false); $('urlInput').value = ''; $('urlInput').focus(); resetResults(); });
$('refreshHistory').addEventListener('click', loadHistory);
$('tab-history').addEventListener('shown.bs.tab', loadHistory);
$('tab-about').addEventListener('shown.bs.tab', loadAbout);

// Resume progress display after a page reload.
const resumeId = recall();
if (resumeId) {
  state.jobId = resumeId;
  startPolling();
}
